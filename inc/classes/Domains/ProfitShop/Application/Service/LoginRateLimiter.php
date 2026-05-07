<?php
/**
 * 登入速率限制 Service（per-slug + per-IP 雙維度）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;

/**
 * Partner 登入速率限制（per-slug 計數器 + per-IP 計數器）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§6.4、§8（reviewer M-2 補強）
 *
 * 行為：
 *   - assert_not_blocked(slug, ?ip)：任一維度達 max_attempts 拋 TooManyAttempts
 *     retry_after 取雙維度較大者（保守策略）
 *   - record_failure(slug, ?ip)：slug 維度 +1；若 IP 合法亦 +1（同一視窗策略）
 *     僅 slug 達門檻時通知 admin（避免換 slug 攻擊 spam admin）
 *   - reset(slug, ?ip)：清 slug；若提供 IP 一併清 IP
 *
 * Transient 設計：
 *   slug key   = "ps_partner_login_fail_{slug}"      （與 Phase 3-C 既有相容，不變）
 *   IP   key   = "ps_partner_login_fail_ip_{sha256(ip)}"（PII 保護）
 *   value      = ['count' => int, 'expires_at' => int]
 *   ttl        = window_seconds（與內層 expires_at 同步，window 過期計數歸零）
 *
 * IP 防呆：
 *   - 無效 / 空字串 / null 一律視同 null（不寫 IP 維度 key）
 *   - filter_var($ip, FILTER_VALIDATE_IP) 把關
 *   - 外層 V2Api 應已經做過一次 filter_var，本層僅作為 defense-in-depth
 */
final class LoginRateLimiter {

	private const KEY_PREFIX = 'ps_partner_login_fail_';

	/**
	 * IP key prefix（v2：升級為 hash_hmac + wp_salt('auth')，與 v1 自然分離）
	 *
	 * V1（hash('sha256', $ip)）transient 自然過期後不再使用，無資料遷移需求.
	 */
	private const KEY_PREFIX_IP = 'ps_partner_login_fail_ip_v2_';

	/**
	 * 建構子
	 *
	 * 採 nominal interface（TransientStoreInterface / ClockInterface / EmailNotifierInterface
	 * / SaltProviderInterface）；
	 * Production 由 WpTransientStore / SystemClock / WpAdminEmailNotifier / WpSaltProvider 注入，
	 * 測試由 fake / 匿名 class 實作介面。
	 *
	 * @param TransientStoreInterface $transients     Transient 儲存（提供 set/get/delete）.
	 * @param ClockInterface          $clock          時鐘（提供 now(): int）.
	 * @param EmailNotifierInterface  $email          Email 通知（提供 notify(string,string,string)）.
	 * @param SaltProviderInterface   $salt_provider  Salt 提供器（IP hash key；reviewer M-2）.
	 * @param int                     $max_attempts   失敗次數上限（含）.
	 * @param int                     $window_seconds 視窗秒數（預設 900 = 15 分鐘）.
	 */
	public function __construct(
		private readonly TransientStoreInterface $transients,
		private readonly ClockInterface $clock,
		private readonly EmailNotifierInterface $email,
		private readonly SaltProviderInterface $salt_provider,
		private readonly int $max_attempts = 5,
		private readonly int $window_seconds = 900
	) {}

	/**
	 * 檢查指定 slug + IP 是否仍在允許範圍
	 *
	 * 雙維度任一達門檻即拋；retry_after 取較大者（保守策略，避免攻擊者鑽較短側）.
	 *
	 * @param string      $slug Partner slug
	 * @param string|null $ip   Client IP（無效 / null 時僅檢查 slug 維度）
	 *
	 * @return void
	 *
	 * @throws TooManyAttempts 當任一維度達到 max_attempts.
	 */
	public function assert_not_blocked( string $slug, ?string $ip = null ): void {
		$slug_count = $this->current_count( $this->key_for_slug( $slug ) );
		$slug_blocked = $slug_count >= $this->max_attempts;

		$normalized_ip = $this->normalize_ip( $ip );
		$ip_blocked    = false;
		if ( null !== $normalized_ip ) {
			$ip_count   = $this->current_count( $this->key_for_ip( $normalized_ip ) );
			$ip_blocked = $ip_count >= $this->max_attempts;
		}

		if ( ! $slug_blocked && ! $ip_blocked ) {
			return;
		}

		// 兩維度任一鎖定 → 取雙維度 retry_after 較大者（保守策略）
		$slug_retry = $slug_blocked ? $this->retry_after( $this->key_for_slug( $slug ) ) : 0;
		$ip_retry   = ( $ip_blocked && null !== $normalized_ip )
		? $this->retry_after( $this->key_for_ip( $normalized_ip ) )
		: 0;

		throw new TooManyAttempts( retry_after: max( $slug_retry, $ip_retry ) );
	}

	/**
	 * 紀錄一次失敗嘗試（雙維度同步遞增）
	 *
	 * IP 達門檻不會額外通知 admin（避免攻擊者輪換 slug 時 spam admin）.
	 *
	 * @param string      $slug Partner slug
	 * @param string|null $ip   Client IP（無效 / null 時僅累計 slug 維度）
	 *
	 * @return void
	 */
	public function record_failure( string $slug, ?string $ip = null ): void {
		// slug 維度：保留 Phase 3-C 既有行為（達門檻時 audit log + email warn-and-swallow）
		$slug_new_count = $this->bump_counter( $this->key_for_slug( $slug ) );
		if ( $slug_new_count === $this->max_attempts ) {
			\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[power-shop][audit] partner-lockout slug=%s count=%d', $slug, $slug_new_count )
			);
			$this->notify_admin_safely( $slug, $slug_new_count );
		}

		// IP 維度：僅累計，不寄信（IP 換 slug 攻擊時 admin email 會被洗版）
		$normalized_ip = $this->normalize_ip( $ip );
		if ( null !== $normalized_ip ) {
			$ip_new_count = $this->bump_counter( $this->key_for_ip( $normalized_ip ) );
			if ( $ip_new_count === $this->max_attempts ) {
				// audit log 仍寫（不 spam admin email），方便事後追查
				\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'[power-shop][audit] partner-lockout ip-hash=%s count=%d',
						$this->audit_ip_hash( $normalized_ip ),
						$ip_new_count
					)
				);
			}
		}
	}

	/**
	 * 僅累計 IP 維度的失敗（不動 slug）——用於「未知 slug」場景
	 *
	 * 動機：對 unknown slug 分支若完全不 record 任何維度，
	 * 攻擊者可用同一 IP 對 1000 個不存在的 slug 各試 1 次而 IP 計數完全不增加，
	 * 導致 IP 維度防禦對「unknown slug 攻擊」失效。
	 *
	 * 維持「未知 slug 不在 slug 維度留痕」的原則（避免 timing oracle 暴露 slug 存在性），
	 * 但仍累計 IP 維度以阻擋 IP 層級的暴力探測.
	 *
	 * 與 record_failure 的差異：
	 *   - record_failure：slug 維度 +1（達門檻通知 admin）+ IP 維度 +1
	 *   - record_ip_only_failure：僅 IP 維度 +1，slug 完全不動
	 *
	 * IP 無效時為 no-op（與其他 method 一致）.
	 *
	 * @param string|null $ip Client IP（無效 / null 時為 no-op）
	 *
	 * @return void
	 */
	public function record_ip_only_failure( ?string $ip ): void {
		$normalized_ip = $this->normalize_ip( $ip );
		if ( null === $normalized_ip ) {
			return;
		}
		$ip_new_count = $this->bump_counter( $this->key_for_ip( $normalized_ip ) );
		if ( $ip_new_count === $this->max_attempts ) {
			// audit log 仍寫（不 spam admin email），方便事後追查
			\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[power-shop][audit] partner-lockout-ip-only ip-hash=%s count=%d',
					$this->audit_ip_hash( $normalized_ip ),
					$ip_new_count
				)
			);
		}
	}

	/**
	 * 重置指定 slug 的失敗計數（提供 IP 時一併清 IP 維度）
	 *
	 * @param string      $slug Partner slug
	 * @param string|null $ip   Client IP（提供時一併清 IP 維度）
	 *
	 * @return void
	 */
	public function reset( string $slug, ?string $ip = null ): void {
		$this->transients->delete( $this->key_for_slug( $slug ) );

		$normalized_ip = $this->normalize_ip( $ip );
		if ( null !== $normalized_ip ) {
			$this->transients->delete( $this->key_for_ip( $normalized_ip ) );
		}
	}

	// ============================================================
	// 內部 helper
	// ============================================================

	/**
	 * 將 IP 字串標準化：合法 IP 才回傳，否則回 null
	 *
	 * @param string|null $ip 原始 IP 字串
	 *
	 * @return string|null
	 */
	private function normalize_ip( ?string $ip ): ?string {
		if ( null === $ip || '' === $ip ) {
			return null;
		}
		$validated = filter_var( $ip, FILTER_VALIDATE_IP );
		if ( false === $validated ) {
			return null;
		}
		return (string) $validated;
	}

	/**
	 * 累加單一 transient key 的計數（共用於 slug / IP 兩維度）
	 *
	 * 沿用既有 expires_at（若視窗內），避免攻擊者每次失敗都 reset 視窗.
	 *
	 * @param string $key transient key（已含 prefix）
	 *
	 * @return int 累加後的最新計數
	 */
	private function bump_counter( string $key ): int {
		$now            = $this->clock->now();
		$expires_at_now = $now + $this->window_seconds;

		$existing = $this->transients->get( $key );
		$expires_at = is_array( $existing ) && isset( $existing['expires_at'] )
		? (int) $existing['expires_at']
		: $expires_at_now;

		// 既有視窗已過期 → 重新開啟新視窗
		if ( $expires_at <= $now ) {
			$expires_at = $expires_at_now;
			$new_count  = 1;
		} else {
			$prev_count = ( is_array( $existing ) && isset( $existing['count'] ) ) ? (int) $existing['count'] : 0;
			$new_count  = $prev_count + 1;
		}

		$ttl = max( 1, $expires_at - $now );

		$this->transients->set(
			$key,
			[
				'count'      => $new_count,
				'expires_at' => $expires_at,
			],
			$ttl
		);

		return $new_count;
	}

	/**
	 * 取得 transient key 目前的計數（過期視為 0）
	 *
	 * @param string $key transient key（已含 prefix）
	 *
	 * @return int
	 */
	private function current_count( string $key ): int {
		$entry = $this->transients->get( $key );
		if ( ! is_array( $entry ) || ! isset( $entry['count'], $entry['expires_at'] ) ) {
			return 0;
		}
		// 內層 expires_at 雙重檢查（避免 cache 不一致）
		if ( (int) $entry['expires_at'] <= $this->clock->now() ) {
			return 0;
		}
		return (int) $entry['count'];
	}

	/**
	 * 取得目前剩餘 retry_after 秒數（鎖定狀態）
	 *
	 * @param string $key transient key（已含 prefix）
	 *
	 * @return int
	 */
	private function retry_after( string $key ): int {
		$entry = $this->transients->get( $key );
		if ( ! is_array( $entry ) || ! isset( $entry['expires_at'] ) ) {
			return $this->window_seconds;
		}
		$remain = (int) $entry['expires_at'] - $this->clock->now();
		if ( $remain <= 0 ) {
			return $this->window_seconds;
		}
		return min( $remain, $this->window_seconds );
	}

	/**
	 * Slug 維度 transient key
	 *
	 * @param string $slug Partner slug
	 *
	 * @return string
	 */
	private function key_for_slug( string $slug ): string {
		return self::KEY_PREFIX . $slug;
	}

	/**
	 * IP 維度 transient key（hash_hmac + wp_salt('auth')；reviewer M-2）
	 *
	 * 升級理由（vs Phase 3-D 早期 hash('sha256', $ip)）：
	 *   - 純 sha256 對固定 IPv4 字串 (10^32 級熵) 可離線快速反推
	 *   - hash_hmac 配合 wp_salt 後，attacker 即使 dump transient 也無法以
	 *     字典攻擊還原原始 IP（key 是 wp_salt 控管的高熵秘密）
	 *
	 * @param string $ip 已驗證合法的 IP 字串
	 *
	 * @return string
	 */
	private function key_for_ip( string $ip ): string {
		return self::KEY_PREFIX_IP . hash_hmac( 'sha256', $ip, $this->salt_provider->get( 'auth' ) );
	}

	/**
	 * 計算 audit log 用的 IP hash（與 key_for_ip 同 hash 演算法保證一致性）
	 *
	 * @param string $ip 已驗證合法的 IP 字串
	 *
	 * @return string
	 */
	private function audit_ip_hash( string $ip ): string {
		return hash_hmac( 'sha256', $ip, $this->salt_provider->get( 'auth' ) );
	}

	/**
	 * 寄 admin 通知（warn-and-swallow，永不向上拋）
	 *
	 * @param string $slug  Partner slug
	 * @param int    $count 已累積失敗次數
	 *
	 * @return void
	 */
	private function notify_admin_safely( string $slug, int $count ): void {
		try {
			$subject = '[Power Shop] Partner 登入暴力破解警示';
			$body    = sprintf(
				"Partner slug: %s 已連續登入失敗達 %d 次。\n請檢查是否為帳號被暴力破解攻擊。",
				$slug,
				$count
			);
			// 收件人為空字串時，WpAdminEmailNotifier 內部 fallback 到 get_option('admin_email')。
			$this->email->notify( '', $subject, $body );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// warn-and-swallow：寄信失敗只記 log，不阻擋登入流程；過濾 CR/LF/TAB 防 log injection
			$msg = str_replace( [ "\r", "\n", "\t" ], ' ', $e->getMessage() );
			\error_log( "[power-shop] LoginRateLimiter admin notice failed: {$msg}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
