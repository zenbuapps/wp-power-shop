@ignore @query
Feature: 以短碼在任意頁面嵌入分潤賣場

  站長可用短碼把賣場的商品區塊嵌入任意頁面內容。短碼路徑與獨立頁面路徑
  共用同一份樣板，兩者渲染出**逐字相同的 DOM**。

  # ── 🔴 兩條路徑必須傳入同一組變數 ─────────────────────────
  # 這不是風格一致性問題，是真實發生過的商業邏輯漏洞：
  # 早期只有獨立頁面路徑會傳入前台設定，短碼路徑拿到空設定 ⇒ 販售狀態一律
  # fallback 成「販售中」⇒ **已結束販售的賣場用短碼嵌入後照樣能加購**，
  # 而同一個賣場的獨立頁面網址卻正確顯示已關閉。
  # 兩條路徑「都正常運作、沒有任何錯誤訊息」，只有實際比對兩者輸出才會發現分歧。
  #
  # ── 短碼以賣場 id 定位而非永久連結 ────────────────────────
  # id 是不變的；永久連結可被站長隨時改動，而**已經貼進頁面內容的短碼是純文字、
  # 不會隨之更新**。同理，短碼的標籤名一旦改名，所有已發佈頁面裡的舊短碼會
  # 安靜地渲染不出東西——WordPress 對未註冊的短碼是原樣輸出純文字、不報錯。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932 且狀態為 "publish"
    And 賣場 "夏季賣場" 的商品列表包含商品 101
    And 站台存在一個頁面 "活動說明"

  Rule: 後置（一致）- 短碼與獨立頁面渲染逐字相同的 DOM
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_renderer_and_shortcode_paths_render_identical_dom
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_output_uses_div_wrapper_and_never_main

    Example: 兩條路徑的商品區塊輸出完全相同
      When 系統以獨立頁面路徑渲染賣場 1932
      And 系統以短碼路徑渲染賣場 1932
      Then 兩者的輸出應逐字相同

    Example: 兩條路徑的外層容器一律為 div
      # 一份文件內只能有一個非隱藏的 main；短碼情境的宿主頁面已經有自己的 main，
      # 而獨立頁面走佈景主題的頁首時**同樣可能已經有 main**，
      # 統一用 div 才是對兩條路徑都安全的做法。
      When 系統以任一路徑渲染賣場 1932
      Then 賣場區塊的外層標籤應為 div

    Example: 短碼路徑同樣載入前台互動資源
      When 頁面 "活動說明" 內含賣場 1932 的短碼
      Then 頁面應載入賣場前台的互動資源
      And 商品 Modal 應可正常開啟

  Rule: 安全（設定）- 短碼路徑必須套用與獨立頁面相同的販售期間判斷
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_shortcode_respects_closed_shop_status
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_no_display_combination_ever_renders_modal_when_closed

    Example: 已結束販售的賣場以短碼嵌入時不得可加購
      Given 賣場 "夏季賣場" 已結束販售
      When 頁面 "活動說明" 內含賣場 1932 的短碼
      Then 輸出中不應存在任何可送出的加購表單
      And 輸出中不應存在商品 Modal

    Example: 已結束販售時不印成功通知的容器
      Given 賣場 "夏季賣場" 已結束販售
      When 頁面 "活動說明" 內含賣場 1932 的短碼
      Then 輸出中不應存在加購成功通知的容器

  Rule: 前置（參數）- 短碼的賣場識別必須是純數字且不接受前導零
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_non_numeric_id_returns_empty_string
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_negative_or_zero_id_returns_empty_string
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_numeric_looking_but_invalid_ids_are_rejected
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_missing_atts_does_not_error

    Scenario Outline: 非法的短碼識別一律不渲染
      When 頁面內含賣場識別為 "<id>" 的短碼
      Then 短碼不應渲染出任何商品

      Examples:
        | id   |
        |      |
        | abc  |
        | 007  |
        | -1   |
        | 1.5  |

  Rule: 安全（可見性）- 短碼的可見性判斷與獨立頁面逐字一致
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_draft_shop_returns_empty_string_for_visitor
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_draft_shop_renders_for_privileged_user
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_trashed_shop_returns_empty_string

    Scenario Outline: 依賣場狀態與檢視者權限決定是否渲染
      Given 賣場 "夏季賣場" 的狀態為 "<status>"
      When <who> 瀏覽內含該短碼的頁面
      Then 短碼應 <result>

      Examples:
        | status  | who                      | result  |
        | publish | 未登入的訪客               | 渲染商品 |
        | draft   | 未登入的訪客               | 不渲染   |
        | draft   | 對該賣場有編輯權限的使用者   | 渲染商品 |
        | trash   | 對該賣場有編輯權限的使用者   | 不渲染   |

  Rule: 安全（訊息）- 失敗訊息不得洩漏賣場是否存在
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_draft_and_missing_shop_give_indistinguishable_hints
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_debug_hint_is_only_visible_to_editors
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_nonexistent_id_returns_empty_string_for_visitor

    # 只有具備編輯文章能力（例如作者）的使用者才看得到除錯提示；
    # 而「找不到 / 已刪除」與「未發佈且無預覽權限」**共用同一句訊息**——
    # 否則作者能靠訊息差異推斷出草稿賣場的存在。

    Example: 一般訪客一律看到空白
      Given 賣場 1932 不存在
      When 未登入的訪客瀏覽內含該短碼的頁面
      Then 短碼應輸出空字串

    Scenario Outline: 具備編輯能力者看到的兩種失敗訊息相同
      Given <condition>
      When 具備編輯文章能力的使用者瀏覽內含該短碼的頁面
      Then 短碼應輸出除錯提示
      And 兩種情境的提示文字應完全相同

      Examples:
        | condition                      |
        | 賣場 1932 不存在                |
        | 賣場 1932 為草稿且該使用者無預覽權限 |

  Rule: 後置（隔離）- 短碼渲染失敗不得影響宿主頁面
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_missing_atts_does_not_error

    Example: 渲染過程拋出例外時回傳空字串
      Given 賣場渲染過程將拋出例外
      When 頁面 "活動說明" 內含該短碼
      Then 短碼應輸出空字串
      And 宿主頁面的其餘內容應正常顯示

    Example: 短碼一律回傳字串而非直接輸出
      # 短碼若直接輸出，內容會跑到頁面最前面而非它該在的位置。
      When 系統渲染短碼
      Then 系統不應在短碼位置之外產生任何輸出

  Rule: 後置（多重）- 同一頁可放多個不同賣場的短碼
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_do_shortcode_renders_shop_content

    Example: 一頁兩個賣場的短碼各自獨立運作
      Given 另有分潤賣場 "秋季賣場" 其 id 為 2000
      When 頁面 "活動說明" 同時內含賣場 1932 與 2000 的短碼
      Then 兩個賣場區塊應各自渲染自己的商品
      And 點擊任一賣場的卡片應開啟該賣場的商品 Modal

  Rule: 後置（SEO）- 短碼路徑不注入標題與正規網址
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_output_contains_no_seo_meta

    # 宿主頁面自己有，注入會造成重複的標題。

    Example: 短碼不注入賣場的標題與正規網址
      When 頁面 "活動說明" 內含賣場 1932 的短碼
      Then 頁面標題應維持宿主頁面自己的標題
      And 頁面不應出現第二個 canonical 連結

  Rule: 前端（後台）- 短碼模式的賣場在列表提供複製短碼
    # @covered-by: tests/js/shopListActions.test.ts::正常賣場可複製
    # @covered-by: tests/js/shopListActions.test.ts::🔴 沒有永久連結時**仍可**複製——這是與「開啟」的唯一差異
    # @covered-by: tests/js/shopListActions.test.ts::短碼以 id 組成，與 slug 完全無關
    # @covered-by: tests/js/shopListActions.test.ts::已刪除的賣場停用（短碼貼上後什麼都不顯示，且無錯誤提示）

    Example: 複製短碼可用於未設定永久連結的賣場
      Given 賣場 "夏季賣場" 的模式為 shortcode 且未設定永久連結
      When 管理者檢視賣場列表
      Then 「複製」應為可用狀態

  Rule: 已知落差（存取）- 短碼模式的賣場公開網址仍然開得起來
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_known_gap_shortcode_mode_shop_still_renders_via_public_url

    # ⚠️ specs/tech-debt.md TD-13（部分解決）：
    #   短碼本身已實作可用，但渲染流程**完全不檢查賣場模式**，
    #   模式為短碼的賣場其公開網址行為與獨立頁面 100% 相同、仍會被搜尋引擎索引。
    #   刻意不一起做：那會讓既有已上線的短碼賣場前台網址突然變 404。
    #
    # 🔴 因此編輯頁那句「短碼模式仍會保留公開網址」的據實告知警語**依然成立、
    #   不可移除**——不要因為列表頁現在有「複製」按鈕、看起來功能完整了就把它清掉。
    #   UI 入口有無，與網址本身是否存在，是兩件不能混為一談的事。

    Example: 短碼模式的賣場公開網址仍可開啟
      Given 賣場 "夏季賣場" 的模式為 shortcode 且狀態為 publish
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 系統應回應賣場頁面而非 404

    Example: 編輯頁必須據實告知公開網址仍然存在
      Given 管理者將賣場模式選為 shortcode
      When 管理者檢視賣場編輯頁的基本設定
      Then 畫面應告知公開網址仍會保留
</content>
