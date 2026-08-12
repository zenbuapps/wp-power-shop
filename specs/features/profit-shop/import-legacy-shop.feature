@ignore @command
Feature: 匯入舊版一頁商店為分潤賣場

  站長把舊版「一頁商店」的資料匯入成新版分潤賣場。舊版沒有分潤夥伴的概念，
  因此匯入時必須由站長指定一位夥伴。

  # ── 這是不可逆操作 ───────────────────────────────────────
  # 匯入會新建一個賣場；重複匯入同一筆舊資料會產生重複賣場。
  # 前端因此設了四道閘門，且 Modal 強制走確認按鈕（不可 ESC / 點遮罩關閉）。
  #
  # ── 匯入不刪除舊版資料 ───────────────────────────────────
  # 新舊系統零共享資料、零共享 hook，舊版繼續可用。

  Background:
    Given 管理者 "Admin" 已登入後台且具備 manage_options 能力
    And 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 系統中存在以下舊版一頁商店
      | legacy_id | title      | slug         | importable |
      | 7001      | 舊版賣場A   | old-shop-a   | 是          |
      | 7002      | 舊版賣場B   |              | 是          |

  Rule: 權限（寫入）- 匯入需要 manage_options
    # @covered-by: tests/Integration/Application/LegacyImporterTest.php::test_import_requires_manage_options
    # @covered-by: tests/Integration/Application/LegacyImporterTest.php::test_import_rejects_unauthenticated_request

    # ⚠️ 本 Rule 原先掛的是 LegacyImporterTest::test_list_legacy_shops_returns_200，
    #    而那條是以 administrator 呼叫 **GET 列表**並斷言 200——既不是 import 端點、
    #    也沒有驗證任何拒絕行為 ⇒ 把 import 的 permission 從 manage_options 降成
    #    manage_woocommerce，整個測試套件一條都不會紅。
    #    那條測試沒有對應到本 feature 的任何 Rule，因此不再掛任何 @covered-by。
    #
    # 兩條新測試除了狀態碼，都一併斷言「powershop 數量前後不變」——只驗狀態碼
    # 擋不住「回了 403、但賣場其實已經建好了」這種檢查排在寫入之後的壞法。

    Example: 僅具備 manage_woocommerce 者不得匯入
      Given 使用者僅具備 manage_woocommerce 能力
      When 該使用者執行匯入
      Then 系統應拒絕該請求

  Rule: 前置（參數）- 必須指定存在的分潤夥伴
    # @covered-by: tests/Unit/Application/UseCase/Migration/ImportLegacyShopTest.php::test_throws_legacy_shop_not_importable_when_partner_term_id_missing
    # @covered-by: tests/Unit/Application/UseCase/Migration/ImportLegacyShopTest.php::test_throws_partner_not_found_when_partner_term_id_invalid
    # @covered-by: tests/Integration/Application/LegacyImporterTest.php::test_import_without_partner_term_id_returns_422

    Example: 未指定夥伴時拒絕匯入
      When 管理者 "Admin" 匯入舊版賣場 7001 且未指定夥伴
      Then 系統應回傳錯誤
        | status | code                |
        | 422    | legacy_unimportable |
      And 回應的 reason 應為 "partner_term_missing"

    Example: 指定不存在的夥伴時拒絕匯入
      When 管理者 "Admin" 匯入舊版賣場 7001 並指定 partner_term_id 999999
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 前置（來源）- 舊版資料必須存在
    # @covered-by: tests/Unit/Application/UseCase/Migration/ImportLegacyShopTest.php::test_throws_legacy_shop_not_importable_when_legacy_id_invalid
    # @covered-by: tests/Unit/Application/UseCase/Migration/ListImportableLegacyShopsTest.php::test_returns_empty_when_no_legacy_shops

    Example: 舊版資料不存在時拒絕匯入
      When 管理者 "Admin" 匯入舊版賣場 999999 並指定夥伴 5
      Then 系統應回傳錯誤
        | status | code                |
        | 422    | legacy_unimportable |
      And 回應的 reason 應為 "legacy_not_found"

  Rule: 後置（匯入）- 匯入後產生草稿賣場，不直接上線
    # @covered-by: tests/Unit/Application/UseCase/Migration/ImportLegacyShopTest.php::test_imports_legacy_shop_with_valid_partner
    # @covered-by: tests/Integration/Application/LegacyImporterTest.php::test_import_with_partner_creates_new_profit_shop

    Example: 匯入成功後新賣場為草稿
      When 管理者 "Admin" 匯入舊版賣場 7001 並指定夥伴 5
      Then 系統應回傳新建的賣場
      And 新賣場的狀態應為 "draft"
      And 新賣場的模式應為 "page"
      And 新賣場的分潤夥伴應為 5

    Example: 名稱與永久連結沿用舊版資料
      When 管理者 "Admin" 匯入舊版賣場 7001 並指定夥伴 5
      Then 新賣場的名稱應為 "舊版賣場A"
      And 新賣場的永久連結應為 "old-shop-a"

    Example: 舊版沒有永久連結時以來源 id 產生一個
      When 管理者 "Admin" 匯入舊版賣場 7002 並指定夥伴 5
      Then 新賣場的永久連結應包含來源 id 7002

    Example: 匯入後的分潤比例預設為 0
      When 管理者 "Admin" 匯入舊版賣場 7001 並指定夥伴 5
      Then 新賣場的分潤比例應為 0

    Example: 舊版的顯示設定帶入新賣場的 settings
      Given 舊版賣場 7001 帶有顯示設定
      When 管理者 "Admin" 匯入舊版賣場 7001 並指定夥伴 5
      Then 新賣場的 settings 應包含來源的顯示設定

  Rule: 後置（保留）- 匯入不刪除也不修改舊版資料
    # @covered-by: tests/Integration/Application/LegacyImporterTest.php::test_import_does_not_delete_or_modify_the_legacy_shop

    # ⚠️ 本 Rule 原先掛的兩條 ListImportableLegacyShopsTest 測的是**列表** UseCase，
    #    全程沒有執行過任何一次匯入；而 ImportLegacyShopTest 內那句
    #    `assertSame( 1, $this->shopRepo->count() )` 雖然註解寫著「不刪除舊版，只新增」，
    #    數的卻是**新的 ProfitShop repo**、不是 legacy repo ⇒ 在 UseCase 內加一行
    #    刪除 legacy 的程式碼，一條測試都不會紅。
    #    那兩條列表測試沒有對應到本 feature 的任何 Rule，因此不再掛任何 @covered-by。
    #
    # 新測試同時比對「快照（id / title / slug / meta）」與「post 本體
    # （status / title / name / content）」——只斷言「還在」擋不住
    # 「沒刪掉、但把欄位改寫了」這種壞法。

    Example: 匯入後舊版賣場仍然存在且可用
      When 管理者 "Admin" 匯入舊版賣場 7001 並指定夥伴 5
      Then 舊版賣場 7001 應仍然存在
      And 舊版賣場 7001 的內容應維持不變

  Rule: 前端（閘門）- 不可逆操作必須經過四道閘門
    # @covered-by: tests/js/importGates.test.ts::四道全過時可送出
    # @covered-by: tests/js/importGates.test.ts::🔴 每一道閘門都能獨立擋下送出
    # @covered-by: tests/js/importGates.test.ts::🔴 確認字串必須完整大寫（避免「import」誤觸）
    # @covered-by: tests/js/importGates.test.ts::🔴 importable 必須在這裡再驗一次，不能只信列表的 disabled
    # @covered-by: tests/js/importGates.test.ts::🔴 未選夥伴一律擋下（沒有分潤對象的賣場毫無意義）
    # @covered-by: tests/js/importGates.test.ts::飛行中停用（重複送出會匯入兩份）

    Example: 不可匯入的來源其匯入按鈕為停用並說明原因
      Given 舊版賣場 7003 標記為不可匯入
      When 管理者 "Admin" 檢視舊版賣場列表
      Then 該列的「匯入」應為停用狀態
      And 滑鼠移入時應顯示不可匯入的原因

    Example: 開啟 Modal 後仍再次確認來源是否可匯入
      Given 管理者 "Admin" 已開啟舊版賣場 7001 的匯入 Modal
      When 該來源在此期間變成不可匯入
      Then 「確認匯入」應為停用狀態

    Example: 未選擇分潤夥伴時不可送出
      When 管理者 "Admin" 開啟匯入 Modal 且未選擇夥伴
      Then 「確認匯入」應為停用狀態

    Example: 必須通過二次確認才真正送出
      When 管理者 "Admin" 選好夥伴後點擊「確認匯入」
      Then 畫面應要求二次確認
      And 未完成二次確認前系統不應發出匯入請求

    Example: Modal 不可由 ESC 或點擊遮罩關閉
      Given 管理者 "Admin" 已開啟匯入 Modal
      When 管理者 "Admin" 按下 ESC 或點擊遮罩
      Then Modal 應維持開啟
</content>
