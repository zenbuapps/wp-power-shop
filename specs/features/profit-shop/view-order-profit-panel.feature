@ignore @query
Feature: 後台訂單頁的分潤資訊面板

  站長在訂單編輯頁需要看見「這張訂單有哪些品項要分潤、分給誰、多少錢、撥了沒」。
  這份資訊已經凍結在訂單品項上，面板只是把它讀出來呈現，不重新計算、不重新判定歸屬——
  訂單成立之後賣場改比例、改名甚至被刪除，都不該讓歷史訂單的數字跟著變動。

  # ── 面板的存在條件 ──────────────────────────────────────────
  # 只有「至少一個品項帶有分潤歸屬」的訂單才顯示這個面板。
  # 一般訂單印一個空面板只會佔版面、讓站長每次都得確認一次「這張真的沒有嗎」。
  #
  # ── 已刪資料一律以佔位文字呈現，不可讓整列消失 ────────────────
  # 結算紀錄凍結的是 shop_id 與 partner_term_id 兩個數字。賣場或夥伴被刪除之後
  # 名稱查不回來，但金額仍然要付給人——整列消失等於站長再也看不到這筆債。
  #
  # ── 訂單列表的「分潤賣場」過濾 ───────────────────────────────
  # WooCommerce 的訂單查詢無法直接依訂單品項的 meta 篩選，因此改成兩段式：
  # 先向本外掛索取「屬於該賣場的訂單編號清單」，再把那份清單當成訂單查詢的
  # 指定編號條件。
  # ⚠️ 指定編號的條件有長度上限，因此清單有筆數上限（v1 為 200），
  #    超過上限的賣場只看得到最近的 200 張訂單。這是已知取捨，
  #    UI 必須據實告知，不可讓站長以為看到的就是全部。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932
    And 已存在訂單 8001 其品項與分潤結算 meta 為
      | order_item_id | product_name | shop_id | partner_term_id | rate | actual_price | profit_amount | status  |
      | 7001          | 筆記型電腦     | 1932    | 5               | 20   | 240.00       | 48.00         | pending |
      | 7002          | 無線滑鼠       | 1932    | 5               | 20   | 900.00       | 180.00        | paid    |
    And 訂單 8001 另有品項 7003 且無任何分潤結算 meta
    And 站長已登入後台

  Rule: 後置（呈現）- 逐一列出帶有分潤歸屬的訂單品項
    # @covered-by: NOT-COVERED B9 待補 Playwright；面板是 React 元件，專案目前無前端測試框架

    Example: 面板列出每一個帶有分潤歸屬的品項
      When 站長開啟訂單 8001 的編輯頁
      Then 分潤資訊面板應顯示 2 列
      And 面板應包含品項 7001 與 7002

    Example: 每一列包含賣場、夥伴、比例、金額與狀態
      When 站長開啟訂單 8001 的編輯頁
      Then 品項 7001 那一列應顯示
        | 商品        | 賣場      | 夥伴   | 比例 | 應分潤 | 狀態    |
        | 筆記型電腦   | 夏季賣場   | 夥伴A  | 20%  | 48.00 | 待結算  |

    Example: 沒有分潤歸屬的品項不出現在面板中
      When 站長開啟訂單 8001 的編輯頁
      Then 面板不應包含品項 7003

    Example: 面板顯示該訂單的分潤金額合計
      When 站長開啟訂單 8001 的編輯頁
      Then 面板應顯示分潤合計為 228.00

  Rule: 後置（凍結）- 面板顯示凍結值而非當下重新計算的值
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_classic_checkout_writes_all_settlement_meta
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/RenewalProfitHookTest.php::test_renewal_freezes_shop_id_and_partner_term_id

    Example: 賣場改比例後歷史訂單的數字不變
      Given 賣場 "夏季賣場" 的分潤比例已改為 30
      When 站長開啟訂單 8001 的編輯頁
      Then 品項 7001 的比例應仍顯示 20%
      And 品項 7001 的應分潤應仍為 48.00

    Example: 商品改價後歷史訂單的實際單價不變
      Given 商品的原價已被站長調整
      When 站長開啟訂單 8001 的編輯頁
      Then 品項 7001 的實際單價應仍為 240.00

  Rule: 後置（已刪）- 賣場或夥伴被刪除時以佔位文字呈現
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_deleted_shop_shows_placeholder_and_keeps_amount
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_deleted_partner_shows_placeholder_and_row_survives

    Example: 賣場已刪除時顯示佔位文字
      Given 賣場 "夏季賣場" 已被刪除
      When 站長開啟訂單 8001 的編輯頁
      Then 品項 7001 的賣場欄應顯示 "[已刪除賣場]"
      And 品項 7001 的應分潤應仍為 48.00

    Example: 夥伴已刪除時顯示佔位文字
      Given 分潤夥伴 "夥伴A" 已被刪除
      When 站長開啟訂單 8001 的編輯頁
      Then 品項 7001 的夥伴欄應顯示 "[已停用夥伴]"
      And 該列仍應出現在面板中

    Example: 賣場已刪除時不提供前往賣場的連結
      Given 賣場 "夏季賣場" 已被刪除
      When 站長開啟訂單 8001 的編輯頁
      Then 賣場欄不應是可點擊的連結

    Example: 比例為零的品項標示為待人工處理
      Given 品項 7001 的 _profit_rate 為 0
      When 站長開啟訂單 8001 的編輯頁
      Then 該列應顯示「需人工設定比例」的提示

  Rule: 後置（狀態）- 結算狀態以中文呈現且與結算頁一致
    # @covered-by: NOT-COVERED B9 待補 Playwright；狀態中文對映住在前端 ProfitSettlement/types.ts

    Scenario Outline: 四種結算狀態各有對應的顯示文字
      Given 品項 7001 的 _settlement_status 為 "<status>"
      When 站長開啟訂單 8001 的編輯頁
      Then 該列的狀態應顯示 "<label>"

      Examples:
        | status    | label   |
        | pending   | 待結算   |
        | paid      | 已撥款   |
        | refunded  | 已退款   |
        | cancelled | 已取消   |

    Example: 已撥款的品項顯示撥款時間
      Given 品項 7002 的 _settled_at 已寫入
      When 站長開啟訂單 8001 的編輯頁
      Then 該列應顯示撥款時間

  Rule: 後置（無資料）- 沒有任何分潤歸屬的訂單完全不顯示面板
    # @covered-by: NOT-COVERED B9 待補 Playwright；「不顯示面板」是 React 條件渲染

    Example: 一般訂單不顯示分潤資訊面板
      Given 已存在訂單 8002 其所有品項皆無分潤結算 meta
      When 站長開啟訂單 8002 的編輯頁
      Then 頁面不應出現分潤資訊面板
      And 頁面不應出現空的分潤資訊區塊

  Rule: 前端（過濾）- 訂單列表可依分潤賣場過濾
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_order_ids_returns_matching_orders

    Example: 選定賣場後只顯示該賣場的訂單
      Given 訂單 8001 屬於賣場 1932 且訂單 8002 不屬於任何賣場
      When 站長在訂單列表以賣場 "夏季賣場" 過濾
      Then 列表應包含訂單 8001
      And 列表不應包含訂單 8002

    Example: 過濾前先取得該賣場的訂單編號清單
      When 站長在訂單列表以賣場 "夏季賣場" 過濾
      Then 系統應先向分潤結算端點索取該賣場的訂單編號清單
      And 再以該清單作為訂單查詢的指定編號條件

    Example: 該賣場沒有任何訂單時列表為空且不報錯
      Given 賣場 "冬季賣場" 沒有任何分潤訂單
      When 站長在訂單列表以賣場 "冬季賣場" 過濾
      Then 列表應為空
      And 系統不應回傳錯誤

    Example: 清除過濾後回到未過濾的訂單列表
      Given 站長已在訂單列表以賣場 "夏季賣場" 過濾
      When 站長清除賣場過濾條件
      Then 列表應回到未過濾的狀態

  Rule: 已知落差 - 訂單編號清單有筆數上限
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_order_ids_reports_truncation
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_order_ids_limit_cannot_exceed_cap

    # 這是 v1 的刻意取捨（訂單查詢的「指定編號」條件有長度上限），
    # 不是 bug。UI 必須據實告知，不可讓站長誤以為看到的是全部。

    Example: 超過上限時只回傳最近的 200 筆訂單編號
      Given 賣場 "夏季賣場" 名下有 350 張分潤訂單
      When 站長在訂單列表以賣場 "夏季賣場" 過濾
      Then 系統最多應取得 200 個訂單編號
      And 取得的應為最近的 200 張訂單

    Example: 達到上限時在畫面上據實告知
      Given 賣場 "夏季賣場" 名下有 350 張分潤訂單
      When 站長在訂單列表以賣場 "夏季賣場" 過濾
      Then 畫面應提示結果已被截斷且僅顯示最近的部分訂單

  Rule: 安全（權限）- 訂單編號清單端點要求站台管理權限
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_guest_cannot_read_order_ids

    Scenario Outline: 權限不足者無法取得訂單編號清單
      Given 目前使用者 <role>
      When 使用者索取賣場 1932 的訂單編號清單
      Then 系統應回傳 403

      Examples:
        | role            |
        | 未登入           |
        | 僅具商店管理權限  |
