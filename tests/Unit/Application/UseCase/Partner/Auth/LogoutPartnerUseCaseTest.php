<?php
/**
 * LogoutPartnerUseCase 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3
 *
 * 紅燈合約：
 *   class LogoutPartnerUseCase {
 *     public function __construct(PartnerTokenStore $tokens);
 *     public function execute(string $token): void;
 *   }
 *
 * 行為：
 *   - 任何字串都接受（idempotent；不存在的 token 也不拋）
 *   - 命中時 transient 應被清除
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\LogoutPartnerUseCase;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\FixedSaltProvider;
use Tests\Support\InMemoryTransientStore;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * LogoutPartnerUseCase 紅燈合約測試
 */
final class LogoutPartnerUseCaseTest extends TestCase {

	private FixedClock $clock;
	private InMemoryTransientStore $transients;
	private PartnerTokenStore $tokens;

	protected function setUp(): void {
		parent::setUp();
		$this->clock      = new FixedClock( 1_700_000_000 );
		$this->transients = new InMemoryTransientStore( $this->clock );
		$this->tokens     = new PartnerTokenStore(
			transients: $this->transients,
			clock: $this->clock,
			partners: new InMemoryPartnerRepository( $this->clock ),
			salt_provider: new FixedSaltProvider(),
			ttl: 3600,
			key_prefix: 'ps_partner_token_',
		);
	}

	/**
	 * happy：existing token revoke 後 verify 回 null
	 *
	 * @group happy
	 */
	public function test_logout_revokes_token(): void {
		$result = $this->tokens->issue( 5 );
		$this->assertSame( 5, $this->tokens->verify( $result['token'] ) );

		$useCase = new LogoutPartnerUseCase( tokens: $this->tokens );
		$useCase->execute( $result['token'] );

		$this->assertNull( $this->tokens->verify( $result['token'] ), 'logout 後 token 應失效' );
	}

	/**
	 * edge：未知 token 也不應拋（idempotent / safe-by-default）
	 *
	 * @group edge
	 */
	public function test_logout_with_unknown_token_does_not_throw(): void {
		$useCase = new LogoutPartnerUseCase( tokens: $this->tokens );

		$useCase->execute( 'totally-fake-token' );

		$this->expectNotToPerformAssertions();
	}
}
