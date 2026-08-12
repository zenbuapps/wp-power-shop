<?php
/**
 * ProfitRate ValueObject 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.2、§8.5
 * 規範：int 0–100（含邊界），違反拋 InvalidProfitRate
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use PHPUnit\Framework\TestCase;

/**
 * ProfitRate ValueObject 測試
 */
final class ProfitRateTest extends TestCase {

	/**
	 * 邊界值 0% 為合法（不分潤）
	 *
	 * @group edge
	 */
	public function test_construct_with_zero_succeeds(): void {
		$rate = new ProfitRate( 0 );
		$this->assertSame( 0, $rate->value() );
	}

	/**
	 * 邊界值 100% 為合法（全額分潤）
	 *
	 * @group edge
	 */
	public function test_construct_with_100_succeeds(): void {
		$rate = new ProfitRate( 100 );
		$this->assertSame( 100, $rate->value() );
	}

	/**
	 * 一般中段值 50% 為合法
	 *
	 * @group happy
	 */
	public function test_construct_with_50_succeeds(): void {
		$rate = new ProfitRate( 50 );
		$this->assertSame( 50, $rate->value() );
	}

	/**
	 * 負數應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_negative(): void {
		$this->expectException( InvalidProfitRate::class );
		new ProfitRate( -1 );
	}

	/**
	 * 大於 100 應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_above_100(): void {
		$this->expectException( InvalidProfitRate::class );
		new ProfitRate( 101 );
	}

	/**
	 * value() 回傳 int 型別
	 *
	 * @group happy
	 */
	public function test_value_returns_int(): void {
		$rate = new ProfitRate( 25 );
		$this->assertIsInt( $rate->value() );
		$this->assertSame( 25, $rate->value() );
	}

	/**
	 * 數值相同時 equals 為 true
	 *
	 * @group happy
	 */
	public function test_equals_returns_true_for_same_value(): void {
		$a = new ProfitRate( 30 );
		$b = new ProfitRate( 30 );
		$this->assertTrue( $a->equals( $b ) );
	}

	/**
	 * 數值不同時 equals 為 false
	 *
	 * @group happy
	 */
	public function test_equals_returns_false_for_different_value(): void {
		$a = new ProfitRate( 30 );
		$b = new ProfitRate( 40 );
		$this->assertFalse( $a->equals( $b ) );
	}
}
