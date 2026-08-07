@ignore @query
Feature: 查詢分潤賣場詳情與列表

  店舖管理者在後台檢視賣場列表，並於編輯頁載入單一賣場的完整設定。
  賣場資料只保存設定本身，不夾帶商品的當下資訊。

  # ── 為什麼 items 不夾帶商品名稱與 SKU ─────────────────────
  # 那屬於商品的「當下狀態」而非賣場設定，夾帶會產生 stale 顯示
  #（商品改名後賣場仍顯示舊名）。商品顯示資訊由前端另行補齊，
  # 見 enrich-shop-item-display.feature。
  #
  # ── 回應格式與夥伴端點不同 ────────────────────────────────
  # 管理端點一律回傳 {code, message, data} 包裹形狀。

  Background:
    Given 管理者 "Admin" 已登入後台
    And 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其設定為
      | id   | slug        | status  | mode | partner_term_id | rate |
      | 1932 | summer-sale | publish | page | 5               | 15   |

  Rule: 後置（詳情）- 單一賣場回應必須包含完整設定欄位
    # @covered-by: tests/Integration/Application/ProfitShopCrudTest.php::test_get_shop_returns_full_output
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_get_response_items_contain_full_contract_fields
    # @covered-by: tests/Unit/Application/UseCase/Shop/GetShopTest.php::test_returns_shop_output_when_id_exists
    # @covered-by: tests/Unit/Application/UseCase/Shop/GetShopTest.php::test_throws_not_found_when_id_does_not_exist

    Example: 查詢賣場詳情
      When 管理者 "Admin" 查詢賣場 1932
      Then 回應應包含以下欄位
        | field           |
        | id              |
        | title           |
        | slug            |
        | status          |
        | mode            |
        | partner_term_id |
        | rate            |
        | items           |
        | settings        |
        | created_at      |
        | updated_at      |

    Example: 賣場商品項目採巢狀契約
      Given 賣場 "夏季賣場" 的商品列表包含商品 101
      When 管理者 "Admin" 查詢賣場 1932
      Then 每一筆商品項目應包含以下欄位
        | field          |
        | product_id     |
        | inflated_count |
        | override       |
        | variations     |

    Example: 查詢不存在的賣場回 404
      When 管理者 "Admin" 查詢 id 為 999999 的賣場
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

    Example: 查詢已刪除的賣場回 404
      Given 賣場 "夏季賣場" 已被刪除
      When 管理者 "Admin" 查詢賣場 1932
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 後置（詳情）- 回應不得夾帶商品的當下資訊
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php::test_get_response_items_contain_full_contract_fields

    Example: 商品項目不包含名稱與 SKU
      When 管理者 "Admin" 查詢賣場 1932
      Then 商品項目不應包含 name 欄位
      And 商品項目不應包含 sku 欄位

  Rule: 後置（列表）- 賣場列表支援分頁
    # @covered-by: tests/Unit/Application/UseCase/Shop/ListShopsTest.php::test_returns_all_shops_when_no_filter
    # @covered-by: tests/Unit/Application/UseCase/Shop/ListShopsTest.php::test_filters_by_partner_term_id
    # @covered-by: tests/Unit/Application/UseCase/Shop/ListShopsTest.php::test_returns_empty_array_when_no_shops

    Example: 查詢賣場列表
      When 管理者 "Admin" 查詢賣場列表
      Then 回應應包含賣場陣列與總筆數

    Example: 列表分頁參數超過上限時收斂
      When 管理者 "Admin" 以 per_page 為 500 查詢賣場列表
      Then 實際生效的 per_page 應為 100

  Rule: 前端（填表）- 編輯頁載入後不得被背景重取資料覆蓋
    # @covered-by: tests/js/editFormGuards.test.ts::🔴 同一筆 record 再次抵達時不得重填
    # @covered-by: tests/js/editFormGuards.test.ts::首次拿到 record 時填表
    # @covered-by: tests/js/editFormGuards.test.ts::切換到不同 id 的編輯頁時要重新填
    # @covered-by: tests/js/editFormGuards.test.ts::record 尚未載入（undefined）時不填

    # React Query 在視窗重新取得焦點時會重取資料，若無條件回填表單，
    # 使用者打字到一半的內容會被覆蓋。

    Example: 同一筆賣場只在首次載入時填入表單
      Given 管理者 "Admin" 正在編輯賣場 1932 且已修改名稱尚未儲存
      When 瀏覽器視窗重新取得焦點
      Then 表單中已修改的名稱應維持不變

    Example: 切換到另一筆賣場時重新填表
      Given 管理者 "Admin" 正在編輯賣場 1932
      When 管理者 "Admin" 改為編輯另一個賣場
      Then 表單應填入新賣場的資料

  Rule: 前端（列表）- 列表頁採篩選卡片加實體列表的標準版型
    # @covered-by: tests/e2e/06-profit-shop/profit-shop-admin-ui.spec.ts::賣場列表採「篩選卡片 + 實體列表」標準版型

    Example: 列表頁呈現兩張卡片
      When 管理者 "Admin" 開啟賣場列表頁
      Then 畫面應包含一張「篩選」卡片
      And 畫面應包含一張含新增按鈕與表格的列表卡片

    Example: 名稱欄同時呈現名稱與 id 且可點擊進入編輯頁
      When 管理者 "Admin" 開啟賣場列表頁
      Then 每一列的名稱應同時呈現名稱與 #id
      And 點擊名稱應進入該賣場的編輯頁

    Example: 重置篩選後畫面回到未篩選狀態
      Given 管理者 "Admin" 已套用關鍵字篩選
      When 管理者 "Admin" 點擊「重置」
      Then 列表應回到未篩選狀態
</content>
