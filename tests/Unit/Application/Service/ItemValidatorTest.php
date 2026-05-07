<?php
/**
 * ItemValidator Service 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.1
 * 對應實作（master 將要寫的）：
 *   inc/classes/Domains/ProfitShop/Application/Service/ItemValidator.php
 *   inc/classes/Domains/ProfitShop/Application/Service/ProductLookupInterface.php （新建）
 *
 * 預定責任：
 * - 驗證每個 item 的 product_id 存在
 * - 驗證每個 variation_id 屬於對應 product_id（拋 InvalidVariation）
 *
 * 此 Service 在 Phase 3-A 為骨架（method body 拋 BadMethodCallException），
 * 本測試為 master 在 Green 階段重新撰寫 ItemValidator 時的行為合約。
 *
 * Green 階段 master 必須：
 *   - 建立 ProductLookupInterface（具 exists / has_variation 方法）
 *   - 將 ItemValidator 重構為可注入該介面
 *
 * 注意：本測試以 instanceof 動態檢查避免 import 不存在的 interface
 *       導致 PHPUnit 載入時直接 fatal。
 *
 * @group profit_shop
 * @group application
 * @group service
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\ItemValidator;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ProductLookupInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidVariation;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotFound;
use PHPUnit\Framework\TestCase;

/**
 * ItemValidator 行為合約測試
 */
final class ItemValidatorTest extends TestCase {

	/**
	 * happy：所有 product_id 與 variation 皆存在 → 通過驗證
	 *
	 * @group happy
	 */
	public function test_passes_when_all_products_and_variations_exist(): void {
		$lookup    = $this->makeLookup(
			products: [ 100 => true ],
			variations: [ 100 => [ 200 => true ] ]
		);
		$validator = new ItemValidator( productLookup: $lookup );

		$validator->validate(
			[
				[
					'product_id' => 100,
					'override'   => [ 'regular_price' => '800' ],
					'variations' => [
						200 => [ 'override' => [ 'regular_price' => '850' ] ],
					],
				],
			]
		);

		$this->addToAssertionCount( 1 );
	}

	/**
	 * error：product_id 不存在 → 拋 ProductNotFound
	 *
	 * @group error
	 */
	public function test_throws_product_not_found_when_product_missing(): void {
		$lookup    = $this->makeLookup( products: [], variations: [] );
		$validator = new ItemValidator( productLookup: $lookup );

		$this->expectException( ProductNotFound::class );
		$validator->validate(
			[ [ 'product_id' => 9999, 'override' => [] ] ]
		);
	}

	/**
	 * error：variation_id 不屬於該 product_id → 拋 InvalidVariation
	 *
	 * @group error
	 */
	public function test_throws_invalid_variation_when_variation_does_not_belong_to_product(): void {
		$lookup    = $this->makeLookup(
			products: [ 100 => true ],
			variations: [ 100 => [] ]
		);
		$validator = new ItemValidator( productLookup: $lookup );

		$this->expectException( InvalidVariation::class );
		$validator->validate(
			[
				[
					'product_id' => 100,
					'override'   => [],
					'variations' => [
						999 => [ 'override' => [ 'regular_price' => '500' ] ],
					],
				],
			]
		);
	}

	/**
	 * edge：items 為空 → 視為合法
	 *
	 * @group edge
	 */
	public function test_passes_when_items_empty(): void {
		$lookup    = $this->makeLookup( products: [], variations: [] );
		$validator = new ItemValidator( productLookup: $lookup );

		$validator->validate( [] );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * 動態建立 ProductLookup 替身
	 *
	 * 使用 anonymous class（不 implements interface 來避免測試載入時 fatal），
	 * Green 階段 master 必須建立 ProductLookupInterface 並讓 ItemValidator 接受它；
	 * 此 anonymous class 的 method 簽名與該 interface 一致，PHP 8 nominal-typing
	 * 下會在 master 補完 interface 後改成正式實作（或保留 anon class 但 implements 之）。
	 *
	 * @param array<int, bool>             $products   product_id => bool
	 * @param array<int, array<int, bool>> $variations product_id => variation_id => bool
	 *
	 * @return object 帶 exists() 與 has_variation() 兩 method 的物件
	 */
	private function makeLookup( array $products, array $variations ): ProductLookupInterface {
		return new class( $products, $variations ) implements ProductLookupInterface {

			/**
			 * @param array<int, bool>             $products
			 * @param array<int, array<int, bool>> $variations
			 */
			public function __construct(
				private readonly array $products,
				private readonly array $variations
			) {}

			public function exists( int $product_id ): bool {
				return $this->products[ $product_id ] ?? false;
			}

			public function has_variation( int $product_id, int $variation_id ): bool {
				return isset( $this->variations[ $product_id ][ $variation_id ] )
					? (bool) $this->variations[ $product_id ][ $variation_id ]
					: false;
			}
		};
	}
}
