<?php
/**
 * LoginRateLimiter per-IP 雙維度紅燈測試（Phase 3-D Batch 3 - T-3）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8（reviewer M-2 補強）
 *
 * 紅燈合約（升級後簽名）：
 *   - assert_not_blocked(string $slug, ?string $ip = null): void
 *   - record_failure(string $slug, ?string $ip = null): void
 *   - reset(string $slug, ?string $ip = null): void
 *
 * 行為說明（T-3 升級重點）：
 *   - 既有 per-slug 鎖定行為**完全保留**（向後相容；不傳 IP 時等價舊行為）
 *   - 新增 per-IP 維度：同一 IP 對任意 slug 失敗累計達 max_attempts 即鎖定該 IP
 *   - IP 雜湊以 sha256 寫入 transient key，避免 PII 入庫
 *   - assert_not_blocked 兩維度任一觸發即拋 TooManyAttempts，retry_after 取雙維度較大者
 *   - reset 同時清 slug + IP（IP 提供時）；不提供 IP 時僅清 slug
 *   - 空字串 / 非合法 IP 視同 null（service 內防呆，外層 V2Api 應已 filter_var 把關）
 *
 * Transient key prefix 約定：
 *   - slug 維度：保留既有 'ps_partner_login_fail_' + slug 名（向後相容，現有 prefix 不變）
 *   - IP 維度：新增 'ps_partner_login_fail_ip_' + sha256(ip)
 *   兩者都是 'ps_partner_login_fail_' 前綴，但 IP 後接 'ip_' 區分；slug 不再附加區分
 *   字串以避免破壞既有資料。
 *
 * 紅燈預期：
 *   既有 LoginRateLimiter 簽名 `record_failure(string $slug)` 只收 1 個參數，
 *   傳第 2 個參數會 fatal 或 silently 忽略——前者測試直接 fatal，
 *   後者測試會在「IP 鎖定行為」上 assertion fail（IP key 不會寫入 transient）。
 *
 * @group profit_shop
 * @group application
 * @group service
 * @group security
 * @group phase_3d_batch3_t3
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\LoginRateLimiter;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\FixedSaltProvider;
use Tests\Support\InMemoryTransientStore;
use Tests\Support\SpyEmailNotifier;

/**
 * LoginRateLimiter per-IP 雙維度紅燈合約測試
 */
final class LoginRateLimiterIpDimensionTest extends TestCase {

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
			salt_provider: new FixedSaltProvider(),
			max_attempts: $max_attempts,
			window_seconds: $window_seconds,
		);
	}

	/**
	 * 取出所有 IP 維度的 transient key（含 'ps_partner_login_fail_ip_' prefix）
	 *
	 * @return array<string, array{value: mixed, expires_at: int}>
	 */
	private function ip_keys(): array {
		return $this->transients->dump_with_prefix( 'ps_partner_login_fail_ip_' );
	}

	/**
	 * 取出所有 slug 維度的 transient key（保留既有 'ps_partner_login_fail_' prefix，
	 * 但**排除** IP 維度的 'ps_partner_login_fail_ip_'）
	 *
	 * @return array<string, array{value: mixed, expires_at: int}>
	 */
	private function slug_keys(): array {
		$all_partner_login = $this->transients->dump_with_prefix( 'ps_partner_login_fail_' );
		$result            = [];
		foreach ( $all_partner_login as $key => $entry ) {
			if ( ! str_starts_with( $key, 'ps_partner_login_fail_ip_' ) ) {
				$result[ $key ] = $entry;
			}
		}
		return $result;
	}

	// ============================================================
	// A. 雙維度鎖定基本行為
	// ============================================================

	/**
	 * happy：同時寫入 slug + IP 兩個 transient key
	 *
	 * @group happy
	 */
	public function test_record_failure_with_ip_increments_both_slug_and_ip_counters(): void {
		$limiter = $this->make_limiter();

		$limiter->record_failure( 'partnerA', '1.2.3.4' );

		$this->assertCount( 1, $this->slug_keys(), '應寫入 1 個 slug 維度的 key（保留既有 prefix）' );
		$this->assertCount( 1, $this->ip_keys(), '應寫入 1 個 IP 維度的 key（新 ip_ prefix）' );
	}

	/**
	 * happy：未達門檻時，assert_not_blocked 不拋
	 *
	 * @group happy
	 */
	public function test_assert_not_blocked_passes_when_both_dimensions_under_threshold(): void {
		$limiter = $this->make_limiter( max_attempts: 5 );

		// 同 slug + 同 IP 4 次
		for ( $i = 0; $i < 4; $i++ ) {
			$limiter->record_failure( 'partnerA', '1.2.3.4' );
		}

		// slug=4 < 5，IP=4 < 5
		$limiter->assert_not_blocked( 'partnerA', '1.2.3.4' );
		$this->assertTrue( true, '4 次失敗（雙維度同步）不應被鎖' );
	}

	// ============================================================
	// B. IP 鎖定（跨 slug 攻擊防禦）—— 核心
	// ============================================================

	/**
	 * security：同一 IP 對不同 slug 失敗累計達門檻 → IP 被鎖（攻擊者換 slug 也無效）
	 *
	 * 關鍵防禦：純 per-slug 模式下，攻擊者可對 1000 個 partner 各試 4 次而不被擋；
	 * per-IP 維度確保跨 slug 累計仍會觸發鎖定。
	 *
	 * @group security
	 */
	public function test_assert_not_blocked_throws_when_ip_exceeds_threshold_across_different_slugs(): void {
		$limiter = $this->make_limiter( max_attempts: 5 );

		// 同 IP 對 5 個不同 slug 各失敗 1 次
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $slug ) {
			$limiter->record_failure( $slug, '1.2.3.4' );
		}

		// 此時每個 slug 的 count = 1 < 5（slug 維度全都安全）
		// 但 IP '1.2.3.4' 累計 5 次 → 應觸發 IP 鎖
		// 用一個全新 slug 'f' 嘗試，slug 維度乾淨，仍應因 IP 鎖被拒
		$this->expectException( TooManyAttempts::class );
		$limiter->assert_not_blocked( 'f', '1.2.3.4' );
	}

	/**
	 * security：IP 鎖定不影響其他 IP（per-IP 隔離）
	 *
	 * @group security
	 */
	public function test_ip_lock_does_not_affect_other_ips(): void {
		$limiter = $this->make_limiter( max_attempts: 5 );

		// IP 1.2.3.4 跨 slug 累計到鎖
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $slug ) {
			$limiter->record_failure( $slug, '1.2.3.4' );
		}

		// IP 5.6.7.8 是乾淨的，對任意 slug 都應 pass
		$limiter->assert_not_blocked( 'a', '5.6.7.8' );
		$limiter->assert_not_blocked( 'newpartner', '5.6.7.8' );
		$this->assertTrue( true, '其他 IP 不應受影響' );
	}

	// ============================================================
	// C. slug 鎖定保留（向後相容）
	// ============================================================

	/**
	 * security：不傳 IP 時退化為純 per-slug 行為（既有合約）
	 *
	 * @group security
	 */
	public function test_slug_lock_still_works_with_ip_null(): void {
		$limiter = $this->make_limiter( max_attempts: 5 );

		// 不傳 IP 累積到 5 次失敗
		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record_failure( 'jerry' );
		}

		// 第 6 次應被擋（slug 鎖）
		$this->expectException( TooManyAttempts::class );
		$limiter->assert_not_blocked( 'jerry' );
	}

	/**
	 * security：同一 slug + 同一 IP 累計到上限 → 雙維度同時觸發鎖定
	 *
	 * @group security
	 */
	public function test_slug_lock_works_when_ip_provided_but_under_ip_threshold(): void {
		$limiter = $this->make_limiter( max_attempts: 5 );

		// 同 slug + 同 IP 5 次（兩維度同時達門檻）
		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record_failure( 'jerry', '1.2.3.4' );
		}

		try {
			$limiter->assert_not_blocked( 'jerry', '1.2.3.4' );
			$this->fail( '預期拋 TooManyAttempts' );
		} catch ( TooManyAttempts $e ) {
			$this->assertGreaterThan( 0, $e->getRetryAfter(), 'retry_after 必須 > 0' );
			$this->assertLessThanOrEqual( 900, $e->getRetryAfter(), 'retry_after <= window_seconds' );
		}
	}

	// ============================================================
	// D. 重置行為
	// ============================================================

	/**
	 * security：reset 提供 IP 時，同時清除兩維度計數
	 *
	 * @group security
	 */
	public function test_reset_clears_both_slug_and_ip_counters_when_ip_provided(): void {
		$limiter = $this->make_limiter( max_attempts: 5 );

		// 累積 3 次（兩維度同步）
		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->record_failure( 'partnerA', '1.2.3.4' );
		}

		$limiter->reset( 'partnerA', '1.2.3.4' );

		// 兩維度都歸零後，再失敗 4 次仍不應觸發鎖定
		for ( $i = 0; $i < 4; $i++ ) {
			$limiter->record_failure( 'partnerA', '1.2.3.4' );
		}
		$limiter->assert_not_blocked( 'partnerA', '1.2.3.4' );

		$slug_keys_after_reset = $this->slug_keys();
		$ip_keys_after_reset   = $this->ip_keys();
		// 驗證 reset 後（再累積 4 次前後）兩維度計數正確
		// 此處最後狀態是「reset 後又累積 4 次」，所以兩個 key 都應該 = 4
		$this->assertCount( 1, $slug_keys_after_reset );
		$this->assertCount( 1, $ip_keys_after_reset );
		$slug_entry = array_values( $slug_keys_after_reset )[0];
		$ip_entry   = array_values( $ip_keys_after_reset )[0];
		$this->assertSame( 4, $slug_entry['value']['count'] ?? null, 'reset 後重新累積 4 次（slug）' );
		$this->assertSame( 4, $ip_entry['value']['count'] ?? null, 'reset 後重新累積 4 次（IP）' );
	}

	/**
	 * security：reset 不傳 IP 時僅清 slug 維度，不影響其他 slug 對該 IP 的累計
	 *
	 * @group security
	 */
	public function test_reset_with_null_ip_only_clears_slug_counter(): void {
		$limiter = $this->make_limiter( max_attempts: 5 );

		// IP 1.2.3.4 對 a,b,c,d 各失敗 1 次（IP 累計 4 次）
		foreach ( [ 'a', 'b', 'c', 'd' ] as $slug ) {
			$limiter->record_failure( $slug, '1.2.3.4' );
		}

		// reset slug='a'（不傳 IP）—— 只清 a 的 slug counter，IP 維度不動
		$limiter->reset( 'a' );

		// IP 維度仍累計 4，再 record_failure 一次（任意 slug）→ IP 達 5 → 鎖
		$limiter->record_failure( 'newslug', '1.2.3.4' );

		$this->expectException( TooManyAttempts::class );
		$limiter->assert_not_blocked( 'differentslug', '1.2.3.4' );
	}

	// ============================================================
	// E. PII 防護
	// ============================================================

	/**
	 * security：IP 必須以 sha256 雜湊形式存入 transient key，不得有明文 PII
	 *
	 * @group security
	 */
	public function test_ip_is_hashed_in_transient_key(): void {
		$limiter = $this->make_limiter();
		$ip      = '192.168.1.100';

		$limiter->record_failure( 'partnerA', $ip );

		$all_keys = array_keys( $this->transients->dump() );

		// 任一 key 都不得包含明文 IP
		foreach ( $all_keys as $key ) {
			$this->assertStringNotContainsString(
				$ip,
				$key,
				"transient key 不應含明文 IP；違規 key={$key}"
			);
		}

		// 應存在含 sha256(ip) 的 key
		$expected_hash    = hash( 'sha256', $ip );
		$matched_with_hash = array_filter(
			$all_keys,
			static fn( string $k ): bool => str_contains( $k, $expected_hash )
		);
		$this->assertNotEmpty(
			$matched_with_hash,
			'應存在帶有 sha256(ip) 的 transient key（PII 雜湊保護）'
		);
	}

	// ============================================================
	// F. 邊界
	// ============================================================

	/**
	 * edge：空字串 IP 視同 null（不寫 IP 維度 key）
	 *
	 * @group edge
	 */
	public function test_record_failure_with_empty_ip_string_treats_as_null(): void {
		$limiter = $this->make_limiter();

		$limiter->record_failure( 'partnerA', '' );

		$this->assertEmpty( $this->ip_keys(), '空字串 IP 不應寫入 IP 維度 key' );
		$this->assertCount( 1, $this->slug_keys(), 'slug 維度仍正常寫入' );
	}

	/**
	 * edge：非合法 IP 字串視同 null（service 內防呆，雖然外層 V2Api 應該已 filter_var 把關）
	 *
	 * @group edge
	 */
	public function test_record_failure_with_invalid_ip_string_treats_as_null(): void {
		$limiter = $this->make_limiter();

		$limiter->record_failure( 'partnerA', 'not-an-ip' );
		$limiter->record_failure( 'partnerB', 'localhost' );
		$limiter->record_failure( 'partnerC', '999.999.999.999' );

		$this->assertEmpty(
			$this->ip_keys(),
			'非合法 IP 字串（FILTER_VALIDATE_IP 失敗）不應寫入 IP 維度 key'
		);
	}

	// ============================================================
	// G. retry_after 計算
	// ============================================================

	/**
	 * security：雙維度都鎖定時，retry_after 取較大者（保守策略）
	 *
	 * 場景：先讓 IP 在 t=0 被鎖（expires_at=900），再推進時間到 t=300
	 * 然後讓 slug 在 t=300 被鎖（expires_at=1200），此時：
	 *   - IP retry_after  = 900 - 300 = 600
	 *   - slug retry_after = 1200 - 300 = 900
	 * 預期 TooManyAttempts.retry_after = max(600, 900) = 900（取較保守的 slug）
	 *
	 * @group security
	 */
	public function test_too_many_attempts_includes_retry_after_for_whichever_dimension_locked(): void {
		$limiter = $this->make_limiter( max_attempts: 5, window_seconds: 900 );

		// Step 1: t=0，讓 IP '1.2.3.4' 跨 slug 累積到鎖（5 次不同 slug）
		// 但故意讓每個 slug 都 < 5，僅 IP 達門檻
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $slug ) {
			$limiter->record_failure( $slug, '1.2.3.4' );
		}
		// IP key expires_at = 1_700_000_000 + 900 = 1_700_000_900

		// Step 2: 推進 300 秒
		$this->clock->advance( 300 );
		// 現在 t = 1_700_000_300

		// Step 3: 讓 slug 'jerry' 用另一個 IP 累積 5 次（slug 鎖，但用新 IP 避免汙染）
		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record_failure( 'jerry', '5.6.7.8' );
		}
		// jerry slug key expires_at = 1_700_000_300 + 900 = 1_700_001_200

		// Step 4: 用 'jerry' + IP '1.2.3.4' assert_not_blocked
		// 兩維度同時鎖：
		//   - slug='jerry' → retry_after = 1_700_001_200 - 1_700_000_300 = 900
		//   - IP '1.2.3.4' → retry_after = 1_700_000_900 - 1_700_000_300 = 600
		// 應取較大者 = 900
		try {
			$limiter->assert_not_blocked( 'jerry', '1.2.3.4' );
			$this->fail( '預期拋 TooManyAttempts（雙維度都鎖定）' );
		} catch ( TooManyAttempts $e ) {
			$this->assertSame(
				900,
				$e->getRetryAfter(),
				'雙維度鎖定時，retry_after 應取較大者（保守策略）'
			);
		}
	}

	// ============================================================
	// H. record_ip_only_failure（T-3 reviewer M-1：未知 slug 也應 IP 維度 record）
	// ============================================================

	/**
	 * security：未知 slug 場景下，PartnerAuthService 呼叫 record_ip_only_failure
	 * 應只增加 IP 維度計數，不動 slug 維度——避免 timing oracle 但仍阻擋 IP 暴力探測
	 *
	 * @group security
	 */
	public function test_unknown_slug_still_records_ip_failure(): void {
		$limiter = $this->make_limiter( max_attempts: 5 );

		// 模擬「同一 IP 對 5 個不存在的 slug 各試 1 次」（攻擊者枚舉場景）
		// 由於 record_ip_only_failure 只動 IP 不動 slug，這裡呼叫 5 次等同於枚舉 5 個 unknown slug
		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record_ip_only_failure( '1.2.3.4' );
		}

		// slug 維度應完全乾淨（未知 slug 不留痕）
		$this->assertEmpty(
			$this->slug_keys(),
			'record_ip_only_failure 不應寫入任何 slug 維度的 key（避免 timing oracle）'
		);

		// IP 維度應累計到 5 次（每次 +1）→ IP 鎖定觸發
		$this->assertCount(
			1,
			$this->ip_keys(),
			'IP 維度應有 1 個 key（5 次累計到同一 IP key）'
		);
		$ip_entry = array_values( $this->ip_keys() )[0];
		$this->assertSame(
			5,
			$ip_entry['value']['count'] ?? null,
			'IP 維度應累計到 5 次（每次 record_ip_only_failure +1）'
		);

		// 用一個全新（合法）slug 嘗試，應因 IP 鎖被拒
		$this->expectException( TooManyAttempts::class );
		$limiter->assert_not_blocked( 'fresh-slug', '1.2.3.4' );
	}

	/**
	 * security：僅 IP 鎖定時，retry_after 對應 IP transient TTL 剩餘
	 *
	 * @group security
	 */
	public function test_retry_after_reflects_ip_dimension_when_only_ip_locked(): void {
		$limiter = $this->make_limiter( max_attempts: 5, window_seconds: 900 );

		// IP 跨 slug 累計 5 次
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $slug ) {
			$limiter->record_failure( $slug, '1.2.3.4' );
		}

		// 推進 100 秒
		$this->clock->advance( 100 );

		// 用全新 slug 'fresh'（slug 維度乾淨）+ 該 IP
		try {
			$limiter->assert_not_blocked( 'fresh', '1.2.3.4' );
			$this->fail( '預期拋 TooManyAttempts（IP 鎖）' );
		} catch ( TooManyAttempts $e ) {
			$this->assertSame(
				800,
				$e->getRetryAfter(),
				'僅 IP 鎖時，retry_after = 900 - 100 = 800'
			);
		}
	}
}
