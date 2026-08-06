<?php
/**
 * SettlementRepository 測試替身（記憶體實作）
 */

declare( strict_types=1 );

namespace Tests\Support;

use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\SettlementRepositoryInterface;

/**
 * 記憶體版 SettlementRepository
 *
 * `implements SettlementRepositoryInterface` 是刻意的——production 介面若新增方法，
 * 這個 fake 會在載入時就 fatal，而不是等到某個測試恰好呼叫到才發現。
 *
 * 額外能力（測試專用，不在 production 介面上）：
 *   - `seed()`：預埋紀錄
 *   - `$save_calls`：記錄 save() 被呼叫的順序與當下狀態，供併發 / 冪等測試斷言
 *   - `fail_save_for()`：讓指定 order_item_id 的 save() 拋例外，模擬持久化失敗
 */
final class InMemorySettlementRepository implements SettlementRepositoryInterface {

	/**
	 * order_item_id => SettlementRecord
	 *
	 * @var array<int, SettlementRecord>
	 */
	private array $records = [];

	/**
	 * 每次 save() 的快照（order_item_id => status），依呼叫順序
	 *
	 * @var array<int, array{order_item_id: int, status: string, settled_at: int|null, settled_by: int|null}>
	 */
	public array $save_calls = [];

	/**
	 * 這些 order_item_id 的 save() 會拋例外
	 *
	 * @var int[]
	 */
	private array $failing_ids = [];

	/**
	 * 預埋一筆紀錄
	 *
	 * @param SettlementRecord $record 紀錄
	 *
	 * @return void
	 */
	public function seed( SettlementRecord $record ): void {
		$this->records[ $record->order_item_id ] = $record;
	}

	/**
	 * 讓指定 order_item_id 的 save() 拋例外
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return void
	 */
	public function fail_save_for( int $order_item_id ): void {
		$this->failing_ids[] = $order_item_id;
	}

	/**
	 * 依 order_item_id 取得結算記錄
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return SettlementRecord|null
	 */
	public function find_by_order_item( int $order_item_id ): ?SettlementRecord {
		return $this->records[ $order_item_id ] ?? null;
	}

	/**
	 * 儲存結算記錄
	 *
	 * @param SettlementRecord $record 紀錄
	 *
	 * @throws \RuntimeException 當該 ID 被設定為失敗時拋出
	 *
	 * @return void
	 */
	public function save( SettlementRecord $record ): void {
		if ( in_array( $record->order_item_id, $this->failing_ids, true ) ) {
			throw new \RuntimeException( '模擬持久化失敗' );
		}

		$this->records[ $record->order_item_id ] = $record;
		$this->save_calls[]                      = [
			'order_item_id' => $record->order_item_id,
			'status'        => $record->status,
			'settled_at'    => $record->settled_at,
			'settled_by'    => $record->settled_by,
		];
	}

	/**
	 * 找出某 partner 旗下的結算記錄
	 *
	 * @param int            $term_id  Partner term ID
	 * @param FilterCriteria $criteria 過濾條件（本 fake 忽略）
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
	 * 找出某賣場的結算記錄
	 *
	 * @param int            $shop_id  賣場 ID
	 * @param FilterCriteria $criteria 過濾條件（本 fake 忽略）
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
}
