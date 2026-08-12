<?php
/**
 * ProfitCalculator Domain Service 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §1.5（Domain 純 PHP）
 *
 * 規格：
 * - 公式 actual × qty × rate / 100
 * - rounding 透過注入的 RoundingStrategy（test 用 Mockery mock）
 * - rate=0 直接回 '0.00'（不必 invoke rounding）
 * - rate=100 等於 actual × qty
 * - qty=0 回 '0.00'
 * - qty<0 throw \InvalidArgumentException
 *
 * 預期紅燈：
 * - Class J7\PowerShop\Domains\ProfitShop\Domain\Service\ProfitCalculator not found
 * - Interface J7\PowerShop\Domains\ProfitShop\Domain\Service\RoundingStrategy not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Service\ProfitCalculator;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\RoundingStrategy;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * ProfitCalculator 測試
 */
final class ProfitCalculatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * 標準情境：actual=100, qty=2, rate=30 → 60.00
	 *
	 * @group happy
	 */
	public function test_calculate_with_rate_30_qty_2_actual_100_returns_60(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );
		$rounding->shouldReceive( 'round' )
			->once()
			->andReturn( '60.00' );

		$calculator = new ProfitCalculator( $rounding );
		$result     = $calculator->calculate( '100.00', 2, new ProfitRate( 30 ) );

		$this->assertSame( '60.00', $result );
	}

	/**
	 * rate=0：直接回 '0.00'，不應 invoke rounding
	 *
	 * @group edge
	 */
	public function test_calculate_with_rate_zero_returns_zero_string(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );
		$rounding->shouldNotReceive( 'round' );

		$calculator = new ProfitCalculator( $rounding );
		$result     = $calculator->calculate( '100.00', 2, new ProfitRate( 0 ) );

		$this->assertSame( '0.00', $result );
	}

	/**
	 * rate=100：等於 actual × qty
	 *
	 * @group edge
	 */
	public function test_calculate_with_rate_100_equals_actual_times_qty(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );
		$rounding->shouldReceive( 'round' )
			->once()
			->andReturn( '500.00' );

		$calculator = new ProfitCalculator( $rounding );
		$result     = $calculator->calculate( '250.00', 2, new ProfitRate( 100 ) );

		$this->assertSame( '500.00', $result );
	}

	/**
	 * qty=0：回 '0.00'
	 *
	 * @group edge
	 */
	public function test_calculate_with_qty_zero_returns_zero(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );
		// qty=0 時不論 rate 如何結果都是 0；實作可選 short-circuit 或仍走 rounding
		$rounding->shouldReceive( 'round' )
			->zeroOrMoreTimes()
			->andReturn( '0.00' );

		$calculator = new ProfitCalculator( $rounding );
		$result     = $calculator->calculate( '100.00', 0, new ProfitRate( 30 ) );

		$this->assertSame( '0.00', $result );
	}

	/**
	 * qty<0：拋出 InvalidArgumentException
	 *
	 * @group error
	 */
	public function test_calculate_with_qty_negative_throws(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );

		$calculator = new ProfitCalculator( $rounding );

		$this->expectException( \InvalidArgumentException::class );
		$calculator->calculate( '100.00', -1, new ProfitRate( 30 ) );
	}

	/**
	 * actual 為小數：99.95 × 1 × 20 / 100 = 19.99
	 *
	 * @group happy
	 */
	public function test_calculate_with_decimal_actual_price(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );
		$rounding->shouldReceive( 'round' )
			->once()
			->andReturn( '19.99' );

		$calculator = new ProfitCalculator( $rounding );
		$result     = $calculator->calculate( '99.95', 1, new ProfitRate( 20 ) );

		$this->assertSame( '19.99', $result );
	}

	/**
	 * RoundingStrategy::round 應以 4 位小數呼叫
	 *
	 * @group happy
	 */
	public function test_rounding_strategy_called_with_4_decimals(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );
		$rounding->shouldReceive( 'round' )
			->once()
			->with( Mockery::type( 'string' ), 4 )
			->andReturn( '60.00' );

		$calculator = new ProfitCalculator( $rounding );
		$calculator->calculate( '100.00', 2, new ProfitRate( 30 ) );

		// 期望由 Mockery 自動驗證
		$this->addToAssertionCount( 1 );
	}

	/**
	 * calculator 應使用 RoundingStrategy 回傳值（不另外處理）
	 *
	 * @group happy
	 */
	public function test_calculate_uses_rounding_result(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );
		$rounding->shouldReceive( 'round' )
			->once()
			->andReturn( '999.9876' );

		$calculator = new ProfitCalculator( $rounding );
		$result     = $calculator->calculate( '100.00', 2, new ProfitRate( 30 ) );

		// 即使數學上不是 999.9876，也應原樣回傳 rounding 的結果
		$this->assertSame( '999.9876', $result );
	}

	/**
	 * rate=0 必須跳過 rounding 呼叫（performance + 邊界保證）
	 *
	 * @group edge
	 */
	public function test_rate_zero_skips_rounding_invocation(): void {
		$rounding = Mockery::mock( RoundingStrategy::class );
		$rounding->shouldReceive( 'round' )->never();

		$calculator = new ProfitCalculator( $rounding );
		$result     = $calculator->calculate( '999.99', 5, new ProfitRate( 0 ) );

		$this->assertSame( '0.00', $result );
	}
}
