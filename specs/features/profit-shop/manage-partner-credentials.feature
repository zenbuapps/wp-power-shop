@ignore @command
Feature: 管理分潤夥伴的報表登入密碼

  站長需要能查看、複製與變更夥伴的報表登入密碼，再自行透過通訊軟體轉交夥伴。
  這件事在「只存不可逆雜湊」的前提下技術上不可能，因此系統**雜湊與明文並存**。

  # ── 🔴 這是站長明確要求的安全降級，不是疏漏 ─────────────────
  # 已知風險（站長已知悉並接受）：DB 或備份外洩時所有夥伴的報表密碼直接可讀；
  # 若夥伴重複使用了其他服務的密碼，該密碼也會一併曝光。
  # 緩解僅限於「這組密碼只能登入唯讀的分潤報表頁，不能改任何資料、不涉金流」。
  #
  # 保留雜湊而非「只留明文」是為了讓這個決定可逆：
  # 日後若要收回明文功能，只需停止寫入並刪除該欄位，登入流程一行都不用改。
  #
  # ── 兩份儲存的分工（不可互換）────────────────────────────
  #   雜湊  —— 登入驗證的**唯一**依據
  #   明文  —— **只供顯示**（後台查看 / 複製）
  # 驗證路徑永遠不得讀明文：雜湊與明文不同步的資料會靜默通過驗證。
  #
  # ── 五條密碼寫入路徑，規則刻意不同 ────────────────────────
  # 全部經過同一個儲存出口，因此雜湊與明文必定同步。

  Background:
    Given 管理者 "Admin" 已登入後台且具備 manage_options 能力
    And 已存在分潤夥伴 "夥伴A" 其 term_id 為 5

  Rule: 前置（同步）- 雜湊與明文必須成套寫入且明文不得被跳脫改寫
    # @covered-by: tests/Integration/Infrastructure/Persistence/PartnerTermRepositoryTest.php::test_save_persists_plain_password_alongside_hash
    # @covered-by: tests/Integration/Infrastructure/Persistence/PartnerTermRepositoryTest.php::test_save_preserves_backslash_and_quotes_in_plain_password
    # @covered-by: tests/Integration/Infrastructure/Persistence/PartnerTermRepositoryTest.php::test_save_creates_term_with_hashed_password

    # 只寫一邊的症狀是「站長把後台看到的密碼給了夥伴、夥伴卻登不進去」，
    # 兩邊都沒有任何錯誤訊息。

    Example: 任一條路徑寫入密碼後兩份儲存皆更新
      When 管理者 "Admin" 將夥伴 "夥伴A" 的密碼設為 "New-Pass1"
      Then 夥伴 "夥伴A" 的密碼雜湊應可驗證 "New-Pass1"
      And 後台顯示的夥伴 "夥伴A" 密碼應為 "New-Pass1"

    Example: 含跳脫字元的密碼在顯示端必須與實際密碼逐字相同
      When 管理者 "Admin" 將夥伴 "夥伴A" 的密碼設為 "a1\\b'c\"d"
      Then 後台顯示的密碼應為 "a1\\b'c\"d"
      And 夥伴以 "a1\\b'c\"d" 登入應成功

    Example: 登入驗證只依據雜湊
      Given 夥伴 "夥伴A" 的明文欄位與雜湊不同步
      When 夥伴以明文欄位所顯示的密碼登入
      Then 登入應失敗

  Rule: 前置（相容）- 功能上線前建立的夥伴沒有明文可還原
    # @covered-by: tests/Integration/Infrastructure/Persistence/PartnerTermRepositoryTest.php::test_legacy_partner_without_plain_meta_reads_password_as_null
    # @covered-by: tests/Unit/Application/UseCase/Partner/ListPartnersTest.php::test_partner_without_stored_plain_password_is_null

    Example: 無明文時後台顯示無法顯示而非空白
      Given 夥伴 "夥伴A" 只有密碼雜湊而沒有明文
      When 管理者 "Admin" 檢視夥伴 "夥伴A"
      Then 畫面應顯示「無法顯示，請重設密碼」
      And 畫面不應顯示空白密碼

    Example: 無明文時列表的複製密碼操作為停用且說明原因
      Given 夥伴 "夥伴A" 只有密碼雜湊而沒有明文
      When 管理者 "Admin" 檢視夥伴列表
      Then 該列的「複製密碼」應為停用狀態
      And 滑鼠移入時應顯示停用原因

  Rule: 安全（洩漏）- 明文只能出現在管理者專用端點
    # @covered-by: tests/Integration/Application/PartnerCrudTest.php::test_partner_me_endpoint_never_exposes_plain_password
    # @covered-by: tests/Integration/Application/PartnerCrudTest.php::test_create_partner_returns_output_with_plain_password

    Example: 夥伴自己查詢身分時不得取得密碼明文
      Given 夥伴 "夥伴A" 已登入自助報表頁
      When 夥伴 "夥伴A" 查詢自己的身分資料
      Then 回應不應包含任何密碼欄位

    Example: 管理者查詢夥伴時可取得密碼明文
      When 管理者 "Admin" 查詢夥伴 "夥伴A"
      Then 回應應包含 password 欄位

  Rule: 路徑（更新）- 從編輯頁儲存時，空白代表不變更密碼
    # @covered-by: tests/Integration/Infrastructure/Persistence/PartnerTermRepositoryTest.php::test_save_without_password_keeps_existing_plain_password
    # @covered-by: tests/Unit/Application/DTO/DtoRoundTripTest.php::test_partner_input_normalizes_empty_password_to_null

    # 密碼在編輯頁是隨主表單送出的一般欄位，站長可能清空後按儲存。
    # 未正規化就會把空字串當新密碼寫進去——夥伴的密碼變成空值、從此登不進報表頁，
    # 過程中沒有任何錯誤訊息。

    Scenario Outline: 空白密碼一律視為不變更
      When 管理者 "Admin" 更新夥伴 "夥伴A" 且密碼欄位為 <password>
      Then 夥伴 "夥伴A" 的密碼應維持不變

      Examples:
        | password |
        | 空字串    |
        | 未提供    |

    Example: 編輯頁的密碼欄位不得為必填
      When 管理者 "Admin" 在編輯頁不填密碼並按儲存
      Then 表單應可正常送出

  Rule: 路徑（更新）- 只在密碼真的改動時才送出
    # @covered-by: tests/js/editFormGuards.test.ts::🔴 密碼沒改時不得送出
    # @covered-by: tests/js/editFormGuards.test.ts::密碼真的改了才送
    # @covered-by: tests/js/editFormGuards.test.ts::🔴 清空欄位視為「不變更」，連送都不送
    # @covered-by: tests/js/editFormGuards.test.ts::舊資料沒有明文可還原（initial 為 null）時，站長輸入什麼都算改動
    # @covered-by: tests/js/editFormGuards.test.ts::只差一個空白也算改動（密碼不做 trim）

    # 儲存端只要收到密碼就會更新密碼變更時間，而那會讓該夥伴所有登入 session
    # 立即失效。若每次存檔都原封不動送回同一組密碼，站長改個名稱都會把夥伴踢出報表頁。

    Example: 只改名稱時不得送出密碼
      Given 編輯頁已預填夥伴 "夥伴A" 目前的密碼
      When 管理者 "Admin" 只修改名稱後按儲存
      Then 送出的內容不應包含密碼欄位
      And 夥伴 "夥伴A" 的登入 session 應維持有效

    Example: 連續兩次儲存不得重複登出夥伴
      When 管理者 "Admin" 修改密碼並儲存
      And 管理者 "Admin" 未再修改密碼即再次儲存
      Then 第二次儲存不應再次變更密碼變更時間

  Rule: 路徑（站長指定）- 站長指定密碼須通過強度驗證且回應不回傳密碼
    # @covered-by: tests/Integration/Infrastructure/Rest/SetPartnerPasswordEndpointTest.php::test_admin_sets_password_returns_200_without_leaking_password
    # @covered-by: tests/Integration/Infrastructure/Rest/SetPartnerPasswordEndpointTest.php::test_weak_password_returns_422_with_reasons
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/SetPartnerPasswordUseCaseTest.php::test_sets_password_when_strong_enough
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/SetPartnerPasswordUseCaseTest.php::test_rejects_too_short_password

    # 與「重新產生」互補而非重複，不可為了 DRY 併掉任一個：
    #   指定 —— 站長要主動交一組自己知道的密碼給夥伴
    #   重新產生 —— 夥伴忘記密碼 / 疑似外洩，一鍵重設成系統強密碼

    Example: 指定弱密碼時被拒絕
      When 管理者 "Admin" 為夥伴 "夥伴A" 指定密碼 "12345678"
      Then 系統應回傳錯誤
        | status | code          |
        | 422    | weak_password |

    Example: 指定密碼成功時回應不含密碼
      When 管理者 "Admin" 為夥伴 "夥伴A" 指定密碼 "Strong-Pass1"
      Then 系統應回傳成功
      And 回應不應包含密碼明文

    Example: 對不存在的夥伴指定密碼時先回 404 而非強度錯誤
      # 強度驗證必須在確認夥伴存在之後，否則弱密碼的 422 會洩漏「該夥伴不存在」。
      When 管理者 "Admin" 為不存在的夥伴指定弱密碼
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 路徑（重新產生）- 系統產生 12 字元密碼並僅回傳一次
    # @covered-by: tests/Integration/Infrastructure/Rest/RegeneratePasswordTest.php::test_admin_regenerates_password_returns_new_plaintext
    # @covered-by: tests/Integration/Infrastructure/Rest/RegeneratePasswordTest.php::test_old_password_cannot_login_after_regenerate
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/RegeneratePartnerPasswordUseCaseTest.php::test_regenerate_returns_plaintext_and_persists_hash
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/RegeneratePartnerPasswordUseCaseTest.php::test_each_execution_produces_unique_password
    # @covered-by: tests/Unit/Application/UseCase/Partner/Auth/RegeneratePartnerPasswordUseCaseTest.php::test_return_value_is_plaintext_only_for_one_time_display

    Example: 重新產生密碼回傳明文供站長轉交
      When 管理者 "Admin" 為夥伴 "夥伴A" 重新產生密碼
      Then 系統應回傳一組 12 字元的新密碼
      And 夥伴 "夥伴A" 以該新密碼登入應成功

    Example: 重新產生密碼的回應必須禁止快取
      When 管理者 "Admin" 為夥伴 "夥伴A" 重新產生密碼
      Then 回應的 Cache-Control 應包含 "no-store"

    Example: 重新產生密碼需要後台 nonce
      When 管理者 "Admin" 未帶有效 nonce 呼叫重新產生密碼
      Then 系統應拒絕該請求

  Rule: 傳輸（原樣）- 夥伴端點的密碼欄位不得經過一般文字清理
    # @covered-by: tests/Integration/Infrastructure/Rest/SetPartnerPasswordEndpointTest.php::test_password_literal_is_preserved

    # 一般文字清理會把密碼裡的 `<` / `>` / 連續空白靜默改寫。
    # 症狀是「站長設的密碼夥伴登不進去」，而且兩邊看到的字串**完全一樣**，極難排查。

    Example: 含標記與連續空白的密碼必須原樣寫入
      When 管理者 "Admin" 為夥伴 "夥伴A" 指定密碼 "a1 <b>  c&d"
      Then 夥伴以 "a1 <b>  c&d" 登入應成功

  Rule: 前端（產生）- 密碼欄位旁的產生按鈕只填入不送出
    # @covered-by: tests/js/partnerFormUtils.test.ts::🔴 不發出任何網路請求（產生是純前端，按儲存才生效）
    # @covered-by: tests/js/partnerFormUtils.test.ts::排除易混淆字元 0 O 1 l I
    # @covered-by: tests/js/partnerFormUtils.test.ts::🔴 必定通過後端 PartnerPassword VO 的強度規則（跨層契約）
    # @covered-by: tests/js/partnerFormUtils.test.ts::🔴 走 crypto.getRandomValues，不是 Math.random

    Example: 點擊產生只填入欄位
      When 管理者 "Admin" 在夥伴表單點擊「產生」
      Then 密碼欄位應被填入一組 12 字元英數密碼
      And 系統不應發出任何請求

    Example: 產生的密碼排除易混淆字元
      When 管理者 "Admin" 在夥伴表單點擊「產生」
      Then 產生的密碼不應包含 "0"、"O"、"1"、"l"、"I"

    Example: 產生後必須按儲存才生效
      When 管理者 "Admin" 點擊「產生」後直接離開頁面
      Then 夥伴 "夥伴A" 的密碼應維持不變

  Rule: 已知落差（建立）- 建立夥伴時的密碼沒有後端強度驗證
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopKnownGapsTest.php::test_known_gap_create_partner_accepts_weak_password

    # ⚠️ specs/tech-debt.md TD-33：這是已知的規則不一致，刻意未修。
    #   建立頁只有「至少 8 字元」的前端規則，後端不過強度驗證；
    #   而該夥伴之後想自助改密碼時會被「需包含英文字母」擋下——
    #   他現在用的密碼反而是系統不允許他設定的。
    #   要修需連同一批既有測試 fixture 一起改。

    Example: 建立夥伴時純數字密碼會被接受
      When 管理者 "Admin" 以密碼 "12345678" 建立新夥伴
      Then 系統應回傳建立成功

    Example: 該夥伴之後自助改密碼時純數字密碼會被拒絕
      Given 夥伴 "夥伴C" 的密碼為 "12345678"
      When 夥伴 "夥伴C" 自助將密碼改為 "87654321"
      Then 系統應回傳錯誤
        | status | code          |
        | 422    | weak_password |
</content>
