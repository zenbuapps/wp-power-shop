<?php
/**
 * Partner Token 儲存 Service
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Partner 登入 token 的持久化（hash → partner_id, expires_at）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 / §8
 *
 * 安全性要點：
 *   - **永不儲存明文 token**：transient key 與 value 都只放 sha256 hash 與 metadata
 *   - **雙重保險過期檢查**：除了 transient TTL（外層），value 內部另存 expires_at（內層）
 *     便於繞過 cache 不一致或人為延長 TTL 的情況
 *   - 隨機 token 由 wp_generate_password(64, false) 產生（純英數，64 字元）
 *
 * 介面合約（紅燈鎖定）：
 *   - issue(int $partner_term_id): array{token: string, expires_at: int}
 *   - verify(string $token): ?int
 *   - revoke(string $token): void
 */
final class PartnerTokenStore {

	/**
	 * 建構子
	 *
	 * Transient / Clock 採 duck-typing（object 型別 + docblock 約定形狀），允許測試 fake
	 * 不必 nominal-implement TransientStoreInterface / ClockInterface（避免汙染 support 層）。
	 * Production 由 WpTransientStore / SystemClock 注入，皆有 implement 對應 interface。
	 *
	 * @param object $transients Transient 儲存抽象（提供 set(string,mixed,int) / get(string) / delete(string)）
	 * @param object $clock      時鐘抽象（提供 now(): int）
	 * @param int    $ttl        TTL 秒數（預設 3600）
	 * @param string $key_prefix Transient key prefix
	 *
	 * @phpstan-param TransientStoreInterface $transients
	 * @phpstan-param ClockInterface $clock
	 */
	public function __construct(
		private readonly object $transients,
		private readonly object $clock,
		private readonly int $ttl = 3600,
		private readonly string $key_prefix = 'ps_partner_token_'
	) {}

	/**
	 * 簽發 token
	 *
	 * @param int $partner_term_id Partner term ID
	 *
	 * @return array{token: string, expires_at: int}
	 */
	public function issue( int $partner_term_id ): array {
		$plain_token = \wp_generate_password( 64, false );
		$hash        = $this->hash_token( $plain_token );
		$expires_at  = $this->clock->now() + $this->ttl;

		$payload = [
			'partner_term_id' => $partner_term_id,
			'expires_at'      => $expires_at,
		];

		$this->transients->set( $this->key_prefix . $hash, $payload, $this->ttl );

		return [
			'token'      => $plain_token,
			'expires_at' => $expires_at,
		];
	}

	/**
	 * 驗證 token，回 partner_term_id
	 *
	 * @param string $token 明文 token
	 *
	 * @return int|null 通過驗證回傳 partner term ID；否則回 null
	 */
	public function verify( string $token ): ?int {
		if ( '' === $token ) {
			return null;
		}

		$hash    = $this->hash_token( $token );
		$payload = $this->transients->get( $this->key_prefix . $hash );

		if ( ! is_array( $payload ) ) {
			return null;
		}
		if ( ! isset( $payload['partner_term_id'], $payload['expires_at'] ) ) {
			return null;
		}

		// 內層 expires_at 檢查（即使 transient 還活著也以此為準）
		if ( (int) $payload['expires_at'] <= $this->clock->now() ) {
			return null;
		}

		return (int) $payload['partner_term_id'];
	}

	/**
	 * 撤銷 token
	 *
	 * @param string $token 明文 token
	 */
	public function revoke( string $token ): void {
		if ( '' === $token ) {
			return;
		}
		$this->transients->delete( $this->key_prefix . $this->hash_token( $token ) );
	}

	/**
	 * 計算 token 的 sha256 hash（hex）
	 *
	 * @param string $token 明文 token
	 *
	 * @return string
	 */
	private function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}
}
