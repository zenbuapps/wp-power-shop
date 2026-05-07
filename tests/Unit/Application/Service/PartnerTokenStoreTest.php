<?php
/**
 * PartnerTokenStore 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8.0
 *
 * 紅燈合約：
 *   - issue(int $partner_term_id): array{token: string, expires_at: int}
 *   - verify(string $token): ?int        // 命中回 partner_term_id
 *   - revoke(string $token): void
 *
 * 安全要求：
 *   - 永遠不存明文 token（transient 內僅 hash）
 *   - TTL = 3600（OQ-3 固定一小時）
 *   - 雙保險：除 transient 過期，也檢查 expires_at > time()
 *
 * 紅燈狀態：
 *   PartnerTokenStore Phase 3-A stub 是 set/get/delete，不是 issue/verify/revoke。
 *   且不接受 transients/clock 注入；測試會 fail。
 *
 * @group profit_shop
 * @group application
 * @group service
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\InMemoryTransientStore;

/**
 * PartnerTokenStore 紅燈合約測試
 */
final class PartnerTokenStoreTest extends TestCase {

	private FixedClock $clock;
	private InMemoryTransientStore $transients;

	protected function setUp(): void {
		parent::setUp();
		$this->clock      = new FixedClock( 1_700_000_000 );
		$this->transients = new InMemoryTransientStore( $this->clock );
	}

	private function make_store(): PartnerTokenStore {
		return new PartnerTokenStore(
			transients: $this->transients,
			clock: $this->clock,
			ttl: 3600,
			key_prefix: 'ps_partner_token_',
		);
	}

	/**
	 * happy：issue 回 token + expires_at = now + 3600
	 *
	 * @group happy
	 */
	public function test_issue_returns_token_and_expires_at(): void {
		$store = $this->make_store();

		$result = $store->issue( 5 );

		$this->assertArrayHasKey( 'token', $result );
		$this->assertArrayHasKey( 'expires_at', $result );
		$this->assertIsString( $result['token'] );
		$this->assertGreaterThanOrEqual( 32, strlen( $result['token'] ), 'token 強度應 >= 32 字元' );
		$this->assertSame( $this->clock->now() + 3600, $result['expires_at'] );
	}

	/**
	 * security：明文 token 不可寫入 transient（資料庫被脫不洩 token）
	 *
	 * @group security
	 */
	public function test_plaintext_token_never_stored_in_transient(): void {
		$store = $this->make_store();

		$result = $store->issue( 5 );

		$dump = $this->transients->dump_with_prefix( 'ps_partner_token_' );
		$this->assertNotEmpty( $dump, 'issue 必須寫入至少一筆 transient' );

		// 掃描所有 entry，明文 token 不可出現在 key 或 value 任一處
		foreach ( $dump as $key => $entry ) {
			$serialised_value = is_scalar( $entry['value'] )
				? (string) $entry['value']
				: \wp_json_encode( $entry['value'] );
			$this->assertStringNotContainsString(
				$result['token'],
				$key,
				'transient key 不可含明文 token'
			);
			$this->assertStringNotContainsString(
				$result['token'],
				(string) $serialised_value,
				'transient value 不可含明文 token'
			);
		}
	}

	/**
	 * happy：verify 用對的 token 命中 → 回 partner_term_id
	 *
	 * @group happy
	 */
	public function test_verify_returns_partner_id_for_valid_token(): void {
		$store = $this->make_store();

		$result = $store->issue( 42 );

		$this->assertSame( 42, $store->verify( $result['token'] ) );
	}

	/**
	 * error：錯誤 token → 回 null（不拋例外）
	 *
	 * @group error
	 */
	public function test_verify_returns_null_for_unknown_token(): void {
		$store = $this->make_store();
		$store->issue( 5 );

		$this->assertNull( $store->verify( 'totally-fake-token' ) );
	}

	/**
	 * edge：token TTL 過期（推進時鐘 3601 秒）→ verify 回 null
	 *
	 * @group edge
	 * @group security
	 */
	public function test_verify_returns_null_after_ttl_expired(): void {
		$store = $this->make_store();

		$result = $store->issue( 5 );

		// 推進時鐘超過 TTL
		$this->clock->advance( 3601 );

		$this->assertNull( $store->verify( $result['token'] ), 'TTL 過期後 verify 必須回 null' );
	}

	/**
	 * edge：dual safety check — 即使 transient 沒過期（被誤調），expires_at 過期也應回 null
	 *
	 * 模擬：transient 仍在但內部 expires_at 已被改成過去
	 *
	 * @group edge
	 * @group security
	 */
	public function test_verify_double_checks_expires_at_even_if_transient_alive(): void {
		$store = $this->make_store();

		$result = $store->issue( 5 );

		// 對所有 transient entry 強制把 expires_at 改成「未來」（讓 transient 不會自動清除），
		// 但讓內部紀錄的 expires_at（PartnerTokenStore 自存的那份）小於 now。
		// 這要靠 PartnerTokenStore 內部存兩份：transient TTL + value 內含 expires_at。
		// 我們以 set_to 推進時鐘 + 把 transient TTL 設遠未來 來模擬。
		foreach ( $this->transients->dump_with_prefix( 'ps_partner_token_' ) as $key => $_entry ) {
			// 把 transient 自身的 expires_at 設定為遠未來，模擬 transient 還活著
			$this->transients->force_expires_at( $key, $this->clock->now() + 999_999 );
		}

		// 推進時鐘超過原 token TTL（3600）
		$this->clock->advance( 3601 );

		$this->assertNull(
			$store->verify( $result['token'] ),
			'dual safety check：即使 transient 還在，token 內部 expires_at 過期也應回 null'
		);
	}

	/**
	 * happy：revoke 後 verify 回 null
	 *
	 * @group happy
	 * @group security
	 */
	public function test_revoke_makes_token_unusable(): void {
		$store = $this->make_store();

		$result = $store->issue( 5 );
		$this->assertSame( 5, $store->verify( $result['token'] ) );

		$store->revoke( $result['token'] );

		$this->assertNull( $store->verify( $result['token'] ), 'revoke 後 token 應失效' );
	}

	/**
	 * security：兩次 issue 應產生不同 token（不可重用 / 可預測）
	 *
	 * @group security
	 */
	public function test_each_issue_produces_unique_token(): void {
		$store = $this->make_store();

		$tokens = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$tokens[] = $store->issue( 5 )['token'];
		}

		$this->assertCount(
			5,
			array_unique( $tokens ),
			'每次 issue 必須產生不同 token（隨機性 + 唯一性）'
		);
	}
}
