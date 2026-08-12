<?php
/**
 * 批次標記取消 UseCase
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
 * 批次把結算紀錄從 pending 標記為 cancelled（POST profit-settlements/mark-cancelled）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§5.5
 *
 * 用途：訂單雖然成立，但站長判斷該筆不該計入分潤（例如測試單、內部單、
 * 夥伴自己下的單）。取消後金額仍凍結在 meta 裡，只是不再列入待撥款。
 *
 * 併發處理與 `MarkSettlementsPaidUseCase` 完全相同（Domain 規則 + CAS 仲裁兩層守門），
 * 理由見該檔 docblock。
 *
 * ## 與 mark-paid 的兩個關鍵差異
 *
 * 1. **不寫 `_settled_at` / `_settled_by`**——`SettlementRecord::mark_cancelled()` 只改
 *    status。取消不是撥款，把它寫進「結算時間 / 結算人」欄位會讓那兩欄的語意變成
 *    「最後一次被動過」，之後任何以 `_settled_at` 統計實際撥款的報表都會算錯。
 * 2. **`paid` 不可轉 cancelled**（Domain 狀態機的規定，這裡不繞過）。錢已經撥出去了，
 *    改成「取消」等於帳面上抹掉一筆真實支出。要沖回請走 `reverse-paid`，
 *    它會留下 `refund_after_paid` 的稽核紀錄。
 */
final class MarkSettlementsCancelledUseCase {

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
	 * 執行批次取消
	 *
	 * @param int[] $order_item_ids   要標記的 order_item_id 清單
	 * @param int   $operator_user_id 操作者 user ID（僅寫入稽核紀錄）
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

			try {
				$record->mark_cancelled();
			} catch ( \Throwable $e ) {
				$skipped[ $id ] = SettlementBatchGuard::describe_illegal_transition(
					$e,
					'取消',
					'待撥款（pending）；已撥款請改用「人工沖回」'
				);
				continue;
			}

			if ( ! $this->mutator->compare_and_set( $id, SettlementRecord::STATUS_PENDING, SettlementRecord::STATUS_CANCELLED ) ) {
				$skipped[ $id ] = SettlementBatchGuard::describe_lost_race(
					$this->mutator->current_status( $id ),
					'標記為取消'
				);
				continue;
			}

			$updated[] = $record;
		}

		$audit_failed = $this->auditor->record( $updated, SettlementAuditRecorder::TYPE_CANCEL, $operator_user_id );

		return new SettlementBatchOutput(
			updated_ids: array_map( static fn( SettlementRecord $r ): int => $r->order_item_id, $updated ),
			skipped: $skipped,
			total_amount: SettlementAuditRecorder::sum_amount( $updated ),
			audit_log_failed_partner_ids: $audit_failed,
		);
	}
}
