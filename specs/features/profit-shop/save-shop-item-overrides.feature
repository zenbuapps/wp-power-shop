@ignore @command
Feature: 儲存賣場商品的價格覆寫設定

  店舖管理者在分潤賣場編輯頁調整商品的價格覆寫與浮報數後儲存，
  設定必須完整持久化並在重新載入後原樣還原。

  # 契約基準（BUG-A 修補後）
  # items 單筆一律採 Domain 巢狀形狀，與 OverrideItem::to_array() 對齊：
  #   { product_id, inflated_count,
  #     override:   { regular_price, sale_price, signup_fee },
  #     variations: { <variation_id>: { override: { … } } } }
  #
  # 修補前 specs/api/api.yml 與前端型別使用扁平的
  #   { product_id, variation_id, price_override, inflated_count }
  # 而 ProfitShopHydrator::build_override_item() 只讀 override / variations，
  # 導致 price_override 與 variation_id 於儲存時被靜默丟棄。
  #
  # ── 修補範圍邊界（硬性） ──────────────────────────────────
  # 本 Feature 只涵蓋「讓前端送出的巢狀 items 能被正確寫入 / 讀出」。
  # 不得觸碰下列既有實作：
  #   - Domain/Service/PriceCalculator（fallback chain）
  #   - Infrastructure/WooCommerce/CartPriceOverrideHook（HMAC 簽章邏輯）
  #   - Domain/Entity/OverrideItem、Domain/ValueObject/PriceOverride
  # 若實作時發現修正必須動到 Domain 層 → 先回報再繼續。
  #
  # 下列 Rule 中「非負十進位字串」「sale_price 不得高於 regular_price」
  # 皆為 PriceOverride VO 既有的 invariant，本次僅確認其在
  # 前端送出 → Hydrator → Repository 這條路徑上真的被觸發，不修改 VO 本身。

  Background:
    Given 管理者 "Admin" 已登入後台
    And 系統中已存在以下商品
      | id  | name        | type   | status  |
      | 101 | MacBook Pro | simple | publish |
      | 102 | iPhone 15   | simple | publish |
    And 已存在分潤賣場 "夏季賣場"

  Rule: 前置（參數）- override 各價格欄位若提供必須為非負十進位字串
    # @covered-by: tests/Unit/Domain/ValueObject/PriceOverrideTest.php::test_construct_throws_when_regular_price_is_negative
    # @covered-by: tests/Unit/Domain/ValueObject/PriceOverrideTest.php::test_construct_throws_when_price_is_non_numeric_string
    # @covered-by: tests/Unit/Domain/ValueObject/PriceOverrideTest.php::test_zero_prices_are_valid
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_negative_regular_price_returns_400_validation_failed
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_non_numeric_regular_price_returns_400_validation_failed

    Scenario Outline: 傳入非法價格字串時系統應拒絕儲存
      When 管理者 "Admin" 儲存賣場 "夏季賣場" 的商品設定
        | product_id | regular_price |
        | 101        | <price>       |
      Then 系統應回傳驗證錯誤
        | code              |
        | validation_failed |

      Examples:
        | price  |
        | -100   |
        | abc    |

  Rule: 前置（參數）- sale_price 不得高於 regular_price
    # @covered-by: tests/Unit/Domain/ValueObject/PriceOverrideTest.php::test_construct_throws_when_sale_price_greater_than_regular
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_sale_price_greater_than_regular_price_returns_400_validation_failed
    # @covered-by: tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php::test_sale_price_greater_than_regular_price_throws_invalid_price_override

    Example: 特價高於原價時系統應拒絕儲存
      When 管理者 "Admin" 儲存賣場 "夏季賣場" 的商品設定
        | product_id | regular_price | sale_price |
        | 101        | 50000         | 60000      |
      Then 系統應回傳驗證錯誤
        | code              |
        | validation_failed |

  Rule: 前置（參數）- product_id 必須對應存在的商品
    # @covered-by: tests/Unit/Application/Service/ItemValidatorTest.php::test_throws_product_not_found_when_product_missing
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_nonexistent_product_id_returns_404_not_found

    Example: 商品不存在時系統應拒絕儲存
      When 管理者 "Admin" 儲存賣場 "夏季賣場" 的商品設定
        | product_id | regular_price |
        | 999        | 50000         |
      Then 系統應回傳錯誤
        | code      |
        | not_found |

  Rule: 後置（持久化）- 價格覆寫必須完整寫入且可原樣讀回
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_put_nested_items_round_trips_through_get
    # @covered-by: tests/Integration/Infrastructure/Persistence/CptProfitShopRepositoryTest.php::test_find_returns_aggregate_with_items

    Example: 儲存後重新查詢應還原相同的覆寫值
      When 管理者 "Admin" 儲存賣場 "夏季賣場" 的商品設定
        | product_id | regular_price | sale_price | inflated_count |
        | 101        | 50000         | 49900      | 30             |
      And 管理者 "Admin" 重新查詢賣場 "夏季賣場"
      Then 賣場商品列表應為
        | product_id | regular_price | sale_price | signup_fee | inflated_count |
        | 101        | 50000         | 49900      |            | 30             |

  Rule: 後置（持久化）- 未提供的價格欄位必須保存為 null 代表沿用原價
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_only_sale_price_set_leaves_regular_price_null
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_both_price_fields_empty_persists_as_null
    # @covered-by: tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php::test_missing_override_key_results_in_all_null_price_fields
    # @covered-by: tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php::test_empty_string_regular_price_is_treated_as_null

    Example: 只設定特價時原價欄位應為 null
      When 管理者 "Admin" 儲存賣場 "夏季賣場" 的商品設定
        | product_id | sale_price |
        | 101        | 49900      |
      And 管理者 "Admin" 重新查詢賣場 "夏季賣場"
      Then 賣場商品 101 的 regular_price 應為 null
      And 賣場商品 101 的 sale_price 應為 "49900"

    Example: 兩個價格欄位皆留空時應完全沿用原價
      When 管理者 "Admin" 儲存賣場 "夏季賣場" 的商品設定
        | product_id | inflated_count |
        | 102        | 0              |
      And 管理者 "Admin" 重新查詢賣場 "夏季賣場"
      Then 賣場商品 102 的 regular_price 應為 null
      And 賣場商品 102 的 sale_price 應為 null
      And 賣場商品 102 的 signup_fee 應為 null

  Rule: 後置（持久化）- 儲存必須以整體覆寫語意處理 items
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_update_with_fewer_items_removes_missing_products

    Example: 未包含在送出 items 中的商品應自賣場移除
      Given 賣場 "夏季賣場" 的商品列表為
        | product_id | inflated_count |
        | 101        | 30             |
        | 102        | 10             |
      When 管理者 "Admin" 儲存賣場 "夏季賣場" 的商品設定
        | product_id | inflated_count |
        | 101        | 30             |
      And 管理者 "Admin" 重新查詢賣場 "夏季賣場"
      Then 賣場商品列表應為
        | product_id | inflated_count |
        | 101        | 30             |

  Rule: 後置（回應）- items 每筆必須包含契約定義的完整欄位
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_get_response_items_contain_full_contract_fields
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_put_flat_price_override_is_ignored_and_get_reflects_null
    # @covered-by: tests/Unit/Domain/Entity/OverrideItemTest.php::test_to_array_serializes_per_spec_2_3

    Example: 查詢賣場時每筆商品項目應包含指定欄位
      When 管理者 "Admin" 重新查詢賣場 "夏季賣場"
      Then 回應中每筆商品項目應包含以下欄位
        | field          |
        | product_id     |
        | inflated_count |
        | override       |
        | variations     |
      And 每筆 override 應包含以下欄位
        | field         |
        | regular_price |
        | sale_price    |
        | signup_fee    |
