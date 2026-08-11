@ignore @command
Feature: 無定價商品靠賣場覆寫價出售的逐筆授權

  商品（或變體）自身沒有價格、只靠賣場覆寫價出售時，WooCommerce 有四道閘門會擋下它。
  系統必須在**每一筆通過簽章驗證的購物車品項**這個粒度上放行，
  而不是在「這個商品 ID 在這個請求裡」這個粒度上放行。

  # ── WooCommerce 的四道閘門（條件都收斂到 '' !== get_price()）──────
  # ① 顯示：variation_is_visible() —— 無價變體不進 get_available_variations()
  # ② 加購：WC_Cart::add_to_cart() 的 is_purchasable() —— 直接 throw
  # ③ 每次載入購物車：woocommerce_cart_item_is_purchasable —— 不通過就把品項移除
  # ④ Cart Block / Store API：CartController 的 validate_cart_item()
  #
  # 覆寫價是在「拿到變體清單**之後**」才逐筆疊上去的，閘門 ① 把清單濾成空的就
  # 沒有東西可以疊 ⇒ 必須修根因（讓這些商品在對的範圍內有價格），不是逐一拆閘門。
  #
  # ── 🔴 授權粒度就是這題的全部 ────────────────────────────────
  # 正確粒度是「**這一筆通過 HMAC 驗章的 cart item**」。
  # 支點由 WooCommerce 免費提供：WC_Product_Factory::get_product() 每次都 new、
  # 不做實例快取 ⇒ 對某一筆 cart item 自己的 WC_Product 實例 set_price()，
  # **在結構上不可能**外溢到同請求內任何其他地方。
  #
  # 曾經用「product ID → 價格」的請求級救援表，被驗到三個洞：
  #   (a) 從賣場合法加購一次後，該商品在**一般單品頁**變成可購買 ⇒ 無歸屬銷售
  #   (b) 守門寫成逐欄位判空字串，而多數正常商品的 sale_price 本來就是空 ⇒ 正常商品被污染
  #   (c) 無條件合併持久化購物車 usermeta ⇒ 站長改高價格後舊價仍能復活
  # 三個洞全程零錯誤訊息、既有測試全綠。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 系統中已存在以下商品
      | id  | name       | type     | own_price |
      | 201 | 課程方案    | variable |           |
      | 999 | 一般商品    | simple   | 1000      |
    And 商品 201 具有變體 2011 與 2012 且兩者自身皆無價格
    And 已存在 status 為 publish 且在販售期間內的分潤賣場 "課程賣場" 其 id 為 1932
    And 賣場 "課程賣場" 的商品列表包含商品 201

  Rule: 前置（政策）- 商品層覆寫不得救起「站長從未定價的半成品變體」
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_parent_level_override_never_rescues_priceless_variation_on_add_to_cart
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_real_variation_level_override_still_rescues_priceless_variation
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_parent_level_override_does_not_rescue_priceless_variation

    # R-4 裁決。站長設商品層覆寫的意圖是「這個商品在我的賣場賣 X 元」，
    # 不會預期連沒填價格的半成品變體都一起上架；ItemsEditor 的展開列本來就
    # 提供逐變體設定的入口，要救就明確設變體層覆寫，操作與結果一一對應。
    #
    # 🔴 這條規則在**顯示層與加購層都必須實作**。
    #   曾經只實作在顯示層：前台正確顯示「暫無可購買規格」、卡片停用，
    #   但直接 POST 加購該變體 ID **成功**、帶合法簽章、產生真實訂單。
    #   最惡毒的是顯示層探針會回報 PASS——它問的是「畫面對不對」，而畫面是對的。
    #   判斷一個「該不該賣」的規則有沒有實作完整，唯一可靠的問法是
    #   「**繞過畫面直接送 POST 會怎樣**」。

    Example: 只設商品層覆寫時無定價變體不可購買
      Given 賣場 "課程賣場" 對商品 201 設定商品層覆寫原價 "500" 與特價 "450"
      And 賣場 "課程賣場" 未對變體 2011 設定任何覆寫
      When 顧客從賣場 1932 加購變體 2011
      Then 加購應失敗
      And 購物車件數應為 0

    Example: 設了變體層覆寫價的無定價變體可以購買
      Given 賣場 "課程賣場" 對變體 2011 設定變體層覆寫原價 "300" 與特價 "270"
      When 顧客從賣場 1932 加購變體 2011
      Then 加購應成功
      And 該購物車品項的 price 應為 "270.00"

    Example: 一般（非變體）商品即使自身無定價也放行
      Given 商品 202 為 simple 商品且自身無價格
      And 賣場 "課程賣場" 的商品列表包含商品 202 且設定覆寫價 "600"
      When 顧客從賣場 1932 加購商品 202
      Then 加購應成功

  Rule: 前置（政策）- 「變體條目存在」不等於「有變體層覆寫價」
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_empty_variation_entry_is_not_treated_as_variation_level_override

    # 🔴 判準必須與取價服務逐字相同：
    #   null !== $override && ( null !== regular_price || null !== sale_price )
    #
    # 只判斷「條目存在」會讓「三個價格欄位全 null 的空條目」被放行，
    # 再由取價服務 fallback 到商品層覆寫，正好構成 R-4 禁止的組合。
    #
    # ⚠️ 空條目是站長**正常後台操作**的產物：替某個變體設定「灌水購買人數」時
    #    就會建出一個價格全 null 的條目；「打了價格又清空」同樣留下空條目。
    #    「設了灌水人數但不覆寫價格」是合法狀態——要修的是判準，不是資料。
    #
    # ⚠️ signup_fee 刻意不納入判斷：取價服務本來就不看它，納入會再度造成判準分歧。

    Example: 只設了灌水人數的空條目不算變體層覆寫
      Given 賣場 "課程賣場" 對商品 201 設定商品層覆寫原價 "500" 與特價 "450"
      And 賣場 "課程賣場" 對變體 2011 只設定灌水購買人數 7 而未設定任何覆寫價
      When 顧客從賣場 1932 加購變體 2011
      Then 加購應失敗
      And 賣場前台的商品 201 卡片應標記為不可購買
      And 賣場前台不應為商品 201 印出商品 Modal

    Example: 只設了 signup_fee 的條目不算變體層覆寫價
      Given 賣場 "課程賣場" 對商品 201 設定商品層覆寫原價 "500"
      And 賣場 "課程賣場" 對變體 2011 只設定 signup_fee 而未設定原價或特價
      When 顧客從賣場 1932 加購變體 2011
      Then 加購應失敗

  Rule: 閘門①（顯示）- 覆寫價只在「取得變體清單」這個動作的範圍內生效
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_armed_filters_make_priceless_variation_visible_and_purchasable
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_disarm_removes_every_filter_and_restores_original_prices
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_re_arm_replaces_previous_context

    Example: 帶賣場情境時無定價變體出現在可選清單中
      Given 賣場 "課程賣場" 對變體 2011 設定變體層覆寫價 "270"
      When 系統帶著賣場 1932 的情境取得商品 201 的可購買變體
      Then 變體 2011 應出現在清單中且價格為 "270"

    Example: 不帶賣場情境時無定價變體不出現
      When 系統不帶任何賣場情境取得商品 201 的可購買變體
      Then 變體 2011 不應出現在清單中

    Example: 所有變體都無法購買時卡片標記為暫無可購買規格且不印 Modal
      Given 賣場 "課程賣場" 對商品 201 只設定商品層覆寫價
      When 顧客瀏覽賣場 1932 的前台
      Then 商品 201 的卡片應顯示「暫無可購買規格」
      And 商品 201 的卡片應為停用狀態
      And 頁面中不應存在商品 201 的商品 Modal

  Rule: 閘門②（加購）- 加購授權是一次性的，且必須綁在單一目標商品上
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_pending_add_authorization_is_cleared_after_add
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_pending_add_authorization_does_not_cover_sibling_variation
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_priceless_variation_cannot_be_added_without_profit_shop_id
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_closed_shop_rejects_hand_crafted_post_for_priceless_variation

    # 加購時拿不到 WC_Product 物件（只有 id），因此這道只能用一次性旗標。
    # 比對鍵一律是 `variation_id ?: product_id`——用父商品會讓兄弟變體一起被放行。

    Example: 加購授權不得讓兄弟變體一起放行
      Given 賣場 "課程賣場" 對變體 2011 設定變體層覆寫價 "270"
      And 賣場 "課程賣場" 未對變體 2012 設定任何覆寫
      When 顧客從賣場 1932 加購變體 2011
      And 顧客在同一個請求內嘗試加購變體 2012
      Then 變體 2011 應加購成功
      And 變體 2012 應加購失敗

    Example: 授權在下一次加購開始前必定被清空
      Given 顧客已從賣場 1932 加購變體 2011 成功
      When 顧客在同一個請求內加購另一個不屬於任何賣場且無定價的商品
      Then 該商品應加購失敗

    Example: 加購失敗後殘留的授權不得影響任何頁面渲染
      Given 顧客從賣場 1932 加購變體 2011 但因庫存不足而失敗
      When 系統開始渲染任何頁面
      Then 該筆殘留授權應已被清除

  Rule: 閘門③（載入）- 每次載入購物車都必須逐筆重新驗章
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_priceless_cart_item_survives_session_reload_via_per_item_hook
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_priced_products_are_untouched_by_authorization

    # 這道 hook 是全 WooCommerce 唯一帶 cart item key / values / product 的
    # per-item purchasable hook，因此也是唯一能把授權綁在單一 cart item 上的位置。
    #
    # 三道早退缺一不可：
    #   (1) 已判定可購買 → 不介入（一般商品完全不進入後續邏輯）
    #   (2) 商品自身有價格 → 不介入（它不可購買是別的原因，那些理由我們無權推翻）
    #   (3) 驗章不過 → 回傳**原始**判定（false），讓 WooCommerce 自己移除品項
    #
    # 🔴 驗章不過時**絕不回傳 true**——那會製造「可購買為真但價格為空」這種
    #   WooCommerce 從未預期的狀態，接著還原流程的 set_price('') 就是 0 元商品，
    #   把「竄改 ⇒ 付原價」倒轉成「竄改 ⇒ 免費」。

    Example: 已可購買的一般商品不進入救援邏輯
      Given 購物車中有一筆商品 999 的品項
      When 系統載入購物車
      Then 系統不應對商品 999 執行任何救援查詢

    Example: 自身有價格但因其他原因不可購買的商品不被救援
      Given 購物車中有一筆商品 999 的品項且該商品已被下架
      When 系統載入購物車
      Then 該品項應維持不可購買
      And 該品項的價格不應被賣場覆寫價改寫

    Example: 驗章通過的無定價品項在每次載入時恢復可購買
      Given 顧客已從賣場 1932 合法加購變體 2011 且覆寫價為 "270"
      When 顧客在新的請求中重新載入購物車
      Then 該品項應仍在購物車中
      And 該品項的 price 應為 "270.00"

    Example: 驗章失敗的無定價品項由 WooCommerce 自行移除並提示顧客
      Given 購物車中有一筆無定價品項且其簽章已失效
      When 顧客載入購物車
      Then 該品項應被移除
      And 顧客應看到一則「已無法購買」的錯誤提示

  Rule: 閘門④（Store API）- Cart Block 與 Store API 讀到的是同一個商品實例
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_priceless_cart_item_survives_session_reload_via_per_item_hook
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_priceless_variation_with_override_can_be_added_to_cart

    Example: Cart Block 顯示的無定價品項價格與購物車頁一致
      Given 顧客已從賣場 1932 合法加購變體 2011 且覆寫價為 "270"
      When 顧客以 Cart Block 檢視購物車
      Then 該品項應存在且 price 為 "270"

    Example: Store API 的加購端點不得用於無定價商品
      When 顧客以 Store API 的加購端點加購變體 2011
      Then 加購應失敗

  Rule: 撤銷（授權）- 只靠救援才有價的品項必須可被站長停掉
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_rescued_item_loses_authorization_when_shop_unpublished_or_product_removed

    # 🔴 這條與「已在購物車內的品項不受販售期間影響」是**兩條不同的規則**，
    #   差異點才是關鍵，不可為了 DRY 併成一條：
    #     - 商品自身有價格的品項 → **不撤銷**（活動結束那秒靜默清空所有購物車更糟）
    #     - 只靠救援才有價的品項 → **必須撤銷**（它在賣場之外根本不是商品）
    #   併成「都不撤銷」＝站長把賣場整個下架都停不掉那些訂單；
    #   併成「都撤銷」＝翻掉既有邊界、大量正常購物車在活動結束當下被清空。
    #
    # 移除**不是靜默的**：WooCommerce 自己會加一則錯誤提示告知顧客。

    Scenario Outline: 賣場端條件改變後救援授權必須失效
      Given 顧客已從賣場 1932 合法加購變體 2011 且該變體只靠賣場覆寫價才有價格
      When <change>
      And 顧客重新載入購物車
      Then 該品項應被移除

      Examples:
        | change                        |
        | 站長把賣場 1932 改為 draft       |
        | 站長把商品 201 移出賣場 1932     |
        | 賣場 1932 的販售期間已結束       |
        | 站長刪除變體 2011 的變體層覆寫價  |

    Example: 商品自身有價格的品項不因賣場下架而被移除
      Given 顧客已從賣場 1932 加購自身有價格的商品 999
      When 站長把賣場 1932 改為 draft
      And 顧客重新載入購物車
      Then 該品項應仍在購物車中

    Example: 一般購物車不因這道回查而增加查詢
      Given 購物車中只有自身有價格的商品
      When 顧客載入購物車
      Then 系統不應為救援回查發出任何賣場查詢

  Rule: 還原（安全）- 無定價商品驗章失敗時不得變成 0 元
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_tampered_priceless_cart_item_is_removed_not_zeroed
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_priceless_product_without_override_cannot_be_added_to_cart

    # 守門欄位必須是「這個商品自身有沒有價格」（get_price('edit')），
    # **不可**寫成「sale 與 regular 皆空」——只寫 _price 是 WooCommerce 明文承認的
    # 合法狀態（匯入 / 程式建立的商品常見），用後者會把完全正常販售中的商品
    # 在驗章失敗時直接刪出購物車。
    #
    # ⚠️ 只改守門、不改還原值等於沒修：還原值若用 `sale ?: regular` 推導，
    #    對這類商品會得到空字串 → 下一個請求被閘門 ③ 移除，症狀完全一樣。

    Example: 只有 _price 的商品驗章失敗時退回它自己的價格
      Given 購物車中有一筆商品其 price 為 "500" 但 regular 與 sale 皆為空
      When 該品項的簽章驗證失敗
      Then 該品項應保留在購物車中
      And 該品項的價格應為 "500"

    Example: 完全無定價的商品驗章失敗時被移除而非變成 0 元
      Given 購物車中有一筆自身完全無價格的品項
      When 該品項的簽章驗證失敗
      Then 該品項應被移除
      And 該品項不應以 0 元留在購物車中

  Rule: 不變量（隔離）- 顯示層的價格覆寫永遠不得影響任何購物車品項
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_armed_filters_never_touch_a_cart_item_object
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_every_cart_item_is_protected_not_just_the_first

    # cart item 的價格由簽章驗證獨佔決定。
    #
    # ⚠️ 「購物車請求不會走到顯示層」這個論證是**錯的**——它的前提是「請求類型」，
    #    而請求類型不是隔離邊界：單品頁的價格 filter 一旦掛上就整個 request 不解除，
    #    而購物車與商品頁**在同一個 request 裡並存**。佈景主題 / 外掛
    #    （mini-cart fragments、免運進度條、幣別切換器）只要在渲染期再算一次總額，
    #    一筆完全沒有分潤 meta、沒有簽章的品項就會被套上覆寫價（實測 1000 → 27），
    #    整條簽章保護被繞過、訂單無分潤歸屬。
    #
    # 判準是**物件身分**比對，不是 product ID：用 ID 比對會把單品頁一起排掉。

    Example: 沒有分潤 meta 的購物車品項不受單品頁價格 filter 影響
      Given 購物車中有一筆完全沒有分潤 meta 的商品 999 品項
      And 目前正在渲染帶賣場情境的商品單品頁
      When 系統在渲染期間重新計算購物車總額
      Then 商品 999 的品項小計應為其原價 "1000"

    Example: 同一個商品在單品頁仍正確顯示覆寫價
      Given 購物車中有一筆商品 999 的品項
      And 賣場 1932 對商品 999 設定覆寫價 "27"
      When 顧客帶著賣場情境開啟商品 999 的單品頁
      Then 單品頁應顯示 "27"
</content>
