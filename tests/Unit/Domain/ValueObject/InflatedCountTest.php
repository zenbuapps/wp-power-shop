<?php
/**
 * InflatedCount ValueObject 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.3、§8.10
 * 規範：負數 sanitize 到 0（不拋例外）；極大值（PHP_INT_MAX）允許
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use PHPUnit\Framework\TestCase;

/**
 * InflatedCount ValueObject 測試
 */
final class InflatedCountTest extends TestCase {

	/**
	 * 一般正整數值應保留原值
	 *
	 * @group happy
	 */
	public function test_construct_with_positive_value(): void {
		$count = new InflatedCount( 100 );
		$this->assertSame( 100, $count->value() );
	}

	/**
	 * 0 為合法值
	 *
	 * @group edge
	 */
	public function test_construct_with_zero_value(): void {
		$count = new InflatedCount( 0 );
		$this->assertSame( 0, $count->value() );
	}

	/**
	 * 負數應 sanitize 到 0（不拋例外）
	 *
	 * @group edge
	 */
	public function test_negative_value_clamped_to_zero(): void {
		$count = new InflatedCount( -50 );
		$this->assertSame( 0, $count->value() );
	}

	/**
	 * PHP_INT_MIN 應 sanitize 到 0
	 *
	 * @group edge
	 */
	public function test_negative_int_min_clamped_to_zero(): void {
		$count = new InflatedCount( PHP_INT_MIN );
		$this->assertSame( 0, $count->value() );
	}

	/**
	 * PHP_INT_MAX 為合法極大值
	 *
	 * @group edge
	 */
	public function test_php_int_max_allowed(): void {
		$count = new InflatedCount( PHP_INT_MAX );
		$this->assertSame( PHP_INT_MAX, $count->value() );
	}

	/**
	 * value() 回傳 int 型別
	 *
	 * @group happy
	 */
	public function test_value_returns_int(): void {
		$count = new InflatedCount( 42 );
		$this->assertIsInt( $count->value() );
		$this->assertSame( 42, $count->value() );
	}
}
