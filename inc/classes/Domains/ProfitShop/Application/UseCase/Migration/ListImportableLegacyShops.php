<?php
/**
 * 列出可匯入舊版一頁商店 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration;

use J7\PowerShop\Domains\ProfitShop\Application\Service\LegacyShopRepositoryInterface;

/**
 * 列出可匯入的舊版一頁商店 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * 直接回傳 array<int, array<string, mixed>>（每筆含 id / title / meta），
 * V2Api 層直接序列化為 JSON。
 */
final class ListImportableLegacyShops {

	/**
	 * 建構子
	 *
	 * @param LegacyShopRepositoryInterface $legacyRepo Legacy 資料源
	 */
	public function __construct(
		private readonly LegacyShopRepositoryInterface $legacyRepo
	) {}

	/**
	 * 執行列表查詢
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function execute(): array {
		return $this->legacyRepo->all();
	}
}
