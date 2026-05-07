<?php
/**
 * 商品 / Variation 存在性查找介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * 商品 / Variation 存在性查找抽象
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.1
 *
 * Application 層用此抽象避免直接依賴 wc_get_product，
 * Domain 不感知 WC，單元測試可注入 in-memory 替身。
 */
interface ProductLookupInterface {

	/**
	 * 商品是否存在
	 *
	 * @param int $product_id 商品 ID
	 *
	 * @return bool
	 */
	public function exists( int $product_id ): bool;

	/**
	 * Variation 是否屬於該商品
	 *
	 * @param int $product_id   父商品 ID
	 * @param int $variation_id Variation ID
	 *
	 * @return bool
	 */
	public function has_variation( int $product_id, int $variation_id ): bool;
}
