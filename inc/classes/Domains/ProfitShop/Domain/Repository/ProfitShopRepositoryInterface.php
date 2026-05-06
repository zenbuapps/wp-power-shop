<?php
/**
 * 分潤賣場 Repository 介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Repository;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;

/**
 * 分潤賣場聚合根的持久化抽象。
 *
 * Infrastructure 層提供具體實作（CptProfitShopRepository）。
 * 對應規格：specs/2026-05-06-profit-shop-design.md §1.5、§2.2。
 */
interface ProfitShopRepositoryInterface {

	/**
	 * 依 ID 取得賣場
	 *
	 * @param int $id 賣場 ID
	 *
	 * @return ProfitShop|null 找不到時回傳 null
	 */
	public function find( int $id ): ?ProfitShop;

	/**
	 * 儲存賣場（新增或更新）
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 *
	 * @return int 賣場 ID
	 */
	public function save( ProfitShop $shop ): int;

	/**
	 * 刪除賣場（trash）
	 *
	 * @param int $id 賣場 ID
	 *
	 * @return void
	 */
	public function delete( int $id ): void;

	/**
	 * 找出某 partner 旗下的所有賣場
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return ProfitShop[]
	 */
	public function find_by_partner( int $term_id ): array;
}
