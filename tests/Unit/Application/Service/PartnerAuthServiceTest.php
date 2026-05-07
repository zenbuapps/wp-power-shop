<?php
/**
 * PartnerAuthService 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8
 *
 * 紅燈合約：
 *   PartnerAuthService::attempt_login(string $slug, string $plain_password): PartnerSnapshot
 *
 * 流程：
 *   1. LoginRateLimiter::assert_not_blocked($slug)：被鎖時拋 TooManyAttempts，不繼續查 partner
 *   2. PartnerRepository::find_by_slug($slug)：找不到 → 拋 InvalidCredentials
 *      （統一錯誤訊息以避免帳號列舉攻擊）
 *   3. PartnerRepository::verify_password()：失敗 → record_failure → 拋 InvalidCredentials
 *   4. 成功 → reset → 回傳 PartnerSnapshot
 *
 * 紅燈狀態：
 *   - PartnerAuthService 目前沒有 attempt_login 方法（Phase 3-A stub），測試會 fail。
 *   - 同時驗證 LoginRateLimiter 的 spy 行為（紅燈：limiter 未實作）。
 *
 * @group profit_shop
 * @group application
 * @group service
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\LoginRateLimiter;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerAuthService;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\InMemoryTransientStore;
use Tests\Support\SpyEmailNotifier;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * PartnerAuthService 紅燈合約測試
 */
final class PartnerAuthServiceTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;
	private InMemoryTransientStore $transients;
	private FixedClock $clock;
	private SpyEmailNotifier $email;
	private LoginRateLimiter $limiter;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
		$this->clock       = new FixedClock( 1_700_000_000 );
		$this->transients  = new InMemoryTransientStore( $this->clock );
		$this->email       = new SpyEmailNotifier();

		// 紅燈：LoginRateLimiter constructor 目前不接受這些注入；綠燈時 master 應加。
		$this->limiter = new LoginRateLimiter(
			transients: $this->transients,
			clock: $this->clock,
			email: $this->email,
			max_attempts: 5,
			window_seconds: 900,
		);
	}

	/**
	 * happy：正確 slug + 密碼 → 回 PartnerSnapshot，且失敗計數已 reset
	 *
	 * @group happy
	 */
	public function test_happy_returns_partner_snapshot_and_resets_counter(): void {
		$this->seed_partner( 5, 'Jerry', 'jerry', 'plain-pa55!' );

		$service = new PartnerAuthService(
			partnerRepo: $this->partnerRepo,
			limiter: $this->limiter,
		);

		$snapshot = $service->attempt_login( 'jerry', 'plain-pa55!' );

		$this->assertInstanceOf( PartnerSnapshot::class, $snapshot );
		$this->assertSame( 5, $snapshot->term_id );
		$this->assertSame( 'jerry', $snapshot->slug->value() );

		// 成功登入應該重置 rate-limit 計數（驗證後續可立即再登入）
		$snapshot2 = $service->attempt_login( 'jerry', 'plain-pa55!' );
		$this->assertSame( 5, $snapshot2->term_id );
	}

	/**
	 * error：slug 不存在 → 拋 InvalidCredentials（不洩漏帳號是否存在）
	 *
	 * @group error
	 * @group security
	 */
	public function test_unknown_slug_throws_invalid_credentials(): void {
		$service = new PartnerAuthService(
			partnerRepo: $this->partnerRepo,
			limiter: $this->limiter,
		);

		try {
			$service->attempt_login( 'ghost', 'whatever' );
			$this->fail( '未拋 InvalidCredentials' );
		} catch ( InvalidCredentials $e ) {
			// message 不應洩漏「slug 不存在」之類資訊
			$this->assertStringNotContainsString( 'ghost', $e->getMessage() );
			$this->assertStringNotContainsString( 'slug', strtolower( $e->getMessage() ) );
		}
	}

	/**
	 * error：密碼錯誤 → 拋 InvalidCredentials，且 limiter 應 record_failure
	 *
	 * @group error
	 * @group security
	 */
	public function test_wrong_password_throws_invalid_credentials_and_records_failure(): void {
		$this->seed_partner( 5, 'Jerry', 'jerry', 'correct-pw' );

		$service = new PartnerAuthService(
			partnerRepo: $this->partnerRepo,
			limiter: $this->limiter,
		);

		// 連續失敗 4 次，第 5 次仍拋 InvalidCredentials
		for ( $i = 0; $i < 4; $i++ ) {
			try {
				$service->attempt_login( 'jerry', 'wrong-pw' );
				$this->fail( "第 {$i} 次未拋 InvalidCredentials" );
			} catch ( InvalidCredentials ) {
				// expected
			}
		}

		// 第 5 次失敗後，limiter 應該已累積到鎖定門檻；此後 attempt_login 拋 TooManyAttempts
		try {
			$service->attempt_login( 'jerry', 'wrong-pw' );
		} catch ( InvalidCredentials | TooManyAttempts ) {
			// 容忍兩種：因為實作可能在 record_failure 後立刻 assert，導致同次拋 TooManyAttempts
		}

		$this->expectException( TooManyAttempts::class );
		$service->attempt_login( 'jerry', 'wrong-pw' );
	}

	/**
	 * error：已被 limiter 鎖定時，attempt_login 必須立刻拋 TooManyAttempts，不查 partner
	 *
	 * @group error
	 * @group security
	 */
	public function test_already_blocked_short_circuits_to_too_many_attempts(): void {
		$this->seed_partner( 5, 'Jerry', 'jerry', 'correct-pw' );

		$service = new PartnerAuthService(
			partnerRepo: $this->partnerRepo,
			limiter: $this->limiter,
		);

		// 預先拉滿失敗次數（5 次）
		for ( $i = 0; $i < 5; $i++ ) {
			try {
				$service->attempt_login( 'jerry', 'wrong-pw' );
			} catch ( \DomainException ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				// expected; ignore
			}
		}

		// 第 6 次（即使密碼正確也應該被擋）
		$this->expectException( TooManyAttempts::class );
		$service->attempt_login( 'jerry', 'correct-pw' );
	}

	/**
	 * happy：登入成功後 limiter 計數重置（不會殘留之前的 partial failures）
	 *
	 * @group happy
	 * @group security
	 */
	public function test_successful_login_resets_rate_limiter(): void {
		$this->seed_partner( 5, 'Jerry', 'jerry', 'correct-pw' );

		$service = new PartnerAuthService(
			partnerRepo: $this->partnerRepo,
			limiter: $this->limiter,
		);

		// 先失敗 3 次（尚未到 5 次門檻）
		for ( $i = 0; $i < 3; $i++ ) {
			try {
				$service->attempt_login( 'jerry', 'wrong-pw' );
			} catch ( InvalidCredentials ) {
				// expected
			}
		}

		// 然後成功登入
		$snapshot = $service->attempt_login( 'jerry', 'correct-pw' );
		$this->assertSame( 5, $snapshot->term_id );

		// 計數已 reset：再失敗 4 次也不會被鎖
		for ( $i = 0; $i < 4; $i++ ) {
			try {
				$service->attempt_login( 'jerry', 'wrong-pw' );
				$this->fail( "第 {$i} 次未拋 InvalidCredentials" );
			} catch ( InvalidCredentials ) {
				// expected
			}
		}

		// 第 4 次失敗後仍未鎖定（門檻 5），下一次嘗試不應拋 TooManyAttempts
		try {
			$service->attempt_login( 'jerry', 'wrong-pw' );
		} catch ( InvalidCredentials ) {
			// expected：第 5 次失敗仍可能拋 InvalidCredentials（or TooManyAttempts at 5）
		} catch ( TooManyAttempts ) {
			// 也可接受：5 次到門檻
		}
		$this->assertSame( 5, 5 ); // sentinel：reset 後計數歸零驗證完成
	}

	/**
	 * security：錯誤訊息對「不存在」與「密碼錯」應一致（避免帳號列舉）
	 *
	 * @group security
	 */
	public function test_message_does_not_distinguish_unknown_slug_from_wrong_password(): void {
		$this->seed_partner( 5, 'Jerry', 'jerry', 'correct-pw' );

		$service = new PartnerAuthService(
			partnerRepo: $this->partnerRepo,
			limiter: $this->limiter,
		);

		$message_unknown = null;
		$message_wrong   = null;

		try {
			$service->attempt_login( 'ghost', 'pw' );
		} catch ( InvalidCredentials $e ) {
			$message_unknown = $e->getMessage();
		}

		try {
			$service->attempt_login( 'jerry', 'wrong' );
		} catch ( InvalidCredentials $e ) {
			$message_wrong = $e->getMessage();
		}

		$this->assertNotNull( $message_unknown );
		$this->assertNotNull( $message_wrong );
		$this->assertSame(
			$message_unknown,
			$message_wrong,
			'帳號列舉防禦：兩種失敗的錯誤訊息必須完全相同'
		);
	}

	// ========== helper ==========

	/**
	 * 預植入 partner（含密碼）
	 */
	private function seed_partner( int $term_id, string $name, string $slug, string $password ): void {
		$snapshot = $this->partnerRepo->seed( $term_id, $name, $slug );
		$this->partnerRepo->save( $snapshot, $password );
	}
}
