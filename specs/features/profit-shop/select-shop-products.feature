@ignore @command
Feature: 透過商品選擇 Modal 加入賣場商品

  店舖管理者在分潤賣場編輯頁點擊「選擇商品」，於 Modal 內使用完整商品篩選器
  與分頁列表跨頁勾選商品，確認後將商品加入賣場商品列表。

  此 Feature 描述的是前端 form 狀態的變更，尚未寫入後端；
  實際持久化見 save-shop-item-overrides.feature。

  Background:
    Given 管理者 "Admin" 已登入後台
    And 系統中已存在以下商品
      | id  | name        | type     | status  | sku      | regular_price |
      | 101 | MacBook Pro | simple   | publish | MBP-2024 | 59900         |
      | 102 | iPhone 15   | simple   | publish | IP15-128 | 29900         |
      | 103 | iPad Air    | variable | publish | IPA-WIF  | 19900         |
      | 104 | AirPods     | simple   | draft   | APP-3RD  | 5900          |
    And 已存在分潤賣場 "夏季賣場" 且其商品列表為
      | product_id | inflated_count |
      | 101        | 30             |
    And 管理者 "Admin" 正在編輯分潤賣場 "夏季賣場"

  Rule: 前置（開啟）- Modal 必須提供與商品列表頁相同的篩選能力
    # @covered-by: NOT-COVERED ProductPickerModal 內嵌 ProductTableBody，屬 React 元件行為（B9 待補）

    Example: 開啟商品選擇 Modal 時應呈現完整篩選器與分頁列表
      When 管理者 "Admin" 點擊「選擇商品」
      Then 商品選擇 Modal 應開啟
      And Modal 內應提供以下篩選條件
        | filter               |
        | s                    |
        | sku                  |
        | type                 |
        | status               |
        | product_category_id  |
        | product_tag_id       |
        | stock_status         |
        | price_range          |
      And Modal 內商品列表應為分頁載入
      And Modal 內不應出現「新增商品」按鈕

  Rule: 前置（勾選）- 已在賣場中的商品不可重複勾選
    # @covered-by: NOT-COVERED getCheckboxProps 三重 disabled，屬 React 元件行為（B9 待補）

    Example: 已加入賣場的商品在 Modal 內應標記且禁選
      When 管理者 "Admin" 點擊「選擇商品」
      Then Modal 內商品 101 應標記為「已加入」
      And Modal 內商品 101 的勾選框應為停用狀態

    Example: 尚未加入的商品可正常勾選
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      Then Modal 的待加入數量應為 1

  Rule: 前置（勾選）- 變體列不可被勾選
    # @covered-by: NOT-COVERED 同上（isVariation 判斷），B9 待補

    Example: 可變商品的變體子列不提供勾選
      Given 商品 103 具有變體 1031 與 1032
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 展開商品 103
      Then 變體 1031 的勾選框應為停用狀態
      And 變體 1032 的勾選框應為停用狀態

  Rule: 中置（跨頁）- 勾選狀態必須跨分頁與篩選條件變更保留
    # @covered-by: NOT-COVERED 以 record 物件累積的跨頁勾選，屬 React 元件行為（B9 待補）

    Example: 切換分頁後既有勾選不應遺失
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      And 管理者 "Admin" 切換至第 2 頁
      And 管理者 "Admin" 勾選商品 104
      Then Modal 的待加入數量應為 2

    Example: 變更篩選條件後既有勾選不應遺失
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      And 管理者 "Admin" 以條件篩選商品列表
        | type   |
        | simple |
      Then Modal 的待加入數量應為 1

  Rule: 中置（隔離）- Modal 的勾選狀態不得與商品列表頁的批次選取互相污染
    # @covered-by: NOT-COVERED Modal 自帶 useState 不接 Jotai atom（TD-8），屬 React 元件行為（B9 待補）

    Example: Modal 勾選不應影響商品列表頁的已選商品
      Given 管理者 "Admin" 在商品列表頁已選取商品 104
      When 管理者 "Admin" 前往分潤賣場編輯頁並點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      And 管理者 "Admin" 關閉 Modal
      And 管理者 "Admin" 返回商品列表頁
      Then 商品列表頁的已選商品應為
        | id  |
        | 104 |

  Rule: 後置（取消）- 取消 Modal 不得變更賣場商品列表
    # @covered-by: NOT-COVERED destroyOnHidden + 關閉時 reset，屬 React 元件行為（B9 待補）

    Example: 勾選後取消 Modal 賣場商品列表應維持原狀
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      And 管理者 "Admin" 取消 Modal
      Then 賣場商品列表應為
        | product_id | inflated_count |
        | 101        | 30             |

  Rule: 後置（加入）- 確認加入後新商品應以預設值附加於賣場商品列表末端
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_put_nested_items_round_trips_through_get

    Example: 確認加入後新商品出現在列表末端且採用預設值
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      And 管理者 "Admin" 勾選商品 103
      And 管理者 "Admin" 點擊「加入賣場」
      Then 商品選擇 Modal 應關閉
      And 賣場商品列表應為
        | product_id | regular_price | sale_price | inflated_count |
        | 101        |               |            | 30             |
        | 102        |               |            | 0              |
        | 103        |               |            | 0              |

  Rule: 後置（加入）- 加入動作不得覆寫既有商品已設定的覆寫值
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_put_variation_overrides_round_trip_through_get
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_only_sale_price_set_leaves_regular_price_null

    Example: 既有商品的價格覆寫與浮報數在加入其他商品後應維持不變
      Given 賣場商品 101 已設定 sale_price 為 "49900"
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      And 管理者 "Admin" 點擊「加入賣場」
      Then 賣場商品 101 的 sale_price 應為 "49900"
      And 賣場商品 101 的 inflated_count 應為 30

  Rule: 後置（加入）- 未勾選任何商品時確認按鈕應為停用狀態
    # @covered-by: NOT-COVERED 確認按鈕 disabled 狀態，屬 React 元件行為（B9 待補）

    Example: 未勾選商品時不可送出
      When 管理者 "Admin" 點擊「選擇商品」
      Then 「加入賣場」按鈕應為停用狀態

  Rule: 後置（加入）- 批次加入必須去重且靜默略過重複項
    # @covered-by: tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php::test_product_id_zero_or_negative_is_silently_skipped
    # @covered-by: NOT-COVERED handleBatchAdd 的雙層 dedupe 住在 ItemsEditor，屬 React 元件行為（B9 待補）

    # Q4 決議下已加入的商品在 Modal 內 checkbox disabled，理論上勾不到；
    # 本 Rule 為縱深防禦，涵蓋「Modal 開啟後賣場 items 被其他來源變更」的競態。

    Example: 批次中含已在賣場的商品時應靜默略過不報錯
      Given Modal 的待加入集合為
        | product_id |
        | 101        |
        | 102        |
      When 管理者 "Admin" 點擊「加入賣場」
      Then 不應出現錯誤訊息
      And 賣場商品列表應為
        | product_id | inflated_count |
        | 101        | 30             |
        | 102        | 0              |
      And 系統應提示實際加入 1 筆

    Example: 批次內部出現同一商品兩次時只加入一次
      Given Modal 的待加入集合為
        | product_id |
        | 102        |
        | 102        |
      When 管理者 "Admin" 點擊「加入賣場」
      Then 賣場商品 102 應只出現 1 次

  Rule: 後置（加入）- 單次批次加入不得超過 100 筆
    # @covered-by: NOT-COVERED 100 筆上限只在前端把關，後端無對應驗證（見 profit-shop.rule.md §12.9）

    # 上限依據：WC REST per_page 上限為 100，商品顯示資訊補齊查詢一次打得完；
    # 且 ItemsEditor 每次按鍵重建整個 items 陣列（ItemsEditor.tsx:106-112），
    # 列數過多會使價格欄輸入明顯延遲。

    Example: 勾選達到上限後不可再勾選
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 已勾選 100 筆商品
      Then 其餘商品的勾選框應為停用狀態
      And Modal 應提示「單次最多加入 100 筆」

    Example: 未達上限時不應出現任何限制提示
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 已勾選 99 筆商品
      Then 商品的勾選框應為可用狀態
      And Modal 不應顯示數量上限提示

  Rule: 後置（加入）- 賣場商品總數超過 500 筆時應警示但不阻擋
    # @covered-by: NOT-COVERED 500 筆軟上限只在前端警示，後端無對應驗證（見 profit-shop.rule.md §12.9）

    Example: 加入後總數超過軟上限應顯示效能警示
      Given 賣場 "夏季賣場" 已有 480 筆商品
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選 30 筆商品
      And 管理者 "Admin" 點擊「加入賣場」
      Then 賣場商品總數應為 510
      And 系統應顯示商品數量過多的效能警示
      And 賣場表單的「儲存」應為可用狀態
