@ignore @command
Feature: 站長標記分潤結算狀態

  站長把錢匯給夥伴之後，回到結算頁把對應的紀錄標記為「已撥款」。
  這是**真實金流的紀錄動作**：標錯了，站長要嘛重複付款、要嘛以為付過而永遠不付。
  同一頁還提供「標記取消」與「人工沖回」兩個相反方向的動作。

  # ── 三個動作與各自允許的狀態轉換 ────────────────────────────
  #   標記已撥款 mark-paid       ：pending → paid
  #   標記取消   mark-cancelled  ：pending → cancelled
  #   人工沖回   reverse-paid    ：paid    → refunded（並標記為已撥款後退款）
  # 其餘任何轉換一律拒絕。狀態機的唯一真相在 SettlementRecord Entity，
  # 三個 UseCase 都不自己再寫一套判斷。
  #
  # ── 🔴 併發必須靠條件式更新，不能靠「先讀再判斷再寫」──────────
  # 站長開兩個分頁、或手滑連按兩次，「讀出來是 pending → 判斷可以 → 寫成 paid」
  # 這個序列會在兩邊都通過，結果是**同一筆分潤被撥款兩次**。
  # 正確做法是把判斷與寫入合併成單一個條件式更新（只在目前值仍等於預期值時才寫），
  # 並以實際被更新的筆數判斷自己有沒有搶到。
  #
  # ⚠️ 條件式更新繞過了 WooCommerce 的 meta 寫入函式，也就繞過了它的快取清除，
  #    必須自行清掉該訂單品項的 meta 快取，否則同一個請求後續讀到的還是舊狀態。
  #
  # ── 🔴 批次操作必須回傳逐筆結果 ─────────────────────────────
  # 站長勾 50 筆按撥款，其中 3 筆已被另一個分頁改成 paid。
  #   整批失敗 ⇒ 站長無所適從，不知道該怎麼補救
  #   靜默略過 ⇒ 站長以為 50 筆全部成功
  # 兩者都不可接受，必須回傳「成功清單 + 失敗清單（含每一筆的原因）」。
  #
  # ── 撥款稽核紀錄 ────────────────────────────────────────────
  # 撥款是真實金流，「誰在什麼時候把多少錢標記成已撥給誰」必須留在資料庫裡
  # （夥伴 termmeta 的 _partner_settled_log），不能只寫進會被輪替或清空的 log 檔。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932
    And 系統中已存在以下分潤結算紀錄
      | order_item_id | partner_term_id | profit_amount | status    |
      | 7001          | 5               | 48.00         | pending   |
      | 7002          | 5               | 180.00        | pending   |
      | 7003          | 5               | 30.00         | paid      |
      | 7004          | 5               | 20.00         | refunded  |
      | 7005          | 5               | 10.00         | cancelled |
    And 站長 "站長甲" 的 user_id 為 1 且已登入並具有站台管理權限

  Rule: 安全（權限）- 三個標記動作一律要求站台管理權限
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_shop_manager_is_denied_on_every_settlement_endpoint
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_mark_paid_without_nonce_is_rejected

    Scenario Outline: 權限不足者無法變更結算狀態
      Given 目前使用者 <role>
      When 使用者執行 <action>
      Then 系統應回傳 403
      And 所有結算紀錄的狀態皆不應被改動

      Examples:
        | role             | action     |
        | 未登入            | 標記已撥款  |
        | 僅具商店管理權限   | 標記已撥款  |
        | 僅具商店管理權限   | 標記取消    |
        | 僅具商店管理權限   | 人工沖回    |

    Example: 夥伴 token 不能變更結算狀態
      Given 夥伴 "夥伴A" 已登入並持有有效 token
      When 夥伴 "夥伴A" 以自己的 token 執行標記已撥款
      Then 系統應回傳 403
      And 訂單品項 7001 的狀態應仍為 "pending"

  Rule: 安全（操作者）- 撥款操作者取自登入身分，不採信請求參數
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_missing_operator_throws
    # @covered-by: tests/Integration/Infrastructure/Persistence/WpSettlementStatusMutatorTest.php::test_mark_settled_writes_audit_fields

    Example: 撥款操作者記為當前登入的站長
      When 站長 "站長甲" 標記訂單品項 7001 為已撥款
      Then 訂單品項 7001 的 _settled_by 應為 1

    Example: 請求中夾帶的操作者識別一律被忽略
      When 站長 "站長甲" 標記訂單品項 7001 為已撥款並在請求中帶入 settled_by 為 999
      Then 訂單品項 7001 的 _settled_by 應為 1

  Rule: 後置（撥款）- 只有待結算的紀錄可以標記為已撥款
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_pending_records_are_marked_paid
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_already_paid_records_are_skipped_and_reported
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_cancelled_and_refunded_cannot_be_marked_paid
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_mark_paid_updates_meta_and_writes_audit_log

    Example: 標記待結算紀錄為已撥款
      When 站長標記訂單品項 7001 為已撥款
      Then 訂單品項 7001 的 _settlement_status 應為 "paid"
      And 訂單品項 7001 的 _settled_at 應為當下時間
      And 訂單品項 7001 的 _settled_by 應為 1

    Scenario Outline: 非待結算的紀錄不得被標記為已撥款
      When 站長標記訂單品項 <item_id> 為已撥款
      Then 該筆應出現在失敗清單中
      And 訂單品項 <item_id> 的 _settlement_status 應仍為 "<status>"

      Examples:
        | item_id | status    |
        | 7003    | paid      |
        | 7004    | refunded  |
        | 7005    | cancelled |

    Example: 不存在的訂單品項出現在失敗清單而非造成整批失敗
      When 站長同時標記訂單品項 7001 與 999999 為已撥款
      Then 訂單品項 7001 的 _settlement_status 應為 "paid"
      And 訂單品項 999999 應出現在失敗清單中

  Rule: 後置（逐筆）- 批次操作回傳逐筆結果而非全有全無
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_missing_records_are_reported_as_skipped
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_record_without_status_meta_is_skipped
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_empty_id_list_throws
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_all_invalid_ids_throws
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_mixed_batch_reports_both_updated_and_skipped
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_mark_paid_with_empty_list_returns_400

    # ── 🔴 「逐筆回報」不適用於空清單，2026-08-12 改判 ─────────────
    # 本 Rule 原本有一條 Example 斷言「空的識別清單回傳空結果而非錯誤」，
    # 與實作**完全相反**：`MarkSettlementsPaidUseCase::execute( [], … )` 拋 DomainException，
    # 端點回 400，兩條測試都鎖死這個行為。
    #
    # 改判為「保留 fail-fast 實作、改規格」的理由：
    # 這是**金流操作**。批次撥款收到空清單，通常代表前端狀態有問題
    #（例如選取集合被意外清空），靜默回「空結果、成功」會讓站長
    # **以為已處理完畢**——那是這種操作最不該有的歧義。fail-fast 讓問題當場暴露。
    #
    # ⚠️ 這與本 Rule 主旨不衝突：「逐筆回報」處理的是「清單裡有好有壞」，
    #    空清單則是「根本沒有東西可處理」，屬於呼叫端的輸入錯誤，兩者是不同層次。

    Example: 部分成功時同時回傳成功與失敗清單
      When 站長同時標記訂單品項 7001、7002 與 7003 為已撥款
      Then 成功清單應包含 7001 與 7002
      And 失敗清單應包含 7003
      And 失敗清單中每一筆應包含 order_item_id 與失敗原因

    Example: 一筆失敗不影響其他筆的寫入
      When 站長同時標記訂單品項 7001、7002 與 7003 為已撥款
      Then 訂單品項 7001 的 _settlement_status 應為 "paid"
      And 訂單品項 7002 的 _settlement_status 應為 "paid"
      And 訂單品項 7003 的 _settlement_status 應仍為 "paid"

    Example: 空的識別清單應被拒絕並回報錯誤
      When 站長以空的識別清單執行標記已撥款
      Then 系統應回傳錯誤
        | status |
        | 400    |
      And 所有結算紀錄的狀態皆不應被改動

    Example: 清單內全為非法識別時等同空清單一併拒絕
      When 站長以全為非法值的識別清單執行標記已撥款
      Then 系統應回傳錯誤
      And 所有結算紀錄的狀態皆不應被改動

    Example: 失敗原因取自狀態機的既有訊息
      When 站長標記訂單品項 7004 為已撥款
      Then 失敗原因應說明無法從 refunded 轉換到 paid

  Rule: 安全（併發）- 同一筆紀錄同時被撥款兩次時只能成功一次
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_transition_goes_through_compare_and_set
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_losing_the_race_skips_the_record_and_writes_nothing
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_running_same_batch_twice_does_not_double_count
    # @covered-by: tests/Integration/Infrastructure/Persistence/WpSettlementStatusMutatorTest.php::test_second_cas_with_same_from_loses_the_race
    # @covered-by: tests/Integration/Infrastructure/Persistence/WpSettlementStatusMutatorTest.php::test_cas_guard_lives_in_sql_where_clause_not_in_php
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_concurrent_mark_paid_does_not_double_count

    Example: 兩個並行請求對同一筆撥款只有一個成功
      When 兩個並行請求同時標記訂單品項 7001 為已撥款
      Then 其中一個請求的成功清單應包含 7001
      And 另一個請求的失敗清單應包含 7001
      And 訂單品項 7001 的 _settled_at 應只被寫入一次

    Example: 撥款寫入後同一請求內讀到的必須是新狀態
      When 站長標記訂單品項 7001 為已撥款
      And 系統在同一個請求內重新讀取訂單品項 7001
      Then 讀到的 _settlement_status 應為 "paid"

    Example: 條件式更新不得在目前狀態不符時寫入任何欄位
      Given 訂單品項 7003 的 _settlement_status 為 "paid"
      When 站長標記訂單品項 7003 為已撥款
      Then 訂單品項 7003 的 _settled_at 不應被改寫
      And 訂單品項 7003 的 _settled_by 不應被改寫

  Rule: 後置（稽核）- 每次撥款都必須寫入夥伴的撥款稽核紀錄
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_audit_log_is_written_per_partner
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_audit_failure_is_reported_without_rolling_back_status

    Example: 撥款後在夥伴稽核紀錄追加一筆
      When 站長標記訂單品項 7001 與 7002 為已撥款
      Then 夥伴 "夥伴A" 的撥款稽核紀錄應新增一筆
        | type   | total  | item_count | by |
        | settle | 228.00 | 2          | 1  |

    Example: 稽核紀錄為累積追加而非覆寫
      Given 夥伴 "夥伴A" 已有一筆撥款稽核紀錄
      When 站長標記訂單品項 7001 為已撥款
      Then 夥伴 "夥伴A" 的撥款稽核紀錄應有 2 筆

    Example: 稽核金額只計入實際成功的筆數
      When 站長同時標記訂單品項 7001 與 7003 為已撥款
      Then 稽核紀錄的 total 應為 "48.00"
      And 稽核紀錄的 item_count 應為 1

    Example: 稽核紀錄寫入失敗不得回滾已完成的狀態變更
      Given 寫入撥款稽核紀錄將拋出例外
      When 站長標記訂單品項 7001 為已撥款
      Then 訂單品項 7001 的 _settlement_status 應為 "paid"
      And 系統應寫入一筆錯誤紀錄
      And 站長不應看到「操作失敗」的訊息

    Example: 涉及多位夥伴時各自寫入自己的稽核紀錄
      Given 訂單品項 7006 屬於夥伴 "夥伴B" 且狀態為 pending
      When 站長同時標記訂單品項 7001 與 7006 為已撥款
      Then 夥伴 "夥伴A" 與 "夥伴B" 應各自新增一筆撥款稽核紀錄

  Rule: 後置（取消）- 只有待結算的紀錄可以標記為取消
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_mark_cancelled_updates_pending_records

    Example: 標記待結算紀錄為取消
      When 站長標記訂單品項 7001 為取消
      Then 訂單品項 7001 的 _settlement_status 應為 "cancelled"

    Scenario Outline: 非待結算的紀錄不得被標記為取消
      When 站長標記訂單品項 <item_id> 為取消
      Then 該筆應出現在失敗清單中
      And 訂單品項 <item_id> 的 _settlement_status 應仍為 "<status>"

      Examples:
        | item_id | status    |
        | 7003    | paid      |
        | 7004    | refunded  |
        | 7005    | cancelled |

    Example: 標記取消不寫入撥款時間與操作者
      When 站長標記訂單品項 7001 為取消
      Then 訂單品項 7001 的 _settled_at 應為空
      And 訂單品項 7001 的 _settled_by 應為空

  Rule: 後置（沖回）- 人工沖回只接受已撥款的紀錄
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_reverse_paid_rejects_non_paid_records
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_reverse_paid_succeeds_for_paid_records

    # 對應設計文件 §7.9 的退款處理 SOP：已撥款後發生退款時，系統只發通知，
    # 由站長判斷後手動執行沖回。**不**自動扣夥伴已撥款項。

    Example: 人工沖回把已撥款轉為已退款
      When 站長對訂單品項 7003 執行人工沖回
      Then 訂單品項 7003 的 _settlement_status 應為 "refunded"
      And 該筆應被標記為「已撥款後退款」

    Scenario Outline: 非已撥款的紀錄不得被人工沖回
      When 站長對訂單品項 <item_id> 執行人工沖回
      Then 該筆應出現在失敗清單中
      And 訂單品項 <item_id> 的 _settlement_status 應仍為 "<status>"

      Examples:
        | item_id | status    |
        | 7001    | pending   |
        | 7004    | refunded  |
        | 7005    | cancelled |

    Example: 人工沖回寫入稽核紀錄
      When 站長對訂單品項 7003 執行人工沖回
      Then 夥伴 "夥伴A" 的撥款稽核紀錄應新增一筆且 type 為 "refund_after_paid"

    Example: 人工沖回不會自動扣回夥伴已撥款金額
      When 站長對訂單品項 7003 執行人工沖回
      Then 系統不應對夥伴產生任何負值的撥款紀錄

  Rule: 後置（格式）- 回應遵循後台端點的統一外層
    # @covered-by: tests/Unit/Application/UseCase/Settlement/MarkSettlementsPaidUseCaseTest.php::test_output_serialises_with_counts
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_list_returns_wrapped_payload_with_pagination

    Example: 批次結果包裹在統一外層內
      When 站長標記訂單品項 7001 為已撥款
      Then 回應最外層應包含 code、message 與 data 三個欄位
      And 回應的 data 應包含 succeeded 與 failed 兩個清單
