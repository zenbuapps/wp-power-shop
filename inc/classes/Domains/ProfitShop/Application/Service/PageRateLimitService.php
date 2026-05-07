<?php
/**
 * 前台頁面 IP-based rate-limit Service
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;

/**
 * 前台頁面 IP-based rate-limit（Phase 5-A.1）
 *
 * 與 LoginRateLimiter 的差異：
 *   - LoginRateLimiter：per-slug 失敗計數（成功時 reset），語意是「登入暴力破解防禦」
 *   - 本 service：per-IP 純頻率（任何訪問都計入），語意是「前台頁面 DoS 緩解」
 *
 * 參數（自決確認）：
 *   - 視窗 60 秒
 *   - 上限 30 次（GET 頁面，比 login 寬鬆）
 *   - 鎖定剩餘秒數抽 retry_after
 *   - key prefix: 'power_shop_page_rl_'
 *
 * Fail-open（自決 Q3=A）：transient 故障時 silently 放行 + error_log，
 * 不阻擋正常訪客；rate-limit 屬於「防禦深度」而非「正確性」核心。
 *
 * IP hash 用 hash_hmac + wp_salt('auth')（與 LoginRateLimiter v2 一致）：
 *   - 純 sha256 對固定 IPv4 字串可離線快速反推
 *   - hash_hmac 配合 wp_salt 後，attacker 即使 dump transient 也無法字典攻擊
 *
 * 並發限制（reviewer M-3）：
 *   並發場景下計數可能略低估（±N 容差）。
 *   get/set 兩步間若多請求同時讀到 N 後寫回 N+1，實際計數會比理論低。
 *   本 service 屬於防禦深度（DoS 緩解），不適合作為強一致性閾值。
 *   若需精確計數，需切換至 atomic INCR（如 Redis），跨 TransientStoreInterface 抽象層。
 */
final class PageRateLimitService {

	/**
	 * 視窗秒數
	 */
	private const WINDOW_SECONDS = 60;

	/**
	 * 視窗內請求上限
	 */
	private const MAX_REQUESTS = 30;

	/**
	 * Key prefix（與 LoginRateLimiter 自然分離）
	 */
	private const KEY_PREFIX = 'power_shop_page_rl_';

	/**
	 * 建構子
	 *
	 * 採 nominal interface（Application Port）；
	 * Production 由 WpTransientStore / SystemClock / WpSaltProvider 注入，
	 * 測試由 fakes 取代。
	 *
	 * @param TransientStoreInterface $transients Transient 儲存
	 * @param ClockInterface          $clock      時鐘
	 * @param SaltProviderInterface   $salt       Salt 提供器（IP hash key）
	 */
	public function __construct(
		private readonly TransientStoreInterface $transients,
		private readonly ClockInterface $clock,
		private readonly SaltProviderInterface $salt
	) {}

	/**
	 * 檢查並計數一次訪問
	 *
	 * 行為：
	 *   - $ip 為 null（取不到）→ no-op（fail-open）
	 *   - 視窗內計數 < MAX_REQUESTS → +1 後返回
	 *   - 視窗內計數 >= MAX_REQUESTS → 拋 TooManyAttempts（含剩餘秒數）
	 *   - transient 故障 → error_log 後 silently 放行（fail-open）
	 *
	 * @param string|null $ip       Client IP（null 表示取不到，視為 fail-open 放行）
	 * @param string      $page_key 頁面命名空間（'partner_portal' / 'profit_shop'）
	 *
	 * @return void
	 *
	 * @throws TooManyAttempts 達上限時拋出，retry_after = 視窗內剩餘秒數
	 */
	public function check_or_throw( ?string $ip, string $page_key ): void {
		if ( null === $ip ) {
			return; // fail-open：取不到 IP（CLI / 異常環境）
		}

		$key = self::KEY_PREFIX . $page_key . '_'
		. hash_hmac( 'sha256', $ip, $this->salt->get( 'auth' ) );

		try {
			$entry = $this->transients->get( $key );
		} catch ( \Throwable $e ) {
			$this->log_failure( 'get', $e );
			return; // fail-open
		}

		$now = $this->clock->now();

		// 解析既有 entry：['count' => int, 'expires_at' => int]
		// 與 LoginRateLimiter 同一視窗策略：視窗未過期則沿用 expires_at，避免攻擊者每次訪問 reset 視窗
		if ( is_array( $entry )
			&& isset( $entry['count'], $entry['expires_at'] )
			&& (int) $entry['expires_at'] > $now ) {
			$count      = (int) $entry['count'];
			$expires_at = (int) $entry['expires_at'];
		} else {
			$count      = 0;
			$expires_at = $now + self::WINDOW_SECONDS;
		}

		if ( $count >= self::MAX_REQUESTS ) {
			$retry_after = max( 1, $expires_at - $now );
			throw new TooManyAttempts(
				retry_after: $retry_after,
				message: sprintf( 'PAGE_RATE_LIMIT_EXCEEDED:%s', $page_key )
			);
		}

		$ttl = max( 1, $expires_at - $now );
		try {
			$this->transients->set(
				$key,
				[
					'count'      => $count + 1,
					'expires_at' => $expires_at,
				],
				$ttl
			);
		} catch ( \Throwable $e ) {
			$this->log_failure( 'set', $e );
			// fail-open：寫入失敗不阻擋訪客
		}
	}

	/**
	 * 記錄 transient 故障（過濾 CR/LF/TAB 防 log injection）
	 *
	 * @param string     $op 操作名稱
	 * @param \Throwable $e  例外
	 *
	 * @return void
	 */
	private function log_failure( string $op, \Throwable $e ): void {
		$msg = str_replace( [ "\r", "\n", "\t" ], ' ', $e->getMessage() );
		\error_log( "[power-shop] PageRateLimit transient {$op} failed: {$msg}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
