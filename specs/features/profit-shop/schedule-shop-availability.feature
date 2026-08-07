@ignore @command
Feature: 設定賣場的開啟關閉時間與期間外呈現

  店舖管理者可設定賣場只在特定期間內販售，並針對「尚未開賣」與「已結束販售」
  兩種狀態各自選擇前台要顯示哪些內容。

  # ── 呈現方式是三個互相獨立的勾選框，不是三選一 ─────────────
  #   countdown —— 倒數計時盤
  #   text      —— 站長自訂文字
  #   products  —— 商品卡片（按鈕停用、不可購買）
  # 任意組合皆可；**全部不勾＝什麼都不顯示**，只剩賣場標題與站長自訂內容。
  #
  # ── 🔴 預設值必須是「只顯示商品」 ─────────────────────────
  # 既有賣場的設定裡沒有這兩個鍵，而「只顯示商品、按鈕停用」正是本功能上線前的
  # 既有行為。預設成空陣列會讓全站已排程的賣場在時間外**突然變成空白頁**。
  #
  # ── 🔴 「沒設定」與「設定為空」必須用型別區分，不可用真值判斷 ──
  # 空陣列是**合法值**（站長刻意全部取消勾選）。
  # 用真值判斷會讓站長的取消勾選被靜默還原成「顯示商品」，
  # 改了設定卻沒有反應，也沒有任何錯誤訊息。

  Background:
    Given 管理者 "Admin" 已登入後台
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932 且狀態為 "publish"
    And 賣場 "夏季賣場" 的商品列表包含商品 101

  Rule: 前置（狀態）- 販售狀態由伺服器時間與起訖時間決定
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_shop_status_is_published_without_schedule
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_shop_status_is_coming_before_start_time
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_shop_status_is_closed_after_end_time
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_shop_status_is_published_within_window

    # 一律以**伺服器時間**判斷，永不讀取任何客戶端時間——
    # 否則會出現「後台已開賣、前台仍顯示未開始」。

    Scenario Outline: 依起訖時間決定販售狀態
      Given 賣場 "夏季賣場" 的開賣時間為 <start> 且結束時間為 <end>
      When 系統解析賣場的販售狀態
      Then 狀態應為 "<status>"

      Examples:
        | start | end  | status    |
        | 未設定 | 未設定 | published |
        | 過去   | 未來  | published |
        | 過去   | 未設定 | published |
        | 未設定 | 未來  | published |
        | 未來   | 未設定 | coming    |
        | 未來   | 未來  | coming    |
        | 未設定 | 過去  | closed    |
        | 過去   | 過去  | closed    |
        | 未來   | 過去  | coming    |

    Example: 開賣的那一毫秒仍算尚未開賣
      Given 賣場 "夏季賣場" 的開賣時間恰好等於現在
      When 系統解析賣場的販售狀態
      Then 狀態應為 "coming"

  Rule: 前置（正規化）- 壞掉的時間資料視為未設定而不誤關店
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_invalid_timestamps_are_treated_as_unset
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_timestamp_strings_are_cast_to_int

    # ⚠️ specs/tech-debt.md TD-63：這是 fail-open，而且**這個判斷同時是安全閘門**。
    #   裁決維持現狀：誤關店的商業損失立即且明顯，而要寫壞這份設定需要管理權限
    #   （已在信任邊界內）。要收緊的正解是在**寫入端**驗證，
    #   **不是**在讀取端改成 fail-closed——後者會讓一個打錯字的結束時間直接關掉整個賣場。
    #
    # ⚠️ 這個判斷現在有四個消費端，其中三個是安全閘門：
    #   加購歸屬的第三道閘門、定價階段的重驗、商品單頁的 filter 註冊，
    #   以及救援品項的授權回查。改動正規化語意前必須同時評估這四處。

    Scenario Outline: 非數值的時間資料視為未設定
      Given 賣場 "夏季賣場" 的開賣時間為 <start> 且結束時間為 <end>
      When 系統解析賣場的販售狀態
      Then 狀態應為 "<status>"

      Examples:
        | start | end     | status    | 說明                    |
        | 未來   | 非數值   | published | 結束時間壞掉 ⇒ 永遠開著   |
        | 非數值 | 未來     | published | 開賣時間壞掉 ⇒ 視為未設定 |
        | 負數   | 未設定   | published | 負數視為未設定           |
        | 布林值 | 未設定   | published | 非數值視為未設定         |

  Rule: 前置（呈現）- 呈現區塊為白名單過濾的多選陣列
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_display_parts_default_to_products_only
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_display_parts_are_independent
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_empty_display_parts_means_show_nothing
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_unknown_display_parts_are_filtered
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_legacy_string_display_mode_is_migrated

    Example: 未設定呈現區塊時退回只顯示商品
      Given 賣場 "夏季賣場" 的設定中沒有呈現區塊的鍵
      And 賣場 "夏季賣場" 已結束販售
      When 訪客開啟賣場公開網址
      Then 商品卡片應照常印出但為停用狀態
      And 頁面不應出現倒數計時或自訂文字

    Example: 空陣列代表什麼都不顯示
      Given 賣場 "夏季賣場" 已結束販售且呈現區塊設為空
      When 訪客開啟賣場公開網址
      Then 頁面不應出現商品卡片
      And 頁面不應出現倒數計時或自訂文字
      And 頁面不應出現「此賣場暫無商品」的提示

    Example: 未知的區塊值一律被過濾掉
      Given 賣場 "夏季賣場" 的呈現區塊包含一個未知的值
      When 系統正規化該設定
      Then 未知的值不應出現在結果中

    Example: 區塊的輸出順序固定不受送出順序影響
      When 管理者以任意順序送出三個區塊
      Then 儲存結果的順序應固定

  Rule: 後置（呈現）- 呈現結論由後端算好，兩條渲染路徑只讀結論
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_published_state_always_shows_products_only
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_text_part_without_content_is_not_shown
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_closed_shop_without_display_setting_keeps_browse_behaviour
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_closed_shop_with_no_parts_renders_nothing

    # 販售狀態有三態、呈現設定有兩組，若讓樣板自行配對，
    # 獨立頁面與短碼兩條路徑就各有一份配對邏輯要維持一致——
    # 這個系統已經因為「靠人維持兩份邏輯逐字相同」吃過虧。

    Scenario Outline: 依販售狀態決定套用哪一組呈現設定
      Given 賣場 "夏季賣場" 的販售狀態為 "<status>"
      When 系統解析呈現結論
      Then 應套用 <applied> 的設定

      Examples:
        | status    | applied      |
        | coming    | 尚未開賣      |
        | closed    | 已結束販售    |
        | published | 只顯示商品    |

    Example: 販售中時不受期間外呈現設定影響
      Given 賣場 "夏季賣場" 正在販售中且已設定期間外顯示倒數
      When 訪客開啟賣場公開網址
      Then 頁面不應出現倒數計時
      And 商品應可正常購買

    Example: 勾了自訂文字但文字為空時不印出空的區塊
      Given 賣場 "夏季賣場" 已結束販售且勾選自訂文字但文字為空
      When 訪客開啟賣場公開網址
      Then 頁面不應出現空的文字區塊

  Rule: 安全（Modal）- 任何呈現組合都不得印出商品 Modal
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_no_display_combination_ever_renders_modal_when_closed
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_closed_shop_never_renders_modal_for_rescued_variations

    # 🔴 Modal 內含一張真正的加購表單，即使按鈕停用，表單本身仍在 DOM 內，
    #   使用者改掉停用屬性就能送出。不印出來才是唯一可靠的做法。
    #   （後端的販售期間閘門是第二道防線，兩道都要有。）

    Scenario Outline: 非販售期間的所有呈現組合皆不印 Modal
      Given 賣場 "夏季賣場" 已結束販售且呈現區塊為 <parts>
      When 訪客開啟賣場公開網址
      Then 頁面中不應存在任何商品 Modal
      And 頁面中不應存在可送出的加購表單

      Examples:
        | parts                      |
        | 空                         |
        | products                   |
        | countdown                  |
        | countdown, text            |
        | countdown, text, products  |

  Rule: 後置（倒數）- 倒數盤的目標時間依狀態決定
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_coming_shop_with_countdown_renders_countdown_block
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_countdown_and_products_can_coexist
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_closed_state_uses_after_settings_with_zero_countdown

    Example: 尚未開賣時倒數到開賣時間
      Given 賣場 "夏季賣場" 尚未開賣且勾選顯示倒數
      When 訪客開啟賣場公開網址
      Then 倒數盤的目標時間應為開賣時間

    Example: 首屏數字由伺服器時間印出
      Given 賣場 "夏季賣場" 尚未開賣且勾選顯示倒數
      When 訪客在停用 JavaScript 的環境開啟賣場公開網址
      Then 倒數盤應顯示一個靜止但正確的數字快照

    Example: 倒數歸零後重新載入頁面
      Given 倒數盤的剩餘時間大於 0
      When 倒數歸零
      Then 頁面應重新載入

    Example: 初始剩餘時間已為 0 時不排程重新載入
      # 否則會變成「載入 → 立刻歸零 → 重新載入」的迴圈。
      Given 倒數盤的初始剩餘時間為 0
      Then 系統不應排程重新載入

    Example: 天數超過 99 時改以純文字呈現
      Given 距離開賣還有 120 天
      When 訪客開啟賣場公開網址
      Then 天數欄位應以純文字顯示 120

  Rule: 已知落差（倒數）- 已結束販售時的倒數盤一律歸零
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_closed_shop_with_countdown_renders_zeroed_board

    # ⚠️ specs/tech-debt.md TD-45：已結束的賣場沒有「未來的目標時間」可以倒數，
    #   真正傳達訊息的是搭配的自訂文字。
    #   若站長想要的是「下一檔期開賣倒數」，需要新增獨立的欄位——
    #   **不可**改成讓後端猜測（例如自動把開賣時間當下一檔期），
    #   猜錯的成本是顧客看到一個永遠不會到來的時間。

    Example: 已結束販售且勾選倒數時盤面全為零
      Given 賣場 "夏季賣場" 已結束販售且勾選顯示倒數
      When 訪客開啟賣場公開網址
      Then 倒數盤的四個欄位應皆為 0

  Rule: 前置（文字）- 自訂文字有長度上限且以字元計
    # @covered-by: tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php::test_display_text_is_normalised

    Example: 超過上限的文字被截斷
      When 管理者輸入超過 200 字元的自訂文字
      Then 儲存的文字應被截斷至 200 字元

    Example: 截斷以字元為單位不得切出半個中文字
      When 管理者輸入超過上限的中文自訂文字
      Then 截斷處不應出現半個字元

    Example: 非字串的自訂文字正規化為空字串
      Given 自訂文字欄位的值不是字串
      When 系統正規化該設定
      Then 結果應為空字串

  Rule: 前端（後台）- 排程分頁的送出必須合併既有設定
    # @covered-by: NOT-COVERED ScheduleTab 的 mergeScheduleSettings，屬 React 元件行為（B9 待補）

    Example: 只修改排程時其餘設定必須保留
      Given 賣場 "夏季賣場" 的設定已包含庫存與購買人數顯示開關
      When 管理者只修改開賣時間後儲存
      Then 庫存與購買人數顯示開關應維持不變

    Example: 清空時間時寫入 0 而非移除該鍵
      # 移除鍵不會覆蓋既有的舊值，症狀是「時間清掉了，存檔後又跑回來」。
      Given 賣場 "夏季賣場" 已設定開賣時間
      When 管理者清空開賣時間後儲存
      And 管理者重新載入編輯頁
      Then 開賣時間欄位應為空

    Example: 未設定的時間欄位不得顯示為 1970 年
      Given 賣場 "夏季賣場" 沒有設定開賣時間
      When 管理者開啟排程分頁
      Then 開賣時間欄位應為空白

    Example: 結束時間早於開賣時間時前端阻擋送出
      When 管理者輸入的結束時間早於開賣時間
      Then 表單應顯示驗證錯誤且不送出
      And 畫面應切換到排程分頁

    Example: 自訂文字使用單行輸入而非多行
      # 該欄位經過後端的一般文字清理，換行會被吃掉。
      # 用多行輸入會讓站長打的換行**靜默消失**，畫面上看不出任何異常。
      When 管理者開啟排程分頁
      Then 自訂文字欄位應為單行輸入

    Example: 時間欄位必須說明時區依據
      # ⚠️ specs/tech-debt.md TD-44：後台以瀏覽器時區呈現，判斷以伺服器時間為準。
      When 管理者開啟排程分頁
      Then 畫面應說明時間以瀏覽器時區顯示而判斷以伺服器時間為準

  Rule: 已知落差（存取）- 全部不勾只藏內容，公開網址仍然開得起來
    # @covered-by: NOT-COVERED 本 Rule 描述的正是 TD-46「全部不勾只藏內容、公開網址仍開得起來」這個已知落差，依定義沒有對應實作可測

    # ⚠️ specs/tech-debt.md TD-46：呈現設定不等於存取控制。
    #   改成 404 會讓獨立頁面與短碼兩條路徑的輸出分歧，
    #   直接打破「兩條路徑渲染逐字相同 DOM」這條核心回歸鎖。
    #   真要做「時間外整頁不可存取」，應該是渲染層獨立的一條規則。

    Example: 全部不勾時公開網址仍回 200
      Given 賣場 "夏季賣場" 已結束販售且呈現區塊為空
      When 訪客開啟賣場公開網址
      Then 系統應回應 200
      And 頁面應只有賣場標題與站長自訂內容

  Rule: 已知落差（驗證）- 排程欄位沒有後端驗證
    # @covered-by: NOT-COVERED 本 Rule 描述的正是 TD-47「排程欄位無後端 Domain 驗證」這個已知落差，依定義沒有對應實作可測

    # ⚠️ specs/tech-debt.md TD-47：繞過前端直接呼叫 API 可送出任意組合。
    #   後果有界：時間顛倒只會讓賣場永不開賣，不會產生安全問題。

    Example: 直接以 API 送出顛倒的起訖時間會被接受
      When 直接以 API 將結束時間設為早於開賣時間
      Then 系統應回傳成功
      And 該賣場的販售狀態應為永不開賣
</content>
