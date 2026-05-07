<?php
/**
 * 舊版「一頁商店」資料源介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * 舊版（legacy）一頁商店資料源抽象
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * 簽名與 InMemoryLegacyShopRepository（測試替身）對齊：
 * - all(): array<int, array<string, mixed>>
 * - find(int $id): ?array<string, mixed>
 */
interface LegacyShopRepositoryInterface {

	/**
	 * 列出所有舊版一頁商店
	 *
	 * @return array<int, array<string, mixed>> 每筆至少含 id / title / meta
	 */
	public function all(): array;

	/**
	 * 依 ID 取得舊版一頁商店
	 *
	 * @param int $id 舊版資料 ID
	 *
	 * @return array<string, mixed>|null 不存在時回 null
	 */
	public function find( int $id ): ?array;
}
