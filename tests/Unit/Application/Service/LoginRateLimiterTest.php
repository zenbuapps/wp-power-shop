<?php
/**
 * LoginRateLimiter 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8
 *
 * 紅燈合約：
 *   - assert_not_blocked(string $slug): void   // 5 次失敗在 15 分內 → 拋 TooManyAttempts
 *   - record_failure(string $slug): void       // 累加失敗計數；第 5 次發 admin email（warn-and-swallow）
 *   - reset(string $slug): void                // 登入成功時清計數
 *
 * 預期：
 *   - 注入 transient store + clock + email notifier（皆為 interface 友善）
 *   - 視窗 = 900 秒（15 分鐘）
 *   - 視窗過期計數歸零
 *   - email 失敗只 log warning，不阻擋登入流程（warn-and-swallow）
 *
 * @group profit_shop
 * @group application
 * @group service
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\LoginRateLimiter;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\InMemoryTransientStore;
use Tests\Support\SpyEmailNotifier;

/**
 * LoginRateLimiter 紅燈合約測試
 */
final class LoginRateLimiterTest extends TestCase {

	private FixedClock $clock;
	private InMemoryTransientStore $transients;
	private SpyEmailNotifier $email;

	protected function setUp(): void {
		parent::setUp();
		$this->clock      = new FixedClock( 1_700_000_000 );
		$this->transients = new InMemoryTransientStore( $this->clock );
		$this->email      = new SpyEmailNotifier();
	}

	private function make_limiter( int $max_attempts = 5, int $window_seconds = 900 ): LoginRateLimiter {
		return new LoginRateLimiter(
			transients: $this->transients,
			clock: $this->clock,
			email: $this->email,
			max_attempts: $max_attempts,
			window_seconds: $window_seconds,
		);
	}

	/**
	 * happy：前 4 次失敗仍在門檻內，assert_not_blocked 不拋
	 *
	 * @group happy
	 */
	public function test_first_four_failures_do_not_block(): void {
		$limiter = $this->make_limiter();

		for ( $i = 0; $i < 4; $i++ ) {
			$limiter->record_failure( 'jerry' );
			// 此呼叫不應拋例外
			$limiter->assert_not_blocked( 'jerry' );
		}

		$this->assertSame( 0, $this->email->count(), '前 4 次失敗不應發 admin email' );
		$this->assertSame( 4, 4 ); // 抵達此行表示 4 次 assert_not_blocked 都沒拋
	}

	/**
	 * security：第 5 次失敗 → 後續 assert_not_blocked 拋 TooManyAttempts，且應發 1 封 admin email
	 *
	 * @group security
	 */
	public function test_fifth_failure_blocks_and_sends_email(): void {
		$limiter = $this->make_limiter();

		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record_failure( 'jerry' );
		}

		// 第 5 次失敗應發 1 封 admin email
		$this->assertSame( 1, $this->email->count(), '第 5 次失敗應發 admin email 通知' );

		// 第 6 次嘗試應被擋
		try {
			$limiter->assert_not_blocked( 'jerry' );
			$this->fail( '預期拋 TooManyAttempts' );
		} catch ( TooManyAttempts $e ) {
			$this->assertGreaterThan(
				0,
				$e->getRetryAfter(),
				'TooManyAttempts.retry_after 必須 > 0 秒'
			);
			$this->assertLessThanOrEqual(
				900,
				$e->getRetryAfter(),
				'retry_after 應 <= window_seconds（900）'
			);
		}
	}

	/**
	 * security：reset 後計數清零，可重新累積
	 *
	 * @group security
	 */
	public function test_reset_clears_failure_counter(): void {
		$limiter = $this->make_limiter();

		for ( $i = 0; $i < 4; $i++ ) {
			$limiter->record_failure( 'jerry' );
		}

		$limiter->reset( 'jerry' );

		// reset 後應可再失敗 4 次都不觸發鎖定
		for ( $i = 0; $i < 4; $i++ ) {
			$limiter->record_failure( 'jerry' );
			// 不應拋
			$limiter->assert_not_blocked( 'jerry' );
		}

		$this->assertSame( 4, 4 ); // sentinel：到此處未拋例外即代表 reset 後計數清零成功
	}

	/**
	 * security：email 寄送失敗不可阻擋登入流程（warn-and-swallow）
	 *
	 * 規格：admin email 失敗只記 log 不 throw，不可影響 record_failure 的回傳。
	 *
	 * @group security
	 */
	public function test_email_failure_is_warn_and_swallowed(): void {
		$limiter = $this->make_limiter();
		$this->email->set_send_should_throw( true );

		// 連續 record_failure 5 次都不應拋（即使 email 寄送會丟例外）
		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record_failure( 'jerry' );
		}

		// email spy 仍會紀錄被嘗試呼叫的次數（在丟例外前已 push）
		$this->assertSame( 1, $this->email->count(), 'email 仍被嘗試發送' );

		// 即使 email 失敗，rate-limit 邏輯仍應正常運作（第 6 次嘗試被擋）
		$this->expectException( TooManyAttempts::class );
		$limiter->assert_not_blocked( 'jerry' );
	}

	/**
	 * edge：視窗（900 秒）過期後計數應歸零
	 *
	 * @group edge
	 */
	public function test_window_expiry_resets_counter(): void {
		$limiter = $this->make_limiter( max_attempts: 5, window_seconds: 900 );

		// 累積 5 次失敗 → 鎖定
		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record_failure( 'jerry' );
		}

		// 鎖定狀態驗證
		$blocked = false;
		try {
			$limiter->assert_not_blocked( 'jerry' );
		} catch ( TooManyAttempts ) {
			$blocked = true;
		}
		$this->assertTrue( $blocked, '5 次失敗後應被鎖' );

		// 推進時鐘超過視窗（900 秒 + 1）
		$this->clock->advance( 901 );

		// 視窗過期：assert_not_blocked 應通過（不拋）
		$reblocked = false;
		try {
			$limiter->assert_not_blocked( 'jerry' );
		} catch ( TooManyAttempts ) {
			$reblocked = true;
		}
		$this->assertFalse( $reblocked, '視窗過期後應解鎖' );
	}

	/**
	 * security：per-slug 計數（A 帳號失敗不影響 B 帳號）
	 *
	 * @group security
	 */
	public function test_failure_counter_is_per_slug(): void {
		$limiter = $this->make_limiter();

		// jerry 累積到鎖定
		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record_failure( 'jerry' );
		}

		// alice 應仍可嘗試
		$limiter->assert_not_blocked( 'alice' );

		// jerry 仍被鎖
		$this->expectException( TooManyAttempts::class );
		$limiter->assert_not_blocked( 'jerry' );
	}

	// ========== helper ==========

	/**
	 * 簡化版 assertThrows（PHPUnit 9 沒有原生）
	 *
	 * @param class-string<\Throwable> $expected_class 預期例外類別
	 * @param callable                 $callable       要呼叫的函式
	 */
	private function assertThrows( string $expected_class, callable $callable ): void {
		try {
			$callable();
		} catch ( \Throwable $e ) {
			$this->assertInstanceOf( $expected_class, $e );
			return;
		}
		$this->fail( "預期拋出 {$expected_class}，但沒有任何例外" );
	}
}
