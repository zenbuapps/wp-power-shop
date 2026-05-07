<?php
/**
 * WC 商品查找實作
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\ProductLookupInterface;

/**
 * 透過 wc_get_product() 查 WooCommerce 商品的 ProductLookup 實作
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.1
 */
final class WpProductLookup implements ProductLookupInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 商品是否存在
	 *
	 * @param int $product_id 商品 ID
	 *
	 * @return bool
	 */
	public function exists( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = \wc_get_product( $product_id );
		return (bool) $product;
	}

	/**
	 * Variation 是否屬於該商品
	 *
	 * @param int $product_id   父商品 ID
	 * @param int $variation_id Variation ID
	 *
	 * @return bool
	 */
	public function has_variation( int $product_id, int $variation_id ): bool {
		if ( $product_id <= 0 || $variation_id <= 0 ) {
			return false;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$variation = \wc_get_product( $variation_id );
		if ( ! $variation ) {
			return false;
		}

		// Variation 的 parent id 必須與目標 product_id 相符。
		$parent_id = method_exists( $variation, 'get_parent_id' )
		? (int) $variation->get_parent_id()
		: 0;

		return $parent_id === $product_id;
	}
}
