<?php
/**
 * In-Memory SettlementRepository（測試替身）
 *
 * 提供 SettlementRepositoryInterface 的純記憶體實作。
 *
 * Phase 3-B 範圍主要不直接用此 fake，但 DeleteShop / DeletePartner
 * 等 UseCase 可能查詢結算紀錄判斷可刪除性，故保留。
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Fakes;

use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\SettlementRepositoryInterface;

/**
 * 純記憶體 Settlement Repository
 *
 * @internal Phase 3-B 測試用替身
 */
final class InMemorySettlementRepository implements SettlementRepositoryInterface {

	/**
	 * order_item_id => SettlementRecord
	 *
	 * @var array<int, SettlementRecord>
	 */
	private array $records = [];

	/**
	 * @param int $order_item_id 訂單品項 ID
	 */
	public function find_by_order_item( int $order_item_id ): ?SettlementRecord {
		return $this->records[ $order_item_id ] ?? null;
	}

	/**
	 * @param SettlementRecord $record 結算記錄
	 */
	public function save( SettlementRecord $record ): void {
		$this->records[ $record->order_item_id ] = $record;
	}

	/**
	 * @param int            $term_id  Partner term ID
	 * @param FilterCriteria $criteria 過濾條件
	 *
	 * @return SettlementRecord[]
	 */
	public function find_by_partner( int $term_id, FilterCriteria $criteria ): array {
		return array_values(
			array_filter(
				$this->records,
				static fn( SettlementRecord $r ): bool => $r->partner_term_id === $term_id
			)
		);
	}

	/**
	 * @param int            $shop_id  賣場 ID
	 * @param FilterCriteria $criteria 過濾條件
	 *
	 * @return SettlementRecord[]
	 */
	public function find_by_shop( int $shop_id, FilterCriteria $criteria ): array {
		return array_values(
			array_filter(
				$this->records,
				static fn( SettlementRecord $r ): bool => $r->shop_id === $shop_id
			)
		);
	}

	// ========== Test helper ==========

	/**
	 * 預植入結算紀錄
	 */
	public function seed( SettlementRecord $record ): void {
		$this->records[ $record->order_item_id ] = $record;
	}

	/**
	 * 取得所有紀錄
	 *
	 * @return SettlementRecord[]
	 */
	public function all(): array {
		return array_values( $this->records );
	}
}
