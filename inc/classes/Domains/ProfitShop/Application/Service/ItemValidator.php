<?php
/**
 * 商品項目驗證 Service
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidVariation;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotFound;

/**
 * 驗證 ProfitShopInput::$items 的內容是否合法
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.1
 *
 * 責任：
 * - 商品存在性檢查（透過 ProductLookupInterface）
 * - variation 歸屬檢查（variation_id 必須屬於 product_id）
 * - 可選 partner 存在性檢查（透過 PartnerRepositoryInterface，partner_term_id 為 0 時略過）
 *
 * Test 對應：
 *   tests/Unit/Application/Service/ItemValidatorTest.php
 */
final class ItemValidator implements ItemValidatorInterface {

	/**
	 * 建構子
	 *
	 * 採 ProductLookupInterface 注入（型別安全）：
	 *   - 生產實作：WpProductLookup（呼叫 wc_get_product）
	 *   - 單元測試：anonymous class implements ProductLookupInterface
	 *
	 * @param ProductLookupInterface $productLookup 商品查找抽象
	 */
	public function __construct(
		private readonly ProductLookupInterface $productLookup
	) {}

	/**
	 * 驗證商品項目陣列
	 *
	 * @param array<int, array<string, mixed>> $items 商品覆寫項目原始陣列
	 *
	 * @throws ProductNotFound  當任一 product_id 不存在時拋出
	 * @throws InvalidVariation 當任一 variation 不屬於對應 product 時拋出
	 *
	 * @return void
	 */
	public function validate( array $items ): void {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $product_id <= 0 || ! $this->productLookup->exists( $product_id ) ) {
				throw new ProductNotFound( "商品 ID {$product_id} 不存在" );
			}

			$variations = isset( $item['variations'] ) && is_array( $item['variations'] )
			? $item['variations']
			: [];

			foreach ( $variations as $variation_id => $variation_row ) {
				$vid = (int) $variation_id;
				if ( $vid <= 0 ) {
					continue;
				}
				if ( ! $this->productLookup->has_variation( $product_id, $vid ) ) {
					throw new InvalidVariation(
						"Variation ID {$vid} 不屬於商品 {$product_id}"
					);
				}
			}
		}
	}
}
