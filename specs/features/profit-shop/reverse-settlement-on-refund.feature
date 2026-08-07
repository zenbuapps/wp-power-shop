@ignore @command
Feature: 退款與取消訂單時回沖分潤結算紀錄

  訂單被退款或取消之後，對應的分潤結算紀錄必須跟著回沖，否則站長會對一筆
  已經退錢給顧客的交易付出分潤。回沖的規則依「該筆是否已經撥款」而完全不同：
  尚未撥款的可以直接翻狀態，已經撥款的**只通知、不自動改**——錢已經出去了，
  要不要向夥伴追討是業務決策，不是程式能決定的事。

  # ── 三種回沖情境 ────────────────────────────────────────────
  #   全額退款：pending → refunded；paid → 不動，只發通知與稽核紀錄
  #   部分退款：重算 _profit_amount，狀態維持 pending（決策 D-3）
  #   訂單取消：pending → cancelled；paid → 不動
  #
  # ── 🔴 為什麼部分退款要「重算」而不是「翻狀態」或「不動」──────
  # 顧客買 3 件退 1 件：
  #   把整筆標成 refunded ⇒ 夥伴那 2 件的分潤被吃掉，系統性少付夥伴
  #   完全不動           ⇒ 站長要為已退款的那 1 件付分潤，系統性多付
  # 只有「重算成剩下 2 件的分潤」兩邊都不錯。
  #
  # ── 退款品項如何對照回原始品項 ─────────────────────────────
  # WooCommerce 的退款本身也是一張訂單，其品項帶有 _refunded_item_id meta
  # 指回被退的原始品項，數量與金額為負值。對照一律以這個欄位為準。
  #
  # ── 🔴 狀態機的唯一真相在 Entity ───────────────────────────
  # 「paid → refunded 時要設定 is_refund_after_paid 旗標」已經寫在
  # SettlementRecord::mark_refunded() 內，UseCase **不要**再判一次狀態——
  # 兩處各判一次，遲早分歧。
  #
  # ── 訂閱訂單的連帶影響 ──────────────────────────────────────
  # 訂閱商品結帳後，同一組分潤 meta 會同時存在於主訂單與訂閱物件的品項上。
  # 結算只認訂單型別為 shop_order 的品項，因此對訂閱物件品項的退款一律忽略，
  # 否則同一筆分潤會被回沖兩次。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932 且分潤比例為 20
    And 已存在訂單 8001 其品項與結算紀錄為
      | order_item_id | product_id | qty | actual_price | profit_amount | status  |
      | 7001          | 101        | 3   | 240.00       | 144.00        | pending |
    And 訂單 8001 的狀態為 "processing"

  Rule: 後置（全額退款）- 尚未撥款的紀錄直接轉為已退款
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_full_refund_flips_pending_to_refunded
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_accumulated_partial_refunds_reaching_full_quantity_become_full_refund

    Example: 全額退款把 pending 轉為 refunded
      When 站長對訂單 8001 執行全額退款
      Then 訂單品項 7001 的 _settlement_status 應為 "refunded"

    Example: 全額退款後夥伴報表不再把該筆計入待結算
      Given 訂單品項 7001 已因全額退款轉為 refunded
      When 夥伴 "夥伴A" 查詢 KPI 摘要
      Then 待結算金額不應包含 144.00
      And 已退款金額應包含 144.00

  Rule: 安全（已撥款）- 已撥款的紀錄不得自動變更狀態
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_full_refund_on_paid_record_keeps_paid_and_writes_audit_log
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_full_refund_on_paid_record_persists_refund_after_paid_flag
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_full_refund_on_paid_record_queues_admin_notice
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_full_refund_on_pending_record_does_not_set_refund_after_paid_flag

    # 錢已經匯給夥伴了。程式自動把狀態改掉，站長會失去「這筆需要處理」的線索；
    # 自動向夥伴追討更是程式無權決定的業務行為（設計文件 §7.9 明訂需人工處理）。

    Example: 對已撥款的紀錄退款時狀態維持 paid
      Given 訂單品項 7001 的 _settlement_status 為 "paid"
      When 站長對訂單 8001 執行全額退款
      Then 訂單品項 7001 的 _settlement_status 應仍為 "paid"

    Example: 對已撥款的紀錄退款時寫入稽核紀錄
      Given 訂單品項 7001 的 _settlement_status 為 "paid"
      When 站長對訂單 8001 執行全額退款
      Then 夥伴 "夥伴A" 的撥款稽核紀錄應新增一筆
        | type              | order_id | order_item_id | amount |
        | refund_after_paid | 8001     | 7001          | 144.00 |

    Example: 對已撥款的紀錄退款時通知站長
      Given 訂單品項 7001 的 _settlement_status 為 "paid"
      When 站長對訂單 8001 執行全額退款
      Then 系統應向站長發出「已撥款後退款」的後台通知

    Example: 已撥款後退款的紀錄可由站長人工沖回
      Given 訂單品項 7001 因已撥款後退款而被標記
      When 站長對該筆執行人工沖回
      Then 訂單品項 7001 的 _settlement_status 應為 "refunded"
      And 該筆應被標記為「已撥款後退款」

  Rule: 後置（部分退款）- 依剩餘數量重算分潤金額且狀態不變
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_partial_refund_recalculates_profit_amount_and_keeps_pending
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_partial_refund_does_not_change_frozen_price_and_rate
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_partial_refund_on_paid_record_does_not_change_amount
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_amount_only_refund_without_quantity_does_not_reverse

    # 決策 D-3。SettlementRecord 的 profit_amount 是唯讀屬性，
    # 重算的做法是「以新金額重建一筆紀錄」，不是就地改寫欄位。

    Example: 退掉三件中的一件時分潤金額按比例縮減
      When 站長對訂單 8001 的品項 7001 退款 1 件
      Then 訂單品項 7001 的 _profit_amount 應為 "96.00"
      And 訂單品項 7001 的 _settlement_status 應仍為 "pending"

    Example: 部分退款不改動凍結的實際單價與比例
      When 站長對訂單 8001 的品項 7001 退款 1 件
      Then 訂單品項 7001 的 _actual_price 應仍為 "240.00"
      And 訂單品項 7001 的 _profit_rate 應仍為 20

    Example: 已撥款的紀錄遇到部分退款時金額不變
      Given 訂單品項 7001 的 _settlement_status 為 "paid"
      When 站長對訂單 8001 的品項 7001 退款 1 件
      Then 訂單品項 7001 的 _profit_amount 應仍為 "144.00"
      And 系統應寫入一筆「已撥款後退款」的稽核紀錄

    Example: 累計退完全部數量時視為全額退款
      Given 訂單 8001 的品項 7001 已退款 1 件
      When 站長再對品項 7001 退款 2 件
      Then 訂單品項 7001 的 _settlement_status 應為 "refunded"

  Rule: 後置（取消）- 訂單取消時把尚未撥款的紀錄標為已取消
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_order_cancelled_flips_pending_to_cancelled
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_order_cancelled_does_not_touch_paid_record
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_terminal_status_is_not_rewritten_by_cancel

    Example: 訂單取消把 pending 轉為 cancelled
      When 訂單 8001 的狀態變更為 "cancelled"
      Then 訂單品項 7001 的 _settlement_status 應為 "cancelled"

    Example: 訂單取消不影響已撥款的紀錄
      Given 訂單品項 7001 的 _settlement_status 為 "paid"
      When 訂單 8001 的狀態變更為 "cancelled"
      Then 訂單品項 7001 的 _settlement_status 應仍為 "paid"

    Scenario Outline: 已終結的狀態不得再被取消動作改寫
      Given 訂單品項 7001 的 _settlement_status 為 "<status>"
      When 訂單 8001 的狀態變更為 "cancelled"
      Then 訂單品項 7001 的 _settlement_status 應仍為 "<status>"

      Examples:
        | status    |
        | refunded  |
        | cancelled |

  Rule: 後置（逐筆）- 同一張訂單的多個品項各自獨立處理
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_multiple_line_items_are_reversed_independently

    Example: 只退其中一個品項時另一個品項不受影響
      Given 訂單 8001 另有品項 7002 其結算紀錄為 pending 且金額為 "60.00"
      When 站長對訂單 8001 的品項 7001 執行全額退款
      Then 訂單品項 7001 的 _settlement_status 應為 "refunded"
      And 訂單品項 7002 的 _settlement_status 應仍為 "pending"
      And 訂單品項 7002 的 _profit_amount 應仍為 "60.00"

    Example: 一個品項處理失敗不阻斷其他品項
      Given 訂單 8001 的品項 7001 的結算資料已毀損
      And 訂單 8001 另有品項 7002 其結算紀錄為 pending
      When 站長對訂單 8001 執行全額退款
      Then 訂單品項 7002 的 _settlement_status 應為 "refunded"
      And 系統應寫入一筆錯誤紀錄

  Rule: 後置（隔離）- 沒有分潤紀錄的訂單完全不受影響
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_refund_on_order_without_profit_meta_is_noop
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_hooks_are_registered_by_loader

    Example: 一般訂單退款時不做任何事
      Given 已存在訂單 8002 其品項皆無分潤結算 meta
      When 站長對訂單 8002 執行全額退款
      Then 系統不應寫入任何分潤結算 meta
      And 系統不應寫入任何稽核紀錄

    Example: 一般訂單取消時不做任何事
      Given 已存在訂單 8002 其品項皆無分潤結算 meta
      When 訂單 8002 的狀態變更為 "cancelled"
      Then 系統不應寫入任何分潤結算 meta

  Rule: 安全（型別）- 訂閱物件上的品項不得被回沖
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php::test_non_shop_order_type_is_ignored

    # 訂閱商品的分潤 meta 會被複製到訂閱物件的品項上。若不限定訂單型別，
    # 同一筆分潤會在主訂單與訂閱物件上各被回沖一次，金額出現雙倍偏差。

    Example: 對訂閱物件的品項退款時不改動任何結算狀態
      Given 已存在訂閱物件 9001 其品項帶有與訂單 8001 相同的分潤 meta
      When 系統對訂閱物件 9001 的品項執行退款回沖
      Then 該品項的 _settlement_status 不應被改動
      And 訂單品項 7001 的 _settlement_status 應仍為 "pending"
