@ignore @query
Feature: 分潤夥伴查詢自己的分潤報表

  分潤夥伴登入自助報表頁後，可查詢 KPI 摘要、趨勢圖與結算明細三份報表。
  三份報表的資料範圍**永遠**鎖死在「登入 token 所對應的那位夥伴」，
  任何來自請求參數的夥伴識別一律不予採信。

  # ── 🔴 本 Feature 的核心 invariant ────────────────────────
  # partner_term_id **必須**由 permission callback 從 token 解出後寫入請求內部欄位，
  # UseCase 只從那裡取值——**永遠不可從 query string 讀取**。
  # 否則持夥伴 A 的 token 發出 ?partner_term_id=B 就能撈出夥伴 B 的營收資料。
  #
  # ── 回應格式與 admin 端點不同 ─────────────────────────────
  # 夥伴端點回傳**裸 payload**（不裹 {code, message, data}），這是刻意的契約差異。
  #
  # ── 結算狀態四態 ─────────────────────────────────────────
  # pending（待結算）/ paid（已撥款）/ refunded（已退款）/ cancelled（已取消）
  #
  # ── 🚧 上游鏈路：結算寫入實作中（2026-08-06 起）────────────
  # 本 Feature 描述的是**查詢端**的行為，該側已完整實作並可正確聚合。
  # 但 `wc_order_itemmeta` 上的 `_profit_partner_term_id` / `_profit_amount` /
  # `_settlement_status` 在此之前沒有任何程式寫入，因此真實站台上這三份報表
  # 是空的 / 全 0。寫入鏈路依 specs/plan/2026-08-06-profit-settlement-chain-plan.md
  # 分階段補上，行為見 attribute-order-line-item.feature。
  # 下方 Background 的結算紀錄因此是「寫入鏈路補上之後」的預期狀態。
  #
  # ⚠️ 連帶必須成立的前提（同一份藍圖的地基修補）：結算查詢只認訂單型別為
  #    shop_order 的品項。訂閱商品的分潤 meta 會同時存在於主訂單與訂閱物件上，
  #    不過濾型別的話這裡的金額會剛好變成兩倍。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5 且 slug 為 "partner-a"
    And 已存在分潤夥伴 "夥伴B" 其 term_id 為 9 且 slug 為 "partner-b"
    And 夥伴 "夥伴A" 名下有以下已成立的分潤結算紀錄
      | order_item_id | shop_id | actual_price | profit_amount | status  | order_date |
      | 5001          | 1932    | 450.00       | 45.00         | pending | 2026-08-01 |
      | 5002          | 1932    | 900.00       | 90.00         | paid    | 2026-08-02 |
      | 5003          | 1932    | 300.00       | 30.00         | refunded| 2026-08-03 |
    And 夥伴 "夥伴B" 名下有以下已成立的分潤結算紀錄
      | order_item_id | shop_id | actual_price | profit_amount | status  | order_date |
      | 6001          | 2000    | 8000.00      | 800.00        | pending | 2026-08-01 |
    And 夥伴 "夥伴A" 已登入並持有有效 token

  Rule: 安全（範圍）- 報表範圍鎖死於 token，請求參數一律不予採信
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportsScopeTest.php::test_partner_a_token_can_only_see_partner_a_kpi
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportsScopeTest.php::test_partner_a_token_settlements_query_string_attack_ignored
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/PartnerReportScopeIsolationTest.php::test_filter_criteria_does_not_expose_partner_term_id_field
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/PartnerReportScopeIsolationTest.php::test_kpi_use_case_locks_partner_term_id
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/PartnerReportScopeIsolationTest.php::test_execute_signature_takes_partner_term_id_as_first_explicit_arg
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/ListPartnerSettlementsUseCaseTest.php::test_does_not_leak_other_partners_settlements
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_kpi_ignores_partner_term_id_in_query_string
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_settlements_ignores_partner_term_id_in_query_string
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_settlements_list_is_non_empty_and_all_belong_to_self
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportsScopeTest.php::test_no_token_returns_401
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportsScopeTest.php::test_invalid_token_returns_401

    Scenario Outline: 以請求參數指定他人 partner_term_id 不得改變資料範圍
      When 夥伴 "夥伴A" 以自己的 token 查詢 <report> 並在 query string 帶入 partner_term_id 為 9
      Then 回應內容應僅包含夥伴 "夥伴A" 的資料
      And 回應內容不應包含夥伴 "夥伴B" 的任何金額

      Examples:
        | report      |
        | KPI 摘要     |
        | 趨勢圖       |
        | 結算明細     |

    Example: 未帶 token 查詢報表回 401
      When 未登入的訪客查詢 KPI 摘要
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 後台 nonce 不能用於夥伴報表端點
      Given 站長已登入後台並持有有效的 admin nonce
      When 站長僅以 admin nonce 查詢夥伴報表端點
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: token 已過期時查詢報表回 401
      Given 夥伴 "夥伴A" 的 token 已過期
      When 夥伴 "夥伴A" 查詢 KPI 摘要
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 夥伴識別為非正整數時一律回空報表而非全站資料
      Given 請求內部欄位的夥伴識別為 0
      When 系統彙總結算資料
      Then 回應應為空報表
      And 系統不應查詢任何其他夥伴的資料

  Rule: 後置（KPI）- KPI 摘要依結算狀態分別加總
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/GetPartnerKpiUseCaseTest.php::test_happy_returns_kpi_report_for_partner
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/GetPartnerKpiUseCaseTest.php::test_no_data_returns_zero_kpi
    # @covered-by: tests/Integration/Infrastructure/Persistence/WpSettlementSummaryProviderRealAggregationTest.php::test_summary_returns_correct_totals_for_partner_a
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_kpi_returns_own_non_zero_amounts

    Example: KPI 依狀態分別加總且金額為兩位小數字串
      When 夥伴 "夥伴A" 查詢期間 2026-08-01 至 2026-08-31 的 KPI 摘要
      Then 回應應為
        | total_sales | profit_pending | profit_paid | profit_refunded |
        | 1650.00     | 45.00          | 90.00       | 30.00           |

    Example: 期間內沒有任何紀錄時回傳零而非錯誤
      When 夥伴 "夥伴A" 查詢期間 2026-01-01 至 2026-01-31 的 KPI 摘要
      Then 回應的四個金額欄位皆應為 "0.00"

    Example: KPI 回應包含查詢期間本身
      When 夥伴 "夥伴A" 查詢期間 2026-08-01 至 2026-08-31 的 KPI 摘要
      Then 回應應包含 period_start 與 period_end 欄位

  Rule: 後置（趨勢）- 趨勢資料依指定粒度分組
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/GetPartnerTrendUseCaseTest.php::test_happy_returns_trend_series
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/GetPartnerTrendUseCaseTest.php::test_passes_interval_to_provider
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_trend_returns_own_data_only

    Example: 以日為粒度取得趨勢序列
      When 夥伴 "夥伴A" 以日為粒度查詢 2026-08-01 至 2026-08-03 的趨勢
      Then 回應的序列應包含 3 個資料點

    Example: 趨勢序列僅涵蓋該夥伴的訂單
      When 夥伴 "夥伴A" 查詢趨勢
      Then 序列中的金額總和應等於夥伴 "夥伴A" 的期間銷售總額

  Rule: 後置（明細）- 結算明細支援狀態篩選與分頁
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/ListPartnerSettlementsUseCaseTest.php::test_happy_returns_paginated_list
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/ListPartnerSettlementsUseCaseTest.php::test_empty_list_returns_zero_total
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportEndToEndTest.php::test_settlements_list_is_non_empty_and_all_belong_to_self

    Example: 不指定狀態時回傳全部結算紀錄
      When 夥伴 "夥伴A" 查詢結算明細
      Then 回應的 total 應為 3

    Example: 以狀態篩選結算明細
      When 夥伴 "夥伴A" 以狀態 "pending" 查詢結算明細
      Then 回應的 total 應為 1
      And 回應的每一筆 status 皆應為 "pending"

    Scenario Outline: 未知的結算狀態值一律被忽略
      When 夥伴 "夥伴A" 以狀態 "<status>" 查詢結算明細
      Then 系統不應以該狀態值組出查詢條件

      Examples:
        | status              |
        | unknown             |
        | ' OR 1=1 --         |

    Example: 結算明細回應包含分頁資訊
      When 夥伴 "夥伴A" 查詢結算明細
      Then 回應應包含以下欄位
        | field    |
        | items    |
        | total    |
        | page     |
        | per_page |

  Rule: 前置（分頁）- 分頁參數必須有上限以防資源耗盡
    # 收斂只做在 V2Api::build_filter_criteria_from_request()；FilterCriteria 與
    # WpSettlementSummaryProvider 都沒有上限，因此下列測試同時斷言「回應回報的生效值」
    # 與「真正送進 SQL 的 LIMIT / OFFSET」，缺一不可。
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/ListPartnerSettlementsUseCaseTest.php::test_happy_returns_paginated_list
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_default_pagination_is_page_1_and_per_page_20
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_in_range_pagination_passes_through_untouched
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_per_page_above_limit_is_capped_to_100
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_per_page_zero_is_raised_to_one
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_page_above_limit_is_capped_to_10000

    Scenario Outline: 分頁參數超過上限時收斂到上限值
      When 夥伴 "夥伴A" 以 page 為 <page> 且 per_page 為 <per_page> 查詢結算明細
      Then 實際生效的 page 應為 <effective_page>
      And 實際生效的 per_page 應為 <effective_per_page>

      Examples:
        | page      | per_page | effective_page | effective_per_page |
        | 1         | 20       | 1              | 20                 |
        | 1         | 500      | 1              | 100                |
        | 1         | 0        | 1              | 1                  |
        | 999999999 | 20       | 10000          | 20                 |

    Example: 未指定分頁參數時使用預設值
      When 夥伴 "夥伴A" 查詢結算明細且不帶分頁參數
      Then 實際生效的 per_page 應為 20

  Rule: 前置（期間）- 日期區間必須被正規化且有合理上限
    # 收斂發生在 WpSettlementSummaryProvider::format_datetime()（[0, 253402300799]），
    # 只作用在「進 SQL 的那個值」。
    # ⚠️ 已知落差：KPI 回應的 period_start / period_end 走的是未收斂的原始時間戳
    #    （summary_for_partner() 直接 gmdate() 原值），極大值會回 "292277026596-12-04"。
    #    下列測試只鎖 SQL 這一側，不把回應側的現況當成規格釘死。
    # @covered-by: tests/Unit/Application/UseCase/Partner/Report/GetPartnerTrendUseCaseTest.php::test_passes_interval_to_provider
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_extreme_date_end_is_clamped_to_a_valid_mysql_datetime
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_negative_date_start_is_clamped_to_epoch

    Example: 超出合理範圍的時間戳被收斂
      When 夥伴 "夥伴A" 以極大的時間戳查詢報表
      Then 系統應以可接受的最大時間戳查詢
      And 系統不應拋出錯誤

  Rule: 資料（訂單）- 未成立的訂單不計入報表
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_cancelled_orders_are_excluded

    Example: 已取消或失敗的訂單不計入報表金額
      Given 夥伴 "夥伴A" 名下有一筆屬於已取消訂單的結算紀錄
      When 夥伴 "夥伴A" 查詢 KPI 摘要
      Then 該筆金額不應計入任何 KPI 欄位

  Rule: 相容（HPOS）- 訂單儲存模式切換不影響報表結果
    # @covered-by: tests/Integration/Infrastructure/Persistence/SettlementQueryHposPathTest.php::test_fixture_is_really_on_hpos_and_invisible_to_legacy_sql
    # @covered-by: tests/Integration/Infrastructure/Persistence/SettlementQueryHposPathTest.php::test_summary_for_partner_works_on_hpos
    # @covered-by: tests/Integration/Infrastructure/Persistence/SettlementQueryHposPathTest.php::test_trend_for_partner_works_on_hpos
    # @covered-by: tests/Integration/Infrastructure/Persistence/SettlementQueryHposPathTest.php::test_find_by_partner_works_and_stays_isolated_on_hpos
    # @covered-by: tests/Integration/Infrastructure/Persistence/SettlementQueryHposPathTest.php::test_repository_queries_work_on_hpos
    # @covered-by: tests/Integration/Infrastructure/Persistence/WpSettlementSummaryProviderRealAggregationTest.php::test_summary_works_under_hpos_enabled
    # @covered-by: tests/Integration/Infrastructure/Persistence/WpSettlementSummaryProviderRealAggregationTest.php::test_summary_works_under_legacy_post_orders

    Example: 啟用 HPOS 與未啟用時回傳相同的 KPI
      Given 站台已啟用 HPOS
      When 夥伴 "夥伴A" 查詢期間 2026-08-01 至 2026-08-31 的 KPI 摘要
      Then 回應應與未啟用 HPOS 時相同

  Rule: 後置（格式）- 夥伴端點一律回傳裸 payload
    # ⚠️ 這裡原本掛的兩條 401 測試（PartnerReportsScopeTest 的 no_token / invalid_token）
    #    各自只有一行 assertSame( 401, ... )、與回應形狀無關，屬於「安全（範圍）」，
    #    已移到該 Rule 之下。
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_kpi_response_is_a_bare_payload
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_trend_and_settlements_responses_are_bare_payloads
    # @covered-by: tests/Integration/Infrastructure/Rest/PartnerReportContractTest.php::test_bare_payload_assertion_actually_rejects_a_wrapped_response

    Example: 報表回應不裹 code / message / data 外層
      When 夥伴 "夥伴A" 查詢 KPI 摘要
      Then 回應最外層應直接是報表欄位
      And 回應最外層不應包含 "code" 欄位
</content>
