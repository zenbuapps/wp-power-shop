@ignore @query
Feature: 呈現分潤賣場前台

  顧客造訪 `/profit-shop/{slug}/` 時看到賣場的商品列表。頁面由 PHP 直接印出，
  商品名稱、價格與圖片留在 HTML 內以確保 SEO，互動層只補上「開啟商品 Modal」
  這類伺服器渲染做不到的行為。

  # ── 職責分界的三條紀律 ────────────────────────────────────
  # 1. 商品的名稱／價格／圖片必須留在 PHP 印出的 HTML 內——那是 SEO 的全部價值
  # 2. data-ps-* 屬性群是跨語言 DOM 契約，改動會讓互動靜默失效且無錯誤訊息
  # 3. 加購一律經過 WooCommerce 的購物車資料 filter 鏈，不得自建繞道
  #
  # ── 漸進增強 ─────────────────────────────────────────────
  # 每張卡片只印**一個**動作元素，本身無 JS 即可完成加購；
  # JS 載入後才攔截它改開 Modal。因此不需要任何 <noscript> 樣式或第二顆按鈕。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其 slug 為 "summer-sale" 且 id 為 1932
    And 系統中已存在以下商品
      | id  | name       | type     | status  |
      | 101 | MacBook    | simple   | publish |
      | 103 | iPad Air   | variable | publish |
      | 104 | 已下架商品  | simple   | draft   |
    And 賣場 "夏季賣場" 的商品列表包含商品 101、103 與 104

  Rule: 前置（存取）- 依賣場狀態決定可見性
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_draft_shop_returns_empty_string_for_visitor
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_draft_shop_renders_for_privileged_user
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_trashed_shop_returns_empty_string

    Scenario Outline: 各種狀態下的存取結果
      Given 賣場 "夏季賣場" 的狀態為 "<status>"
      When <who> 開啟 "/profit-shop/summer-sale/"
      Then 系統應回應 <result>

      Examples:
        | status  | who                        | result   |
        | publish | 未登入的訪客                 | 賣場頁面  |
        | draft   | 未登入的訪客                 | 404      |
        | draft   | 對該賣場有編輯權限的使用者     | 賣場頁面  |
        | draft   | 只有一般作者權限的使用者       | 404      |
        | trash   | 對該賣場有編輯權限的使用者     | 404      |

    Example: 不存在的永久連結回 404
      When 訪客開啟 "/profit-shop/never-existed/"
      Then 系統應回應 404

    Example: 永久連結清理後為空時回 404
      When 訪客開啟一個清理後為空字串的賣場網址
      Then 系統應回應 404

  Rule: 前置（速率）- 賣場頁對同一來源有存取頻率上限
    # @covered-by: tests/Unit/Application/Service/PageRateLimitServiceTest.php::test_requests_under_the_limit_are_allowed
    # @covered-by: tests/Unit/Application/Service/PageRateLimitServiceTest.php::test_request_over_the_limit_throws
    # @covered-by: tests/Unit/Application/Service/PageRateLimitServiceTest.php::test_retry_after_reflects_remaining_window
    # @covered-by: tests/Unit/Application/Service/PageRateLimitServiceTest.php::test_window_is_not_extended_by_continued_requests
    # @covered-by: tests/Unit/Application/Service/PageRateLimitServiceTest.php::test_different_page_keys_are_counted_independently

    Example: 同一 IP 在一分鐘內超過 30 次請求時回 429
      When 同一來源 IP 在 60 秒內對賣場頁發出第 31 次請求
      Then 系統應回應 429

    Example: 速率檢查發生在查詢賣場之前
      When 同一來源 IP 觸發速率上限
      Then 系統不應查詢賣場資料

  Rule: 後置（SEO）- 賣場頁必須注入自己的標題與正規網址且不重複
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_output_contains_no_seo_meta

    Example: 賣場頁的標題包含賣場名稱與夥伴名稱
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 頁面標題應包含賣場名稱與夥伴名稱

    Example: 頁面只有一組標題與一組正規網址
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 頁面中的 title 標籤應只有 1 個
      And 頁面中的 canonical 連結應只有 1 個
      And canonical 應指向 "/profit-shop/summer-sale/"

  Rule: 後置（內容）- 商品資訊必須直接印在 HTML 內
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_publish_shop_renders_product_name
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_price_override_is_rendered
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_priceless_variations_with_override_render_prices_on_both_paths

    Example: 商品名稱與價格出現在初始 HTML
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 初始 HTML 應包含商品 101 的名稱
      And 初始 HTML 應包含商品 101 在賣場的生效價格

    Example: 非發佈狀態的商品不出現在賣場
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 頁面不應包含商品 104

    Example: 站長在賣場內容編輯的文案會被渲染
      Given 賣場 "夏季賣場" 的內容包含一段文案
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 頁面應呈現該段文案

  Rule: 後置（卡片）- 每張卡片只印一個動作元素以支援無 JS 環境
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_open_shop_renders_ajax_dom_contract
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_variable_product_without_any_purchasable_variation_says_so

    Scenario Outline: 依商品類型決定卡片的動作元素
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 商品 <product_id> 的卡片動作應為 <element>

      Examples:
        | product_id | element                       | 說明                        |
        | 101        | 直接送出的加購表單              | simple 商品                 |
        | 103        | 連往商品單頁且帶賣場識別的連結    | variable 商品               |

    Example: 停用 JS 時卡片本身即是完整的加購路徑
      Given 瀏覽器停用 JavaScript
      When 訪客在賣場頁點擊商品 101 的卡片動作
      Then 商品 101 應被加入購物車且歸屬於賣場 1932

    Example: 卡片顯示賣場覆寫後的原價與特價
      Given 賣場 "夏季賣場" 對商品 101 設定覆寫原價 "500" 與特價 "450"
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 商品 101 的卡片應顯示劃線原價 "500" 與特價 "450"

    Example: 可變商品的卡片顯示價格區間
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 商品 103 的卡片應顯示其變體價格的區間

    Example: 沒有任何可購買變體的可變商品卡片停用且不印 Modal
      Given 商品 103 沒有任何可購買的變體
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 商品 103 的卡片應標記為停用並顯示「暫無可購買規格」
      And 頁面中不應存在商品 103 的商品 Modal

  Rule: 後置（已售）- 已售件數為真實銷量加上灌水購買人數
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_sold_count_is_real_sales_plus_inflated_count
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_sold_count_block_is_omitted_when_zero

    Example: 顯示真實銷量加灌水人數的合計
      Given 商品 101 的真實銷量為 873 且賣場設定的灌水購買人數為 7
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 商品 101 的卡片應顯示已售 880 件

    Example: 合計為 0 時不顯示已售件數
      Given 商品 101 的真實銷量為 0 且賣場設定的灌水購買人數為 0
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 商品 101 的卡片不應顯示已售件數

    Example: 關閉購買人數顯示時不印已售件數
      Given 賣場 "夏季賣場" 的設定關閉了購買人數顯示
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 卡片不應顯示任何已售件數

  Rule: 後置（Modal）- 每個可購買商品各有一個獨立的商品 Modal
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_open_shop_renders_ajax_dom_contract
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_no_display_combination_ever_renders_modal_when_closed
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_closed_shop_never_renders_modal_for_rescued_variations

    Example: 點擊卡片開啟對應的商品 Modal
      When 訪客點擊商品 101 的卡片
      Then 商品 101 的 Modal 應開啟

    Example: Modal 內呈現完整商品資訊
      When 訪客開啟商品 101 的 Modal
      Then Modal 應包含商品圖片、名稱、價格、庫存狀態與數量選擇器
      And Modal 應包含一張帶賣場識別的加購表單

    Example: 關閉庫存顯示時 Modal 不印庫存文案
      Given 賣場 "夏季賣場" 的設定關閉了庫存顯示
      When 訪客開啟商品 101 的 Modal
      Then Modal 不應顯示庫存文案

    Example: 數量選擇器夾在 1 與可購買上限之間
      When 訪客在商品 Modal 連續點擊減少數量
      Then 數量不應低於 1

    Example: 不支援對話框元素時退回卡片的原生動作
      Given 瀏覽器不支援原生對話框元素
      When 訪客點擊商品 101 的卡片
      Then 卡片的原生動作應照常執行

  Rule: 後置（多賣場）- 同一頁可存在多個賣場且互不干擾
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_renderer_and_shortcode_paths_render_identical_dom

    # 資料印在各自的賣場容器內，查找一律限定在最近的賣場容器祖先。

    Example: 一頁兩個賣場時各自的 Modal 互不錯開
      Given 一個頁面同時嵌入賣場 1932 與賣場 2000
      When 訪客點擊賣場 2000 內的商品卡片
      Then 開啟的應是賣場 2000 的商品 Modal
      And 該 Modal 的加購表單應帶賣場 2000 的識別

  Rule: 後置（提示）- 賣場頁必須自行輸出加購結果訊息
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_shop_page_prints_woocommerce_notices_itself

    # 賣場頁不是 WooCommerce 的樣板頁，佈景主題掛在 WooCommerce 內容區的訊息輸出
    # 不會被觸發。少了它，使用者按下加購後畫面除了重新載入之外沒有任何變化。

    Example: 原生表單加購後頁面呈現 WooCommerce 訊息區塊
      When 訪客以原生表單完成一次加購
      Then 賣場頁應包含 WooCommerce 的訊息輸出區塊

  Rule: 後置（提示）- 原生表單加購成功後必須顯示成功提示
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_template_prints_notices_captured_by_renderer
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_template_prints_no_container_when_captured_notices_are_empty
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_shortcode_path_falls_back_to_wc_print_notices

    # ⚠️ 本 Rule 原本是「已知落差（提示）- 原生表單加購成功後沒有成功提示」（TD-49）。
    #
    #    根因不是「session 裡沒有那則訊息」——真實站台實測（Blocksy + Elementor）在
    #    renderer 前後各夾一個探針，結果是：
    #        get_header() 之前 → 通知 1 則
    #        template 內      → 通知 0 則
    #    佈景主題 / 頁面建構器的頁首在 `get_header()` 期間就呼叫過 `wc_print_notices()`，
    #    而該函式印完會**清空** session ⇒ 等執行到 template 時已經沒有東西可印，
    #    而那次的輸出又落在不會顯示的地方 ⇒ 通知就這樣消失了。
    #
    #    2026-08-11 起 renderer 改在 `get_header()` **之前**就把通知渲染成字串收下來
    #    （先搶先贏），再交給 template 印，落差消失。
    #
    #    ⚠️ shortcode 路徑不適用（宿主頁面的 get_header 早就跑完），
    #      template 對「沒收到預收通知」的情況保留原本的 `wc_print_notices()`。

    Example: 原生表單加購成功後顯示成功提示
      When 訪客以原生表單完成一次加購
      Then 商品應已加入購物車
      And 頁面應顯示加入購物車成功的訊息

    Example: 沒有任何通知時不顯示空的訊息容器
      When 訪客直接開啟賣場頁而未進行加購
      Then 頁面不應出現訊息容器

  Rule: 已知落差（版型）- 以頁面建構器排版的賣場內容不會被渲染
    # @covered-by: NOT-COVERED 本 Rule 描述的正是 TD-28「Elementor 版型不生效」這個已知落差，依定義沒有對應實作可測

    # ⚠️ specs/tech-debt.md TD-28：賣場頁走自訂渲染流程，
    #   頁面建構器的渲染流程不在這條路徑上。純區塊編輯器 / HTML / 短碼內容不受影響。

    Example: 以頁面建構器排版的內容顯示為未渲染的標記
      Given 賣場 "夏季賣場" 的內容以頁面建構器排版
      When 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 內容區會顯示未渲染的原始標記
</content>
