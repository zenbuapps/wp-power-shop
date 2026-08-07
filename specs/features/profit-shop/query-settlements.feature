@ignore @query
Feature: 站長查詢分潤結算列表與摘要

  站長在結算頁看到的是全站所有夥伴的分潤結算明細，用來決定「這一輪要撥多少錢給誰」。
  它與夥伴自助報表查的是同一批資料，差別在於：夥伴只看得到自己的，站長看得到全部，
  而且只有站長能對這些紀錄執行撥款動作。

  # ── 與夥伴報表的三個差異 ────────────────────────────────────
  #   權限：夥伴端點用 token（僅限本人）；本 Feature 一律要求站台管理權限
  #         （manage_options，不是一般的 manage_woocommerce）——因為結算頁緊鄰
  #         「標記已撥款」這個不可逆金流動作。
  #   範圍：可依夥伴、賣場、狀態、日期區間任意篩選。
  #   格式：後台端點一律回傳 {code, message, data} 外層（夥伴端點回裸 payload）。
  #
  # ── 🔴 訂單型別必須過濾 ─────────────────────────────────────
  # 訂閱商品結帳後，同一組分潤 meta 會同時存在於主訂單與訂閱物件的品項上，
  # 而訂閱物件的狀態（active / on-hold）**不在**被排除的訂單狀態清單內。
  # 不限定訂單型別為 shop_order 的話，訂閱商品的每一筆分潤都會被算兩次——
  # 症狀是「金額剛好是兩倍」，沒有任何錯誤訊息。
  #
  # ── 已刪資料的呈現 ──────────────────────────────────────────
  # 結算紀錄凍結的是 shop_id 與 partner_term_id 兩個數字，賣場或夥伴被刪除之後
  # 名稱查不回來。金額照算（已凍結），名稱以佔位文字呈現，不可讓整列消失——
  # 那筆錢仍然要付給人。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤夥伴 "夥伴B" 其 term_id 為 9
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932
    And 已存在分潤賣場 "冬季賣場" 其 id 為 2000
    And 系統中已存在以下分潤結算紀錄
      | order_item_id | order_id | partner_term_id | shop_id | actual_price | profit_amount | status    | order_date |
      | 7001          | 8001     | 5               | 1932    | 240.00       | 48.00         | pending   | 2026-08-01 |
      | 7002          | 8002     | 5               | 1932    | 900.00       | 180.00        | paid      | 2026-08-02 |
      | 7003          | 8003     | 5               | 2000    | 300.00       | 30.00         | refunded  | 2026-08-03 |
      | 7004          | 8004     | 9               | 2000    | 500.00       | 50.00         | pending   | 2026-08-04 |
      | 7005          | 8005     | 5               | 1932    | 100.00       | 20.00         | cancelled | 2026-08-05 |
    And 站長已登入且具有站台管理權限

  Rule: 安全（權限）- 結算端點一律要求站台管理權限
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_guest_cannot_read_settlements
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_subscriber_cannot_read_settlements
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_shop_manager_is_denied_on_every_settlement_endpoint

    Scenario Outline: 權限不足者無法存取結算端點
      Given 目前使用者 <role>
      When 使用者查詢 <endpoint>
      Then 系統應回傳 403

      Examples:
        | role             | endpoint         |
        | 未登入            | 結算列表          |
        | 僅具商店管理權限   | 結算列表          |
        | 未登入            | 結算摘要          |
        | 僅具商店管理權限   | 結算摘要          |

    Example: 夥伴 token 不能存取結算端點
      Given 夥伴 "夥伴A" 已登入並持有有效 token
      When 夥伴 "夥伴A" 以自己的 token 查詢結算列表
      Then 系統應回傳 403
      And 回應不應包含任何結算金額

  Rule: 後置（格式）- 後台端點回傳 code / message / data 外層
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_list_returns_wrapped_payload_with_pagination

    Example: 結算列表回應包含統一外層
      When 站長查詢結算列表
      Then 回應最外層應包含以下欄位
        | field   |
        | code    |
        | message |
        | data    |

    Example: 結算列表的資料區塊包含分頁資訊
      When 站長查詢結算列表
      Then 回應的 data 應包含以下欄位
        | field    |
        | items    |
        | total    |
        | page     |
        | per_page |

    Example: 每一列包含結算所需的欄位
      When 站長查詢結算列表
      Then 回應中每一列應包含以下欄位
        | field           |
        | order_item_id   |
        | order_id        |
        | product_name    |
        | partner_name    |
        | shop_name       |
        | actual_price    |
        | profit_amount   |
        | status          |

  Rule: 前置（篩選）- 支援夥伴、賣場、狀態與日期四種篩選
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_list_filters_by_status
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_list_filters_by_partner
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_unknown_status_filter_is_rejected_not_silently_widened
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ListSettlementsUseCaseTest.php::test_filter_is_passed_through_to_provider

    Example: 依夥伴篩選
      When 站長以夥伴 5 查詢結算列表
      Then 回應應包含 4 筆
      And 回應不應包含 order_item_id 7004

    Example: 依賣場篩選
      When 站長以賣場 1932 查詢結算列表
      Then 回應應包含 3 筆
      And 回應的每一列 shop_id 皆應為 1932

    Example: 依狀態篩選
      When 站長以狀態 "pending" 查詢結算列表
      Then 回應應包含 2 筆
      And 回應的每一列 status 皆應為 "pending"

    Example: 依日期區間篩選
      When 站長查詢 2026-08-01 至 2026-08-02 的結算列表
      Then 回應應包含 order_item_id 7001 與 7002
      And 回應不應包含 order_item_id 7003

    Example: 多個篩選條件同時生效
      When 站長以夥伴 5 且賣場 1932 且狀態 "pending" 查詢結算列表
      Then 回應應包含 1 筆
      And 回應的第一列 order_item_id 應為 7001

    Scenario Outline: 未知的狀態值不得被組進查詢條件
      When 站長以狀態 "<status>" 查詢結算列表
      Then 系統不應以該狀態值組出查詢條件
      And 系統不應回傳錯誤以外的資料

      Examples:
        | status      |
        | unknown     |
        | ' OR 1=1 -- |

  Rule: 前置（分頁）- 分頁參數必須有上限以防資源耗盡
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ListSettlementsUseCaseTest.php::test_returns_paginated_list
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ListSettlementsUseCaseTest.php::test_total_is_full_count_not_page_size
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ListSettlementsUseCaseTest.php::test_second_page_returns_correct_slice

    Scenario Outline: 分頁參數超過上限時收斂到上限值
      When 站長以 page 為 <page> 且 per_page 為 <per_page> 查詢結算列表
      Then 實際生效的 page 應為 <effective_page>
      And 實際生效的 per_page 應為 <effective_per_page>

      Examples:
        | page      | per_page | effective_page | effective_per_page |
        | 1         | 20       | 1              | 20                 |
        | 1         | 500      | 1              | 100                |
        | 1         | 0        | 1              | 1                  |
        | 999999999 | 20       | 10000          | 20                 |
        | -5        | 20       | 1              | 20                 |

    Example: 未指定分頁參數時使用預設值
      When 站長查詢結算列表且不帶分頁參數
      Then 實際生效的 per_page 應為 20

  Rule: 後置（摘要）- 摘要依狀態分別加總
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_summary_returns_amounts_and_counts
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_summary_respects_filter
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_summary_also_rejects_unknown_status

    Example: 摘要分別回傳待撥款、已撥款與退款三組數字
      When 站長查詢結算摘要
      Then 回應應為
        | pending_amount | pending_count | paid_amount | paid_count | refunded_amount | refunded_count |
        | 98.00          | 2             | 180.00      | 1          | 30.00           | 1              |

    Example: 摘要遵循與列表相同的篩選條件
      When 站長以賣場 1932 查詢結算摘要
      Then 待撥款金額應為 "68.00"
      And 已撥款金額應為 "180.00"

    Example: 沒有任何紀錄時摘要回零而非錯誤
      When 站長查詢 2026-01-01 至 2026-01-31 的結算摘要
      Then 三組金額皆應為 "0.00"
      And 三組筆數皆應為 0

    Example: 金額加總必須以十進位運算
      When 站長查詢結算摘要
      Then 加總結果的小數位數應與單筆金額一致
      And 加總不應出現浮點誤差

  Rule: 安全（型別）- 訂閱物件上的品項不得被計入
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_subscription_line_items_are_excluded_from_list
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_subscription_line_items_are_excluded_from_summary
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_subscription_line_items_are_excluded_from_export

    Example: 訂閱商品只計入一次
      Given 已存在訂閱物件 9001 其品項帶有與 order_item_id 7001 相同的分潤 meta
      When 站長查詢結算列表
      Then 回應中屬於夥伴 5 且賣場 1932 的待撥款筆數應為 1
      And 待撥款金額應為 "48.00"

    Example: 摘要同樣排除訂閱物件的品項
      Given 已存在訂閱物件 9001 其品項帶有與 order_item_id 7001 相同的分潤 meta
      When 站長查詢結算摘要
      Then 待撥款金額應為 "98.00"

  Rule: 資料（訂單）- 未成立的訂單不計入結算
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_cancelled_orders_are_excluded

    Scenario Outline: 已取消、失敗或已刪除的訂單不出現在結算列表
      Given 訂單 8001 的狀態為 "<order_status>"
      When 站長查詢結算列表
      Then 回應不應包含 order_item_id 7001

      Examples:
        | order_status |
        | cancelled    |
        | failed       |
        | trash        |

    Example: 待付款訂單仍計入待結算
      Given 訂單 8001 的狀態為 "pending"
      When 站長查詢結算列表
      Then 回應應包含 order_item_id 7001

  Rule: 後置（已刪）- 賣場或夥伴被刪除後仍必須看得到該筆金額
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_deleted_shop_shows_placeholder_and_keeps_amount
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_deleted_partner_shows_placeholder_and_row_survives
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_existing_shop_and_partner_show_real_names

    Example: 賣場已刪除時以佔位文字呈現
      Given 賣場 "夏季賣場" 已被刪除
      When 站長查詢結算列表
      Then order_item_id 7001 的 shop_name 應為 "[已刪除賣場]"
      And order_item_id 7001 的 profit_amount 應仍為 "48.00"

    Example: 夥伴已刪除時以佔位文字呈現
      Given 分潤夥伴 "夥伴A" 已被刪除
      When 站長查詢結算列表
      Then order_item_id 7001 的 partner_name 應為 "[已停用夥伴]"
      And 該筆仍應出現在結算列表中

    Example: 分潤比例為零的紀錄需被標示為待人工處理
      Given order_item_id 7001 的 _profit_rate 為 0
      When 站長查詢結算列表
      Then 該筆應帶有「需人工設定比例」的標記

  Rule: 相容（HPOS）- 訂單儲存模式切換不影響查詢結果
    # @covered-by: tests/Integration/Infrastructure/Persistence/WpSettlementSummaryProviderRealAggregationTest.php::test_summary_works_under_hpos_enabled
    # @covered-by: tests/Integration/Infrastructure/Persistence/WpSettlementSummaryProviderRealAggregationTest.php::test_summary_works_under_legacy_post_orders
    # @covered-by: MANUAL 兩條互斥，須跑 composer test:both-order-storage 兩輪才會都執行；沒有 CI matrix 會自動跑

    Example: 啟用 HPOS 與未啟用時回傳相同結果
      Given 站台已啟用 HPOS
      When 站長查詢結算列表
      Then 回應應與未啟用 HPOS 時逐筆相同

  Rule: 已知落差 - 目前未提供的查詢能力
    # @covered-by: NOT-COVERED 本 Rule 描述的是「目前未提供」的查詢能力，依定義沒有對應實作可測

    # 以下為刻意未納入 v1 範圍的項目，避免日後被當成 bug 修掉。

    Example: 結算列表不提供跨夥伴的合併撥款檢視
      When 站長查詢結算列表
      Then 系統不應提供「依夥伴自動分組並產生撥款單」的功能
