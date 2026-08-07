@ignore @command
Feature: 加購時將購物車品項歸屬到分潤賣場

  顧客從分潤賣場加購商品時，系統必須確認這筆加購「真的來自一個現在開著、
  而且確實包含這個商品的賣場」，才把 profit_shop_id 寫進 cart_item_data。
  歸屬成立與否直接決定：顧客付多少錢、以及夥伴拿不拿得到分潤。

  # ── 四道閘門（依執行順序）──────────────────────────────────
  # ① 賣場存在
  # ② 賣場 status = publish
  # ③ 賣場在販售期間內（Phase 16 的 startTime / endTime）
  # ④ 商品確實在該賣場的 items 內
  # 任一道不成立 → 不注入 profit_shop_id（購物車仍正常加入，走商品原價、無分潤）。
  #
  # 這四道閘門在兩個地方各跑一次（雙層）：
  #   - AddToCartHook（priority 5）：從 $_GET / $_POST 取 profit_shop_id
  #   - CartPriceOverrideHook::add_cart_item_data（priority 10）：重驗同樣四道
  # 雙層的理由：AddToCartHook 開頭有 short-circuit（上游已帶 profit_shop_id 就
  # 整段跳過），而 add_to_cart() 的第 5 參數 $cart_item_data 正是第三方外掛
  # （訂閱續訂 / 贈品 / bundle）注入資料的標準管道。
  #
  # ⚠️ 失敗一律「靜默降級」而非報錯：顧客的加購動作仍然成功，只是變成一般購買。
  #    這是刻意的——擋下加購對顧客毫無意義（他只是點了一個按鈕），
  #    真正該被擋下的是「不該生效的分潤價」。
  #
  # ⚠️ 已在購物車內的品項**不**受販售期間閘門追溯影響（閘門 ③ 只在加購當下生效）。
  #    活動結束的那一秒靜默清空所有顧客的購物車，比放行更糟。
  #    唯一例外是「只靠賣場覆寫價才有價格」的品項，見
  #    authorize-priceless-cart-item.feature 的「授權必須可撤銷」Rule。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 系統中已存在以下商品
      | id  | name        | type   | status  | regular_price |
      | 101 | MacBook Pro | simple | publish | 59900         |
      | 102 | iPhone 15   | simple | publish | 29900         |
      | 103 | iPad Air    | simple | publish | 19900         |
    And 已存在分潤賣場 "夏季賣場" 其設定為
      | id   | status  | partner_term_id | startTime | endTime |
      | 1932 | publish | 5               | 0         | 0       |
    And 賣場 "夏季賣場" 的商品列表為
      | product_id | regular_price | sale_price |
      | 101        | 50000         | 45000      |
      | 102        |               |            |

  Rule: 前置（來源）- profit_shop_id 只從 $_GET 與 $_POST 讀取
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_with_profit_shop_id_writes_override_meta_to_cart_item

    # 刻意排除 $_COOKIE：避免攻擊者透過 XSS 在受害者瀏覽器植入 profit_shop_id
    # 進行分潤歸因竄改。雖然閘門 ④ 仍會擋下未授權商品，收斂攻擊面本身有價值。

    Example: query string 帶 profit_shop_id 時歸屬成立
      When 顧客以 GET 加購商品 101 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 該購物車品項應歸屬於賣場 1932

    Example: 表單 POST 帶 profit_shop_id 時歸屬成立
      When 顧客以 POST 加購商品 101 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 該購物車品項應歸屬於賣場 1932

    Example: 只有 Cookie 帶 profit_shop_id 時不成立歸屬
      Given 顧客瀏覽器的 Cookie 中帶有 profit_shop_id 為 1932
      When 顧客加購商品 101 且請求參數不含 profit_shop_id
      Then 該購物車品項不應歸屬於任何賣場
      And 該購物車品項應以商品原價計價

  Rule: 前置（格式）- profit_shop_id 必須是正整數字串
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_with_nonexistent_profit_shop_id_falls_back_to_original_price

    Scenario Outline: 非正整數的 profit_shop_id 一律視同未提供
      When 顧客加購商品 101 並帶入參數
        | profit_shop_id |
        | <raw>          |
      Then 該購物車品項不應歸屬於任何賣場

      Examples:
        | raw     |
        |         |
        | abc     |
        | 19.32   |
        | -1932   |
        | +1932   |
        | 0       |

    Example: profit_shop_id 為陣列時視同未提供
      When 顧客加購商品 101 且 profit_shop_id 以陣列形式送出
      Then 該購物車品項不應歸屬於任何賣場

  Rule: 前置（短路）- 上游已帶入 profit_shop_id 時不重覆注入
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_closed_shop_rejects_upstream_supplied_profit_shop_id

    # AddToCartHook 尊重上游（第三方外掛以 add_to_cart() 第 5 參數注入）的值。
    # 這代表**四道閘門在這條路徑上被整段跳過**，因此
    # CartPriceOverrideHook::add_cart_item_data 必須自行重驗全部四道（見下方 Rule）。

    Example: 上游已帶入時不再讀取請求參數
      Given 上游已在 cart_item_data 帶入 profit_shop_id 為 1932
      When 顧客加購商品 101 且請求參數帶入 profit_shop_id 為 9999
      Then 該購物車品項應歸屬於賣場 1932

  Rule: 閘門①（存在）- 賣場必須存在
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_with_nonexistent_profit_shop_id_falls_back_to_original_price

    Example: 偽造不存在的賣場 ID 時不成立歸屬
      When 顧客加購商品 101 並帶入參數
        | profit_shop_id |
        | 999999         |
      Then 該購物車品項不應歸屬於任何賣場
      And 該購物車品項應以商品原價計價

    Example: 查詢賣場拋出例外時視同不存在
      Given 查詢賣場 1932 將拋出例外
      When 顧客加購商品 101 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 該購物車品項不應歸屬於任何賣場
      And 系統應寫入一筆錯誤紀錄且訊息中不含換行字元

  Rule: 閘門②（狀態）- 賣場必須為 publish
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_with_draft_profit_shop_falls_back_to_original_price

    # draft 賣場常被站長當作「促銷預覽」使用，其覆寫價不得洩漏到顧客購物車。

    Example: 草稿賣場不成立歸屬
      Given 賣場 "夏季賣場" 的狀態為 "draft"
      When 顧客加購商品 101 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 該購物車品項不應歸屬於任何賣場

  Rule: 閘門③（期間）- 賣場必須在販售期間內
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_sale_window_gate_rejects_before_start_and_ignores_unset_schedule
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_closed_shop_rejects_hand_crafted_post_for_priced_product

    # 販售期間的定義與賣場前台「是否印出商品 Modal」共用同一份實作
    # （ProfitShopFrontData::is_sale_window_open）。兩者若各寫一份，
    # 就會出現「畫面說關店、後端卻收單」。一律以伺服器時間判斷。

    Scenario Outline: 依開賣與結束時間決定是否成立歸屬
      Given 賣場 "夏季賣場" 的開賣時間為 <start> 且結束時間為 <end>
      When 顧客加購商品 101 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 賣場歸屬是否成立應為 <attributed>

      Examples:
        | start | end  | attributed | 說明             |
        | 未設定 | 未設定 | 是          | 絕大多數賣場      |
        | 過去   | 未來  | 是          | 販售中           |
        | 未來   | 未設定 | 否          | 尚未開賣         |
        | 未設定 | 過去  | 否          | 已結束販售        |
        | 未來   | 過去  | 否          | 時間顛倒，永不開賣 |

    Example: 期間外的加購仍成功但走商品原價
      Given 賣場 "夏季賣場" 的結束時間已過
      When 顧客加購商品 101 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 加購應成功
      And 該購物車品項應以商品原價計價
      And 該購物車品項不應帶有任何分潤 meta

  Rule: 閘門④（成員）- 商品必須在該賣場的商品列表內
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_when_product_not_in_shop_falls_back

    # 防「持賣場 A 的合法 ID，套到賣場 A 沒有的商品上」。

    Example: 拿合法賣場套未授權商品時不成立歸屬
      When 顧客加購商品 103 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 該購物車品項不應歸屬於任何賣場
      And 該購物車品項應以商品原價計價

    Example: 變體商品以父商品 ID 比對賣場成員資格
      Given 商品 104 為可變商品且具有變體 1041
      And 賣場 "夏季賣場" 的商品列表包含商品 104
      When 顧客加購變體 1041 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 該購物車品項應歸屬於賣場 1932

  Rule: 縱深（重驗）- 定價階段必須重新驗證同樣四道閘門
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_closed_shop_rejects_upstream_supplied_profit_shop_id
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_before_calculate_totals_falls_back_to_original_when_signature_invalid

    # 涵蓋「上游已帶 profit_shop_id」而跳過 AddToCartHook 的路徑。

    Scenario Outline: 上游注入的 profit_shop_id 在定價階段仍受四道閘門檢查
      Given 上游已在 cart_item_data 帶入 profit_shop_id 為 1932
      And <condition>
      When 顧客加購商品 <product_id>
      Then 該購物車品項不應帶有分潤簽章

      Examples:
        | condition                 | product_id | 對應閘門 |
        | 賣場 1932 不存在            | 101        | ①       |
        | 賣場 "夏季賣場" 的狀態為 draft | 101        | ②       |
        | 賣場 "夏季賣場" 的結束時間已過  | 101        | ③       |
        | 賣場 "夏季賣場" 商品列表不含 103 | 103        | ④       |

  Rule: 環境（後台）- 純後台請求不掛載歸屬 hook
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_hook_does_not_register_in_admin_context
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_hook_registers_in_ajax_admin_context

    Example: 後台非 AJAX 請求不註冊 hook
      Given 目前為 WordPress 後台且非 AJAX 請求
      Then 系統不應註冊加購歸屬的 filter

    Example: 後台 AJAX 請求仍註冊 hook
      Given 目前為 WordPress 後台的 AJAX 請求
      Then 系統應註冊加購歸屬的 filter

  Rule: 後置（歸屬）- 歸屬成立後必須寫入完整分潤 meta 與簽章
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_with_profit_shop_id_writes_override_meta_to_cart_item
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_cart_item_meta_survives_session_serialization
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_multiple_cart_items_from_different_shops
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_same_product_from_different_shops_treats_as_different_cart_items

    Example: 歸屬成立後 cart item 帶有六筆分潤 meta
      When 顧客加購商品 101 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 該購物車品項應包含以下 meta
        | key                            | value    |
        | _profit_shop_id                | 1932     |
        | _profit_partner_term_id        | 5        |
        | _profit_price_override         | 45000.00 |
        | _profit_regular_price_override | 50000.00 |
        | _profit_sale_price_override    | 45000.00 |
      And 該購物車品項應帶有分潤簽章

    Example: 賣場未設定任何覆寫價時不寫入分潤 meta
      When 顧客加購商品 102 並帶入參數
        | profit_shop_id |
        | 1932           |
      Then 該購物車品項不應帶有分潤簽章
      And 該購物車品項應以商品原價計價
</content>
</invoke>
