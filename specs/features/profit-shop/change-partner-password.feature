@ignore @command
Feature: 分潤夥伴自助修改密碼

  分潤夥伴在自助報表頁可自行修改登入密碼。修改成功後該夥伴的所有既有登入
  session 立即失效，必須以新密碼重新登入。

  # ── 為什麼一定要驗目前密碼 ────────────────────────────────
  # 報表頁是公開網址、以 Cookie 認證。若不驗目前密碼，攻擊者偷到 Cookie 後
  # 可以直接改密碼把真正的夥伴鎖在門外。
  #
  # ── 速率限制維度與登入刻意隔離 ────────────────────────────
  # 改密使用 pseudo-slug「pwchange:{夥伴 term id}」計數，與登入的 slug 維度分開：
  #   - 登入失敗被鎖 → 不影響已登入者改密
  #   - 改密失敗被鎖 → 不影響該夥伴登入
  #
  # ── 弱密碼不得污染速率限制 ────────────────────────────────
  # 密碼格式不合規是使用者輸入問題，不是攻擊訊號。
  # 否則使用者按錯 5 次格式就被鎖 15 分鐘。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5 且密碼為 "current-pw1"
    And 夥伴 "夥伴A" 已登入並持有有效 token

  Rule: 前置（認證）- 只有持有效夥伴 token 者能呼叫
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_unauthenticated_returns_401
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_admin_nonce_alone_is_rejected
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerVsAdminAuthIsolationTest.php::test_admin_with_wp_nonce_cannot_access_partner_reports

    Example: 未登入者不得修改密碼
      When 未登入的訪客嘗試修改密碼
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 後台 nonce 不能走此端點
      Given 站長已登入後台並持有有效的 admin nonce
      When 站長僅以 admin nonce 呼叫夥伴自助改密端點
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 夥伴識別為非正整數時立即拒絕
      Given 請求內部欄位的夥伴識別為 0
      When 系統執行改密流程
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |
      And 系統不應對 pseudo-slug "pwchange:0" 累計任何失敗

  Rule: 前置（驗證）- 必須提供正確的目前密碼
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_wrong_current_password_returns_422_invalid_credentials
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/ChangePasswordUseCaseTest.php::test_wrong_current_password_throws_invalid_credentials_and_records_failure
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/ChangePasswordUseCaseTest.php::test_happy_new_password_verifies_old_does_not

    Example: 目前密碼正確時允許修改
      When 夥伴 "夥伴A" 以目前密碼 "current-pw1" 修改為新密碼 "brand-new-2"
      Then 系統應回傳成功

    Example: 目前密碼錯誤時拒絕並累計失敗
      When 夥伴 "夥伴A" 以目前密碼 "wrong-pw" 修改為新密碼 "brand-new-2"
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |
      And pseudo-slug "pwchange:5" 的失敗計數應增加 1

    Example: 目前密碼錯誤時密碼不得被變更
      When 夥伴 "夥伴A" 以錯誤的目前密碼嘗試修改
      Then 夥伴 "夥伴A" 仍可用 "current-pw1" 登入

  Rule: 前置（強度）- 新密碼必須符合複雜度規則
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_weak_new_password_returns_422_with_reasons
    # @covered-by: tests/Unit/Domain/ValueObject/PartnerPasswordTest.php::test_construct_throws_when_too_short
    # @covered-by: tests/Unit/Domain/ValueObject/PartnerPasswordTest.php::test_construct_throws_when_missing_letter
    # @covered-by: tests/Unit/Domain/ValueObject/PartnerPasswordTest.php::test_construct_throws_when_missing_digit
    # @covered-by: tests/Unit/Domain/ValueObject/PartnerPasswordTest.php::test_construct_throws_with_all_reasons_when_empty

    # 規則為累積式：一次回傳所有違反的原因。
    #   too_short      —— 少於 8 個字元（以 codepoint 計，非 byte）
    #   missing_letter —— 不含英文字母
    #   missing_digit  —— 不含數字

    Scenario Outline: 弱密碼被拒絕並回傳結構化原因
      When 夥伴 "夥伴A" 以正確的目前密碼修改為新密碼 "<new_password>"
      Then 系統應回傳錯誤
        | status | code          |
        | 422    | weak_password |
      And 回應的 data.reasons 應包含 <reasons>

      Examples:
        | new_password | reasons                                    |
        | ab1          | too_short                                  |
        | abcdefgh     | missing_digit                              |
        | 12345678     | missing_letter                             |
        | 中文         | too_short, missing_letter, missing_digit    |

    Example: 長度以字元計而非位元組計
      When 夥伴 "夥伴A" 以正確的目前密碼修改為新密碼 "中文密碼測試a1"
      Then 系統不應回報 too_short

    Example: 弱密碼不得累計速率限制
      When 夥伴 "夥伴A" 以正確的目前密碼連續 5 次提交弱密碼
      And 夥伴 "夥伴A" 以正確的目前密碼提交一組合規的新密碼
      Then 系統應回傳成功

  Rule: 安全（速率）- 改密失敗達門檻後鎖定，且與登入維度隔離
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_too_many_wrong_current_returns_429_with_retry_after
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/ChangePasswordUseCaseTest.php::test_too_many_failures_blocks_change_password
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/ChangePasswordUseCaseTest.php::test_pwchange_pseudo_slug_does_not_pollute_login_slug_counter
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/ChangePasswordUseCaseTest.php::test_weak_new_password_throws_without_recording_rate_limit

    Example: 目前密碼連續錯誤 5 次後鎖定
      When 夥伴 "夥伴A" 連續 5 次以錯誤的目前密碼提交
      And 夥伴 "夥伴A" 再次提交
      Then 系統應回傳錯誤
        | status | code         |
        | 429    | rate_limited |
      And 回應應包含 Retry-After 標頭

    Example: 改密被鎖不影響該夥伴登入
      Given 夥伴 "夥伴A" 的改密已因連續失敗而被鎖定
      When 訪客以 slug "partner-a" 與正確密碼登入
      Then 系統應回傳登入成功

    Example: 登入被鎖不影響已登入者改密
      Given 夥伴 "夥伴A" 的登入已因連續失敗而被鎖定
      When 夥伴 "夥伴A" 以正確的目前密碼修改密碼
      Then 系統應回傳成功

  Rule: 後置（撤銷）- 修改成功後所有既有 session 立即失效
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_old_token_revoked_after_self_change_password
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_change_password_clears_partner_cookie_with_max_age_zero
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_new_password_can_login_after_change
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/ChangePasswordUseCaseTest.php::test_password_changed_at_strictly_greater_than_old_token_issued_at

    Example: 修改成功後舊 token 立即失效
      When 夥伴 "夥伴A" 成功修改密碼
      And 夥伴以修改前的 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 修改成功時清除 Cookie 強制重新登入
      When 夥伴 "夥伴A" 成功修改密碼
      Then 回應應設定 "profit_partner_token" 的存活秒數為 0

    Example: 修改成功後只能以新密碼登入
      When 夥伴 "夥伴A" 成功將密碼改為 "brand-new-2"
      Then 以 "brand-new-2" 登入應成功
      And 以 "current-pw1" 登入應失敗

  Rule: 後置（回應）- 回應不得洩漏任何憑證且不得被快取
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_response_has_no_cache_headers
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_happy_change_password_returns_200_and_updates_meta
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/ChangePasswordUseCaseTest.php::test_output_dto_round_trip

    Example: 成功回應只包含成功旗標與變更時間
      When 夥伴 "夥伴A" 成功修改密碼
      Then 回應應包含以下欄位
        | field               |
        | success             |
        | password_changed_at |
      And 回應不應包含密碼明文
      And 回應不應包含密碼雜湊或 token

    Example: 成功回應必須禁止快取
      When 夥伴 "夥伴A" 成功修改密碼
      Then 回應的 Cache-Control 應包含 "no-store"
      And 回應應包含 "Pragma: no-cache"

    Example: 稽核紀錄不得包含密碼
      When 夥伴 "夥伴A" 成功修改密碼
      Then 稽核紀錄應包含夥伴識別與時間
      And 稽核紀錄不應包含任何密碼明文

  Rule: 前置（傳輸）- 密碼欄位必須原樣傳遞不得被清理改寫
    # @covered-by: tests/Integration/Infrastructure/Rest/SetPartnerPasswordEndpointTest.php::test_password_literal_is_preserved
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php::test_missing_required_fields_returns_400

    # 密碼若經過一般的文字清理，含 `<` `>` 或連續空白的密碼會被靜默改寫，
    # 症狀是「設的密碼登不進去」而且兩邊看到的字串完全一樣，極難排查。

    Example: 含特殊字元的密碼必須原樣寫入
      When 夥伴 "夥伴A" 以正確的目前密碼修改為新密碼 "a1 <b>  c&d"
      Then 以 "a1 <b>  c&d" 登入應成功

  Rule: 前端（防呆）- 表單必須阻擋無意義的輸入並防止重複送出
    # @covered-by: NOT-COVERED ChangePassword.tsx 的 Form rule 與三重 disabled 守門，專案無前端測試框架（B9 待補）

    Example: 新密碼與目前密碼相同時不允許送出
      When 夥伴在改密表單將新密碼填為與目前密碼相同
      Then 表單應顯示驗證錯誤且不送出

    Example: 確認密碼不一致時不允許送出
      When 夥伴在改密表單的確認密碼與新密碼不一致
      Then 表單應顯示驗證錯誤且不送出

    Example: 送出中與成功後皆不可再次送出
      When 夥伴送出改密請求
      Then 送出按鈕應為停用狀態
      And 成功之後送出按鈕應維持停用狀態

    Example: 弱密碼失敗時只清空目前密碼欄位
      When 夥伴的改密因新密碼過弱而失敗
      Then 目前密碼欄位應被清空
      And 新密碼與確認密碼欄位應保留使用者輸入

    Example: 其他失敗時清空全部密碼欄位
      When 夥伴的改密因目前密碼錯誤而失敗
      Then 三個密碼欄位應全部被清空

    Example: 成功後導回登入頁並顯示一次性提示
      When 夥伴成功修改密碼
      Then 畫面應導向登入頁
      And 登入頁應顯示一次性的密碼已變更提示

    Example: 使用者在成功導向前離開頁面不得觸發後續動作
      When 夥伴成功修改密碼後立即離開該頁
      Then 系統不應在離開後再觸發登出導向
</content>
