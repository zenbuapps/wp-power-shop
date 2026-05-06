<?php
/**
 * PriceOverride ValueObject 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.3、§8.5
 * 規範：三欄位 nullable string（regular_price、sale_price、signup_fee）
 *      非 null 值必為合法十進位字串、≥ 0
 *      若 sale_price 與 regular_price 都非 null，sale_price 須 ≤ regular_price
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use PHPUnit\Framework\TestCase;

/**
 * PriceOverride ValueObject 測試
 */
final class PriceOverrideTest extends TestCase {

	/**
	 * 全部欄位皆為 null 時建構成功（代表完全不覆寫）
	 *
	 * @group happy
	 */
	public function test_construct_with_all_null_values_succeeds(): void {
		$vo = new PriceOverride( null, null, null );

		$this->assertNull( $vo->regular_price );
		$this->assertNull( $vo->sale_price );
		$this->assertNull( $vo->signup_fee );
	}

	/**
	 * 三欄位皆為合法十進位字串時建構成功
	 *
	 * @group happy
	 */
	public function test_construct_with_valid_decimal_strings_succeeds(): void {
		$vo = new PriceOverride( '1000.00', '799.00', '199.00' );

		$this->assertSame( '1000.00', $vo->regular_price );
		$this->assertSame( '799.00', $vo->sale_price );
		$this->assertSame( '199.00', $vo->signup_fee );
	}

	/**
	 * regular_price 為負數應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_regular_price_is_negative(): void {
		$this->expectException( InvalidPriceOverride::class );
		new PriceOverride( '-1', null, null );
	}

	/**
	 * sale_price 為負數應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_sale_price_is_negative(): void {
		$this->expectException( InvalidPriceOverride::class );
		new PriceOverride( '1000', '-50', null );
	}

	/**
	 * sale_price 大於 regular_price 應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_sale_price_greater_than_regular(): void {
		$this->expectException( InvalidPriceOverride::class );
		new PriceOverride( '500', '799', null );
	}

	/**
	 * signup_fee 為負數應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_signup_fee_is_negative(): void {
		$this->expectException( InvalidPriceOverride::class );
		new PriceOverride( null, null, '-10' );
	}

	/**
	 * 價格欄位含非數字字串應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_price_is_non_numeric_string(): void {
		$this->expectException( InvalidPriceOverride::class );
		new PriceOverride( 'abc', null, null );
	}

	/**
	 * 0 元為合法價格（免費商品）
	 *
	 * @group edge
	 */
	public function test_zero_prices_are_valid(): void {
		$vo = new PriceOverride( '0', '0', '0' );

		$this->assertSame( '0', $vo->regular_price );
		$this->assertSame( '0', $vo->sale_price );
		$this->assertSame( '0', $vo->signup_fee );
	}

	/**
	 * 4 位小數應被保留（不被截斷）
	 *
	 * @group edge
	 */
	public function test_decimal_with_4_places_preserved(): void {
		$vo = new PriceOverride( '1000.1234', '999.5678', null );

		$this->assertSame( '1000.1234', $vo->regular_price );
		$this->assertSame( '999.5678', $vo->sale_price );
	}

	/**
	 * 兩個 PriceOverride 三欄位完全相同時 equals 為 true
	 *
	 * @group happy
	 */
	public function test_equals_returns_true_for_same_values(): void {
		$a = new PriceOverride( '1000', '799', '199' );
		$b = new PriceOverride( '1000', '799', '199' );

		$this->assertTrue( $a->equals( $b ) );
	}

	/**
	 * 任一欄位不同 equals 應為 false
	 *
	 * @group happy
	 */
	public function test_equals_returns_false_for_different_values(): void {
		$a = new PriceOverride( '1000', '799', null );
		$b = new PriceOverride( '1000', '699', null );

		$this->assertFalse( $a->equals( $b ) );
	}

	/**
	 * to_array 結果可重新傳回 constructor 並產生等價的 VO
	 *
	 * @group happy
	 */
	public function test_to_array_round_trips_through_constructor(): void {
		$original = new PriceOverride( '1000.00', '799.00', '199.00' );
		$arr      = $original->to_array();

		$rebuilt = new PriceOverride(
			$arr['regular_price'],
			$arr['sale_price'],
			$arr['signup_fee']
		);

		$this->assertTrue( $original->equals( $rebuilt ) );
		$this->assertSame(
			[
				'regular_price' => '1000.00',
				'sale_price'    => '799.00',
				'signup_fee'    => '199.00',
			],
			$arr
		);
	}
}
