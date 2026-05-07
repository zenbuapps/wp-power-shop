<?php
/**
 * 登入速率限制 Service
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;

/**
 * Partner 登入速率限制（per-slug 計數器 + 視窗）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§6.4
 *
 * 行為：
 *   - assert_not_blocked(slug)：若 count >= max_attempts 拋 TooManyAttempts
 *   - record_failure(slug)：count++，count == max_attempts 時呼叫 email notifier 通知 admin
 *     （warn-and-swallow：email 寄送失敗只記 log，不可阻擋登入流程）
 *   - reset(slug)：登入成功時清除計數
 *
 * Transient 設計：
 *   key   = "ps_partner_login_fail_{slug}"
 *   value = ['count' => int, 'expires_at' => int]
 *   ttl   = window_seconds（與內層 expires_at 同步，window 過期計數歸零）
 */
final class LoginRateLimiter {

	private const KEY_PREFIX = 'ps_partner_login_fail_';

	/**
	 * 建構子
	 *
	 * Transient / Clock / Email 採 duck-typing（object 型別 + docblock 約定形狀），允許測試 fake
	 * 不必 nominal-implement 對應 interface（避免汙染 tests/Support 層）。
	 * Production 由 WpTransientStore / SystemClock / WpAdminEmailNotifier 注入，皆有 implement 對應 interface。
	 *
	 * @param object $transients     Transient 儲存（提供 set/get/delete）.
	 * @param object $clock          時鐘（提供 now(): int）.
	 * @param object $email          Email 通知（提供 notify(string,string,string)）.
	 * @param int    $max_attempts   失敗次數上限（含）.
	 * @param int    $window_seconds 視窗秒數（預設 900 = 15 分鐘）.
	 *
	 * @phpstan-param TransientStoreInterface $transients
	 * @phpstan-param ClockInterface $clock
	 * @phpstan-param EmailNotifierInterface $email
	 */
	public function __construct(
		private readonly object $transients,
		private readonly object $clock,
		private readonly object $email,
		private readonly int $max_attempts = 5,
		private readonly int $window_seconds = 900
	) {}

	/**
	 * 檢查指定 slug 是否仍在允許範圍
	 *
	 * @param string $slug Partner slug
	 *
	 * @return void
	 *
	 * @throws TooManyAttempts 當失敗次數達到 max_attempts
	 */
	public function assert_not_blocked( string $slug ): void {
		$count = $this->current_count( $slug );
		if ( $count >= $this->max_attempts ) {
			throw new TooManyAttempts( retry_after: $this->retry_after( $slug ) );
		}
	}

	/**
	 * 紀錄一次失敗嘗試
	 *
	 * @param string $slug Partner slug
	 *
	 * @return void
	 */
	public function record_failure( string $slug ): void {
		$count          = $this->current_count( $slug );
		$new_count      = $count + 1;
		$expires_at_now = $this->clock->now() + $this->window_seconds;

		// 沿用既有 expires_at（若有）以避免每次失敗都 reset 視窗（攻擊者可藉此維持 count=4 不過期）
		$existing = $this->transients->get( $this->key( $slug ) );
		$expires_at = is_array( $existing ) && isset( $existing['expires_at'] )
		? (int) $existing['expires_at']
		: $expires_at_now;

		// 若既有的視窗已過期，重新開啟新視窗
		if ( $expires_at <= $this->clock->now() ) {
			$expires_at = $expires_at_now;
			$new_count  = 1;
		}

		$ttl = max( 1, $expires_at - $this->clock->now() );

		$this->transients->set(
			$this->key( $slug ),
			[
				'count'      => $new_count,
				'expires_at' => $expires_at,
			],
			$ttl
		);

		// 達到門檻：通知 admin（warn-and-swallow）
		if ( $new_count === $this->max_attempts ) {
			$this->notify_admin_safely( $slug, $new_count );
		}
	}

	/**
	 * 重置指定 slug 的失敗計數
	 *
	 * @param string $slug Partner slug
	 *
	 * @return void
	 */
	public function reset( string $slug ): void {
		$this->transients->delete( $this->key( $slug ) );
	}

	/**
	 * 取得目前 slug 的失敗計數（過期視為 0）
	 *
	 * @param string $slug Partner slug
	 *
	 * @return int
	 */
	private function current_count( string $slug ): int {
		$entry = $this->transients->get( $this->key( $slug ) );
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
	 * @param string $slug Partner slug
	 *
	 * @return int
	 */
	private function retry_after( string $slug ): int {
		$entry = $this->transients->get( $this->key( $slug ) );
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
	 * Transient key
	 *
	 * @param string $slug Partner slug
	 *
	 * @return string
	 */
	private function key( string $slug ): string {
		return self::KEY_PREFIX . $slug;
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
			// warn-and-swallow：寄信失敗只記 log，不阻擋登入流程
			\error_log( '[power-shop] LoginRateLimiter admin notice failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
