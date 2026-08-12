<?php
/**
 * OverrideItem Entity 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.3、§8.5
 * 規範：分潤賣場單筆商品覆寫資料的 Entity；包含 product_id、override（PriceOverride）、
 *      inflated_count（InflatedCount）、variations（array<int, VariationOverride>）。
 *      to_array() 必須符合 spec §2.3 JSON 結構。
 *
 * Phase 9（2026-07-30）：variations 的 value 由 PriceOverride 升級為 VariationOverride
 *      （PriceOverride + InflatedCount），讓可變商品能逐變體設定灌水購買人數。
 *      get_variation_override() 改為委派但簽章不變，仍回傳 ?PriceOverride。
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Entity;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\VariationOverride;
use PHPUnit\Framework\TestCase;

/**
 * OverrideItem Entity 測試
 */
final class OverrideItemTest extends TestCase {

	/**
	 * 建立必要參數時建構成功
	 *
	 * @group happy
	 */
	public function test_construct_with_required_props_succeeds(): void {
		$override = new PriceOverride( '1000.00', '799.00', null );
		$count    = new InflatedCount( 100 );

		$item = new OverrideItem( 123, $override, $count, [] );

		$this->assertSame( 123, $item->product_id );
		$this->assertTrue( $override->equals( $item->override ) );
		$this->assertSame( 100, $item->inflated_count->value() );
		$this->assertSame( [], $item->variations );
	}

	/**
	 * variations 預設為空陣列（不需傳第 4 個參數）
	 *
	 * @group happy
	 */
	public function test_construct_with_empty_variations_default(): void {
		$override = new PriceOverride( '1000.00', '799.00', null );
		$count    = new InflatedCount( 50 );

		$item = new OverrideItem( 123, $override, $count );

		$this->assertSame( [], $item->variations );
	}

	/**
	 * set_variation_override 可新增 variation 覆寫
	 *
	 * @group happy
	 */
	public function test_set_variation_override_adds_new_variation(): void {
		$item    = $this->make_item( 123 );
		$var_vo  = new PriceOverride( '1100.00', '899.00', null );

		$item->set_variation_override( 456, $var_vo );

		$retrieved = $item->get_variation_override( 456 );
		$this->assertNotNull( $retrieved );
		$this->assertTrue( $var_vo->equals( $retrieved ) );
	}

	/**
	 * set_variation_override 對既有 variation_id 會覆寫
	 *
	 * @group happy
	 */
	public function test_set_variation_override_replaces_existing(): void {
		$item       = $this->make_item( 123 );
		$first_vo   = new PriceOverride( '1100.00', '899.00', null );
		$second_vo  = new PriceOverride( '1200.00', '999.00', null );

		$item->set_variation_override( 456, $first_vo );
		$item->set_variation_override( 456, $second_vo );

		$retrieved = $item->get_variation_override( 456 );
		$this->assertNotNull( $retrieved );
		$this->assertTrue( $second_vo->equals( $retrieved ) );
	}

	/**
	 * remove_variation_override 移除既有 variation
	 *
	 * @group happy
	 */
	public function test_remove_variation_override_removes_existing(): void {
		$item   = $this->make_item( 123 );
		$var_vo = new PriceOverride( '1100.00', '899.00', null );
		$item->set_variation_override( 456, $var_vo );

		$item->remove_variation_override( 456 );

		$this->assertNull( $item->get_variation_override( 456 ) );
	}

	/**
	 * remove_variation_override 對不存在的 variation_id 應靜默略過（不拋例外）
	 *
	 * @group edge
	 */
	public function test_remove_variation_override_silently_skips_when_not_found(): void {
		$item = $this->make_item( 123 );

		$item->remove_variation_override( 999 );

		// 不應拋例外；對等 assertion 確認 variations 仍為空陣列
		$this->assertSame( [], $item->variations );
	}

	/**
	 * get_variation_override 在 variation 存在時回傳對應 PriceOverride
	 *
	 * @group happy
	 */
	public function test_get_variation_override_returns_override_when_present(): void {
		$item   = $this->make_item( 123 );
		$var_vo = new PriceOverride( '1100.00', '899.00', null );
		$item->set_variation_override( 456, $var_vo );

		$retrieved = $item->get_variation_override( 456 );

		$this->assertInstanceOf( PriceOverride::class, $retrieved );
		$this->assertTrue( $var_vo->equals( $retrieved ) );
	}

	/**
	 * get_variation_override 在 variation 不存在時回傳 null
	 *
	 * @group edge
	 */
	public function test_get_variation_override_returns_null_when_absent(): void {
		$item = $this->make_item( 123 );

		$this->assertNull( $item->get_variation_override( 999 ) );
	}

	/**
	 * to_array 序列化結果符合 spec §2.3 JSON 結構
	 *
	 * @group happy
	 */
	public function test_to_array_serializes_per_spec_2_3(): void {
		$override = new PriceOverride( '1000.00', '799.00', null );
		$count    = new InflatedCount( 100 );
		$item     = new OverrideItem( 123, $override, $count );

		$var_vo = new PriceOverride( '1100.00', '899.00', null );
		$item->set_variation_override( 456, $var_vo );

		$expected = [
			'product_id'     => 123,
			'inflated_count' => 100,
			'override'       => [
				'regular_price' => '1000.00',
				'sale_price'    => '799.00',
				'signup_fee'    => null,
			],
			'variations'     => [
				456 => [
					'override'       => [
						'regular_price' => '1100.00',
						'sale_price'    => '899.00',
						'signup_fee'    => null,
					],
					// Phase 9：變體層灌水銷量；set_variation_override 未指定時為 0
					'inflated_count' => 0,
				],
			],
		];

		$this->assertSame( $expected, $item->to_array() );
	}

	// ========== Phase 9：變體層 inflated_count ==========

	/**
	 * 建構子接受 VariationOverride，to_array 同時輸出 override 與 inflated_count
	 *
	 * @group happy
	 */
	public function test_to_array_serializes_variation_inflated_count(): void {
		$item = new OverrideItem(
			123,
			new PriceOverride( '1000.00', '799.00', null ),
			new InflatedCount( 100 ),
			[
				456 => new VariationOverride(
					new PriceOverride( '1100.00', '899.00', null ),
					new InflatedCount( 33 )
				),
			]
		);

		$arr = $item->to_array();

		$this->assertArrayHasKey( 456, $arr['variations'], '防空斷言：variations 應含 456' );
		$this->assertSame(
			[
				'override'       => [
					'regular_price' => '1100.00',
					'sale_price'    => '899.00',
					'signup_fee'    => null,
				],
				'inflated_count' => 33,
			],
			$arr['variations'][456]
		);
	}

	/**
	 * get_variation_inflated_count 在 variation 存在時回傳 InflatedCount
	 *
	 * @group happy
	 */
	public function test_get_variation_inflated_count_returns_count_when_present(): void {
		$item = new OverrideItem(
			123,
			new PriceOverride( null, null, null ),
			new InflatedCount( 0 ),
			[
				456 => new VariationOverride( new PriceOverride( '900', null, null ), new InflatedCount( 88 ) ),
			]
		);

		$count = $item->get_variation_inflated_count( 456 );

		$this->assertInstanceOf( InflatedCount::class, $count );
		$this->assertSame( 88, $count->value() );
	}

	/**
	 * get_variation_inflated_count 在 variation 不存在時回傳 null（且不觸發 undefined key）
	 *
	 * @group edge
	 */
	public function test_get_variation_inflated_count_returns_null_when_absent(): void {
		$item = $this->make_item( 123 );

		$this->assertNull( $item->get_variation_inflated_count( 999 ) );
	}

	/**
	 * 【委派鎖】get_variation_override 仍回傳 ?PriceOverride 而非 VariationOverride
	 *
	 * 這是「PriceCalculator / CartPriceOverrideHook 零改動」的直接契約：
	 * $variations 的 value 型別升級為 VariationOverride 後，本 method 改為委派取
	 * ->override。若哪天有人「順手」讓它回傳 VariationOverride，取價路徑會拿到
	 * 沒有 sale_price 屬性的物件 → 靜默 fallback 原價 → 商家損失。
	 *
	 * @group happy
	 * @group smoke
	 */
	public function test_get_variation_override_still_returns_price_override_after_delegation(): void {
		$item = new OverrideItem(
			123,
			new PriceOverride( null, null, null ),
			new InflatedCount( 0 ),
			[
				456 => new VariationOverride( new PriceOverride( '900', '850', null ), new InflatedCount( 5 ) ),
			]
		);

		$retrieved = $item->get_variation_override( 456 );

		$this->assertInstanceOf( PriceOverride::class, $retrieved );
		$this->assertNotInstanceOf( VariationOverride::class, $retrieved );
		$this->assertSame( '900', $retrieved->regular_price );
		$this->assertSame( '850', $retrieved->sale_price );
	}

	/**
	 * set_variation_override 只傳 2 個參數時，既有的 inflated_count 必須被保留
	 *
	 * 站長只改價格時不應把已設定的灌水銷量清成 0。
	 *
	 * @group happy
	 */
	public function test_set_variation_override_preserves_existing_inflated_count(): void {
		$item = new OverrideItem(
			123,
			new PriceOverride( null, null, null ),
			new InflatedCount( 0 ),
			[
				456 => new VariationOverride( new PriceOverride( '900', null, null ), new InflatedCount( 42 ) ),
			]
		);

		$item->set_variation_override( 456, new PriceOverride( '1200', '1100', null ) );

		$this->assertSame( '1200', $item->get_variation_override( 456 )?->regular_price );
		$this->assertSame( 42, $item->get_variation_inflated_count( 456 )?->value(), '未指定第三參數時應沿用既有灌水銷量' );
	}

	/**
	 * set_variation_override 對全新 variation 且未指定灌水銷量時，預設為 0
	 *
	 * @group edge
	 */
	public function test_set_variation_override_defaults_inflated_count_to_zero_for_new_variation(): void {
		$item = $this->make_item( 123 );

		$item->set_variation_override( 456, new PriceOverride( '900', null, null ) );

		$this->assertSame( 0, $item->get_variation_inflated_count( 456 )?->value() );
	}

	/**
	 * set_variation_override 第三參數可明確覆寫灌水銷量
	 *
	 * @group happy
	 */
	public function test_set_variation_override_accepts_explicit_inflated_count(): void {
		$item = $this->make_item( 123 );

		$item->set_variation_override( 456, new PriceOverride( '900', null, null ), new InflatedCount( 77 ) );

		$this->assertSame( 77, $item->get_variation_inflated_count( 456 )?->value() );
	}

	/**
	 * 建構子對 variations 內的非 VariationOverride 值必須 fail-fast
	 *
	 * PHP 無法對 array value 做原生型別檢查。若讓升級前的 PriceOverride 混進來，
	 * get_variation_override() 取 ->override 只會得到 null 加一則 Warning——
	 * 結果是變體覆寫價「靜默失效退回原價」＝商家損失。故必須大聲拋出。
	 *
	 * @group error
	 * @group security
	 */
	public function test_construct_rejects_non_variation_override_values(): void {
		$this->expectException( \InvalidArgumentException::class );

		new OverrideItem(
			123,
			new PriceOverride( null, null, null ),
			new InflatedCount( 0 ),
			// 舊型別（Phase 9 之前的 $variations value）不應被接受
			[ 456 => new PriceOverride( '900', null, null ) ]
		);
	}

	/**
	 * 空 variations 在 to_array 結果應為空陣列
	 *
	 * @group edge
	 */
	public function test_to_array_with_empty_variations_returns_empty_object(): void {
		$item = $this->make_item( 123 );

		$arr = $item->to_array();

		$this->assertArrayHasKey( 'variations', $arr );
		$this->assertSame( [], $arr['variations'] );
	}

	/**
	 * to_array 中 inflated_count 必須為 int 型別
	 *
	 * @group happy
	 */
	public function test_to_array_includes_inflated_count_as_int(): void {
		$override = new PriceOverride( '1000.00', '799.00', null );
		$count    = new InflatedCount( 250 );
		$item     = new OverrideItem( 123, $override, $count );

		$arr = $item->to_array();

		$this->assertArrayHasKey( 'inflated_count', $arr );
		$this->assertIsInt( $arr['inflated_count'] );
		$this->assertSame( 250, $arr['inflated_count'] );
	}

	/**
	 * 建立一個基礎 OverrideItem（給多個測試共用）
	 *
	 * @param int $product_id 商品 id
	 *
	 * @return OverrideItem
	 */
	private function make_item( int $product_id ): OverrideItem {
		$override = new PriceOverride( '1000.00', '799.00', null );
		$count    = new InflatedCount( 100 );
		return new OverrideItem( $product_id, $override, $count );
	}
}
