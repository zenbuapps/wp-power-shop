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

    Example: 僅具備 manage_woocommerce 者不得匯入
      Given 使用者僅具備 manage_woocommerce 能力
      When 該使用者執行匯入
      Then 系統應拒絕該請求

  Rule: 前置（參數）- 必須指定存在的分潤夥伴

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

    Example: 舊版資料不存在時拒絕匯入
      When 管理者 "Admin" 匯入舊版賣場 999999 並指定夥伴 5
      Then 系統應回傳錯誤
        | status | code                |
        | 422    | legacy_unimportable |
      And 回應的 reason 應為 "legacy_not_found"

  Rule: 後置（匯入）- 匯入後產生草稿賣場，不直接上線

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

    Example: 匯入後舊版賣場仍然存在且可用
      When 管理者 "Admin" 匯入舊版賣場 7001 並指定夥伴 5
      Then 舊版賣場 7001 應仍然存在
      And 舊版賣場 7001 的內容應維持不變

  Rule: 前端（閘門）- 不可逆操作必須經過四道閘門

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
