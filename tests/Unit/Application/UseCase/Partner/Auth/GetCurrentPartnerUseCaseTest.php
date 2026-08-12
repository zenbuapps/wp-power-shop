<?php
/**
 * GetCurrentPartnerUseCase 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3
 *
 * 紅燈合約：
 *   class GetCurrentPartnerUseCase {
 *     public function __construct(PartnerTokenStore $tokens, PartnerRepositoryInterface $partners);
 *     public function execute(string $token): PartnerSnapshot;
 *   }
 *
 * 行為：
 *   - token 無效 → 拋 InvalidCredentials
 *   - token 命中 → 用 PartnerRepository::find_by_id() 取 PartnerSnapshot
 *   - find_by_id 回 null（資料殘留 / 已刪除）→ 拋 InvalidCredentials
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\GetCurrentPartnerUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\FixedSaltProvider;
use Tests\Support\InMemoryTransientStore;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * GetCurrentPartnerUseCase 紅燈合約測試
 */
final class GetCurrentPartnerUseCaseTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;
	private PartnerTokenStore $tokens;
	private FixedClock $clock;
	private InMemoryTransientStore $transients;

	protected function setUp(): void {
		parent::setUp();
		$this->clock       = new FixedClock( 1_700_000_000 );
		$this->partnerRepo = new InMemoryPartnerRepository( $this->clock );
		$this->transients  = new InMemoryTransientStore( $this->clock );
		$this->tokens      = new PartnerTokenStore(
			transients: $this->transients,
			clock: $this->clock,
			partners: $this->partnerRepo,
			salt_provider: new FixedSaltProvider(),
			ttl: 3600,
			key_prefix: 'ps_partner_token_',
		);
	}

	/**
	 * happy：valid token → 回 PartnerSnapshot
	 *
	 * @group happy
	 */
	public function test_returns_partner_snapshot_for_valid_token(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$result = $this->tokens->issue( 5 );

		$useCase = new GetCurrentPartnerUseCase(
			tokens: $this->tokens,
			partners: $this->partnerRepo,
		);

		$snapshot = $useCase->execute( $result['token'] );

		$this->assertInstanceOf( PartnerSnapshot::class, $snapshot );
		$this->assertSame( 5, $snapshot->term_id );
		$this->assertSame( 'jerry', $snapshot->slug->value() );
	}

	/**
	 * error：未知 token → 拋 InvalidCredentials
	 *
	 * @group error
	 * @group security
	 */
	public function test_throws_invalid_credentials_for_unknown_token(): void {
		$useCase = new GetCurrentPartnerUseCase(
			tokens: $this->tokens,
			partners: $this->partnerRepo,
		);

		$this->expectException( InvalidCredentials::class );
		$useCase->execute( 'totally-fake-token' );
	}

	/**
	 * error：token 過期 → 拋 InvalidCredentials
	 *
	 * @group error
	 * @group security
	 */
	public function test_throws_invalid_credentials_for_expired_token(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$result = $this->tokens->issue( 5 );

		// 推進時鐘超過 TTL
		$this->clock->advance( 3601 );

		$useCase = new GetCurrentPartnerUseCase(
			tokens: $this->tokens,
			partners: $this->partnerRepo,
		);

		$this->expectException( InvalidCredentials::class );
		$useCase->execute( $result['token'] );
	}

	/**
	 * error：token 命中但 partner 已被刪 → 拋 InvalidCredentials（防殘留 token）
	 *
	 * @group error
	 * @group security
	 */
	public function test_throws_invalid_credentials_when_partner_deleted_after_issue(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$result = $this->tokens->issue( 5 );

		// 模擬 partner 被刪除（token 還在 transient）
		$this->partnerRepo->delete( 5 );

		$useCase = new GetCurrentPartnerUseCase(
			tokens: $this->tokens,
			partners: $this->partnerRepo,
		);

		$this->expectException( InvalidCredentials::class );
		$useCase->execute( $result['token'] );
	}
}
