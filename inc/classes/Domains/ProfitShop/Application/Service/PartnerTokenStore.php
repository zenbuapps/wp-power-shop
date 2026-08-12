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
	 * Payload 內「簽發當下的 password_changed_at 快照」欄位名
	 *
	 * 舊 token（本欄位上線前簽發）的 payload 沒有這個 key，`verify()` 會據此
	 * 退回時間比較。TTL 為 3600 秒，一小時內全部自然汰換。
	 *
	 * @var string
	 */
	private const SNAPSHOT_KEY = 'pwd_snapshot';

	/**
	 * 簽發 token
	 *
	 * Payload 除了 `issued_at` 之外，另外快照一份**簽發當下的**
	 * `password_changed_at`（`pwd_snapshot`）。撤銷比對優先用這個快照，
	 * 理由與時間比較的缺陷見 `verify()` 的 docblock。
	 *
	 * 讀取失敗（DB 異常）時快照寫 `null`，`verify()` 會自動退回時間比較，
	 * 不因為讀不到快照就讓 token 簽發失敗——登入是使用者可見的動線，
	 * 而退回時間比較仍保有 Phase 6-A1 M-1 的防護強度。
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

		try {
			$pwd_snapshot = $this->partners->get_password_changed_at( $partner_term_id );
		} catch ( \Throwable $e ) {
			$pwd_snapshot = null;
		}

		$payload = [
			'partner_term_id' => $partner_term_id,
			'expires_at'      => $expires_at,
			'issued_at'       => $now,
			self::SNAPSHOT_KEY => $pwd_snapshot,
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
	 *   4. 密碼變更撤銷比對（見下方「為何改用快照值比對」）
	 *   5. 通過 → 回 partner_term_id
	 *
	 * ── 🔴 為何改用快照值比對，而不是比較時間戳 ──────────────
	 *
	 * 這裡真正要回答的問題是「**這個 token 簽發之後，密碼有沒有被改過**」。
	 * 時間戳比較只是那個問題的**代理指標**，而它在秒精度下有歧義：
	 * `password_changed_at` 與 `issued_at` 都只到秒，同一秒內發生的兩件事無法排序。
	 *
	 * Phase 6-A1 reviewer M-1 為了閉合「同秒簽發舊 token + 同秒改密」的 race window，
	 * 把判斷從 `<` 收緊成 `<=`（同秒一律視為已撤銷）。方向是對的，但代價是
	 * **把「密碼寫入後同一秒內簽發的合法 token」一起誤殺**——而
	 * `PartnerTermRepository::save()` 在**建立夥伴**時就會寫 `password_changed_at`，
	 * 於是「建立夥伴 → 同秒登入」這個組合會讓 token 一出生就失效。
	 *
	 * 實測（2026-08-07，臨時診斷測試，對**同一個** token 做 before/after 對照）：
	 *   把 password_changed_at 往前調 1 秒**之前** → GET /partner-auth/me 回 401
	 *   往前調 1 秒**之後**                        → 同一個 token 回 200
	 * 而另一輪剛好跨秒（changed_at 落在 T、login 落在 T+1）→ 直接 200 通過。
	 * ⇒ 這不是穩定失敗，是**時間競態**：跑得快就失效、跨秒就正常。
	 * 這正是 11 條 partner 認證整合測試長期紅燈的唯一成因
	 * （夥伴登入 / 改密碼 / token 撤銷 / 報表 IDOR 隔離四塊因此失去自動化保護）。
	 *
	 * 正解是不要再問「哪個時間比較晚」，直接問「**值有沒有變**」：
	 * `issue()` 把簽發當下的 `password_changed_at` 快照進 payload，
	 * `verify()` 比對快照與當下值是否相同。
	 *
	 *   - 密碼改了 → 值變了 → 快照對不上 → 撤銷 ✅
	 *   - 密碼沒改 → 值相同 → 通過 ✅
	 *
	 * 這**完全不依賴時間精度**，同秒歧義結構性消失；而且 M-1 想閉合的 race window
	 * 解得更徹底——同秒改密同樣會讓 `password_changed_at` 變值，快照一樣對不上。
	 * 時間比較反而擋不住「改密後跨秒才簽發的偽造 token」這類情境。
	 *
	 * ── 向後相容 ──────────────────────────────────────────────
	 * 本機制上線前簽發的 token，payload 沒有 `SNAPSHOT_KEY`，會退回時間比較
	 * （維持 M-1 契約，含它已知的誤殺）。TTL 為 3600 秒，一小時內自然全數汰換，
	 * 因此那條 legacy 分支**不需要**永久保留——與 cart signature 的 v1 分支
	 * （持久化購物車無過期機制，必須永久保留）性質不同，不要混為一談。
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
		// ── 撤銷比對 ─────────────────────────────────────────────
		// 優先走「快照值比對」；payload 沒有快照欄位（本機制上線前簽發的舊 token）
		// 才退回 Phase 6-A1 M-1 的時間比較。兩條路的差異與取捨見本方法 docblock。
		if ( array_key_exists( self::SNAPSHOT_KEY, $payload ) ) {
			$snapshot = $payload[ self::SNAPSHOT_KEY ];
			$snapshot = ( null === $snapshot ) ? null : (int) $snapshot;

			// 值一有變動即撤銷。四種組合都正確（左為快照、右為當下值）：
			// `null vs null` 通過（從未變更過密碼）；
			// `null vs 12345` 撤銷（簽發時沒有、現在有了 ⇒ 密碼變過）；
			// `12345 vs null` 撤銷（meta 被刪，異常狀態 ⇒ fail-closed）；
			// `12345 vs 12346` 撤銷（密碼變過）。
			if ( $snapshot !== $password_changed_at ) {
				return null;
			}

			return $partner_id;
		}

		// Legacy fallback：舊 token 無快照欄位，沿用 Phase 6-A1 reviewer M-1 的時間比較。
		// issued_at == password_changed_at 視為已撤銷（必須嚴格大於才通過），
		// 防範「同秒簽發舊 token + 同秒改密」場景下舊 token 仍被誤判為有效。
		// ⚠️ 這條路徑有已知的誤殺（見 docblock），但只影響 TTL 內的既有 token，
		// 且維持既有行為比在過渡期改變它更安全。
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
