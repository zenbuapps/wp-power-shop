<?php
/**
 * LoginPartnerUseCase 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§7
 *
 * 紅燈合約：
 *   class LoginPartnerUseCase {
 *     public function __construct(PartnerAuthService $auth, PartnerTokenStore $tokens);
 *     public function execute(PartnerLoginInput $in): PartnerLoginOutput;
 *   }
 *
 * 流程：
 *   PartnerAuthService::attempt_login(slug, pw) → PartnerSnapshot
 *   PartnerTokenStore::issue(term_id) → {token, expires_at}
 *   組裝 PartnerLoginOutput{ token, expires_at, partner_id, partner_name }
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerLoginInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerLoginOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\LoginRateLimiter;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerAuthService;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\LoginPartnerUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\FixedSaltProvider;
use Tests\Support\InMemoryTransientStore;
use Tests\Support\SpyEmailNotifier;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * LoginPartnerUseCase 紅燈合約測試
 */
final class LoginPartnerUseCaseTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;
	private FixedClock $clock;
	private InMemoryTransientStore $transients;
	private SpyEmailNotifier $email;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
		$this->clock       = new FixedClock( 1_700_000_000 );
		$this->transients  = new InMemoryTransientStore( $this->clock );
		$this->email       = new SpyEmailNotifier();
	}

	/**
	 * happy：正確 slug + 密碼 → 回 PartnerLoginOutput 攜 token
	 *
	 * @group happy
	 */
	public function test_happy_returns_login_output_with_token(): void {
		$snap = $this->partnerRepo->seed( 5, 'Jerry', 'jerry', 'jerry@example.com' );
		$this->partnerRepo->save( $snap, 'plain-pa55!' );

		$useCase = $this->make_use_case();

		$output = $useCase->execute(
			new PartnerLoginInput( slug: 'jerry', password: 'plain-pa55!' )
		);

		$this->assertInstanceOf( PartnerLoginOutput::class, $output );
		$this->assertNotEmpty( $output->token );
		$this->assertNotEmpty( $output->expires_at );
		$this->assertSame( 5, $output->partner_id );
		$this->assertSame( 'Jerry', $output->partner_name );
	}

	/**
	 * error：密碼錯 → 拋 InvalidCredentials（不簽 token）
	 *
	 * @group error
	 * @group security
	 */
	public function test_wrong_password_throws_invalid_credentials(): void {
		$snap = $this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$this->partnerRepo->save( $snap, 'correct-pw' );

		$useCase = $this->make_use_case();

		$this->expectException( InvalidCredentials::class );
		$useCase->execute( new PartnerLoginInput( slug: 'jerry', password: 'wrong' ) );
	}

	/**
	 * error：未知 slug → 拋 InvalidCredentials（不洩漏存在性）
	 *
	 * @group error
	 * @group security
	 */
	public function test_unknown_slug_throws_invalid_credentials(): void {
		$useCase = $this->make_use_case();

		$this->expectException( InvalidCredentials::class );
		$useCase->execute( new PartnerLoginInput( slug: 'ghost', password: 'pw' ) );
	}

	/**
	 * security：超過嘗試次數 → 拋 TooManyAttempts
	 *
	 * @group security
	 */
	public function test_too_many_failures_blocks_login(): void {
		$snap = $this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$this->partnerRepo->save( $snap, 'correct-pw' );

		$useCase = $this->make_use_case();

		// 失敗 5 次
		for ( $i = 0; $i < 5; $i++ ) {
			try {
				$useCase->execute( new PartnerLoginInput( slug: 'jerry', password: 'wrong' ) );
			} catch ( \DomainException ) {
				// expected
			}
		}

		// 第 6 次（即使密碼正確）→ 應被擋
		$this->expectException( TooManyAttempts::class );
		$useCase->execute( new PartnerLoginInput( slug: 'jerry', password: 'correct-pw' ) );
	}

	// ========== helper ==========

	private function make_use_case(): LoginPartnerUseCase {
		$limiter = new LoginRateLimiter(
			transients: $this->transients,
			clock: $this->clock,
			email: $this->email,
			salt_provider: new FixedSaltProvider(),
			max_attempts: 5,
			window_seconds: 900,
		);
		$auth    = new PartnerAuthService(
			partnerRepo: $this->partnerRepo,
			limiter: $limiter,
		);
		$tokens  = new PartnerTokenStore(
			transients: $this->transients,
			clock: $this->clock,
			partners: $this->partnerRepo,
			salt_provider: new FixedSaltProvider(),
			ttl: 3600,
			key_prefix: 'ps_partner_token_',
		);

		return new LoginPartnerUseCase(
			auth: $auth,
			tokens: $tokens,
		);
	}
}
