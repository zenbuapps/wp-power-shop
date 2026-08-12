<?php
/**
 * 賣場頁 / Partner Portal 的存取頻率上限
 *
 * 對應規格：specs/features/profit-shop/render-shop-front.feature
 *           Rule: 前置（速率）- 賣場頁對同一來源有存取頻率上限
 * 對應實作：Application/Service/PageRateLimitService
 *
 * ⚠️ 這支 Service 自 Phase 5-A.1 起就在保護兩個公開前台頁面
 * （`/profit-shop/{slug}/` 與 `/profit-report/{slug}/`），但在 B7 逐條盤點
 * 之前**沒有任何測試**。它是純 Application 層（三個 Port 全走 interface），
 * 用既有 fakes 就能完整覆蓋，屬於「可測但沒測」的真缺口。
 *
 * 🔴 本檔特別鎖住兩件**做錯了也不會有任何徵兆**的事：
 *   1. 視窗不得因為持續請求而被重置（否則攻擊者只要不停打就永遠觸發不了上限）
 *   2. 所有異常路徑一律 fail-open（IP 取不到 / transient 故障 / page_key 非法）——
 *      這是刻意的取捨：寧可漏擋，也不要因為 transient 故障就把全站訪客擋在門外
 *
 * @group profit_shop
 * @group application
 * @group service
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\PageRateLimitService;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\FixedSaltProvider;
use Tests\Support\InMemoryTransientStore;

/**
 * PageRateLimitService 單元測試
 */
final class PageRateLimitServiceTest extends TestCase {

	private const MAX_REQUESTS  = 30;
	private const WINDOW        = 60;
	private const IP            = '203.0.113.7';
	private const PAGE          = 'profit_shop';

	private InMemoryTransientStore $transients;
	private FixedClock $clock;

	protected function setUp(): void {
		parent::setUp();
		$this->clock      = new FixedClock();
		$this->transients = new InMemoryTransientStore( $this->clock );
	}

	private function make(): PageRateLimitService {
		return new PageRateLimitService(
			transients: $this->transients,
			clock: $this->clock,
			salt: new FixedSaltProvider()
		);
	}

	// ========== Happy：視窗內放行 ==========

	/**
	 * 視窗內第 1..30 次一律放行
	 *
	 * @test
	 * @group happy
	 */
	public function test_requests_under_the_limit_are_allowed(): void {
		$service = $this->make();

		for ( $i = 1; $i <= self::MAX_REQUESTS; $i++ ) {
			$service->check_or_throw( self::IP, self::PAGE );
		}

		$this->assertTrue( true, '前 30 次不應拋出任何例外' );
	}

	// ========== Error：達上限 ==========

	/**
	 * 第 31 次必須拋 TooManyAttempts
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_request_over_the_limit_throws(): void {
		$service = $this->make();

		for ( $i = 1; $i <= self::MAX_REQUESTS; $i++ ) {
			$service->check_or_throw( self::IP, self::PAGE );
		}

		$this->expectException( TooManyAttempts::class );
		$service->check_or_throw( self::IP, self::PAGE );
	}

	/**
	 * retry_after 必須是視窗內的剩餘秒數（供 Retry-After header 用）
	 *
	 * @test
	 * @group error
	 */
	public function test_retry_after_reflects_remaining_window(): void {
		$service = $this->make();

		for ( $i = 1; $i <= self::MAX_REQUESTS; $i++ ) {
			$service->check_or_throw( self::IP, self::PAGE );
		}

		// 視窗已過 20 秒，應剩 40 秒
		$this->clock->advance( 20 );

		try {
			$service->check_or_throw( self::IP, self::PAGE );
			$this->fail( '應拋 TooManyAttempts' );
		} catch ( TooManyAttempts $e ) {
			$this->assertSame( self::WINDOW - 20, $e->getRetryAfter() );
		}
	}

	// ========== Security：視窗策略 ==========

	/**
	 * 🔴 視窗不得因為持續請求而被重置
	 *
	 * 若每次訪問都把 `expires_at` 往後推，攻擊者只要維持穩定的請求速率就**永遠**
	 * 觸發不了上限——限流形同虛設，而且沒有任何徵兆（畫面一切正常）。
	 *
	 * 這裡在計數過程中讓時鐘前進，最後確認視窗仍在原本的結束時間到期。
	 *
	 * @test
	 * @group security
	 * @group edge
	 */
	public function test_window_is_not_extended_by_continued_requests(): void {
		$service = $this->make();

		// 第 1 次建立視窗（到期時間 = now + 60）
		$service->check_or_throw( self::IP, self::PAGE );

		// 中間隔 50 秒再打滿剩下的次數——若視窗被延長，這裡會把到期時間推到 +110
		$this->clock->advance( 50 );
		for ( $i = 2; $i <= self::MAX_REQUESTS; $i++ ) {
			$service->check_or_throw( self::IP, self::PAGE );
		}

		// 此刻 t=50，第 31 次應被擋下，且剩餘秒數是「原視窗」的 10 秒而非 60 秒
		try {
			$service->check_or_throw( self::IP, self::PAGE );
			$this->fail( '應拋 TooManyAttempts' );
		} catch ( TooManyAttempts $e ) {
			$this->assertSame(
				10,
				$e->getRetryAfter(),
				'視窗到期時間必須沿用第一次請求時算出的值，不可因後續請求而延長'
			);
		}
	}

	/**
	 * 視窗過期後計數重新開始
	 *
	 * @test
	 * @group happy
	 * @group edge
	 */
	public function test_counter_resets_after_window_expires(): void {
		$service = $this->make();

		for ( $i = 1; $i <= self::MAX_REQUESTS; $i++ ) {
			$service->check_or_throw( self::IP, self::PAGE );
		}

		$this->clock->advance( self::WINDOW + 1 );

		$service->check_or_throw( self::IP, self::PAGE );
		$this->assertTrue( true, '視窗過期後應重新計數而非繼續擋' );
	}

	/**
	 * 不同 IP 各自獨立計數
	 *
	 * @test
	 * @group security
	 */
	public function test_different_ips_are_counted_independently(): void {
		$service = $this->make();

		for ( $i = 1; $i <= self::MAX_REQUESTS; $i++ ) {
			$service->check_or_throw( self::IP, self::PAGE );
		}

		// 另一個 IP 不應受影響
		$service->check_or_throw( '198.51.100.9', self::PAGE );
		$this->assertTrue( true, '不同 IP 不得共用計數' );
	}

	/**
	 * 不同頁面命名空間各自獨立計數
	 *
	 * 賣場前台與 partner portal 共用這支 Service，兩者的額度不可互相消耗。
	 *
	 * @test
	 * @group security
	 */
	public function test_different_page_keys_are_counted_independently(): void {
		$service = $this->make();

		for ( $i = 1; $i <= self::MAX_REQUESTS; $i++ ) {
			$service->check_or_throw( self::IP, 'profit_shop' );
		}

		$service->check_or_throw( self::IP, 'partner_portal' );
		$this->assertTrue( true, '不同 page_key 不得共用計數' );
	}

	/**
	 * 🔴 IP 不得以明文落地
	 *
	 * transient key 只能出現 `hash_hmac(ip, salt)`，不得有原始 IP 字串。
	 * 另外斷言「不得是無金鑰的 sha256」——純 sha256 對固定格式 IPv4
	 * （約 2^32 種）可離線窮舉反推。
	 *
	 * @test
	 * @group security
	 */
	public function test_ip_never_lands_in_plaintext(): void {
		$service = $this->make();
		$service->check_or_throw( self::IP, self::PAGE );

		$keys = array_keys( $this->transients->dump() );
		$this->assertNotEmpty( $keys, '應寫入一筆計數 transient' );

		$expected = hash_hmac( 'sha256', self::IP, ( new FixedSaltProvider() )->get( 'auth' ) );
		$unkeyed  = hash( 'sha256', self::IP );

		foreach ( $keys as $key ) {
			$this->assertStringNotContainsString( self::IP, $key, "key 不得含明文 IP：{$key}" );
			$this->assertStringNotContainsString(
				$unkeyed,
				$key,
				"IP hash 不得退回無金鑰的 sha256（可離線窮舉反推）：{$key}"
			);
		}

		$matched = array_filter( $keys, static fn( string $k ): bool => str_contains( $k, $expected ) );
		$this->assertNotEmpty( $matched, '應存在帶 hash_hmac(ip, salt) 的 key' );
	}

	// ========== Edge：fail-open 三條路徑 ==========

	/**
	 * IP 取不到（CLI / 異常環境）→ 放行且不寫入任何計數
	 *
	 * @test
	 * @group edge
	 */
	public function test_null_ip_is_a_noop(): void {
		$service = $this->make();

		for ( $i = 1; $i <= self::MAX_REQUESTS + 5; $i++ ) {
			$service->check_or_throw( null, self::PAGE );
		}

		$this->assertSame( 0, $this->transients->size(), 'IP 為 null 時不應寫入任何 transient' );
	}

	/**
	 * page_key 非白名單 → 放行（fail-open）
	 *
	 * 白名單本身是防未來 caller 誤帶 user-controlled 字串；命中時的處置是
	 * **放行**而非阻擋——限流是輔助措施，不該因為呼叫端寫錯而把訪客擋在門外。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_invalid_page_key_fails_open(): void {
		$service = $this->make();

		foreach ( [ 'Profit-Shop', 'profit shop', '', str_repeat( 'a', 33 ), '../etc' ] as $bad ) {
			for ( $i = 1; $i <= self::MAX_REQUESTS + 2; $i++ ) {
				$service->check_or_throw( self::IP, $bad );
			}
		}

		$this->assertSame( 0, $this->transients->size(), '非法 page_key 不應寫入 transient' );
	}

	/**
	 * transient 讀取故障 → 放行（fail-open）
	 *
	 * 寧可漏擋，也不要因為 object cache 故障就把全站訪客擋在門外。
	 *
	 * @test
	 * @group edge
	 */
	public function test_transient_failure_fails_open(): void {
		$throwing = new class() implements \J7\PowerShop\Domains\ProfitShop\Application\Service\TransientStoreInterface {
			public function set( string $key, mixed $value, int $ttl ): bool {
				throw new \RuntimeException( 'object cache down' );
			}
			public function get( string $key ): mixed {
				throw new \RuntimeException( 'object cache down' );
			}
			public function delete( string $key ): bool {
				throw new \RuntimeException( 'object cache down' );
			}
		};

		$service = new PageRateLimitService(
			transients: $throwing,
			clock: $this->clock,
			salt: new FixedSaltProvider()
		);

		for ( $i = 1; $i <= self::MAX_REQUESTS + 5; $i++ ) {
			$service->check_or_throw( self::IP, self::PAGE );
		}

		$this->assertTrue( true, 'transient 故障時不應拋出例外，也不應阻擋訪客' );
	}
}
