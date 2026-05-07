<?php
/**
 * 商品快照提供者 Service
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\ProductSnapshot;

/**
 * 從 WC 端取得商品快照（含 variation）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §8
 *
 * 注入 ProductLookupInterface 確認商品存在；實際 hydrate 走 wc_get_product。
 * Phase 3-D 的 Cart hooks 才會大量呼叫此 Service，目前僅提供合約。
 */
final class ProductSnapshotProvider {

	/**
	 * 建構子
	 *
	 * @param ProductLookupInterface $product_lookup 商品查找抽象
	 */
	public function __construct(
		private readonly ProductLookupInterface $product_lookup
	) {}

	/**
	 * 取得指定商品（含可選 variation）的快照
	 *
	 * @param int      $product_id   商品 ID
	 * @param int|null $variation_id Variation ID（可為 null）
	 *
	 * @throws ProductNotFound 當商品不存在時拋出
	 *
	 * @return ProductSnapshot
	 */
	public function snapshot_for( int $product_id, ?int $variation_id ): ProductSnapshot {
		if ( ! $this->product_lookup->exists( $product_id ) ) {
			throw new ProductNotFound( "商品 ID {$product_id} 不存在" );
		}

		// 直接讀 wc_get_product 重建 snapshot；若 WC 不在（純 PHP 環境）回退最小快照。
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new ProductSnapshot(
				id: $product_id,
				regular_price: '0',
				sale_price: null,
				signup_fee: null,
				variations: []
			);
		}

		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			throw new ProductNotFound( "商品 ID {$product_id} 不存在" );
		}

		$regular_price = (string) $product->get_regular_price();
		$sale_price    = $product->get_sale_price();
		$sale_price    = ( '' === (string) $sale_price ) ? null : (string) $sale_price;

		$variations = [];
		if ( null !== $variation_id && $variation_id > 0 ) {
			$variation = \wc_get_product( $variation_id );
			if ( $variation ) {
				$v_sale                  = $variation->get_sale_price();
				$v_sale                  = ( '' === (string) $v_sale ) ? null : (string) $v_sale;
				$variations[ $variation_id ] = [
					'regular_price' => (string) $variation->get_regular_price(),
					'sale_price'    => $v_sale,
					'signup_fee'    => null,
				];
			}
		}

		return new ProductSnapshot(
			id: $product_id,
			regular_price: '' === $regular_price ? '0' : $regular_price,
			sale_price: $sale_price,
			signup_fee: null,
			variations: $variations
		);
	}
}
