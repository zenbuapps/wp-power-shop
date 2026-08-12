@ignore @query
Feature: 補齊賣場商品的顯示資訊

  分潤賣場的 items 只保存 product_id 與覆寫設定，不保存商品名稱、SKU 與縮圖。
  賣場編輯頁載入後必須另行取得商品當下資訊，以免商品欄只顯示 #id。

  # 設計取捨：不由 power-shop 的 ProfitShopOutput 夾帶 name / sku，
  # 因為那屬於商品「當下狀態」而非賣場設定，夾帶會產生 stale 顯示
  #（商品改名後賣場仍顯示舊名）。改由前端以 wc-rest 批次補齊，
  # 與商品選擇 Modal 同源，可共用 react-query 快取。

  Background:
    Given 管理者 "Admin" 已登入後台
    And 系統中已存在以下商品
      | id  | name        | type   | status  | sku      |
      | 101 | MacBook Pro | simple | publish | MBP-2024 |
      | 102 | iPhone 15   | simple | publish | IP15-128 |

  Rule: 前置（參數）- 僅在賣場含有商品時才發出補齊查詢
    # @covered-by: tests/js/shopItemChunks.test.ts::賣場沒有商品時每一塊都是空的（= 五個查詢全部停用）
    # @covered-by: tests/js/shopItemChunks.test.ts::只有一筆商品時，只有第一塊非空，其餘四個查詢仍停用

    Example: 賣場沒有商品時不應發出補齊查詢
      Given 已存在分潤賣場 "空賣場" 且其商品列表為空
      When 管理者 "Admin" 進入賣場 "空賣場" 的編輯頁
      Then 系統不應發出商品補齊查詢

  Rule: 前置（參數）- 補齊查詢必須以賣場 items 的 product_id 集合為範圍
    # @covered-by: tests/js/shopItemChunks.test.ts::查詢範圍恰好等於 items 的 product_id 集合，不多不少
    # @covered-by: tests/js/shopItemChunks.test.ts::每塊最多 100 筆（WC REST 的 per_page 硬上限）
    # @covered-by: tests/js/shopItemChunks.test.ts::🔴 拖曳排序（同一組 id、不同順序）必須得到逐字相同的分塊

    Example: 補齊查詢應一次帶入所有 product_id
      Given 已存在分潤賣場 "夏季賣場" 且其商品列表為
        | product_id |
        | 101        |
        | 102        |
      When 管理者 "Admin" 進入賣場 "夏季賣場" 的編輯頁
      Then 系統應發出一次商品補齊查詢
        | include |
        | 101,102 |

  Rule: 後置（回應）- 補齊後每筆賣場商品應呈現名稱與 SKU
    # @covered-by: tests/js/shopItemDisplayState.test.ts::補齊查到了 → ok，並帶出完整商品
    # @covered-by: tests/js/shopItemDisplayState.test.ts::剛從 Modal 加入、補齊還沒回來 → partial（先用 Modal 帶的名稱撐著）
    # @covered-by: tests/js/shopItemDisplayState.test.ts::查到了就是 ok，即使還有其他查詢在載入中

    Example: 補齊成功後商品欄應顯示名稱與 SKU
      Given 已存在分潤賣場 "夏季賣場" 且其商品列表為
        | product_id |
        | 101        |
      When 管理者 "Admin" 進入賣場 "夏季賣場" 的編輯頁
      Then 賣場商品列表應呈現
        | product_id | name        | sku      |
        | 101        | MacBook Pro | MBP-2024 |

  Rule: 後置（失效）- 商品已刪除時必須標記失效且仍可移除
    # @covered-by: tests/js/shopItemDisplayState.test.ts::查詢成功但查無此 id → missing（商品已失效）
    # @covered-by: tests/js/shopItemDisplayState.test.ts::🔴 isLoading 勝過 missing——載入中不得先標成「已失效」

    Example: 商品已刪除時應標記失效並保留移除操作
      Given 已存在分潤賣場 "夏季賣場" 且其商品列表為
        | product_id |
        | 101        |
        | 777        |
      And 商品 777 已不存在
      When 管理者 "Admin" 進入賣場 "夏季賣場" 的編輯頁
      Then 賣場商品 777 應標記為「商品已失效」
      And 賣場商品 777 的「移除」操作應為可用狀態
      And 賣場商品 101 應正常顯示名稱

  Rule: 後置（降級）- 補齊查詢失敗不得阻擋賣場編輯
    # @covered-by: tests/js/shopItemDisplayState.test.ts::🔴 查詢失敗 → failed，不得與 missing 混為一談
    # @covered-by: tests/js/shopItemDisplayState.test.ts::isError 與 isLoading 同時為真時，仍以 pending 為準（查詢尚未結束）

    # ── 2026-08-12 移除一個錯標；本 Rule 目前是**部分覆蓋** ──────────
    # 原本另外標了 `ProfitShopItemsRoundTripTest::test_put_nested_items_round_trips_through_get`，
    # 那是**後端 PUT → GET 的持久化 round-trip**，與「補齊查詢失敗時的降級」語意無關，已移除。
    #
    # ✅ 已覆蓋（上面兩條 vitest）：狀態判定本身——
    #    `failed` 與 `missing` 不得混為一談、載入中不得先標成失敗。
    #    這一半是本 Rule 最容易寫錯的地方，覆蓋良好。
    #
    # ❌ 未覆蓋：Example 的兩個斷言主體——
    #    (1)「賣場商品 101 應以 `#101` 呈現」：`failed` 狀態下的 JSX 降級渲染
    #    (2)「賣場表單的『儲存』應為可用狀態」：跨元件的表單可用性，需要 e2e
    #
    # 要補什麼：(1) 可用 vitest + testing-library 對該列的渲染輸出斷言；
    # (2) 屬於跨元件狀態，較適合 e2e——在補齊查詢被攔成失敗的情況下，
    #     斷言儲存按鈕仍可按且送出成功。
    #
    # ⚠️ 這條的實際風險比看起來高：`specs/tech-debt.md` TD-7 記載
    #    Powerhouse 對 403 會跳全域 confirm 對話框**卡住整頁**，
    #    與本 Rule 的承諾直接衝突，而成因不在本 repo 內。
    #    補 e2e 時要把那個情境一起納入，否則會測出「我們這半沒問題」的假象。

    Example: 補齊查詢失敗時仍應可編輯與儲存賣場
      Given 已存在分潤賣場 "夏季賣場" 且其商品列表為
        | product_id |
        | 101        |
      And 商品補齊查詢將失敗
      When 管理者 "Admin" 進入賣場 "夏季賣場" 的編輯頁
      Then 賣場商品 101 應以 "#101" 呈現
      And 賣場表單的「儲存」應為可用狀態
