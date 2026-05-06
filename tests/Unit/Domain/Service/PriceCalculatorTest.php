<?php
/**
 * PriceCalculator Domain Service 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.3（Fallback chain 5 層）
 *
 * Fallback chain 結帳價（calculate）：
 *   1. variation override sale_price
 *   2. parent override sale_price
 *   3. variation original sale_price
 *   4. parent original sale_price
 *   5. variation original regular_price
 *   6. parent original regular_price
 *
 * Fallback chain Signup fee（calculate_signup_fee）：
 *   1. variation override signup_fee
 *   2. parent override signup_fee
 *   3. variation original signup_fee
 *   4. parent original signup_fee
 *   （全 null 回 null）
 *
 * 預期紅燈：
 * - Class J7\PowerShop\Domains\ProfitShop\Domain\Service\PriceCalculator not found
 * - Class J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\ProductSnapshot not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\PriceCalculator;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\ProductSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use PHPUnit\Framework\TestCase;

/**
 * PriceCalculator 測試
 */
final class PriceCalculatorTest extends TestCase {

	/**
	 * Fallback Layer 1：variation override sale_price 命中
	 *
	 * @group happy
	 */
	public function test_uses_variation_override_sale_price_when_present(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( '900.00', '700.00', null ),
			variation_overrides: [
				456 => new PriceOverride( '850.00', '600.00', null ),
			]
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: '800.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => '750.00',
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, 456 );

		$this->assertSame( '600.00', $result );
	}

	/**
	 * Fallback Layer 2：variation override 無 sale_price → 使用 parent override sale_price
	 *
	 * @group happy
	 */
	public function test_falls_back_to_parent_override_sale_price(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, '500.00', null ),
			variation_overrides: [
				456 => new PriceOverride( null, null, null ),
			]
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: '800.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => '750.00',
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, 456 );

		$this->assertSame( '500.00', $result );
	}

	/**
	 * Fallback Layer 3：override 全空 → 使用 variation original sale_price
	 *
	 * @group happy
	 */
	public function test_falls_back_to_variation_original_sale_price(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, null ),
			variation_overrides: [
				456 => new PriceOverride( null, null, null ),
			]
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: '800.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => '750.00',
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, 456 );

		$this->assertSame( '750.00', $result );
	}

	/**
	 * Fallback Layer 4：override 全空 + variation 無原始 sale → 使用 parent original sale_price
	 *
	 * @group happy
	 */
	public function test_falls_back_to_parent_original_sale_price(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, null ),
			variation_overrides: [
				456 => new PriceOverride( null, null, null ),
			]
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: '800.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => null,
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, 456 );

		$this->assertSame( '800.00', $result );
	}

	/**
	 * Fallback Layer 5：所有 sale 路徑皆 null → 使用 variation original regular_price
	 *
	 * @group happy
	 */
	public function test_falls_back_to_variation_original_regular_price(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, null ),
			variation_overrides: [
				456 => new PriceOverride( null, null, null ),
			]
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: null,
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => null,
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, 456 );

		$this->assertSame( '950.00', $result );
	}

	/**
	 * Fallback Layer 6：variation 無 regular → 使用 parent original regular_price
	 *
	 * @group happy
	 */
	public function test_falls_back_to_parent_original_regular_price(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, null ),
			variation_overrides: []
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: null,
			variations: []
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, null );

		$this->assertSame( '1000.00', $result );
	}

	/**
	 * variation_id = null：跳過所有 variation 層，直接走 parent 路徑
	 *
	 * @group edge
	 */
	public function test_variation_id_null_skips_variation_layers(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, '500.00', null ),
			variation_overrides: [
				// 即使有 variation override，傳入 null 也不該走進來
				456 => new PriceOverride( null, '100.00', null ),
			]
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: '800.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => '50.00',
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, null );

		// 應跳過 variation 全部直接拿 parent override sale
		$this->assertSame( '500.00', $result );
	}

	/**
	 * variation_id 不在 product.variations 內：跳過 variation 層走 parent
	 *
	 * @group edge
	 */
	public function test_variation_id_not_in_product_skips_variation_layers(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, '500.00', null ),
			variation_overrides: []
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: '800.00',
			variations: [
				// 只有 456，但 caller 傳 999
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => '50.00',
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, 999 );

		$this->assertSame( '500.00', $result );
	}

	/**
	 * calculate 應回傳十進位字串（型別保證）
	 *
	 * @group happy
	 */
	public function test_calculate_returns_decimal_string(): void {
		$item    = $this->make_item();
		$product = $this->make_product( regular_price: '1234.56' );

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, null );

		$this->assertIsString( $result );
		$this->assertSame( '1234.56', $result );
	}

	/**
	 * 0 元商品：sale_price='0' 應視為「有值」，不應 fallback 到後面
	 *
	 * @group edge
	 */
	public function test_zero_price_does_not_become_null(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, '0', null ),
			variation_overrides: []
		);
		$product = $this->make_product(
			regular_price: '1000.00',
			sale_price: '800.00',
			variations: []
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate( $item, $product, null );

		// '0' / '0.00' 是合法價格，不應被視為 null fallback
		$this->assertContains( $result, [ '0', '0.00' ] );
	}

	/**
	 * Signup fee Layer 1：variation override signup_fee 命中
	 *
	 * @group happy
	 */
	public function test_signup_fee_uses_variation_override_first(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, '300.00' ),
			variation_overrides: [
				456 => new PriceOverride( null, null, '200.00' ),
			]
		);
		$product = $this->make_product(
			signup_fee: '500.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => null,
					'signup_fee'    => '400.00',
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate_signup_fee( $item, $product, 456 );

		$this->assertSame( '200.00', $result );
	}

	/**
	 * Signup fee Layer 2：variation override 無 → parent override signup_fee
	 *
	 * @group happy
	 */
	public function test_signup_fee_falls_back_to_parent_override(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, '300.00' ),
			variation_overrides: [
				456 => new PriceOverride( null, null, null ),
			]
		);
		$product = $this->make_product(
			signup_fee: '500.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => null,
					'signup_fee'    => '400.00',
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate_signup_fee( $item, $product, 456 );

		$this->assertSame( '300.00', $result );
	}

	/**
	 * Signup fee Layer 3：override 全空 → variation original signup_fee
	 *
	 * @group happy
	 */
	public function test_signup_fee_falls_back_to_variation_original(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, null ),
			variation_overrides: [
				456 => new PriceOverride( null, null, null ),
			]
		);
		$product = $this->make_product(
			signup_fee: '500.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => null,
					'signup_fee'    => '400.00',
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate_signup_fee( $item, $product, 456 );

		$this->assertSame( '400.00', $result );
	}

	/**
	 * Signup fee Layer 4：override 全空 + variation 無 → parent original signup_fee
	 *
	 * @group happy
	 */
	public function test_signup_fee_falls_back_to_parent_original(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, null ),
			variation_overrides: [
				456 => new PriceOverride( null, null, null ),
			]
		);
		$product = $this->make_product(
			signup_fee: '500.00',
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => null,
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate_signup_fee( $item, $product, 456 );

		$this->assertSame( '500.00', $result );
	}

	/**
	 * Signup fee 全 null → 回傳 null
	 *
	 * @group edge
	 */
	public function test_signup_fee_returns_null_when_all_layers_null(): void {
		$item = $this->make_item(
			parent_override: new PriceOverride( null, null, null ),
			variation_overrides: [
				456 => new PriceOverride( null, null, null ),
			]
		);
		$product = $this->make_product(
			signup_fee: null,
			variations: [
				456 => [
					'regular_price' => '950.00',
					'sale_price'    => null,
					'signup_fee'    => null,
				],
			]
		);

		$calculator = new PriceCalculator();
		$result     = $calculator->calculate_signup_fee( $item, $product, 456 );

		$this->assertNull( $result );
	}

	/**
	 * 建立 OverrideItem fixture
	 *
	 * @param PriceOverride|null         $parent_override     Parent override
	 * @param array<int, PriceOverride>  $variation_overrides variation_id => PriceOverride
	 *
	 * @return OverrideItem
	 */
	private function make_item(
		?PriceOverride $parent_override = null,
		array $variation_overrides = []
	): OverrideItem {
		return new OverrideItem(
			product_id: 123,
			override: $parent_override ?? new PriceOverride( null, null, null ),
			inflated_count: new InflatedCount( 0 ),
			variations: $variation_overrides,
		);
	}

	/**
	 * 建立 ProductSnapshot fixture
	 *
	 * @param string                                                                                $regular_price 原價
	 * @param string|null                                                                            $sale_price    特價
	 * @param string|null                                                                            $signup_fee    Signup fee
	 * @param array<int, array{regular_price: string, sale_price: string|null, signup_fee: string|null}> $variations    variation 對照表
	 *
	 * @return ProductSnapshot
	 */
	private function make_product(
		string $regular_price = '1000.00',
		?string $sale_price = null,
		?string $signup_fee = null,
		array $variations = []
	): ProductSnapshot {
		return new ProductSnapshot(
			id: 123,
			regular_price: $regular_price,
			sale_price: $sale_price,
			signup_fee: $signup_fee,
			variations: $variations,
		);
	}
}
