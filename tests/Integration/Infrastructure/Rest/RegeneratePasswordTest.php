<?php
/**
 * Regenerate Partner Password IT（Phase 3-C 紅燈，補 Phase 3-B observation 1）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 *
 * 紅燈合約：
 *   POST /power-shop/profit-partners/{id}/regenerate-password
 *   - permission_callback：admin（manage_options）
 *   - 成功：200 + body { partner_id, password }（明文，一次性顯示）
 *   - DB 內 hash 已更新
 *   - 舊密碼無法再登入
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
 * regenerate-password IT
 */
final class RegeneratePasswordTest extends TestCase {

	private int $admin_id = 0;
	private int $partner_term_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'old-password'
		);
	}

	/**
	 * @group security
	 */
	public function test_admin_regenerates_password_returns_new_plaintext(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners/' . $this->partner_term_id . '/regenerate-password' );
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'password', $body );
		$this->assertNotEmpty( $body['password'] );
		$this->assertNotSame( 'old-password', $body['password'] );
		$this->assertGreaterThanOrEqual( 12, strlen( (string) $body['password'] ) );
	}

	/**
	 * @group security
	 */
	public function test_old_password_cannot_login_after_regenerate(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners/' . $this->partner_term_id . '/regenerate-password' );
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );
		\rest_do_request( $request );

		// 確保不會被 admin login session 干擾
		\wp_set_current_user( 0 );

		// 嘗試用舊密碼登入
		$login = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$login->set_header( 'Content-Type', 'application/json' );
		$login->set_body( \wp_json_encode( [ 'slug' => 'jerry', 'password' => 'old-password' ] ) );
		$response = \rest_do_request( $login );

		$this->assertSame( 401, $response->get_status(), '舊密碼不可再登入' );
	}

	/**
	 * @group security
	 */
	public function test_new_password_can_login(): void {
		\wp_set_current_user( $this->admin_id );
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners/' . $this->partner_term_id . '/regenerate-password' );
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );
		$response = \rest_do_request( $request );
		$new_pw   = $response->get_data()['password'] ?? '';
		$this->assertNotEmpty( $new_pw );

		\wp_set_current_user( 0 );

		$login = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$login->set_header( 'Content-Type', 'application/json' );
		$login->set_body( \wp_json_encode( [ 'slug' => 'jerry', 'password' => $new_pw ] ) );
		$login_resp = \rest_do_request( $login );

		$this->assertSame( 200, $login_resp->get_status(), '新密碼應可登入' );
	}

	/**
	 * @group security
	 */
	public function test_non_admin_cannot_regenerate_password(): void {
		$shop_manager = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		\wp_set_current_user( $shop_manager );

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners/' . $this->partner_term_id . '/regenerate-password' );
		// 即便附 nonce，capability 檢查也應先擋下；此處刻意帶上 nonce 以確保不是「沒帶 nonce 才被擋」.
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );
		$response = \rest_do_request( $request );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * 沒有帶 X-WP-Nonce → 應 403 rest_forbidden（CSRF 防護）
	 *
	 * @group security
	 * @group edge
	 */
	public function test_regenerate_password_returns_403_when_nonce_missing(): void {
		\wp_set_current_user( $this->admin_id );

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners/' . $this->partner_term_id . '/regenerate-password' );
		// 故意不帶 X-WP-Nonce.
		$response = \rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'rest_forbidden', is_array( $data ) ? ( $data['code'] ?? null ) : null );
	}

	/**
	 * 帶錯誤的 X-WP-Nonce → 應 403 rest_forbidden（CSRF 防護）
	 *
	 * @group security
	 * @group edge
	 */
	public function test_regenerate_password_returns_403_when_nonce_invalid(): void {
		\wp_set_current_user( $this->admin_id );

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners/' . $this->partner_term_id . '/regenerate-password' );
		$request->set_header( 'X-WP-Nonce', 'invalid_nonce_string' );
		$response = \rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
