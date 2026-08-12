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
    # @covered-by: tests/js/componentWiring.test.ts::🔴 複用商品列表頁的 ProductTableBody，而非自己刻一份篩選器
    # @covered-by: tests/js/componentWiring.test.ts::🔴 useTable 明確關閉 syncWithLocation

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
    # @covered-by: tests/js/productPickerSelection.test.ts::已在賣場中的商品不可重複勾選
    # @covered-by: tests/js/productPickerSelection.test.ts::🔴 id 是字串也要能與 number 的 existingIds 比對上（R-2）
    # @covered-by: tests/js/productPickerSelection.test.ts::達單次批次上限後，未選的列被停用
    # @covered-by: tests/js/productPickerSelection.test.ts::🔴 達上限時，**已選**的列仍必須可以取消勾選
    # @covered-by: tests/js/productPickerSelection.test.ts::停用條件的優先序：變體 > 已在賣場 > 批次上限

    Example: 已加入賣場的商品在 Modal 內應標記且禁選
      When 管理者 "Admin" 點擊「選擇商品」
      Then Modal 內商品 101 應標記為「已加入」
      And Modal 內商品 101 的勾選框應為停用狀態

    Example: 尚未加入的商品可正常勾選
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      Then Modal 的待加入數量應為 1

  Rule: 前置（勾選）- 變體列不可被勾選
    # @covered-by: tests/js/productPickerSelection.test.ts::變體列不可被勾選
    # @covered-by: tests/js/productPickerSelection.test.ts::一般商品可勾選

    Example: 可變商品的變體子列不提供勾選
      Given 商品 103 具有變體 1031 與 1032
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 展開商品 103
      Then 變體 1031 的勾選框應為停用狀態
      And 變體 1032 的勾選框應為停用狀態

  Rule: 中置（跨頁）- 勾選狀態必須跨分頁與篩選條件變更保留
    # @covered-by: tests/js/productPickerSelection.test.ts::🔴 換頁後前一頁的選取必須保留
    # @covered-by: tests/js/productPickerSelection.test.ts::新勾選的列被併進既有選取集合
    # @covered-by: tests/js/productPickerSelection.test.ts::重複勾選同一筆不會累積兩份
    # @covered-by: tests/js/productPickerSelection.test.ts::取消勾選只移除指定的列

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
    # @covered-by: tests/js/productPickerSelection.test.ts::不 import 商品列表頁的全域選取 atom
    # @covered-by: tests/js/productPickerSelection.test.ts::選取狀態走元件區域 useState

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
    # @covered-by: tests/js/componentWiring.test.ts::🔴 取消時走 handleClose（會 reset 選取），不是 onConfirm
    # @covered-by: tests/js/componentWiring.test.ts::🔴 onCancel 必須放在 ...modalProps 之後
    # @covered-by: tests/js/componentWiring.test.ts::關閉時不掛載 children（destroyOnHidden）

    Example: 勾選後取消 Modal 賣場商品列表應維持原狀
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      And 管理者 "Admin" 取消 Modal
      Then 賣場商品列表應為
        | product_id | inflated_count |
        | 101        | 30             |

  Rule: 後置（加入）- 確認加入後新商品應以預設值附加於賣場商品列表末端
    # @covered-by: NOT-COVERED 零覆蓋——實作是未 export 的元件內函式，目前沒有任何測試碰得到

    # ── 2026-08-12 移除一個錯標 ────────────────────────────────
    # 原本標 `ProfitShopItemsRoundTripTest::test_put_nested_items_round_trips_through_get`。
    # 那條測的是**後端** PUT → GET 的持久化 round-trip，完全不經過商品選擇 Modal 的加入路徑；
    # 本 Rule 講的是**前端 form state**：新商品附加於**末端**、且採
    # `inflated_count: 0` 與三個價格欄位皆 null 的預設值。兩件事。
    #
    # 實作在 `ItemsEditor.tsx` 的 `handleBatchAdd`（約 :357）：
    #   toAdd.push({ product_id, inflated_count: 0, override: emptyPriceOverride(), variations: {} })
    #   const next = [ ...items, ...toAdd ]
    #
    # ── 🔴 要補什麼（本檔四條零覆蓋的 Rule 共用同一個前提）──────────
    # `handleBatchAdd` 是元件內的 inline `useCallback`、**未 export**
    #（`grep -rn handleBatchAdd tests/` 只命中註解、零測試），現況下測不到。
    # 補法：先抽成純函式（例如 `utils.ts` 的 `appendPickedItems( items, records )`
    # 與 `shouldWarnSoftLimit( total )`），再以 vitest 覆蓋。
    # vitest **已進 CI**（`.github/workflows/pipe.yml` 的 `pnpm test:js`），補了就有 CI 守著。
    #
    # 本 Rule 該斷言的是：附加位置在末端、四個預設值正確、既有列不被改動。

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
    # @covered-by: NOT-COVERED 零覆蓋——同上，實作在未 export 的 handleBatchAdd 內

    # ── 2026-08-12 移除兩個錯標 ────────────────────────────────
    # 原本標 `ProfitShopItemsRoundTripTest` 的 `test_put_variation_overrides_round_trip_through_get`
    # 與 `test_only_sale_price_set_leaves_regular_price_null`。兩條都是**後端 REST round-trip**，
    # 完全不經過 Modal 加入路徑——它們證明的是「送什麼進去就讀得出什麼」，
    # 而本 Rule 要防的是「**加入新商品這個動作**會不會順手沖掉既有列已設定的值」。
    #
    # 實作靠的是 `const next = [ ...items, ...toAdd ]`——既有列的 object reference
    # 完全不動，已設定的價格覆寫與浮報數因此不可能被沖掉。
    #
    # 補法同本檔第一條（抽純函式 + vitest）。本 Rule 該斷言的是：
    # 加入後既有列的欄位值與 reference 皆不變，只有陣列尾端多出新列。

    Example: 既有商品的價格覆寫與浮報數在加入其他商品後應維持不變
      Given 賣場商品 101 已設定 sale_price 為 "49900"
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選商品 102
      And 管理者 "Admin" 點擊「加入賣場」
      Then 賣場商品 101 的 sale_price 應為 "49900"
      And 賣場商品 101 的 inflated_count 應為 30

  Rule: 後置（加入）- 未勾選任何商品時確認按鈕應為停用狀態
    # @covered-by: tests/js/componentWiring.test.ts::🔴 未勾選任何商品時確認按鈕停用

    Example: 未勾選商品時不可送出
      When 管理者 "Admin" 點擊「選擇商品」
      Then 「加入賣場」按鈕應為停用狀態

  Rule: 後置（加入）- 批次加入必須去重且靜默略過重複項
    # @covered-by: NOT-COVERED 零覆蓋——同上，實作在未 export 的 handleBatchAdd 內

    # ── 🔴 2026-08-12 移除兩個錯標，其中一個斷言的是相反命題 ──────────
    # ① 原本標 `ProfitShopHydratorTest::test_product_id_zero_or_negative_is_silently_skipped`：
    #    那條測的是 `product_id <= 0` 的項目被靜默跳過，與「同一批次內去重」無關。
    #
    # ② 🔴 原本標 `ProfitShopKnownGapsTest::test_backend_rejects_duplicate_product_in_same_shop`：
    #    那條 `assertSame( 409, … )`——斷言後端**會**對重複商品**報錯**，
    #    而本 Rule 明文要求「**不應**出現錯誤訊息」並「提示實際加入 1 筆」。
    #    **它斷言的是相反命題**：這個標記不只是沒覆蓋，還會讓讀的人以為
    #    「重複商品會被擋下」，而前端實際的設計是靜默略過、不打擾使用者。
    #    這正是本輪盤點被 13 條錯標騙過一次才發現的那種形狀。
    #
    # 實作：`handleBatchAdd` 以 `seenInBatch` / `alreadyInShop` 兩個 Set 做雙層去重，
    # 全部重複時走 `notification.info( '選取的商品都已在賣場中' )`，
    # 否則 `notification.success( '已加入 N 筆' )`——兩條路徑都**不是**錯誤。
    #
    # 補法同本檔第一條（抽純函式 + vitest）。本 Rule 該斷言的是：
    # 批次內重複只加入一次、已在賣場者被略過、以及兩種提示各自出現的條件。

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
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopKnownGapsTest.php::test_known_gap_backend_accepts_more_items_than_frontend_limit

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
    # @covered-by: NOT-COVERED 零覆蓋——同上，實作在未 export 的 handleBatchAdd 內

    # ── 2026-08-12 移除一個錯標 ────────────────────────────────
    # 原本標 `ProfitShopKnownGapsTest::test_known_gap_backend_accepts_more_items_than_frontend_limit`。
    # 那條只送 **105 筆**斷言後端會接受（它針對的是「單次批次 100 筆」那個前端上限），
    # **既沒有觸及 500 這個門檻，也沒有驗證警示出現、更沒有驗證儲存仍可用**。
    # 本 Rule 的三個斷言（總數變 510 / 顯示效能警示 / 儲存仍為可用）它一個都沒碰。
    #
    # 實作：`handleBatchAdd` 在 `next.length > SOFT_ITEM_LIMIT` 時發
    # `notification.warning`，文案明寫「仍可正常儲存」——**警示而不阻擋**是刻意的。
    #
    # 補法同本檔第一條，另建議把門檻判斷抽成 `shouldWarnSoftLimit( total )` 純函式。
    # 本 Rule 該斷言的是：跨越門檻時才警示、未跨越時不警示、且兩種情況都不阻擋儲存。

    Example: 加入後總數超過軟上限應顯示效能警示
      Given 賣場 "夏季賣場" 已有 480 筆商品
      When 管理者 "Admin" 點擊「選擇商品」
      And 管理者 "Admin" 勾選 30 筆商品
      And 管理者 "Admin" 點擊「加入賣場」
      Then 賣場商品總數應為 510
      And 系統應顯示商品數量過多的效能警示
      And 賣場表單的「儲存」應為可用狀態
