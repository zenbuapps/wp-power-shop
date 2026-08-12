<?php
/**
 * Set Partner Password Endpoint IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 *
 * 合約：
 *   POST /power-shop/profit-partners/{id}/set-password
 *   - permission_callback：admin（manage_options）+ X-WP-Nonce（CSRF）
 *   - body: { password }（**不過** sanitize_text_field，保留密碼字面值）
 *   - 成功：200 + 裸 payload { partner_id, password_changed_at }
 *     ⚠️ **不回傳密碼明文**——站長自己輸入的，回傳只會多一個洩漏面
 *   - 弱密碼：422 weak_password + data.reasons[]
 *   - 成功後舊密碼立即失效、新密碼可登入、舊 token 全撤銷
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
 * set-password IT
 */
final class SetPartnerPasswordEndpointTest extends TestCase {

	private int $admin_id        = 0;
	private int $partner_term_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry-setpw' ),
				contact_email: null
			),
			'old-pa55word'
		);
	}

	/**
	 * 送出 set-password 請求
	 *
	 * @param string      $password 明文密碼
	 * @param string|null $nonce    nonce（null = 不帶）
	 *
	 * @return \WP_REST_Response
	 */
	private function set_password( string $password, ?string $nonce = null ): \WP_REST_Response {
		$request = new \WP_REST_Request(
			'POST',
			'/power-shop/profit-partners/' . $this->partner_term_id . '/set-password'
		);
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		$request->set_body( \wp_json_encode( [ 'password' => $password ] ) );
		return \rest_do_request( $request );
	}

	/**
	 * happy：合規密碼 → 200 + password_changed_at，且 response 不含密碼
	 *
	 * @test
	 * @group happy
	 */
	public function test_admin_sets_password_returns_200_without_leaking_password(): void {
		$response = $this->set_password( 'NewPass456', \wp_create_nonce( 'wp_rest' ) );

		$this->assertSame( 200, $response->get_status() );

		$body = $response->get_data();
		$this->assertSame( $this->partner_term_id, $body['partner_id'] ?? null );
		$this->assertGreaterThan( 0, $body['password_changed_at'] ?? 0 );
		$this->assertArrayNotHasKey(
			'password',
			$body,
			'response 絕不可回傳密碼明文（站長自己輸入的，回傳只是多一個洩漏面）'
		);
	}

	/**
	 * happy：設定後新密碼可登入、舊密碼失效
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_new_password_can_login_and_old_cannot(): void {
		$this->set_password( 'NewPass456', \wp_create_nonce( 'wp_rest' ) );

		\wp_set_current_user( 0 );

		$old_login = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$old_login->set_header( 'Content-Type', 'application/json' );
		$old_login->set_body( \wp_json_encode( [ 'slug' => 'jerry-setpw', 'password' => 'old-pa55word' ] ) );
		$this->assertSame( 401, \rest_do_request( $old_login )->get_status(), '舊密碼不可再登入' );

		$new_login = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$new_login->set_header( 'Content-Type', 'application/json' );
		$new_login->set_body( \wp_json_encode( [ 'slug' => 'jerry-setpw', 'password' => 'NewPass456' ] ) );
		$this->assertSame( 200, \rest_do_request( $new_login )->get_status(), '新密碼應可登入' );
	}

	/**
	 * happy：success path 強制 no-store（request body 帶過明文）
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_response_has_no_cache_headers(): void {
		$response = $this->set_password( 'NewPass456', \wp_create_nonce( 'wp_rest' ) );
		$headers  = $response->get_headers();

		$this->assertSame( 'no-store, no-cache, must-revalidate', $headers['Cache-Control'] ?? null );
		$this->assertSame( 'no-cache', $headers['Pragma'] ?? null );
	}

	/**
	 * error：弱密碼 → 422 weak_password + data.reasons[]，且不動既有密碼
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_weak_password_returns_422_with_reasons(): void {
		$response = $this->set_password( 'onlyletters', \wp_create_nonce( 'wp_rest' ) );

		$this->assertSame( 422, $response->get_status() );

		$body = $response->get_data();
		$this->assertSame( 'weak_password', $body['code'] ?? null );
		$this->assertNotEmpty( $body['data']['reasons'] ?? [], '應回傳具體不合格原因' );

		$this->assertTrue(
			PartnerTermRepository::instance()->verify_password( $this->partner_term_id, 'old-pa55word' ),
			'驗證失敗時不可動到既有密碼'
		);
	}

	/**
	 * error：缺 X-WP-Nonce → 403 rest_forbidden（CSRF 防護）
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_returns_403_when_nonce_missing(): void {
		$response = $this->set_password( 'NewPass456' );

		$this->assertSame( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'rest_forbidden', is_array( $data ) ? ( $data['code'] ?? null ) : null );
	}

	/**
	 * error：非 admin（shop_manager）→ 401/403
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_non_admin_cannot_set_password(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$response = $this->set_password( 'NewPass456', \wp_create_nonce( 'wp_rest' ) );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * error：partner 不存在 → 404
	 *
	 * @test
	 * @group error
	 */
	public function test_returns_404_when_partner_missing(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners/999999/set-password' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );
		$request->set_body( \wp_json_encode( [ 'password' => 'NewPass456' ] ) );

		$this->assertSame( 404, \rest_do_request( $request )->get_status() );
	}

	/**
	 * edge：密碼含空白與符號時原樣保留（**不**被 sanitize_text_field 吃掉）
	 *
	 * 若哪天有人「順手」把 body 丟進 `WP::sanitize_text_field_deep()`，
	 * 密碼裡的 `<` / `>` / 連續空白會被靜默改寫，症狀是「站長設的密碼夥伴登不進去」，
	 * 而且兩邊看到的字串完全一樣，極難排查。本測試鎖死這個行為。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_password_literal_is_preserved(): void {
		$literal = 'a1 <b>  c&d';

		$this->assertSame( 200, $this->set_password( $literal, \wp_create_nonce( 'wp_rest' ) )->get_status() );

		$this->assertTrue(
			PartnerTermRepository::instance()->verify_password( $this->partner_term_id, $literal ),
			'密碼字面值必須原樣保留，不可經過 sanitize'
		);
	}
}
