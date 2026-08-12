@ignore @command
Feature: 分潤夥伴登入自助報表

  分潤夥伴以 slug + 密碼登入 `/profit-report/{slug}/`，取得一組限時 token 後
  才能查詢自己的分潤報表。登入端點對外公開，因此防暴力破解與防帳號列舉
  是這個 Feature 的主要內容。

  # ── 統一錯誤訊息（防帳號列舉）─────────────────────────────
  # 「slug 不存在」與「密碼錯誤」一律回同一個 401 unauthorized，
  # 不得從錯誤訊息或耗時差異推斷某個 slug 是否存在。
  #
  # ── 雙維度速率限制 ───────────────────────────────────────
  # per-slug 與 per-IP 各自獨立計數，任一達 5 次即鎖定 15 分鐘。
  # 兩個維度都存在的理由：
  #   - 只有 per-slug → 攻擊者輪換 slug 就繞過
  #   - 只有 per-IP   → 分散式來源就繞過
  #
  # ── token 永不以明文落地 ─────────────────────────────────
  # 儲存的是 token 的雜湊；Cookie 為 HttpOnly + Secure + SameSite=Lax，
  # 且 Path 限定在本外掛的 REST 命名空間之下。前端讀不到 token（XSS 防護）。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 slug 為 "partner-a" 且密碼為 "correct-pw1"
    And 已存在分潤夥伴 "夥伴B" 其 slug 為 "partner-b"

  Rule: 後置（成功）- 登入成功回傳 token、到期時間與夥伴基本資料
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerAuthCookieTest.php::test_login_response_body_contains_token_and_expires_at
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerAuthCookieTest.php::test_login_response_sets_secure_httponly_cookie
    # @covered-by: tests/Unit/Application/Service/PartnerAuthServiceTest.php::test_happy_returns_partner_snapshot_and_resets_counter
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_partner_logs_in_with_real_password

    Example: 以正確的 slug 與密碼登入
      When 訪客以 slug "partner-a" 與密碼 "correct-pw1" 登入
      Then 系統應回傳登入成功
      And 回應應包含以下欄位
        | field      |
        | token      |
        | expires_at |
        | partner    |
      And 回應的 partner 應包含 id / name / slug

    Example: 登入成功時簽發 HttpOnly Cookie
      When 訪客以 slug "partner-a" 與密碼 "correct-pw1" 登入
      Then 回應應設定名為 "profit_partner_token" 的 Cookie
      And 該 Cookie 應具備 HttpOnly、Secure 與 SameSite=Lax 屬性
      And 該 Cookie 的 Path 應限定在 "/wp-json/power-shop/"
      And 該 Cookie 的存活秒數應為 3600

    Example: 登入成功後清空該夥伴的失敗計數
      Given 夥伴 "夥伴A" 已累積 3 次登入失敗
      When 訪客以 slug "partner-a" 與正確密碼登入
      Then 夥伴 "夥伴A" 的登入失敗計數應歸零
      And 該來源 IP 的登入失敗計數應歸零

  Rule: 安全（列舉）- 帳號不存在與密碼錯誤必須回相同的錯誤
    # @covered-by: tests/Unit/Application/Service/PartnerAuthServiceTest.php::test_unknown_slug_throws_invalid_credentials
    # @covered-by: tests/Unit/Application/Service/PartnerAuthServiceTest.php::test_wrong_password_throws_invalid_credentials_and_records_failure
    # @covered-by: tests/Unit/Application/Service/PartnerAuthServiceTest.php::test_message_does_not_distinguish_unknown_slug_from_wrong_password
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_wrong_password_is_rejected_and_issues_no_token
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_other_partners_password_cannot_log_into_this_slug

    Scenario Outline: 兩種登入失敗回相同的錯誤碼與訊息
      When 訪客以 slug "<slug>" 與密碼 "<password>" 登入
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

      Examples:
        | slug           | password    | 情境         |
        | partner-a      | wrong-pw    | 密碼錯誤      |
        | never-existed  | any-pw      | slug 不存在   |

    Example: 未知 slug 的失敗不在 slug 維度留下痕跡
      When 訪客以不存在的 slug "never-existed" 登入失敗
      Then 系統不應為該 slug 建立失敗計數

    Example: 未知 slug 的失敗仍累計來源 IP 的計數
      # 否則攻擊者可用同一 IP 對 1000 個不存在的 slug 各試一次而完全不被記錄。
      When 訪客以不存在的 slug "never-existed" 登入失敗
      Then 該來源 IP 的登入失敗計數應增加 1

  Rule: 安全（速率）- 任一維度達 5 次失敗即鎖定並回 429
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerBruteForceTest.php::test_five_failures_block_with_429
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerBruteForceTest.php::test_window_reset_unblocks_login
    # @covered-by: tests/Unit/Application/Service/LoginRateLimiterTest.php::test_fifth_failure_blocks_and_sends_email
    # @covered-by: tests/Unit/Application/Service/LoginRateLimiterIpDimensionTest.php::test_assert_not_blocked_throws_when_ip_exceeds_threshold_across_different_slugs
    # @covered-by: tests/Unit/Application/Service/PartnerAuthServiceTest.php::test_already_blocked_short_circuits_to_too_many_attempts

    Example: 同一 slug 連續失敗 5 次後被鎖定
      When 訪客以 slug "partner-a" 連續 5 次輸入錯誤密碼
      And 訪客再次以 slug "partner-a" 登入
      Then 系統應回傳錯誤
        | status | code         |
        | 429    | rate_limited |
      And 回應應包含 Retry-After 標頭

    Example: 鎖定期間即使密碼正確也不放行
      Given 夥伴 "夥伴A" 已因連續失敗而被鎖定
      When 訪客以 slug "partner-a" 與正確密碼 "correct-pw1" 登入
      Then 系統應回傳錯誤
        | status | code         |
        | 429    | rate_limited |

    Example: 同一 IP 輪換不同 slug 攻擊仍會被鎖定
      When 同一來源 IP 對 5 個不同的 slug 各嘗試登入失敗 1 次
      And 該 IP 再次嘗試登入任一 slug
      Then 系統應回傳錯誤
        | status | code         |
        | 429    | rate_limited |

    Example: 兩個維度都被鎖定時 Retry-After 取較長者
      Given slug 維度剩餘鎖定時間為 300 秒
      And IP 維度剩餘鎖定時間為 600 秒
      When 訪客嘗試登入
      Then 回應的 Retry-After 應為 600

    Example: 鎖定視窗過後計數自動歸零
      Given 夥伴 "夥伴A" 已因連續失敗而被鎖定
      When 經過 15 分鐘後訪客以正確密碼登入
      Then 系統應回傳登入成功

    Example: 視窗內的重複失敗不得延長視窗
      Given 夥伴 "夥伴A" 在 10 分鐘前開始累積失敗
      When 訪客再次以錯誤密碼登入
      Then 該計數的到期時間應維持原本的視窗結束時間

  Rule: 通知（警示）- slug 維度達門檻時通知站長，IP 維度不寄信
    # @covered-by: tests/Unit/Application/Service/LoginRateLimiterTest.php::test_fifth_failure_blocks_and_sends_email
    # @covered-by: tests/Unit/Application/Service/LoginRateLimiterTest.php::test_email_failure_is_warn_and_swallowed

    # IP 維度不寄信是刻意的：攻擊者輪換 slug 時會把站長信箱洗版。

    Example: slug 失敗達 5 次時寄出警示信給站長
      When 訪客以 slug "partner-a" 連續 5 次輸入錯誤密碼
      Then 系統應寄出一封暴力破解警示信
      And 系統應寫入一筆稽核紀錄

    Example: 寄信失敗不得阻擋登入流程
      Given 站台的寄信功能將失敗
      When 訪客以 slug "partner-a" 連續 5 次輸入錯誤密碼
      Then 系統仍應正常回傳 429
      And 系統應寫入一筆寄信失敗的紀錄

    Example: IP 維度達門檻時只寫稽核紀錄不寄信
      When 同一來源 IP 累積 5 次登入失敗
      Then 系統應寫入一筆稽核紀錄
      And 系統不應為 IP 維度寄出警示信

  Rule: 隱私（IP）- 來源 IP 永不以明文落地
    # @covered-by: tests/Unit/Application/Service/LoginRateLimiterIpDimensionTest.php::test_ip_is_hashed_in_transient_key

    Example: 速率限制的儲存鍵與稽核紀錄皆不含明文 IP
      When 訪客以來源 IP "203.0.113.9" 登入失敗
      Then 速率限制的儲存鍵不應包含 "203.0.113.9"
      And 稽核紀錄不應包含 "203.0.113.9"

    Scenario Outline: 無效的來源 IP 一律退化為僅 slug 維度
      Given 取得的來源 IP 為 "<ip>"
      When 訪客登入失敗
      Then 系統不應建立任何 IP 維度的計數

      Examples:
        | ip        |
        |           |
        | not-an-ip |

    Example: 反向代理環境可由站台自行注入真實來源 IP
      Given 站台已透過 filter 提供真實來源 IP
      When 訪客登入失敗
      Then 速率限制應以該真實來源 IP 計數

  Rule: 後置（token）- token 永不以明文儲存且有存活上限
    # @covered-by: tests/Unit/Application/Service/PartnerTokenStoreTest.php::test_plaintext_token_never_stored_in_transient
    # @covered-by: tests/Unit/Application/Service/PartnerTokenStoreTest.php::test_verify_returns_null_after_ttl_expired
    # @covered-by: tests/Unit/Application/Service/PartnerTokenStoreTest.php::test_verify_double_checks_expires_at_even_if_transient_alive
    # @covered-by: tests/Unit/Application/Service/PartnerTokenStoreTest.php::test_each_issue_produces_unique_token
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerTokenSecurityTest.php::test_plaintext_token_never_persists_in_transients
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerTokenSecurityTest.php::test_expired_token_returns_401_on_me

    Example: token 以雜湊形式儲存
      When 訪客登入成功並取得 token
      Then 儲存端不應存在該 token 的明文

    Example: token 於 3600 秒後失效
      Given 夥伴 "夥伴A" 已登入並取得 token
      When 經過 3600 秒後夥伴以該 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

  Rule: 後置（撤銷）- 密碼變更後既有 token 立即失效
    # @covered-by: tests/Unit/Application/Service/PartnerTokenStorePasswordRotationTest.php::test_verify_returns_null_when_password_changed_after_issue
    # @covered-by: tests/Unit/Application/Service/PartnerTokenStorePasswordRotationTest.php::test_verify_revokes_token_when_password_changes_within_same_second
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerPasswordRotationRevokesTokenTest.php::test_old_token_is_revoked_after_admin_regenerates_password
    # @covered-by: tests/Integration/Infrastructure/PartnerTermRepositoryGetPasswordChangedAtTest.php::test_password_changed_at_strictly_increases_even_within_same_second

    # 「同秒簽發舊 token + 同秒改密」也必須被涵蓋：
    # token 的簽發時間**等於**密碼變更時間時視為已撤銷。

    Example: 密碼變更後舊 token 立即失效
      Given 夥伴 "夥伴A" 已登入並取得 token
      When 夥伴 "夥伴A" 的密碼被變更
      And 夥伴以舊 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 同一秒內簽發的舊 token 在同秒改密後亦失效
      Given 夥伴 "夥伴A" 的 token 於時間 T 簽發
      When 夥伴 "夥伴A" 的密碼於同一個時間 T 被變更
      And 夥伴以該 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

  Rule: 後置（登出）- 登出必須是冪等的且永遠清除本機狀態
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/LogoutPartnerUseCaseTest.php::test_logout_revokes_token
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/LogoutPartnerUseCaseTest.php::test_logout_with_unknown_token_does_not_throw
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerTokenSecurityTest.php::test_revoked_token_cannot_be_reused
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_logout_revokes_token_for_reports
    # @covered-by: tests/js/partnerPortalAuth.test.ts::🔴 clear 是冪等的：連續呼叫、或本來就沒東西都不得拋錯
    # @covered-by: tests/js/partnerPortalAuth.test.ts::沒有資料時 read 回 null
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/LogoutPartnerUseCaseTest.php::test_logout_with_unknown_token_does_not_throw

    Example: 登出後 token 立即失效
      Given 夥伴 "夥伴A" 已登入並取得 token
      When 夥伴 "夥伴A" 登出
      And 夥伴以該 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 登出時清除 Cookie
      When 夥伴 "夥伴A" 登出
      Then 回應應設定 "profit_partner_token" 的存活秒數為 0

    Example: 重複登出不得報錯
      Given 夥伴 "夥伴A" 已登出
      When 夥伴 "夥伴A" 再次登出
      Then 系統應回傳成功

    Example: 登出 API 失敗時前端仍清除本機登入狀態
      Given 登出 API 將失敗
      When 夥伴 "夥伴A" 在報表頁點擊登出
      Then 本機的登入資訊應被清除
      And 畫面應導向登入頁

  Rule: 隔離（跨夥伴）- 網址的夥伴與本機登入身分不一致時必須遮蔽
    # @covered-by: tests/js/partnerPortalAuth.test.ts::🔴 slug 不符時 status 是 loading，**不是** authenticated
    # @covered-by: tests/js/partnerPortalAuth.test.ts::🔴 slug 不符時 partner 同時被遮蔽成 null（雙保險）
    # @covered-by: tests/js/partnerPortalAuth.test.ts::🔴 slug 不符時 status **不可**是 guest
    # @covered-by: tests/js/partnerPortalAuth.test.ts::slug 不符時回報 isMismatch，呼叫端據此觸發強制登出

    Example: 以夥伴A 的登入狀態開啟夥伴B 的報表網址
      Given 夥伴 "夥伴A" 已在瀏覽器登入
      When 使用者開啟 "/profit-report/partner-b/"
      Then 畫面不應顯示夥伴 "夥伴A" 的任何資料
      And 本機的登入資訊應被清除
      And 畫面應導向登入頁

  Rule: 前置（掛載）- 報表頁只在自己的網址上接手，不得攔截站台其他頁面
    # 這條分兩個方向，缺任一個都會出大事：
    #   沒掛上 ⇒ 夥伴開報表頁看到的是佈景主題的 404，而所有 API 測試照樣全綠
    #   掛太廣 ⇒ 每一個頁面都被判成「找不到分潤頁面」，整個站台等同下線
    # @covered-by: tests/Integration/Infrastructure/WordPress/PartnerPortalRendererTest.php::test_renderer_is_hooked_on_template_redirect
    # @covered-by: tests/Integration/Infrastructure/WordPress/PartnerPortalRendererTest.php::test_non_report_request_falls_through_without_output
    # @covered-by: tests/Integration/Infrastructure/WordPress/PartnerPortalRendererTest.php::test_query_var_is_the_one_registered_by_rewrite_rules
    # @covered-by: tests/Integration/Infrastructure/WordPress/PartnerPortalRendererTest.php::test_mount_id_contract_is_stable
    # @covered-by: tests/Integration/Infrastructure/WordPress/RewriteRulesTest.php::test_profit_report_rewrite_rule_exists
    # @covered-by: tests/Integration/Infrastructure/WordPress/RewriteRulesTest.php::test_profit_report_query_var_is_registered

    Example: 報表頁網址由報表頁自己接手
      When 訪客開啟 "/profit-report/partner-a/"
      Then 系統應輸出夥伴報表頁而非佈景主題的頁面

    Example: 非報表頁網址必須原封不動交還佈景主題
      When 訪客開啟站台的任一般頁面
      Then 系統不應輸出任何分潤報表相關內容
      And 頁面應由佈景主題正常渲染

  Rule: 前置（表單）- 登入頁必須從網址帶入 slug 且不可由使用者更改
    # @covered-by: tests/js/componentWiring.test.ts::🔴 slug 從網址帶入且使用者不可更改
    # @covered-by: tests/js/componentWiring.test.ts::⚠️ SLUG 取不到時欄位可編輯（刻意的降級，非疏漏）

    Example: 登入表單的 slug 由網址帶入並鎖定
      When 訪客開啟 "/profit-report/partner-a/"
      Then 登入表單的 slug 欄位應為 "partner-a"
      And 登入表單的 slug 欄位應為停用狀態

    Example: 被鎖定期間登入按鈕顯示倒數且停用
      Given 系統回應 429 且 Retry-After 為 600
      Then 登入按鈕應為停用狀態
      And 畫面應顯示剩餘等待秒數

    Example: 無法解析的 Retry-After 不得讓按鈕永久停用
      Given 系統回應 429 但 Retry-After 無法解析為數字
      Then 登入按鈕應為可用狀態
</content>
