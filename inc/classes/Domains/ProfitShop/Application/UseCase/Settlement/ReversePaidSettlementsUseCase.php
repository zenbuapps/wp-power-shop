<?php
/**
 * 批次人工沖回（paid → refunded）UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementBatchOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditRecorder;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementStatusMutatorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\Support\SettlementBatchGuard;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\SettlementRepositoryInterface;

/**
 * 批次把已撥款的結算紀錄人工沖回為 refunded（POST profit-settlements/reverse-paid）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.3、§5.3、§7.5、§7.9
 *
 * 用途：錢已經撥給夥伴之後才發生退款 / 爭議，站長需要在系統裡留下「這筆已撥款被沖回」
 * 的紀錄，好在下次撥款時扣抵。
 *
 * 併發處理與 `MarkSettlementsPaidUseCase` 相同（Domain 規則 + CAS 仲裁兩層守門），
 * 只是 CAS 的來源狀態是 `paid` 而非 `pending`。
 *
 * ## 🔴 只允許 paid → refunded，且**必須自己檢查**
 *
 * `SettlementRecord::mark_refunded()` 的 Domain 狀態機同時接受 `pending → refunded`
 * 與 `paid → refunded`（兩者都是合法的業務轉換，只是 `is_refund_after_paid` 不同）。
 * 本 UseCase 因此**不能**只依賴 Domain 例外把關——直接呼叫 `mark_refunded()` 會讓
 * 一筆 `pending` 也被「人工沖回」成功，而那筆錢根本還沒撥出去：
 *   - 稽核紀錄會多一筆 `refund_after_paid`，站長下次撥款據此扣抵 → **少付夥伴一次**
 *   - 而該筆本來應該要撥的款，因為狀態已是 refunded 也不會再出現在待撥款清單
 *
 * 所以這裡有一道**明確的 `status === paid` 前置檢查**，不符者一律進 `skipped`。
 * 而 CAS 的 `from = paid` 是同一條規則在併發層的第二道保證：即使前置檢查通過之後
 * 狀態才被別人改掉，UPDATE 也只會影響 0 列。
 * pending 要作廢請走 `mark-cancelled`（那條路徑不會產生 refund 稽核紀錄）。
 *
 * ⚠️ 已知落差：`SettlementRecord::mark_refunded()` 會把 `is_refund_after_paid` 設為 true，
 * 但 `OrderItemSettlementRepository` 目前**不持久化這個欄位**（讀回時一律 false）。
 * 「已撥款後退款」這個事實目前只保存在 `_partner_settled_log` 的 `refund_after_paid`
 * 稽核紀錄裡。補上 meta 持久化屬於退款回沖任務的範圍。
 */
final class ReversePaidSettlementsUseCase {

	/**
	 * 單次批次上限（與 mark-paid 一致）
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
	 */
	public function __construct(
		private readonly SettlementRepositoryInterface $settlements,
		private readonly SettlementStatusMutatorInterface $mutator,
		private readonly SettlementAuditRecorder $auditor
	) {}

	/**
	 * 執行批次沖回
	 *
	 * @param int[] $order_item_ids   要沖回的 order_item_id 清單
	 * @param int   $operator_user_id 操作者 user ID（寫入稽核紀錄）
	 *
	 * @throws \DomainException 清單為空 / 超過批次上限 / 操作者 ID 不合法時拋出（→ 400）
	 *
	 * @return SettlementBatchOutput
	 */
	public function execute( array $order_item_ids, int $operator_user_id ): SettlementBatchOutput {
		$ids = SettlementBatchGuard::normalize_ids( $order_item_ids, self::MAX_BATCH_SIZE );
		SettlementBatchGuard::assert_operator( $operator_user_id );

		$updated = [];
		$skipped = [];

		foreach ( $ids as $id ) {
			$record = $this->settlements->find_by_order_item( $id );

			if ( null === $record ) {
				$skipped[ $id ] = '找不到對應的分潤結算紀錄（可能不屬於分潤賣場，或訂單品項已被刪除）';
				continue;
			}

			// 見 class docblock：Domain 狀態機也接受 pending → refunded，
			// 但那在「人工沖回」的語意下是錯的，必須在這一層擋掉。
			if ( SettlementRecord::STATUS_PAID !== $record->status ) {
				$skipped[ $id ] = sprintf(
					'目前狀態為 %s，只有已撥款（paid）可人工沖回；待撥款請改用「標記取消」',
					$record->status
				);
				continue;
			}

			try {
				$record->mark_refunded();
			} catch ( \Throwable $e ) {
				$skipped[ $id ] = SettlementBatchGuard::describe_illegal_transition(
					$e,
					'沖回',
					'已撥款（paid）'
				);
				continue;
			}

			if ( ! $this->mutator->compare_and_set( $id, SettlementRecord::STATUS_PAID, SettlementRecord::STATUS_REFUNDED ) ) {
				$skipped[ $id ] = SettlementBatchGuard::describe_lost_race(
					$this->mutator->current_status( $id ),
					'沖回'
				);
				continue;
			}

			$updated[] = $record;
		}

		$audit_failed = $this->auditor->record(
			$updated,
			SettlementAuditRecorder::TYPE_REFUND_AFTER_PAID,
			$operator_user_id
		);

		return new SettlementBatchOutput(
			updated_ids: array_map( static fn( SettlementRecord $r ): int => $r->order_item_id, $updated ),
			skipped: $skipped,
			total_amount: SettlementAuditRecorder::sum_amount( $updated ),
			audit_log_failed_partner_ids: $audit_failed,
		);
	}
}
