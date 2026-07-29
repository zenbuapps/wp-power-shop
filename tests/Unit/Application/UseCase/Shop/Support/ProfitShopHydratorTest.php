<?php
/**
 * ProfitShopHydrator characterization test（BUG-A 回歸鎖）
 *
 * 對應規格：
 *   - specs/plan/2026-07-29-profit-shop-product-selector-plan.md §0（BUG-A 是純前端問題）、§測試策略
 *   - specs/features/profit-shop/save-shop-item-overrides.feature
 *
 * 測試對象（只讀不改）：
 *   inc/classes/Domains/ProfitShop/Application/UseCase/Shop/Support/ProfitShopHydrator.php
 *
 * 背景：ProfitShopHydrator 目前零測試覆蓋（grep -rln "Hydrator" tests/ → 0 命中），
 * 這正是 BUG-A（前端扁平 items 契約導致價格覆寫被靜默丟棄）潛伏至今的根本原因。
 * PHP 產品程式碼本次變更 = 0，本檔只是 retrofit characterization test 鎖住既有
 * 正確行為——所有測試第一次跑就應該全綠。
 *
 * @group profit_shop
 * @group application
 * @group hydrator
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop\Support;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPriceOverride;
use PHPUnit\Framework\TestCase;

/**
 * ProfitShopHydrator 測試
 */
final class ProfitShopHydratorTest extends TestCase {

	/**
	 * happy：完整巢狀 override + variations 透過 from_input → to_output 完整 round-trip
	 *
	 * 這是 BUG-A 修正後前端「應該送」的正確契約形狀，用來對照 test_flat_price_override_key_is_completely_ignored
	 * 這個「舊契約已死」的回歸鎖。
	 *
	 * @group happy
	 */
	public function test_from_input_and_to_output_round_trip_preserves_nested_override_and_variations(): void {
		$input = $this->build_input(
			[
				[
					'product_id'     => 101,
					'inflated_count' => 50,
					'override'       => [
						'regular_price' => '888',
						'sale_price'    => '666',
						'signup_fee'    => null,
					],
					'variations'     => [
						301 => [
							'override' => [
								'regular_price' => '900',
								'sale_price'    => '850',
								'signup_fee'    => null,
							],
						],
					],
				],
			]
		);

		$shop = ProfitShopHydrator::from_input( 500, $input );

		// ---- 先驗證 Entity 端（防空斷言：先確認筆數與存在性，才驗欄位值）----
		$items = $shop->items();
		$this->assertCount( 1, $items );

		$item = $items[0];
		$this->assertSame( 101, $item->product_id );
		$this->assertSame( '888', $item->override->regular_price );
		$this->assertSame( '666', $item->override->sale_price );
		$this->assertNull( $item->override->signup_fee );
		$this->assertSame( 50, $item->inflated_count->value() );
		$this->assertCount( 1, $item->variations );
		$this->assertArrayHasKey( 301, $item->variations );
		$this->assertSame( '900', $item->variations[301]->regular_price );
		$this->assertSame( '850', $item->variations[301]->sale_price );

		// ---- 再驗證 to_output() 序列化端（完整 round-trip 到 API response 形狀）----
		$output = ProfitShopHydrator::to_output( $shop, '2026-01-01 00:00:00', '2026-01-02 00:00:00' );
		$this->assertCount( 1, $output->items );

		$out_item = $output->items[0];
		$this->assertSame( 101, $out_item['product_id'] );
		$this->assertSame( 50, $out_item['inflated_count'] );
		$this->assertSame(
			[
				'regular_price' => '888',
				'sale_price'    => '666',
				'signup_fee'    => null,
			],
			$out_item['override']
		);
		$this->assertArrayHasKey( 301, $out_item['variations'] );
		$this->assertSame(
			[
				'regular_price' => '900',
				'sale_price'    => '850',
				'signup_fee'    => null,
			],
			$out_item['variations'][301]['override']
		);
	}

	/**
	 * edge：#1 override 完全缺席 → 三欄皆 null
	 *
	 * @group edge
	 */
	public function test_missing_override_key_results_in_all_null_price_fields(): void {
		$input = $this->build_input(
			[
				[ 'product_id' => 201 ],
			]
		);

		$item = ProfitShopHydrator::from_input( 1, $input )->items()[0];

		$this->assertSame( 201, $item->product_id );
		$this->assertNull( $item->override->regular_price );
		$this->assertNull( $item->override->sale_price );
		$this->assertNull( $item->override->signup_fee );
	}

	/**
	 * edge：#2 regular_price: "" → 視為 null（nullable_string 空字串正規化）
	 *
	 * @group edge
	 */
	public function test_empty_string_regular_price_is_treated_as_null(): void {
		$input = $this->build_input(
			[
				[
					'product_id' => 202,
					'override'   => [
						'regular_price' => '',
						'sale_price'    => null,
						'signup_fee'    => null,
					],
				],
			]
		);

		$item = ProfitShopHydrator::from_input( 1, $input )->items()[0];

		$this->assertNull( $item->override->regular_price, '空字串應正規化為 null' );
	}

	/**
	 * edge：#3 variations 鍵為字串 "1031" → 轉為 int 1031
	 *
	 * @group edge
	 */
	public function test_string_variation_key_is_cast_to_int(): void {
		$input = $this->build_input(
			[
				[
					'product_id' => 203,
					'variations' => [
						'1031' => [
							'override' => [
								'regular_price' => '500',
								'sale_price'    => null,
								'signup_fee'    => null,
							],
						],
					],
				],
			]
		);

		$item = ProfitShopHydrator::from_input( 1, $input )->items()[0];

		$this->assertCount( 1, $item->variations );
		$this->assertArrayHasKey( 1031, $item->variations );
		$this->assertIsInt( array_key_first( $item->variations ), 'variations 鍵必須是 int，而非字串' );
		$this->assertSame( '500', $item->variations[1031]->regular_price );
	}

	/**
	 * edge：#4 variations 鍵為 "0" / 負數 → 略過（僅保留合法正整數鍵）
	 *
	 * @group edge
	 */
	public function test_variation_keys_zero_and_negative_are_skipped(): void {
		$input = $this->build_input(
			[
				[
					'product_id' => 204,
					'variations' => [
						'0'  => [ 'override' => [ 'regular_price' => '100', 'sale_price' => null, 'signup_fee' => null ] ],
						'-5' => [ 'override' => [ 'regular_price' => '200', 'sale_price' => null, 'signup_fee' => null ] ],
						'10' => [ 'override' => [ 'regular_price' => '300', 'sale_price' => null, 'signup_fee' => null ] ],
					],
				],
			]
		);

		$item = ProfitShopHydrator::from_input( 1, $input )->items()[0];

		$this->assertCount( 1, $item->variations, '鍵 0 與負數應被略過，只剩下鍵 10' );
		$this->assertArrayHasKey( 10, $item->variations );
		$this->assertArrayNotHasKey( 0, $item->variations );
		$this->assertArrayNotHasKey( -5, $item->variations );
	}

	/**
	 * edge：#5 product_id: "101"（字串）→ 轉為 int 101
	 *
	 * @group edge
	 */
	public function test_string_product_id_is_cast_to_int(): void {
		$input = $this->build_input(
			[
				[ 'product_id' => '101' ],
			]
		);

		$item = ProfitShopHydrator::from_input( 1, $input )->items()[0];

		$this->assertSame( 101, $item->product_id );
		$this->assertIsInt( $item->product_id );
	}

	/**
	 * error：#6 sale_price "60000" > regular_price "50000" → 拋出 InvalidPriceOverride
	 *
	 * @group error
	 */
	public function test_sale_price_greater_than_regular_price_throws_invalid_price_override(): void {
		$input = $this->build_input(
			[
				[
					'product_id' => 205,
					'override'   => [
						'regular_price' => '50000',
						'sale_price'    => '60000',
						'signup_fee'    => null,
					],
				],
			]
		);

		$this->expectException( InvalidPriceOverride::class );
		ProfitShopHydrator::from_input( 1, $input );
	}

	/**
	 * error：#7a regular_price "-100"（負數）→ 拋出 InvalidPriceOverride
	 *
	 * @group error
	 */
	public function test_negative_regular_price_throws_invalid_price_override(): void {
		$input = $this->build_input(
			[
				[
					'product_id' => 206,
					'override'   => [
						'regular_price' => '-100',
						'sale_price'    => null,
						'signup_fee'    => null,
					],
				],
			]
		);

		$this->expectException( InvalidPriceOverride::class );
		ProfitShopHydrator::from_input( 1, $input );
	}

	/**
	 * error：#7b regular_price "abc"（非數字字串）→ 拋出 InvalidPriceOverride
	 *
	 * @group error
	 */
	public function test_non_numeric_regular_price_throws_invalid_price_override(): void {
		$input = $this->build_input(
			[
				[
					'product_id' => 207,
					'override'   => [
						'regular_price' => 'abc',
						'sale_price'    => null,
						'signup_fee'    => null,
					],
				],
			]
		);

		$this->expectException( InvalidPriceOverride::class );
		ProfitShopHydrator::from_input( 1, $input );
	}

	/**
	 * edge：#8【最重要，BUG-A 回歸鎖】扁平 price_override / variation_id 完全被忽略，三欄仍 null
	 *
	 * 舊版前端（BUG-A 成因）送出的是扁平欄位 `price_override` / `variation_id`，
	 * 而非 Domain 契約定義的巢狀 `override.regular_price` / `variations`。
	 * Hydrator 只讀 `row['override']` / `row['variations']`，從未讀過這些扁平鍵——
	 * 這正是「價格覆寫被靜默丟棄」的根因所在，也是本次修正後必須繼續成立的既有行為。
	 *
	 * @group edge
	 * @group smoke
	 * @group bug_a_regression
	 */
	public function test_flat_price_override_key_is_completely_ignored(): void {
		$input = $this->build_input(
			[
				[
					'product_id'     => 208,
					// 舊契約的扁平欄位，Hydrator 不應讀取這兩個鍵：
					'price_override' => 50000,
					'variation_id'   => 999,
				],
			]
		);

		$item = ProfitShopHydrator::from_input( 1, $input )->items()[0];

		$this->assertSame( 208, $item->product_id, '前提：product_id 本身仍應正確讀取' );
		$this->assertNull( $item->override->regular_price, 'BUG-A 回歸鎖：扁平 price_override 不應被讀取為 regular_price' );
		$this->assertNull( $item->override->sale_price, 'BUG-A 回歸鎖：扁平 price_override 不應被讀取為 sale_price' );
		$this->assertNull( $item->override->signup_fee );
		$this->assertSame( [], $item->variations, 'BUG-A 回歸鎖：扁平 variation_id 不應被讀取進 variations' );
	}

	/**
	 * edge：#9 product_id <= 0（0 或負數）→ 靜默跳過（E-7 既有行為，鎖住避免日後誤改）
	 *
	 * ItemValidator 已在更前面攔截不存在的商品，此處是縱深防禦；
	 * 維持現況即可，不應拋例外也不應被寫入。
	 *
	 * @group edge
	 */
	public function test_product_id_zero_or_negative_is_silently_skipped(): void {
		$input = $this->build_input(
			[
				[ 'product_id' => 0 ],
				[ 'product_id' => -3 ],
				[ 'product_id' => 209 ],
			]
		);

		$items = ProfitShopHydrator::from_input( 1, $input )->items();

		$this->assertCount( 1, $items, 'product_id<=0 的兩筆應被靜默跳過，只剩下合法的 209' );
		$this->assertSame( 209, $items[0]->product_id );
	}

	// ========== Helpers ==========

	/**
	 * 建立一個帶有指定 items 的 ProfitShopInput（其它賣場層級欄位使用合法預設值）
	 *
	 * @param array<int, array<string, mixed>> $items     items 原始陣列
	 * @param array<string, mixed>             $overrides 覆寫賣場層級欄位（title / slug / mode ...）
	 */
	private function build_input( array $items, array $overrides = [] ): ProfitShopInput {
		return ProfitShopInput::from_array(
			array_merge(
				[
					'title'           => '測試賣場',
					'slug'            => 'hydrator-test-shop',
					'status'          => 'draft',
					'mode'            => 'page',
					'partner_term_id' => 1,
					'rate'            => 10,
					'items'           => $items,
					'settings'        => [],
				],
				$overrides
			)
		);
	}
}
