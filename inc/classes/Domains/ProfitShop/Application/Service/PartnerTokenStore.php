<?php
/**
 * Partner Token 儲存 Service
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;

/**
 * Partner 登入 token 的持久化（hash → partner_id, expires_at, issued_at）
 *
 * 對應規格：
 *   - specs/2026-05-06-profit-shop-design.md §6.3 / §8（password rotation 撤銷）
 *   - reviewer L-1：hash → hash_hmac + wp_salt 升級
 *   - .claude/rules/profit-shop.rule.md §4（nominal interface DI）、§7（issued_at 撤銷不變式）
 *
 * 安全性要點：
 *   - **永不儲存明文 token**：transient key 與 value 都只放 HMAC hash 與 metadata
 *   - **HMAC + salt**：hash_token 使用 hash_hmac('sha256', $token, $salt_provider->get('auth'))，
 *     即使 transient 內容被脫，attacker 沒有 wp_salt 也無法反推或偽造 token
 *   - **雙重保險過期檢查**：除了 transient TTL（外層），value 內部另存 expires_at（內層）
 *     便於繞過 cache 不一致或人為延長 TTL 的情況
 *   - **password rotation 撤銷**：value 內部存 issued_at，verify 時與 partner 的
 *     password_changed_at 比對；若 issued_at < password_changed_at，視為已撤銷
 *   - 隨機 token 由 wp_generate_password(64, false) 產生（純英數，64 字元）
 *
 * 介面合約（紅燈鎖定）：
 *   - issue(int $partner_term_id): array{token: string, expires_at: int}
 *   - verify(string $token): ?int
 *   - revoke(string $token): void
 */
final class PartnerTokenStore {

	/**
	 * HMAC domain prefix（reviewer LOW-T2-1）
	 *
	 * 將 token 與其他 hash_hmac 用途（例如 cart price signature）以前綴隔離，
	 * 避免共用 wp_salt('auth') 時跨協議碰撞——即使兩種用途都用同一把 salt key，
	 * domain prefix 保證 hash output 命名空間不重疊.
	 */
	private const HMAC_DOMAIN = 'partner-token:v1';

	/**
	 * 建構子
	 *
	 * 採 nominal interface DI（禁鴨子型別 object）；Production 由 V2Api factory 注入：
	 *   - WpTransientStore / SystemClock / PartnerTermRepository / WpSaltProvider
	 * Unit Test 由 tests/Support / tests/Unit/Application/Fakes 的 fakes 注入。
	 *
	 * @param TransientStoreInterface    $transients    Transient 儲存抽象
	 * @param ClockInterface             $clock         時鐘抽象
	 * @param PartnerRepositoryInterface $partners      Partner repository（用於讀 password_changed_at）
	 * @param SaltProviderInterface      $salt_provider Salt 提供器（HMAC 用 key）
	 * @param int                        $ttl           TTL 秒數（預設 3600）
	 * @param string                     $key_prefix    Transient key prefix
	 */
	public function __construct(
		private readonly TransientStoreInterface $transients,
		private readonly ClockInterface $clock,
		private readonly PartnerRepositoryInterface $partners,
		private readonly SaltProviderInterface $salt_provider,
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
		$now         = $this->clock->now();
		$expires_at  = $now + $this->ttl;

		$payload = [
			'partner_term_id' => $partner_term_id,
			'expires_at'      => $expires_at,
			'issued_at'       => $now,
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
	 * 驗證流程：
	 *   1. 空字串 → null
	 *   2. transient 不存在或格式錯誤 → null
	 *   3. 內層 expires_at 過期 → null
	 *   4. password_changed_at 撤銷比對（Phase 6-A1 reviewer M-1：縱深防禦覆蓋同秒 race window）：
	 *      - 為 null（從未變更） → 不檢查
	 *      - issued_at > password_changed_at → 通過（必須嚴格大於）
	 *      - issued_at <= password_changed_at → 視為已撤銷，回 null
	 *      （issued_at == password_changed_at 同秒視為已撤銷，
	 *       閉合「同秒簽發 + 同秒改密」的 race window）
	 *   5. 通過 → 回 partner_term_id
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
		if ( ! isset( $payload['partner_term_id'], $payload['expires_at'], $payload['issued_at'] ) ) {
			return null;
		}

		$now = $this->clock->now();

		// 內層 expires_at 檢查（即使 transient 還活著也以此為準）
		if ( (int) $payload['expires_at'] <= $now ) {
			return null;
		}

		$partner_id = (int) $payload['partner_term_id'];
		$issued_at  = (int) $payload['issued_at'];

		// password rotation 撤銷比對（spec §6.3 + Phase 6-A1 reviewer M-1）
		// fail-closed：DB 異常一律視為驗證失敗（reviewer LOW-T2-2）.
		// 若 partner repo 拋（例如 DB 連線失敗），不該讓 token 「順利通過」造成繞過撤銷邏輯.
		try {
			$password_changed_at = $this->partners->get_password_changed_at( $partner_id );
		} catch ( \Throwable $e ) {
			\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[ProfitShop][partner-token] DB error in verify: %s',
					str_replace( [ "\r", "\n", "\t" ], ' ', $e->getMessage() )
				)
			);
			return null;
		}
		// Phase 6-A1 reviewer M-1：縱深防禦覆蓋同秒 race window。
		// issued_at == password_changed_at 視為已撤銷（必須嚴格大於才通過）,
		// 防範「同秒簽發舊 token + 同秒改密」場景下舊 token 仍被誤判為有效.
		if ( null !== $password_changed_at && $issued_at <= $password_changed_at ) {
			return null;
		}

		return $partner_id;
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
	 * 計算 token 的 HMAC-SHA256 hash（hex）
	 *
	 * 使用 hash_hmac 配合 wp_salt 作為 key，防止 transient 被脫後 attacker 反推 / 偽造 token。
	 * （單純 hash('sha256', $token) 在 transient table 被脫時，attacker 可離線暴力破解。）
	 *
	 * @param string $token 明文 token
	 *
	 * @return string
	 */
	private function hash_token( string $token ): string {
		return hash_hmac(
			'sha256',
			self::HMAC_DOMAIN . '|' . $token,
			$this->salt_provider->get( 'auth' )
		);
	}
}
