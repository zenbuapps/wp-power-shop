@ignore @command
Feature: 套用賣場覆寫價到購物車並以簽章防竄改

  購物車品項歸屬到賣場之後，實際生效的價格由「加購當下寫入的分潤 meta」決定，
  而那組 meta 由 HMAC-SHA256 簽章保護。每次結算總額前都要重新驗章：
  驗過才套價，驗不過一律退回商品原價。

  # ── 為什麼價格三者必須成套 ─────────────────────────────────
  # 曾經只呼叫 set_price()，結果購物車（Cart Block / Store API）顯示的**劃線原價**
  # 讀到的是商品的**原始** regular_price——實收價正確、劃線價錯誤，且沒有任何錯誤訊息。
  # Store API 的 ProductSchema 讀的是 get_regular_price()，因此
  # price / regular_price / sale_price 必須一起覆寫。
  #
  # ── regular 與 sale 的空字串語意不同（本 Feature 最容易寫錯的一行）──
  #   regular = '' ⇒「不覆寫原價」⇒ **不呼叫** set_regular_price()，沿用商品原本原價
  #   sale    = '' ⇒「沒有特價」  ⇒ **必須呼叫** set_sale_price('') 清掉商品原本的特價
  # 不清會出現「原價劃掉 → 特價」但實收卻是覆寫原價的矛盾。
  #
  # ── 取價語意的唯一真相 ────────────────────────────────────
  # 購物車與 WooCommerce 單品頁共用 Domain\Service\OverridePriceResolver。
  # 變體只要有**任一**覆寫欄位（原價或特價），就**整組**採用變體層，
  # 不與商品層逐欄混用——逐欄混用會造成「單品頁一個價、購物車另一個價」。
  #
  # ── 已知且刻意保留的取捨 ──────────────────────────────────
  # - 套用 regular/sale 後 is_on_sale() 會如實翻為 true，因此帶
  #   exclude_sale_items 的優惠券對分潤商品會從可折扣變成不可折扣。語意上正確。
  # - 變體只覆寫 sale_price 且該變體原始 regular 低於覆寫 sale 時，會顯示
  #   「原價 100 / 現價 500」這種跨層級的荒謬結果（specs/tech-debt.md TD-34）。
  #   PriceOverride VO 只保證同一層內 sale ≤ regular，跨層級不保證。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 系統中已存在以下商品
      | id  | name        | type   | regular_price | sale_price |
      | 101 | MacBook Pro | simple | 1000          |            |
      | 102 | iPhone 15   | simple | 900           | 800        |
    And 已存在 status 為 publish 且不限販售期間的分潤賣場 "夏季賣場" 其 id 為 1932

  Rule: 前置（正規化）- 價格在簽章、驗證、套用三處必須用同一套正規化
    # @covered-by: tests/Unit/Application/Service/CartPriceSignatureServiceTest.php::test_sign_produces_deterministic_output_for_same_inputs
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_before_calculate_totals_sets_product_price_to_override_when_signature_valid

    # 正規化實作是 wc_format_decimal($price, '')，行為是「補足到站台小數位數」，
    # 不是 trim trailing zeros。任一路徑漏正規化，簽章就會對不上、
    # 分潤價靜默退回原價，而站長通常要到對帳才發現。

    Scenario Outline: 價格字串一律補足到站台小數位數
      When 系統正規化價格字串 "<raw>"
      Then 結果應為 "<normalized>"

      Examples:
        | raw    | normalized |
        | 888    | 888.00     |
        | 888.0  | 888.00     |
        | 888.00 | 888.00     |
        | 888.5  | 888.50     |
        | 888.55 | 888.55     |

    Example: 未覆寫的價格欄位一律正規化為空字串而非零
      When 系統正規化「未覆寫」的價格
      Then 結果應為 ""
      And 結果不應為 "0.00"

  Rule: 前置（寫入）- 加購時必須同時寫入五筆價格相關 meta 與一筆簽章
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_with_profit_shop_id_writes_override_meta_to_cart_item
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_cart_item_regular_and_sale_price_reflect_shop_override

    Example: 同時覆寫原價與特價
      Given 賣場 "夏季賣場" 對商品 101 設定覆寫原價 "500" 與覆寫特價 "450"
      When 顧客從賣場 1932 加購商品 101
      Then 該購物車品項應包含以下 meta
        | key                            | value  |
        | _profit_price_override         | 450.00 |
        | _profit_regular_price_override | 500.00 |
        | _profit_sale_price_override    | 450.00 |

    Example: 只覆寫原價時特價 meta 為空字串
      Given 賣場 "夏季賣場" 對商品 102 設定覆寫原價 "700" 且不設定覆寫特價
      When 顧客從賣場 1932 加購商品 102
      Then 該購物車品項的 _profit_sale_price_override 應為 ""
      And 該購物車品項的 _profit_regular_price_override 應為 "700.00"

    Example: 賣場對該商品完全沒有覆寫價時不寫入任何分潤價格 meta
      Given 賣場 "夏季賣場" 對商品 101 未設定任何覆寫價
      When 顧客從賣場 1932 加購商品 101
      Then 該購物車品項不應帶有分潤簽章

  Rule: 後置（套價）- 驗章通過後 price / regular / sale 必須成套套用
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_cart_item_regular_and_sale_price_reflect_shop_override
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_only_sale_overridden_keeps_product_original_regular_price
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_only_regular_overridden_clears_product_sale_price
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_null_regular_override_is_not_normalized_into_zero
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_variation_override_sets_variation_regular_and_sale_price

    Example: 購物車顯示賣場覆寫後的劃線原價與實收價
      Given 賣場 "夏季賣場" 對商品 101 設定覆寫原價 "500" 與覆寫特價 "450"
      When 顧客從賣場 1932 加購商品 101
      And 系統重新計算購物車總額
      Then 該購物車品項的價格應為
        | price  | regular_price | sale_price |
        | 450.00 | 500.00        | 450.00     |

    Example: 覆寫原價為空時沿用商品自己的原價
      Given 賣場 "夏季賣場" 對商品 101 只設定覆寫特價 "450"
      When 顧客從賣場 1932 加購商品 101
      And 系統重新計算購物車總額
      Then 該購物車品項的 regular_price 應為商品原本的 "1000"
      And 該購物車品項的 price 應為 "450.00"

    Example: 覆寫特價為空時必須清掉商品原本的特價
      Given 賣場 "夏季賣場" 對商品 102 只設定覆寫原價 "700"
      When 顧客從賣場 1932 加購商品 102
      And 系統重新計算購物車總額
      Then 該購物車品項的 sale_price 應為 ""
      And 該購物車品項的 price 應為 "700.00"
      And 該購物車品項不應呈現「原價劃掉→特價」的樣式

  Rule: 後置（一致性）- 賣場卡片、單品頁與購物車三處價格必須相同
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_simple_product_price_is_overridden_on_single_product_page

    # ── 🔴 2026-08-12 移除一個錯標；本 Rule 目前是**部分覆蓋** ──────────
    # 原本另外標了 `CartPriceOverrideHookTest::test_shop_cart_item_does_not_pollute_other_product_instances`，
    # 但那條驗的是「**不污染**」（單品頁的價格 filter 不得影響購物車品項），
    # 與本 Rule 的「三處**一致**」是不同命題——前者是隔離、後者是相等，已移除。
    #
    # ✅ 已覆蓋：三處**各自**都有測試——
    #    單品頁（`SingleProductPriceHookTest`，上面保留的那條）、
    #    購物車（`CartPriceOverrideHookTest` 的套價系列）、
    #    賣場卡片（`ProfitShopFrontData` / 樣板的價格輸出測試）。
    #
    # ❌ 未覆蓋：**沒有任何一條測試在同一組 fixture 上交叉比對三者相等**。
    #
    # 🔴 這個缺口的形狀，正是 Phase 13 那個 bug 能潛伏數個 Phase 的原因：
    #    當時購物車只呼叫 set_price()、沒有套 regular/sale，於是購物車顯示的
    #    劃線原價是商品的**原始** regular_price，與單品頁分歧。
    #    三處各自的測試**全部都是綠的**——因為每一條都只問「我這一處對不對」，
    #    沒有人問「三處是不是同一個數字」。實收價正確、劃線價錯誤，零錯誤訊息。
    #
    # 要補什麼：一條同 fixture 的三方斷言測試——建立一個帶覆寫價的賣場與商品，
    # 在**同一個測試方法內**依序取得賣場卡片輸出、帶賣場情境的單品頁價格、
    # 以及加購後的購物車價格，斷言三者的實收價與原價**各自相等**。
    # 這條測試的價值不在於任何單一處是否正確（那已經有人守了），
    # 而在於它是唯一會因為「兩處分歧」而轉紅的測試。

    Example: 同一組覆寫價在三處呈現一致
      Given 賣場 "夏季賣場" 對商品 101 設定覆寫原價 "500" 與覆寫特價 "450"
      When 顧客在賣場前台看到商品 101 的卡片
      And 顧客帶著賣場情境開啟商品 101 的單品頁
      And 顧客加購商品 101 後檢視購物車
      Then 三處顯示的實收價皆應為 "450"
      And 三處顯示的原價皆應為 "500"

  Rule: 後置（優惠券）- 套用覆寫特價後品項會被視為特價中
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_override_makes_cart_item_on_sale_for_exclude_sale_items_coupon
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_coupon_percentage_discount_applies_on_top_of_override

    # 刻意接受的行為變更：語意上該品項確實在特價中，與單品頁既有行為一致。

    Example: 帶排除特價商品的優惠券對分潤商品不生效
      Given 賣場 "夏季賣場" 對商品 101 設定覆寫原價 "500" 與覆寫特價 "450"
      And 站台存在一張排除特價商品的優惠券 "NOSALE"
      When 顧客從賣場 1932 加購商品 101
      And 顧客套用優惠券 "NOSALE"
      Then 該購物車品項不應獲得折扣

  Rule: 安全（簽章）- 任一被簽章保護的欄位被竄改都必須驗章失敗
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_v2_cart_item_with_tampered_regular_price_falls_back_to_original
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_v2_cart_item_with_missing_regular_meta_falls_back_to_original
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_before_calculate_totals_falls_back_to_original_when_signature_missing

    Scenario Outline: 竄改分潤 meta 後品項退回商品原價
      Given 顧客已從賣場 1932 加購商品 101 且覆寫價為 "500" / "450"
      When 攻擊者把該購物車品項的 <field> 竄改為 <value>
      And 系統重新計算購物車總額
      Then 該購物車品項應退回商品原價
      And 該購物車品項的分潤 meta 應被清除
      And 系統應寫入一筆簽章不符的紀錄

      Examples:
        | field                          | value |
        | _profit_price_override         | 1     |
        | _profit_regular_price_override | 1     |
        | _profit_sale_price_override    | 1     |
        | _profit_shop_id                | 9999  |
        | _profit_partner_term_id        | 9     |
        | _profit_signature              | 亂數  |

    Example: 缺少任一必要 meta 時視同竄改
      Given 顧客已從賣場 1932 加購商品 101
      When 攻擊者刪除該購物車品項的 _profit_signature
      And 系統重新計算購物車總額
      Then 該購物車品項應退回商品原價

  Rule: 安全（簽章）- 簽章必須綁定商品身分，不得搬移到其他商品
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_v3_signature_cannot_be_transplanted_to_another_product
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_v3_signature_bound_to_wrong_product_is_rejected

    # v3 起 payload 納入 product_id 與 variation_id。
    # 兩個都簽：只簽 target 已足以阻擋搬移，額外簽 product_id 是為了鎖住
    # 「同一個 target 不能被宣稱屬於另一個父商品」——賣場成員資格的回查用的是父商品 ID。

    Example: 把某商品的整組分潤 meta 套到別的商品上必須失敗
      Given 顧客已從賣場 1932 加購商品 101 且取得合法的 v3 簽章
      When 攻擊者把該組分潤 meta 原封套用到不屬於任何賣場的商品 103
      And 系統重新計算購物車總額
      Then 商品 103 應以其商品原價計價
      And 商品 103 不應變成可購買狀態

    Example: 竄改 cart item 的商品身分欄位必須驗章失敗
      Given 顧客已從賣場 1932 加購變體 1041 且取得合法的 v3 簽章
      When 攻擊者把該購物車品項的 variation_id 改為另一個變體
      And 系統重新計算購物車總額
      Then 該購物車品項應退回商品原價

  Rule: 相容（版本）- 簽章分三個版本，舊版本永久保留驗證能力
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_legacy_v1_cart_item_still_applies_price_and_is_not_treated_as_tampering
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_legacy_v1_signature_ignores_injected_regular_price_meta
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_v1_signature_with_forged_v2_prefix_falls_back_to_original_price
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_mixed_v1_and_v2_cart_items_are_evaluated_independently

    # 🔴 舊版本分支不是死碼，且不設下架期限。
    #   登入使用者的持久化購物車（usermeta）沒有任何過期機制，
    #   移除舊分支會讓那些購物車的分潤價**靜默消失**（specs/tech-debt.md TD-35）。
    #
    # payload 格式（domain prefix 內含版本字串，因此三版 hash 天然不可互通）：
    #   v1: cart-signature:v1|{shop}|{partner}|{price}
    #   v2: cart-signature:v2|{shop}|{partner}|{price}|{regular}|{sale}
    #   v3: cart-signature:v3|{shop}|{partner}|{price}|{regular}|{sale}|{product_id}|{variation_id}
    #
    # 🔴 版本一律由簽章前綴判斷，**絕不可**用「某個 meta 在不在」判斷——
    #   後者可被攻擊者操縱：保留合法舊簽章 + 注入偽造欄位 → 新分支驗失敗 →
    #   落回舊分支 → 通過 → 套用一組從未被簽章保護的值。

    Example: 新產生的簽章一律為 v3
      When 系統為一筆加購產生簽章
      Then 簽章應以 "v3." 開頭

    Scenario Outline: 依簽章版本決定套用哪些欄位與是否比對商品身分
      Given 購物車中有一筆帶 <version> 簽章的分潤品項
      When 系統重新計算購物車總額
      Then 套用 regular 與 sale 應為 <apply_rs>
      And 比對商品身分應為 <check_identity>

      Examples:
        | version | apply_rs | check_identity | 說明                          |
        | v1      | 否        | 否              | 只套 price，維持改版前行為        |
        | v2      | 是        | 否              | 該版從未簽過商品身分，比對零增益   |
        | v3      | 是        | 是              | 現行版本                       |

    Example: v1 簽章的品項即使被注入 regular meta 也不套用
      Given 購物車中有一筆帶合法 v1 簽章的分潤品項
      When 攻擊者為該品項注入偽造的 _profit_regular_price_override
      And 系統重新計算購物車總額
      Then 該品項的 regular_price 應維持商品原本的原價
      And 該品項的 price 應維持 v1 簽章保護的覆寫價

    Example: 把舊簽章的前綴改成 v3 必須驗證失敗
      Given 購物車中有一筆帶合法 v1 簽章的分潤品項
      When 攻擊者把該簽章的版本前綴改為 "v3."
      And 系統重新計算購物車總額
      Then 該購物車品項應退回商品原價

    Example: 同一台購物車可同時存在不同版本的品項
      Given 購物車中同時存在 v1、v2 與 v3 簽章的分潤品項
      When 系統重新計算購物車總額
      Then 每一筆品項應各自依其版本正確套價

    Example: 無法辨識的簽章前綴一律驗證失敗
      Given 購物車中有一筆簽章前綴為 "v9." 的分潤品項
      When 系統重新計算購物車總額
      Then 該購物車品項應退回商品原價

  Rule: 後置（還原）- 驗章失敗的還原必須同時還原三個價格欄位
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_signature_failure_also_restores_regular_and_sale_price
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_price_only_product_survives_signature_failure_and_falls_back_to_own_price

    # 同一個 request 內 calculate_totals() 會被呼叫多次。若第一次驗章通過
    # （已寫入覆寫 regular/sale）、第二次因竄改而失敗，只還原 price
    # 會讓錯誤的劃線價殘留在畫面上。

    Example: 還原時劃線原價一併回到商品原本的值
      Given 購物車中有一筆已套用覆寫價 "500" / "450" 的分潤品項
      When 該品項的簽章在後續計算中驗證失敗
      Then 該品項的 price / regular_price / sale_price 應全部回到商品原本的值

  Rule: 效能（重入）- 套價流程不得巢狀重入自己
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_apply_override_never_re_enters_itself
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_reentrancy_guard_preserves_mixed_cart_correctness

    # 還原流程可能呼叫 remove_cart_item()，而 WooCommerce 在
    # woocommerce_cart_item_removed 上掛了 calculate_totals（priority 20），
    # 會直接遞迴回本流程。修正前實測：巢狀深度與「驗章失敗的無定價品項數」1:1 成長
    # （N=2/6/12 → 深度 2/6/12），大購物車在 Xdebug 預設 frame 限制下會 fatal。
    #
    # ⚠️ 觸發不需要攻擊者：輪替 AUTH_SALT（標準資安事件處置動作）
    #    會讓全站簽章一次失效，所有這類品項同時走進這條路。

    Example: 多筆驗章失敗品項時套價流程本體只執行一次
      Given 購物車中有 12 筆驗章會失敗且只靠賣場覆寫價才有價格的品項
      When 系統重新計算購物車總額
      Then 套價流程本體的巢狀深度應為 1

    Example: 重入防護不得影響混合購物車的正確性
      Given 購物車中有 3 筆驗章失敗品項與 2 筆合法分潤品項
      When 系統重新計算購物車總額
      Then 2 筆合法品項應保留且套用覆寫價
      And 3 筆失敗品項應各自產生 1 則移除訊息
</content>
