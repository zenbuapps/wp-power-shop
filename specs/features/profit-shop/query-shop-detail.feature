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

  Rule: 已知落差（列表）- 賣場列表不分頁，一次回傳全部
    # @covered-by: NOT-COVERED 本 Rule 描述的是「刻意不提供」的分頁能力，依定義沒有對應實作可測

    # ── 這條 Rule 曾經承諾分頁，2026-08-12 改判為不做 ──────────────
    # 原本寫的是「後置（列表）- 賣場列表支援分頁」，並斷言「回應應包含賣場陣列與
    # 總筆數」「per_page 超過 500 時收斂為 100」。**兩條都與實作相反**：
    #   `V2Api::get_profit_shops_callback` 只讀 `partner_term_id`，
    #   交給 `ListShops::execute( ?int $partner_term_id )` 後直接 `array_map` + `ok( $data )`
    #   ⇒ 沒有 page / per_page 參數，回應的 data 是純陣列、沒有 total 欄位。
    #
    # 改判為「改規格、不補實作」的理由：
    #   1. 站上目前只有 2 個賣場，補分頁是過度設計
    #   2. `admin-list-ui.rule.md` §3.2 路線 B 本來就規定 profit-* 系列走
    #      「後端一次回全部、前端 useMemo 做 client-side 過濾」——
    #      實作與那條規則一致，**是規格寫錯了，不是實作漏做**
    #
    # ⚠️ 保留本 Rule 而非刪除：刪掉會讓「規格曾經承諾過分頁」這個事實消失，
    #    日後有人重新提出時，沒有任何線索說明當初為什麼決定不做。
    #
    # ⚠️ 原本掛在這裡的三個 @covered-by（ListShopsTest 的
    #    test_returns_all_shops_when_no_filter / test_filters_by_partner_term_id /
    #    test_returns_empty_array_when_no_shops）**全部標錯**——那三條只測
    #    「回傳全部」與「依 partner 過濾」，零分頁斷言，已一併移除。
    #
    # ⚠️ 也不要被 `V2Api` 內另一處的 `min( 100, $per_page )` 誤導：
    #    那屬於結算列表 / 夥伴報表用的 FilterCriteria，與本端點無關。
    #
    # 日後若真要補分頁，須連同 `admin-list-ui.rule.md` §3.2 的路線選擇一起重新評估，
    # 不能只改後端——前端的 client-side 過濾會與 server-side 分頁互相矛盾。

    Example: 查詢賣場列表一次回傳全部賣場
      When 管理者 "Admin" 查詢賣場列表
      Then 回應的 data 應為賣場的純陣列
      And 回應不應包含總筆數欄位

    Example: 列表端點不接受分頁參數
      When 管理者 "Admin" 以 per_page 為 500 查詢賣場列表
      Then 該參數應被忽略
      And 回應仍應包含全部賣場

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
