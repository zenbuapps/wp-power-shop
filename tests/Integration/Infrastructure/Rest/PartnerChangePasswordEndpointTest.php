<?php
/**
 * Partner Change Password Endpoint IT（Phase 6-A1 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 Partner 自助修密碼
 *
 * 紅燈合約：
 *   POST /wp-json/power-shop/partner-auth/change-password
 *   - permission_callback: partner_token（cookie / X-Partner-Token / Bearer 三選一）
 *   - body: { current_password: string, new_password: string }
 *   - 200：裸 payload { success: true, password_changed_at: int }
 *   - 200 同時 Set-Cookie 清空（Max-Age=0；Path=/wp-json/power-shop/）強制 partner 重登
 *   - 200 後 termmeta `_partner_password_changed_at` 更新；新密碼 verify_password 為 true
 *   - 撤銷端到端：改密後舊 cookie / token 打 /partner-auth/me → 401
 *   - 401：未帶 cookie / 無效 token
 *   - 422 invalid_credentials：current_password 錯（注意：此處對齊 ExceptionMapper 的
 *     InvalidCredentials → 401，但 spec 採 422 invalid_credentials 區分「未認證」與
 *     「已認證但 current 錯」；如 master 決定走 401 unauthorized，請更新此測試）
 *   - 422 weak_password：new_password 弱；body.data.reasons 攜帶弱密碼原因
 *   - 429 rate_limited：失敗 5 次後第 6 次 + Retry-After header
 *   - admin nonce / WP cookie 不算數：partner endpoint 必須走 partner token（雙軌隔離）
 *   - 400：缺 current_password / new_password
 *   - Cache-Control: no-store, no-cache, must-revalidate（防 proxy/browser 快取）
 *
 * 紅燈狀態：
 *   - 端點未註冊 → 404 rest_no_route
 *   - 或 callback 未實作 → 500 / 拋 Error
 *
 * @group profit_shop
 * @group rest
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use Tests\Integration\TestCase;

/**
 * partner-auth/change-password IT
 */
final class PartnerChangePasswordEndpointTest extends TestCase {

	private const PARTNER_SLUG = 'jerry';
	private const ORIGINAL_PASSWORD = 'oldpa55!';
	private const NEW_STRONG_PASSWORD = 'NewPass123';

	private int $partner_term_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( self::PARTNER_SLUG ),
				contact_email: null,
			),
			self::ORIGINAL_PASSWORD,
		);
		$this->assertGreaterThan( 0, $this->partner_term_id );
	}

	public function tear_down(): void {
		if ( $this->partner_term_id > 0 ) {
			\wp_delete_term( $this->partner_term_id, 'profit_partner' );
		}
		parent::tear_down();
	}

	/**
	 * Happy：cookie 認證 + 正確 current + 強 new → 200，termmeta 更新，新密碼可驗
	 *
	 * @group happy
	 * @group security
	 */
	public function test_happy_change_password_returns_200_and_updates_meta(): void {
		\wp_set_current_user( 0 );
		$token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $token );

		$response = $this->call_change_password( $token, self::ORIGINAL_PASSWORD, self::NEW_STRONG_PASSWORD );
		$this->assertSame( 200, $response->get_status(), '正確 current + 強 new 應 200' );

		$body = $response->get_data();
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'success', $body );
		$this->assertTrue( $body['success'] );
		$this->assertArrayHasKey( 'password_changed_at', $body );
		$this->assertGreaterThan( 0, (int) $body['password_changed_at'] );

		// termmeta 已更新
		$changed_at = PartnerTermRepository::instance()->get_password_changed_at( $this->partner_term_id );
		$this->assertNotNull( $changed_at );

		// 新密碼可 verify
		$this->assertTrue(
			PartnerTermRepository::instance()->verify_password( $this->partner_term_id, self::NEW_STRONG_PASSWORD ),
			'新密碼應可通過 verify'
		);
	}

	/**
	 * 改密後 Set-Cookie 清空（Max-Age=0），強制 partner 重新登入
	 *
	 * @group security
	 */
	public function test_change_password_clears_partner_cookie_with_max_age_zero(): void {
		$token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $token );

		$response = $this->call_change_password( $token, self::ORIGINAL_PASSWORD, self::NEW_STRONG_PASSWORD );
		$this->assertSame( 200, $response->get_status() );

		$headers        = $response->get_headers();
		$set_cookie     = $headers['Set-Cookie'] ?? ( $headers['set-cookie'] ?? null );
		$set_cookie_str = is_array( $set_cookie ) ? implode( '; ', $set_cookie ) : (string) $set_cookie;

		$this->assertNotEmpty( $set_cookie_str, '改密後必須回 Set-Cookie 清空 cookie' );
		$this->assertStringContainsStringIgnoringCase( 'profit_partner_token=', $set_cookie_str );
		$this->assertStringContainsStringIgnoringCase( 'Max-Age=0', $set_cookie_str );
		$this->assertStringContainsStringIgnoringCase( 'Path=/wp-json/power-shop/', $set_cookie_str );
		$this->assertStringContainsStringIgnoringCase( 'HttpOnly', $set_cookie_str );
	}

	/**
	 * ⭐ 撤銷端到端：改密後舊 token 立即失效（GET /partner-auth/me → 401）
	 *
	 * 與 Phase 3-D PartnerPasswordRotationRevokesTokenTest 同樣的撤銷機制，
	 * 此處驗證 partner 自助管道也走相同 password_changed_at 撤銷邏輯。
	 *
	 * @group security
	 * @group edge
	 */
	public function test_old_token_revoked_after_self_change_password(): void {
		$old_token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $old_token );

		// 確認改密前 me 正常
		$me_before = $this->call_me( $old_token );
		$this->assertSame( 200, $me_before->get_status() );

		// partner 自助修密
		$change = $this->call_change_password( $old_token, self::ORIGINAL_PASSWORD, self::NEW_STRONG_PASSWORD );
		$this->assertSame( 200, $change->get_status() );

		// 舊 token 立即失效
		$me_after = $this->call_me( $old_token );
		$this->assertSame(
			401,
			$me_after->get_status(),
			'partner 自助改密後舊 token 必須立即失效（與 admin regenerate-password 撤銷邏輯一致）'
		);
	}

	/**
	 * 改密後新密碼可重新登入
	 *
	 * @group happy
	 * @group security
	 */
	public function test_new_password_can_login_after_change(): void {
		$old_token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $old_token );

		$change = $this->call_change_password( $old_token, self::ORIGINAL_PASSWORD, self::NEW_STRONG_PASSWORD );
		$this->assertSame( 200, $change->get_status() );

		// 用新密碼登入
		$new_token = $this->login_partner( self::PARTNER_SLUG, self::NEW_STRONG_PASSWORD );
		$this->assertNotEmpty( $new_token, '新密碼應可登入並取得新 token' );
		$this->assertNotSame( $old_token, $new_token );
	}

	/**
	 * 401：未帶 cookie / token
	 *
	 * @group security
	 * @group error
	 */
	public function test_unauthenticated_returns_401(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/change-password' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'current_password' => self::ORIGINAL_PASSWORD,
					'new_password'     => self::NEW_STRONG_PASSWORD,
				],
			),
		);

		$response = \rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * 422 invalid_credentials：current_password 錯（已認證 partner，但 current 對不上）
	 *
	 *   注意：此測試對齊 spec §6.3「current 錯回 422 invalid_credentials」。
	 *   若 master 採 ExceptionMapper 既有 InvalidCredentials → 401 mapping，
	 *   請改本測試斷言為 401 + code='unauthorized'，並在綠燈期說明選擇。
	 *
	 * @group error
	 * @group security
	 */
	public function test_wrong_current_password_returns_422_invalid_credentials(): void {
		$token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $token );

		$response = $this->call_change_password( $token, 'wrong-current', self::NEW_STRONG_PASSWORD );
		$this->assertContains(
			$response->get_status(),
			[ 401, 422 ],
			'current_password 錯應回 401 unauthorized 或 422 invalid_credentials（看 master 選擇 mapping）'
		);

		$body = $response->get_data();
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'code', $body );
		$this->assertContains( $body['code'], [ 'unauthorized', 'invalid_credentials' ] );
	}

	/**
	 * 422 weak_password：new_password 弱 + data.reasons 列出弱密碼原因
	 *
	 * @group error
	 */
	public function test_weak_new_password_returns_422_with_reasons(): void {
		$token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $token );

		$response = $this->call_change_password( $token, self::ORIGINAL_PASSWORD, '123' );
		$this->assertSame( 422, $response->get_status() );

		$body = $response->get_data();
		$this->assertIsArray( $body );
		$this->assertSame( 'weak_password', $body['code'] ?? null );

		// reasons 可能在 body['data']['reasons'] 或 body['reasons']（看 master ExceptionMapper 實作）
		$reasons = $body['data']['reasons'] ?? ( $body['reasons'] ?? null );
		$this->assertIsArray( $reasons );
		$this->assertContains( 'too_short', $reasons );
		$this->assertContains( 'missing_letter', $reasons );
	}

	/**
	 * 429 rate_limited：失敗 5 次後第 6 次拋 + Retry-After header
	 *
	 * @group security
	 */
	public function test_too_many_wrong_current_returns_429_with_retry_after(): void {
		$token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $token );

		// 5 次錯 current
		for ( $i = 0; $i < 5; $i++ ) {
			$this->call_change_password( $token, 'wrong-' . $i, self::NEW_STRONG_PASSWORD );
		}

		// 第 6 次（即使 current 對）→ 應 429
		$response = $this->call_change_password( $token, self::ORIGINAL_PASSWORD, self::NEW_STRONG_PASSWORD );
		$this->assertSame( 429, $response->get_status() );

		$body = $response->get_data();
		$this->assertSame( 'rate_limited', $body['code'] ?? null );

		$headers     = $response->get_headers();
		$retry_after = $headers['Retry-After'] ?? ( $headers['retry-after'] ?? null );
		$this->assertNotNull( $retry_after, '429 必須帶 Retry-After header' );
		$this->assertGreaterThan( 0, (int) ( is_array( $retry_after ) ? $retry_after[0] : $retry_after ) );
	}

	/**
	 * Admin nonce 不算數：partner endpoint 必須走 partner token；
	 *   攻擊面：admin 開著瀏覽器 → 別站誘騙 → 不能用 admin nonce / WP cookie 改 partner 密碼.
	 *
	 * @group security
	 * @group edge
	 */
	public function test_admin_nonce_alone_is_rejected(): void {
		// 設 admin user + 帶 admin nonce，但不帶 partner token
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/change-password' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			\wp_json_encode(
				[
					'current_password' => self::ORIGINAL_PASSWORD,
					'new_password'     => self::NEW_STRONG_PASSWORD,
				],
			),
		);

		$response = \rest_do_request( $request );

		// 沒帶 partner token → 401（admin nonce 對 partner endpoint 無效）
		$this->assertSame(
			401,
			$response->get_status(),
			'admin nonce / WP login 不應允許呼叫 partner-auth/change-password'
		);
	}

	/**
	 * 400：缺 current_password / new_password 欄位
	 *
	 * @group error
	 */
	public function test_missing_required_fields_returns_400(): void {
		$token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $token );

		// 完全空 body
		$response = $this->call_change_password_raw( $token, [] );
		$this->assertContains(
			$response->get_status(),
			[ 400, 422 ],
			'缺欄位應 400 / 422，不該 500'
		);

		// 只給 current 缺 new
		$response2 = $this->call_change_password_raw(
			$token,
			[ 'current_password' => self::ORIGINAL_PASSWORD ],
		);
		$this->assertContains( $response2->get_status(), [ 400, 422 ] );
	}

	/**
	 * Cache-Control: no-store, no-cache, must-revalidate（success 與 failure 都應有）
	 *
	 * @group security
	 */
	public function test_response_has_no_cache_headers(): void {
		$token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $token );

		$response = $this->call_change_password( $token, self::ORIGINAL_PASSWORD, self::NEW_STRONG_PASSWORD );
		$this->assertSame( 200, $response->get_status() );

		$headers       = $response->get_headers();
		$cache_control = $headers['Cache-Control'] ?? ( $headers['cache-control'] ?? null );
		$cache_str     = is_array( $cache_control ) ? implode( ', ', $cache_control ) : (string) $cache_control;

		$this->assertNotEmpty( $cache_str, '必須設 Cache-Control 防快取' );
		$this->assertStringContainsStringIgnoringCase( 'no-store', $cache_str );
		$this->assertStringContainsStringIgnoringCase( 'no-cache', $cache_str );
	}

	// ========== helper ==========

	/**
	 * 登入 partner 並回傳 token（失敗回空字串）
	 */
	private function login_partner( string $slug, string $password ): string {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'slug'     => $slug,
					'password' => $password,
				],
			),
		);

		$response = \rest_do_request( $request );
		if ( 200 !== $response->get_status() ) {
			return '';
		}
		$data = $response->get_data();
		return is_array( $data ) ? (string) ( $data['token'] ?? '' ) : '';
	}

	/**
	 * 呼叫 change-password（帶 X-Partner-Token，正規 body）
	 */
	private function call_change_password( string $token, string $current, string $new ): \WP_REST_Response {
		return $this->call_change_password_raw(
			$token,
			[
				'current_password' => $current,
				'new_password'     => $new,
			],
		);
	}

	/**
	 * 呼叫 change-password（帶 X-Partner-Token，自訂 body）
	 *
	 * @param string               $token Partner token
	 * @param array<string, mixed> $body  raw body
	 */
	private function call_change_password_raw( string $token, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/change-password' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Partner-Token', $token );
		$request->set_body( \wp_json_encode( $body ) );

		$response = \rest_do_request( $request );
		if ( $response instanceof \WP_REST_Response ) {
			return $response;
		}
		return new \WP_REST_Response( [ 'code' => 'unknown' ], 500 );
	}

	/**
	 * 用指定 token 呼叫 me endpoint
	 */
	private function call_me( string $token ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/power-shop/partner-auth/me' );
		$request->set_header( 'X-Partner-Token', $token );
		$response = \rest_do_request( $request );

		if ( $response instanceof \WP_REST_Response ) {
			return $response;
		}
		return new \WP_REST_Response( [ 'code' => 'unknown' ], 500 );
	}
}
