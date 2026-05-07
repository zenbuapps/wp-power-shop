<?php
/**
 * PartnerTokenStore 密碼輪替撤銷 + Hash HMAC 升級紅燈測試（Phase 3-D Batch 2 / T-2）
 *
 * 對應規格：
 *   - specs/2026-05-06-profit-shop-design.md §6.3（password rotation）
 *   - reviewer L-1：hash → hash_hmac + wp_salt 升級
 *   - .claude/rules/profit-shop.rule.md §4（nominal interface DI）、§7（token issued_at 撤銷不變式）
 *
 * 紅燈合約：
 *   1. constructor 升級為 4 依賴：(TransientStoreInterface, ClockInterface, PartnerRepositoryInterface, SaltProviderInterface)
 *   2. issue() payload 新增 issued_at = clock->now()
 *   3. verify()：取出 token 的 issued_at + partners->get_password_changed_at()
 *      - password_changed_at === null → 不檢查（向後相容；從未變更密碼）
 *      - issued_at >= password_changed_at → 通過（同秒視為「同時或之後簽發」）
 *      - issued_at < password_changed_at → 視為已撤銷，回 null
 *   4. hash_token() 改 hash_hmac('sha256', $token, $salt_provider->get('auth'))
 *
 * 紅燈狀態：
 *   PartnerTokenStore 仍是 2 依賴 ctor + 內部 hash('sha256') 無 key + verify 無 password_changed_at 比對。
 *   執行後預期：
 *     - 多數測試於 constructor TypeError 失敗（ArgumentCountError / 不接受 PartnerRepositoryInterface）
 *     - hash 升級類測試於 reflection 找不到 'hash_hmac' 字串失敗
 *
 * @group profit_shop
 * @group application
 * @group service
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\ClockInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SaltProviderInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\TransientStoreInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\FixedSaltProvider;
use Tests\Support\InMemoryTransientStore;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * PartnerTokenStore 密碼輪替 + HMAC 升級測試
 */
final class PartnerTokenStorePasswordRotationTest extends TestCase {

	private const PARTNER_TERM_ID = 5001;

	private FixedClock $clock;
	private InMemoryTransientStore $transients;
	private InMemoryPartnerRepository $partners;
	private FixedSaltProvider $salt_provider;

	protected function setUp(): void {
		parent::setUp();
		$this->clock         = new FixedClock( 100 );
		$this->transients    = new InMemoryTransientStore( $this->clock );
		$this->partners      = new InMemoryPartnerRepository( $this->clock );
		$this->salt_provider = new FixedSaltProvider();

		// 預植入一個 partner（無 password_changed_at，由各測試自行設定）
		$this->partners->seed(
			term_id: self::PARTNER_TERM_ID,
			name: 'Jerry',
			slug: 'jerry',
		);
	}

	private function make_store( ?SaltProviderInterface $salt_provider = null ): PartnerTokenStore {
		return new PartnerTokenStore(
			transients: $this->transients,
			clock: $this->clock,
			partners: $this->partners,
			salt_provider: $salt_provider ?? $this->salt_provider,
			ttl: 3600,
			key_prefix: 'ps_partner_token_',
		);
	}

	// ========== 撤銷邏輯（password_changed_at 比對） ==========

	/**
	 * 核心情境：admin 重設密碼後，舊 token 應立即失效
	 *
	 * t0=100 issue token → password_changed_at=200 → t=110 verify 同 token → null
	 *
	 * @group security
	 * @group edge
	 */
	public function test_verify_returns_null_when_password_changed_after_issue(): void {
		// 確保 partner 起始無 password_changed_at（未變更密碼）
		$this->assertNull( $this->partners->get_password_changed_at( self::PARTNER_TERM_ID ) );

		$store = $this->make_store();

		// t0=100：issue token，此時 issued_at=100
		$this->clock->set_to( 100 );
		$result = $store->issue( self::PARTNER_TERM_ID );
		$this->assertSame(
			self::PARTNER_TERM_ID,
			$store->verify( $result['token'] ),
			'token 簽發後立即 verify 應通過'
		);

		// 模擬 admin 重設密碼：password_changed_at=200
		$this->partners->set_password_changed_at( self::PARTNER_TERM_ID, 200 );

		// t=110：仍在 TTL 內，但密碼已變更（200 > 110 且 200 > issued_at=100）
		$this->clock->set_to( 110 );

		$this->assertNull(
			$store->verify( $result['token'] ),
			'密碼變更時間 (200) > token 簽發時間 (100)，token 應被視為已撤銷'
		);
	}

	/**
	 * 反向情境：partner 在 token 簽發前就改過密碼（這是常態，不該影響新 token）
	 *
	 * @group security
	 * @group happy
	 */
	public function test_verify_returns_partner_id_when_password_changed_before_issue(): void {
		// 過去（t=50）密碼變更過
		$this->partners->set_password_changed_at( self::PARTNER_TERM_ID, 50 );

		$store = $this->make_store();

		// t0=100：簽發新 token
		$this->clock->set_to( 100 );
		$result = $store->issue( self::PARTNER_TERM_ID );

		$this->assertSame(
			self::PARTNER_TERM_ID,
			$store->verify( $result['token'] ),
			'token 簽發於密碼變更之後，verify 應通過'
		);
	}

	/**
	 * 兼容情境：partner 從未變更密碼（password_changed_at === null）
	 *
	 * @group happy
	 */
	public function test_verify_returns_partner_id_when_password_never_changed(): void {
		// 確保起始 null
		$this->assertNull( $this->partners->get_password_changed_at( self::PARTNER_TERM_ID ) );

		$store = $this->make_store();

		$this->clock->set_to( 100 );
		$result = $store->issue( self::PARTNER_TERM_ID );

		// 不推進時鐘，不變更密碼
		$this->assertSame(
			self::PARTNER_TERM_ID,
			$store->verify( $result['token'] ),
			'partner 從未變更密碼時，verify 應如常通過（向後相容）'
		);
	}

	/**
	 * 關鍵邊界：issued_at == password_changed_at（同秒）
	 *
	 * 規範：>= 不撤銷（同時或之後簽發都算合法）
	 *
	 * @group security
	 * @group edge
	 */
	public function test_verify_treats_same_second_password_change_as_not_revoked(): void {
		// password_changed_at 預先設成 100
		$this->partners->set_password_changed_at( self::PARTNER_TERM_ID, 100 );

		$store = $this->make_store();

		// 同秒簽發 token（issued_at=100）
		$this->clock->set_to( 100 );
		$result = $store->issue( self::PARTNER_TERM_ID );

		$this->assertSame(
			self::PARTNER_TERM_ID,
			$store->verify( $result['token'] ),
			'issued_at == password_changed_at（同秒）視為「同時或之後簽發」→ 不撤銷'
		);
	}

	/**
	 * 共存：原本 revoke() 行為仍維持
	 *
	 * @group happy
	 * @group security
	 */
	public function test_revoke_still_works_after_password_rotation_logic(): void {
		$store = $this->make_store();

		$this->clock->set_to( 100 );
		$result = $store->issue( self::PARTNER_TERM_ID );
		$this->assertSame( self::PARTNER_TERM_ID, $store->verify( $result['token'] ) );

		$store->revoke( $result['token'] );

		$this->assertNull(
			$store->verify( $result['token'] ),
			'revoke() 應與密碼撤銷邏輯共存，獨立刪除 transient'
		);
	}

	// ========== Constructor 簽名鎖定（防回退到 object duck-type） ==========

	/**
	 * 用 reflection 鎖死 constructor 必須含 4 個 nominal interface 依賴
	 * 防止被改回 `object $store`、`object $clock` 鴨子型別。
	 *
	 * @group security
	 */
	public function test_constructor_requires_partners_repository_and_salt_provider(): void {
		$ctor = new \ReflectionMethod( PartnerTokenStore::class, '__construct' );

		$params = $ctor->getParameters();

		// 至少 4 個必填依賴（ttl / key_prefix 是 optional）
		$this->assertGreaterThanOrEqual(
			4,
			count( $params ),
			'PartnerTokenStore::__construct 應有至少 4 個 nominal 依賴（transients / clock / partners / salt_provider）'
		);

		$param_types = [];
		foreach ( $params as $p ) {
			$type = $p->getType();
			if ( $type instanceof \ReflectionNamedType ) {
				$param_types[ $p->getName() ] = $type->getName();
			}
		}

		// 必須有 partners 參數，型別 = PartnerRepositoryInterface
		$this->assertArrayHasKey(
			'partners',
			$param_types,
			'constructor 必須有名為 $partners 的參數'
		);
		$this->assertSame(
			PartnerRepositoryInterface::class,
			$param_types['partners'],
			'$partners 必須型別為 PartnerRepositoryInterface（nominal interface DI，禁鴨子型別 object）'
		);

		// 必須有 salt_provider 參數，型別 = SaltProviderInterface
		$this->assertArrayHasKey(
			'salt_provider',
			$param_types,
			'constructor 必須有名為 $salt_provider 的參數'
		);
		$this->assertSame(
			SaltProviderInterface::class,
			$param_types['salt_provider'],
			'$salt_provider 必須型別為 SaltProviderInterface'
		);

		// 既有依賴仍維持 nominal type
		$this->assertSame(
			TransientStoreInterface::class,
			$param_types['transients'] ?? '',
			'$transients 仍應為 TransientStoreInterface'
		);
		$this->assertSame(
			ClockInterface::class,
			$param_types['clock'] ?? '',
			'$clock 仍應為 ClockInterface'
		);
	}

	// ========== Hash HMAC 升級（reviewer L-1） ==========

	/**
	 * 行為驗證：兩個不同 salt 對同一 token 應產生不同 transient key
	 *
	 * @group security
	 */
	public function test_hash_uses_hash_hmac_with_salt(): void {
		// 用兩個不同 salt 各建一個 store，issue 同一 partner，比較 transient key 集合
		$salt_a = new FixedSaltProvider( 'salt_value_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa_padding' );
		$salt_b = new FixedSaltProvider( 'salt_value_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb_padding' );

		// 兩 store 共用同一 InMemoryTransientStore，比較總 key 集合
		$store_a = $this->make_store( $salt_a );
		$result_a = $store_a->issue( self::PARTNER_TERM_ID );

		// 紀錄第一輪 keys
		$keys_after_a = array_keys( $this->transients->dump_with_prefix( 'ps_partner_token_' ) );

		// 用 salt_b 與「相同 transient store」再 issue，模擬同 token 不同 salt
		$store_b = $this->make_store( $salt_b );
		$result_b = $store_b->issue( self::PARTNER_TERM_ID );

		$keys_after_b = array_keys( $this->transients->dump_with_prefix( 'ps_partner_token_' ) );

		// salt_b 的 issue 應產生新的 key（兩 salt 對任何 token 都映射到不同 hash）
		$this->assertGreaterThan(
			count( $keys_after_a ),
			count( $keys_after_b ),
			'換 salt 後 issue 應產生新 transient key（hash_hmac 必須吃 salt）'
		);

		// 雖然 token 不同，但雙方 verify 自己 token 應通過（行為等價）
		$this->assertSame( self::PARTNER_TERM_ID, $store_a->verify( $result_a['token'] ) );
		$this->assertSame( self::PARTNER_TERM_ID, $store_b->verify( $result_b['token'] ) );

		// 額外用 reflection 把關 hash_token method body 含 'hash_hmac' 字串
		// （防演算法被改回 plain sha256；行為測試＋實作鎖死雙保險）
		$method = new \ReflectionMethod( PartnerTokenStore::class, 'hash_token' );
		$file   = $method->getFileName();
		$start  = $method->getStartLine();
		$end    = $method->getEndLine();
		$this->assertNotFalse( $file );
		$source = implode(
			"\n",
			array_slice( file( (string) $file ), (int) $start - 1, (int) $end - (int) $start + 1 )
		);
		$this->assertStringContainsString(
			'hash_hmac',
			$source,
			'PartnerTokenStore::hash_token 必須使用 hash_hmac（不可回退至 plain sha256）'
		);
	}

	/**
	 * 升級遷移：升級前用 plain sha256 寫入的 transient，升級後自然失效（Q1=A 可接受）
	 *
	 * 這是 reviewer L-1 升級時 orchestrator 自決的行為——既有所有 transient 一次性 invalidate。
	 *
	 * @group security
	 * @group edge
	 */
	public function test_legacy_sha256_transient_does_not_verify(): void {
		$plain_token = 'fake_legacy_token_64chars_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

		// 模擬升級前的 transient（用 plain sha256 無 key）
		$legacy_hash = hash( 'sha256', $plain_token );
		$this->transients->set(
			'ps_partner_token_' . $legacy_hash,
			[
				'partner_term_id' => self::PARTNER_TERM_ID,
				'expires_at'      => $this->clock->now() + 3600,
				// 注意：升級前沒有 issued_at 欄位
			],
			3600
		);

		// 升級後的 store（hash_hmac）對同 token 算出的 hash 不同
		$store = $this->make_store();

		$this->assertNull(
			$store->verify( $plain_token ),
			'升級前的 plain sha256 transient 在升級後應自然失效（不同 hash 演算法 → 找不到 key）'
		);
	}

	// ========== TTL / 撤銷與密碼輪替的互動 ==========

	/**
	 * 撤銷邏輯不應誤殺：在 password_changed_at < issued_at 且尚未過 TTL 的 token 應通過
	 * （此 case 與 _before_issue 重疊，但顯式驗證 TTL 中段）
	 *
	 * @group happy
	 */
	public function test_verify_passes_within_ttl_when_password_changed_long_before_issue(): void {
		$this->partners->set_password_changed_at( self::PARTNER_TERM_ID, 1 );

		$store = $this->make_store();

		$this->clock->set_to( 100 );
		$result = $store->issue( self::PARTNER_TERM_ID );

		// 推進至 TTL 中段
		$this->clock->set_to( 1800 );

		$this->assertSame(
			self::PARTNER_TERM_ID,
			$store->verify( $result['token'] ),
			'TTL 中段 + 密碼變更早於簽發時間 → 應通過'
		);
	}
}
