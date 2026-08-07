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
    # @covered-by: NOT-COVERED ItemsEditor 商品欄的 _enrichState 四分支顯示，屬 React 元件行為（B9 待補）

    Example: 補齊成功後商品欄應顯示名稱與 SKU
      Given 已存在分潤賣場 "夏季賣場" 且其商品列表為
        | product_id |
        | 101        |
      When 管理者 "Admin" 進入賣場 "夏季賣場" 的編輯頁
      Then 賣場商品列表應呈現
        | product_id | name        | sku      |
        | 101        | MacBook Pro | MBP-2024 |

  Rule: 後置（失效）- 商品已刪除時必須標記失效且仍可移除
    # @covered-by: NOT-COVERED 「商品已失效」Tag 與移除鈕，屬 React 元件行為（B9 待補）

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
    # @covered-by: NOT-COVERED 補齊查詢失敗不阻擋儲存，屬 React 元件行為（B9 待補）；後端儲存路徑本身不依賴補齊查詢，見 ProfitShopItemsRoundTripTest 全檔

    Example: 補齊查詢失敗時仍應可編輯與儲存賣場
      Given 已存在分潤賣場 "夏季賣場" 且其商品列表為
        | product_id |
        | 101        |
      And 商品補齊查詢將失敗
      When 管理者 "Admin" 進入賣場 "夏季賣場" 的編輯頁
      Then 賣場商品 101 應以 "#101" 呈現
      And 賣場表單的「儲存」應為可用狀態
