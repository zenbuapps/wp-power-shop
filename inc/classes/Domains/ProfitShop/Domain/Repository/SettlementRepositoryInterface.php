<?php
/**
 * 結算記錄 Repository 介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Repository;

use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;

/**
 * 分潤結算記錄的持久化抽象。
 *
 * Infrastructure 層提供具體實作（CustomTableSettlementRepository），
 * 內部以自訂資料表儲存 line item 級別的結算紀錄。
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5、§5。
 */
interface SettlementRepositoryInterface {

	/**
	 * 依 order_item_id 取得結算記錄
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return SettlementRecord|null 找不到時回傳 null
	 */
	public function find_by_order_item( int $order_item_id ): ?SettlementRecord;

	/**
	 * 儲存結算記錄（新增或更新）
	 *
	 * @param SettlementRecord $record 結算記錄
	 *
	 * @return void
	 */
	public function save( SettlementRecord $record ): void;

	/**
	 * 找出某 partner 旗下的結算記錄
	 *
	 * @param int            $term_id  Partner term ID
	 * @param FilterCriteria $criteria 過濾條件
	 *
	 * @return SettlementRecord[]
	 */
	public function find_by_partner( int $term_id, FilterCriteria $criteria ): array;

	/**
	 * 找出某賣場的結算記錄
	 *
	 * @param int            $shop_id  賣場 ID
	 * @param FilterCriteria $criteria 過濾條件
	 *
	 * @return SettlementRecord[]
	 */
	public function find_by_shop( int $shop_id, FilterCriteria $criteria ): array;
}
