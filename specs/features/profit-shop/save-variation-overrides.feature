@ignore @command
Feature: 設定賣場商品的變體層價格與灌水購買人數

  可變商品（variable / variable-subscription）在賣場商品表格可展開，
  逐一為每個變體設定覆寫原價、覆寫特價與灌水購買人數。

  # ── 契約 ─────────────────────────────────────────────────
  # items[].variations 是以 variation_id 為鍵的物件，每筆為：
  #   { "override": { regular_price, sale_price, signup_fee }, "inflated_count": int }
  #
  # ⚠️ inflated_count 是後加的欄位。反序列化時缺鍵一律讀為 0，
  #    下次儲存自然補齊——**不需要任何資料轉換腳本**。
  #    這個預設值是舊資料唯一的接住點，重構時不可移除。
  #
  # ── 取價 fallback 的層級語意 ─────────────────────────────
  # 變體只要有**任一**覆寫價欄位（原價或特價），就**整組**採用變體層，
  # 不與商品層逐欄混用。逐欄混用會造成「單品頁顯示商品層特價、
  # 購物車實收變體層原價」這種價格跳動。
  #
  # ── 「留空」與「填 0」語意完全不同 ────────────────────────
  #   留空 → null → 沿用商品原價（不覆寫）
  #   填 0  → "0"  → 覆寫為 0 元（例如免費贈品）

  Background:
    Given 管理者 "Admin" 已登入後台
    And 系統中已存在以下商品
      | id  | name     | type     |
      | 103 | iPad Air | variable |
      | 101 | MacBook  | simple   |
    And 商品 103 具有變體 1031 與 1032
    And 已存在分潤賣場 "夏季賣場" 且其商品列表包含商品 103

  Rule: 前置（參數）- 變體必須屬於該商品
    # @covered-by: tests/Unit/Application/Service/ItemValidatorTest.php::test_throws_invalid_variation_when_variation_does_not_belong_to_product
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_variation_not_belonging_to_product_returns_400

    Example: 變體不屬於該商品時拒絕儲存
      When 管理者 "Admin" 為商品 101 設定變體 1031 的覆寫
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |

    Example: 變體 id 非正整數時被略過而非報錯
      When 管理者 "Admin" 送出的 variations 含有非正整數的鍵
      Then 系統應忽略該筆而不報錯

  Rule: 前置（參數）- 變體層價格必須符合與商品層相同的規則
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_variation_sale_price_greater_than_regular_returns_400
    # @covered-by: tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php::test_variation_sale_price_greater_than_regular_throws

    Scenario Outline: 非法的變體價格被拒絕
      When 管理者 "Admin" 為變體 1031 設定 regular_price 為 "<price>"
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |

      Examples:
        | price |
        | -100  |
        | abc   |

    Example: 變體的特價高於原價時拒絕儲存
      When 管理者 "Admin" 為變體 1031 設定 regular_price 為 "500" 且 sale_price 為 "600"
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |

    Example: 驗證訊息應同時指出商品與變體
      When 管理者 "Admin" 為變體 1031 設定特價高於原價
      Then 錯誤訊息應同時指出對應的商品與變體

  Rule: 前置（參數）- 灌水購買人數必須為非負整數
    # @covered-by: tests/Unit/Domain/ValueObject/InflatedCountTest.php::test_negative_value_clamped_to_zero
    # @covered-by: tests/Unit/Domain/ValueObject/InflatedCountTest.php::test_construct_with_zero_value
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_negative_variation_inflated_count_is_sanitized_to_zero
    # @covered-by: tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php::test_string_variation_inflated_count_is_cast_to_int

    Example: 負數的灌水購買人數被正規化為 0
      When 管理者 "Admin" 為變體 1031 設定灌水購買人數為 -5
      And 管理者 "Admin" 重新查詢該賣場
      Then 變體 1031 的灌水購買人數應為 0

  Rule: 後置（持久化）- 變體層設定必須完整寫入且可原樣讀回
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_put_variation_overrides_round_trip_through_get
    # @covered-by: tests/Unit/Domain/Entity/OverrideItemTest.php::test_to_array_serializes_variation_inflated_count
    # @covered-by: tests/Unit/Domain/Entity/OverrideItemTest.php::test_set_variation_override_preserves_existing_inflated_count

    Example: 儲存後重新查詢應還原相同的變體設定
      When 管理者 "Admin" 為變體 1031 設定
        | regular_price | sale_price | inflated_count |
        | 900           | 850        | 12             |
      And 管理者 "Admin" 重新查詢該賣場
      Then 變體 1031 的設定應為
        | regular_price | sale_price | signup_fee | inflated_count |
        | 900           | 850        |            | 12             |

    Example: 只設灌水購買人數而不設價格是合法狀態
      # ⚠️ 這會產生一筆「三個價格欄位皆為 null」的空條目，
      #    而空條目**不算**變體層覆寫價，見 authorize-priceless-cart-item.feature。
      When 管理者 "Admin" 為變體 1031 只設定灌水購買人數為 7
      And 管理者 "Admin" 重新查詢該賣場
      Then 變體 1031 的三個價格欄位皆應為 null
      And 變體 1031 的灌水購買人數應為 7

    Example: 未設定任何變體覆寫的商品其 variations 為空
      Given 賣場 "夏季賣場" 未對商品 103 的任何變體設定覆寫
      When 管理者 "Admin" 重新查詢該賣場
      Then 商品 103 的 variations 應為空

  Rule: 相容（舊資料）- 缺少灌水購買人數欄位的舊資料讀為 0 且價格仍可讀出
    # @covered-by: tests/Integration/Infrastructure/Persistence/CptProfitShopRepositoryTest.php::test_legacy_variations_json_without_inflated_count_defaults_to_zero
    # @covered-by: tests/Integration/Infrastructure/Persistence/CptProfitShopRepositoryTest.php::test_legacy_variations_json_is_upgraded_on_next_save
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_variation_without_inflated_count_defaults_to_zero

    Example: 舊格式資料的灌水購買人數讀為 0
      Given 賣場 "夏季賣場" 的變體 1031 為只有 override 而無 inflated_count 的舊格式
      When 管理者 "Admin" 查詢該賣場
      Then 變體 1031 的灌水購買人數應為 0
      And 變體 1031 的覆寫價應可正常讀出

    Example: 舊格式資料在下次儲存後自然升級
      Given 賣場 "夏季賣場" 的變體 1031 為舊格式
      When 管理者 "Admin" 重新儲存該賣場
      Then 變體 1031 的資料應包含 inflated_count 欄位

  Rule: 後置（取價）- 變體層覆寫整組生效，不與商品層逐欄混用
    # @covered-by: tests/Unit/Domain/Service/OverridePriceResolverTest.php::test_variation_override_takes_precedence_over_parent
    # @covered-by: tests/Unit/Domain/Service/OverridePriceResolverTest.php::test_variation_override_does_not_merge_field_wise_with_parent
    # @covered-by: tests/Unit/Domain/Service/OverridePriceResolverTest.php::test_variation_override_with_no_prices_falls_back_to_parent
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_variation_override_does_not_merge_field_wise_with_parent

    Scenario Outline: 依變體層是否有覆寫價決定採用哪一層
      Given 賣場 "夏季賣場" 對商品 103 設定商品層覆寫 <parent>
      And 賣場 "夏季賣場" 對變體 1031 設定變體層覆寫 <variation>
      When 系統為變體 1031 解析生效價格
      Then 生效的原價與特價應為 <effective>

      Examples:
        | parent      | variation   | effective   | 說明                        |
        | 500 / 450   | 900 / 850   | 900 / 850   | 變體層整組生效               |
        | 500 / 450   | 900 / 無    | 900 / 無    | 變體層只設原價也整組生效      |
        | 500 / 450   | 無 / 850    | 無 / 850    | 變體層只設特價也整組生效      |
        | 500 / 450   | 完全未設定   | 500 / 450   | fallback 到商品層            |
        | 完全未設定   | 完全未設定   | 商品原價     | 兩層皆無覆寫                 |

  Rule: 前端（展開）- 可變商品在商品名稱欄提供展開鈕
    # @covered-by: tests/js/componentWiring.test.ts::🔴 關掉 antd 預設的最左展開欄（showExpandColumn: false）
    # @covered-by: tests/js/componentWiring.test.ts::🔴 展開狀態必須受控（expandedRowKeys）
    # @covered-by: tests/js/componentWiring.test.ts::只有可變商品可展開（rowExpandable）

    Example: 展開鈕位於商品圖片左側
      When 管理者 "Admin" 檢視賣場商品表格
      Then 商品 103 的商品名稱欄應在圖片左側顯示展開鈕

    Example: 非可變商品沒有展開鈕
      Given 賣場 "夏季賣場" 的商品列表包含 simple 商品 101
      When 管理者 "Admin" 檢視賣場商品表格
      Then 商品 101 不應顯示展開鈕

    Example: 展開後才載入變體資料
      When 管理者 "Admin" 展開商品 103
      Then 系統應發出一次商品 103 的變體查詢

    Example: 收合後再次展開不重新查詢
      Given 管理者 "Admin" 已展開過商品 103
      When 管理者 "Admin" 收合後再次展開商品 103
      Then 系統不應再次發出變體查詢

    Example: 換頁時清空展開狀態
      Given 管理者 "Admin" 已展開商品 103
      When 管理者 "Admin" 切換賣場商品表格的分頁
      Then 展開狀態應被清空

    Example: 移除已展開的商品不得殘留展開狀態
      Given 管理者 "Admin" 已展開商品 103
      When 管理者 "Admin" 移除商品 103
      Then 展開狀態不應殘留該商品

    Example: 拖曳排序不影響展開狀態的歸屬
      Given 管理者 "Admin" 已展開商品 103
      When 管理者 "Admin" 拖曳調整商品順序
      Then 展開的仍應是商品 103

  Rule: 前端（失效）- 賣場保存了已不存在的變體時必須顯示且可移除
    # @covered-by: tests/js/variationOverrideRows.test.ts::🔴 賣場存了但 WC 已無此變體 → 仍列出，且 record 為 undefined
    # @covered-by: tests/js/variationOverrideRows.test.ts::孤兒排在實際變體之後
    # @covered-by: tests/js/variationOverrideRows.test.ts::全部都是孤兒時照樣列出（商品的變體被整批刪光）
    # @covered-by: tests/js/variationOverrideRows.test.ts::已存在於 WC 的變體不會被重複列出（即使賣場也存了它）

    # 不可靜默隱藏——那會讓一筆仍在資料中、可能影響取價的設定從畫面上消失。

    Example: 已失效的變體顯示標記並提供移除
      Given 賣場 "夏季賣場" 保存了變體 1099 的覆寫但該變體已不存在
      When 管理者 "Admin" 展開商品 103
      Then 變體 1099 應標記為「變體已失效」
      And 變體 1099 應提供移除操作

  Rule: 前端（可變商品）- 商品層的三個欄位改到變體層設定，但殘留值必須顯示
    # @covered-by: tests/js/variationOverrideRows.test.ts::三欄皆 null 時沒有殘留
    # @covered-by: tests/js/variationOverrideRows.test.ts::值為 "0" 也算殘留（0 元覆寫是合法設定：免費贈品）
    # @covered-by: tests/js/variationOverrideRows.test.ts::🔴 值為空字串時仍算殘留——判斷必須是 `null !==`，不可用真值判斷
    # @covered-by: tests/js/variationOverrideRows.test.ts::列出所有有值的欄位
    # @covered-by: tests/js/variationOverrideRows.test.ts::三欄皆 null 時回空字串

    # ⚠️ 「不能只是把輸入框藏起來」：
    #    商品層的覆寫價與灌水購買人數即使沒有輸入框**仍然會生效**
    #    （前者被取價 fallback 使用、後者被賣場卡片的「已售 N 件」使用）。
    #    靜默隱藏會讓站長看不到一個仍在影響售價的設定；
    #    更糟的是若殘留值違反「特價不得高於原價」，送出會被擋下卻找不到欄位可改——死結。

    Example: 可變商品的商品層三欄改為提示文字
      When 管理者 "Admin" 檢視賣場商品表格中的商品 103
      Then 庫存、覆寫價格與灌水購買人數三欄應顯示「依變體設定」

    Example: 商品層仍有殘留覆寫值時必須顯示並提供清除
      Given 商品 103 仍有商品層覆寫價 500 / 450
      When 管理者 "Admin" 檢視賣場商品表格中的商品 103
      Then 畫面應顯示仍有商品層覆寫的提示與其數值
      And 畫面應提供一鍵清除該覆寫的操作

  Rule: 已知落差（前台）- 變體層灌水購買人數目前沒有任何前台顯示點
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_known_gap_variation_inflated_count_has_no_frontend_display

    # ⚠️ specs/tech-debt.md TD-15：賣場前台只渲染父商品卡片，
    #    「已售 N 件」讀的是**商品層**的灌水購買人數；變體層的目前零顯示點。
    #    這是有意識的取捨且已在 UI 上據實揭露，不是承諾後的跳票。
    #
    # ⚠️ 連帶落差（TD-48）：可變商品的商品層灌水購買人數失去設定入口，
    #    而變體層的又沒有顯示點 ⇒ 這兩類商品的「已售 N 件」實際上無法從 0 設成 N。

    Example: 變體層灌水購買人數的欄位提示必須據實說明尚未顯示
      When 管理者 "Admin" 展開商品 103
      Then 變體層灌水購買人數欄位的提示應說明該數值目前僅會儲存而尚未在前台顯示

  Rule: 前置（載入）- 變體清單必須完整載入，不得有筆數上限
    # @covered-by: tests/Integration/Infrastructure/Rest/ProductVariationsEndpointTest.php::test_returns_all_variations_beyond_wc_rest_limit
    # @covered-by: tests/Integration/Infrastructure/Rest/ProductVariationsEndpointTest.php::test_returned_ids_match_product_children_exactly
    # @covered-by: tests/Integration/Infrastructure/Rest/ProductVariationsEndpointTest.php::test_row_shape_matches_frontend_contract
    # @covered-by: tests/Integration/Infrastructure/Rest/ProductVariationsEndpointTest.php::test_guest_is_rejected
    # @covered-by: tests/js/componentWiring.test.ts::🔴 變體查詢打的是自訂端點，不是 wc-rest
    # @covered-by: tests/js/componentWiring.test.ts::🔴 不得殘留任何 wc-rest 的變體查詢路徑
    # @covered-by: tests/js/componentWiring.test.ts::🔴 已無 per_page 上限常數（後端沒有分頁參數可送）

    # ⚠️ 本 Rule 原本是「已知落差（數量）- 變體超過 100 個時只顯示前 100 個」（TD-16）。
    #    上限來自 WooCommerce REST 的 `per_page` 硬限制（`'maximum' => 100`），
    #    而變體層價格覆寫是逐一 variation_id 設定的 ⇒ 第 101 個之後的 SKU
    #    在賣場裡永遠只能賣原價。
    #
    #    2026-08-11 起改走本外掛自訂端點 `GET /power-shop/product-variations/{id}`
    #    （直接讀 `WC_Product_Variable::get_children()`），無上限、不分頁，
    #    落差消失，因此改寫為正向規格。

    Example: 變體超過 100 個時仍完整載入
      Given 商品 103 具有 150 個變體
      When 管理者 "Admin" 展開商品 103
      Then 畫面應列出全部 150 個變體
      And 畫面不應出現任何筆數被截斷的提示

    Example: 變體清單不得包含重複或遺漏
      Given 商品 103 具有 150 個變體
      When 管理者 "Admin" 展開商品 103
      Then 列出的變體識別碼應與該商品的變體集合完全相同
</content>
