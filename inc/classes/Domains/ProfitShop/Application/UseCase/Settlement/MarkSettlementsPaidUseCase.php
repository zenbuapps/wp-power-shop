<?php
/**
 * 批次標記已撥款 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementBatchOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ClockInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditRecorder;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementStatusMutatorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\Support\SettlementBatchGuard;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\SettlementRepositoryInterface;

/**
 * 批次把結算紀錄從 pending 標記為 paid（POST profit-settlements/mark-paid）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5
 *
 * ## 🔴 併發防護：條件式 UPDATE（CAS），不是 read-check-write
 *
 * 撥款是**不可逆的金流動作**，這裡的併發處理方式直接決定會不會重複付錢。
 *
 * ❌ **錯誤做法**：先讀出狀態、確認是 `pending`、再寫入 `paid`。
 *    兩位管理員在兩個分頁同時按下撥款時，兩邊都讀到 `pending`、都通過檢查、都寫入——
 *    系統回報兩次成功，站長真的轉兩次帳。`SettlementRecord::mark_paid()` 內建的
 *    pending 檢查也擋不住，它檢查的是記憶體中的快照而非資料庫當下的值。
 *
 * ✅ **實際做法**：`SettlementStatusMutatorInterface::compare_and_set()`，
 *    把比對與寫入壓進同一個 `UPDATE ... WHERE meta_value = 'pending'`，
 *    以 affected rows 判定誰搶到。資料庫的 row lock 保證只有一個請求拿到 1。
 *
 * 每一筆的處理順序（三段各有分工，順序不可調換）：
 *
 * | # | 動作 | 負責 | 失敗時 |
 * |---|---|---|---|
 * | 1 | `find_by_order_item()` | 取得金額 / partner（稽核紀錄要用）並確認紀錄存在 | 進 `skipped`「找不到」 |
 * | 2 | `$record->mark_paid()` | **Domain 規則**：這個轉換合不合法 | 進 `skipped`（Domain 訊息） |
 * | 3 | `compare_and_set()` | **併發**：這一輪誰搶到 | 進 `skipped`「已被處理」 |
 *
 * 第 2 步保留 Domain 狀態機的意義（規則的真相來源仍在 Entity，不是 CAS 的 `from` 字面值）；
 * 第 3 步才是併發的仲裁者。少了第 2 步，狀態轉換規則會散落成各 UseCase 的字串常數；
 * 少了第 3 步，就是重複撥款。
 *
 * ## 被跳過的項目**必須**明確回報
 *
 * 第二位管理員若只看到「操作成功」而不知道自己那次沒生效，很可能真的再去銀行轉一次帳。
 * 回應因此逐筆列出 `order_item_id => 原因`（含 CAS 失敗後**重新讀出**的當下狀態）。
 *
 * ## `_settled_at` / `_settled_by` 只有搶到的那一方寫
 *
 * 那兩欄是「誰在何時撥的款」的唯一證據；讓沒搶到的請求也寫入會讓證據指向錯的人。
 * 因此它們在 CAS 成功之後才寫（第 3 步之後），不是隨狀態一起寫。
 */
final class MarkSettlementsPaidUseCase {

	/**
	 * 單次批次上限
	 *
	 * 撥款是逐筆查詢 + 逐筆 UPDATE，沒有上限的話一次送十萬筆就是一條打死資料庫的請求。
	 * 500 筆對真實對帳批次已相當充裕。
	 *
	 * @var int
	 */
	public const MAX_BATCH_SIZE = 500;

	/**
	 * 建構子
	 *
	 * @param SettlementRepositoryInterface    $settlements 結算紀錄 Repository（讀取用）
	 * @param SettlementStatusMutatorInterface $mutator     狀態原子轉換器（寫入用）
	 * @param SettlementAuditRecorder          $auditor     稽核紀錄寫入器
	 * @param ClockInterface                   $clock       時鐘
	 */
	public function __construct(
		private readonly SettlementRepositoryInterface $settlements,
		private readonly SettlementStatusMutatorInterface $mutator,
		private readonly SettlementAuditRecorder $auditor,
		private readonly ClockInterface $clock
	) {}

	/**
	 * 執行批次撥款
	 *
	 * @param int[] $order_item_ids   要標記的 order_item_id 清單
	 * @param int   $operator_user_id 操作者 user ID
	 *
	 * @throws \DomainException 清單為空 / 超過批次上限 / 操作者 ID 不合法時拋出（→ 400）
	 *
	 * @return SettlementBatchOutput
	 */
	public function execute( array $order_item_ids, int $operator_user_id ): SettlementBatchOutput {
		$ids = SettlementBatchGuard::normalize_ids( $order_item_ids, self::MAX_BATCH_SIZE );
		SettlementBatchGuard::assert_operator( $operator_user_id );

		$now     = $this->clock->now();
		$updated = [];
		$skipped = [];

		foreach ( $ids as $id ) {
			$record = $this->settlements->find_by_order_item( $id );

			if ( null === $record ) {
				$skipped[ $id ] = '找不到對應的分潤結算紀錄（可能不屬於分潤賣場，或訂單品項已被刪除）';
				continue;
			}

			// 第 2 步：Domain 規則守門（不是併發守門）
			try {
				$record->mark_paid( $operator_user_id, $now );
			} catch ( \Throwable $e ) {
				$skipped[ $id ] = SettlementBatchGuard::describe_illegal_transition(
					$e,
					'已撥款',
					'待撥款（pending）'
				);
				continue;
			}

			// 第 3 步：併發仲裁——只有拿到 affected rows = 1 的請求才算搶到
			if ( ! $this->mutator->compare_and_set( $id, SettlementRecord::STATUS_PENDING, SettlementRecord::STATUS_PAID ) ) {
				$skipped[ $id ] = SettlementBatchGuard::describe_lost_race(
					$this->mutator->current_status( $id ),
					'標記為已撥款'
				);
				continue;
			}

			// 只有搶到的那一方寫撥款證據
			$this->mutator->mark_settled( $id, $now, $operator_user_id );
			$updated[] = $record;
		}

		$audit_failed = $this->auditor->record( $updated, SettlementAuditRecorder::TYPE_SETTLE, $operator_user_id );

		return new SettlementBatchOutput(
			updated_ids: array_map( static fn( SettlementRecord $r ): int => $r->order_item_id, $updated ),
			skipped: $skipped,
			total_amount: SettlementAuditRecorder::sum_amount( $updated ),
			audit_log_failed_partner_ids: $audit_failed,
		);
	}
}
