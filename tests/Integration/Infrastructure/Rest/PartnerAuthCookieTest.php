<?php
/**
 * Partner Auth Cookie 設定 IT（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8.0
 *
 * 紅燈合約：
 *   POST /power-shop/partner-auth/login (slug + password)
 *   - 成功：200 + WP_REST_Response 含 Set-Cookie header
 *     - HttpOnly
 *     - Secure
 *     - SameSite=Lax
 *     - Path=/
 *     - Max-Age=3600
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
 * Partner login Set-Cookie 驗證
 */
final class PartnerAuthCookieTest extends TestCase {

	/**
	 * @group security
	 */
	public function test_login_response_sets_secure_httponly_cookie(): void {
		$partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'plain-pa55!'
		);
		$this->assertGreaterThan( 0, $partner_term_id );

		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'slug'     => 'jerry',
					'password' => 'plain-pa55!',
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		// 取出所有 Set-Cookie header（WP_REST_Response::get_headers 回 array）
		$headers       = $response->get_headers();
		$set_cookie    = $headers['Set-Cookie'] ?? ( $headers['set-cookie'] ?? null );
		$set_cookie_str = is_array( $set_cookie ) ? implode( '; ', $set_cookie ) : (string) $set_cookie;

		$this->assertNotEmpty( $set_cookie_str, 'login 必須回 Set-Cookie header' );
		$this->assertStringContainsStringIgnoringCase( 'profit_partner_token=', $set_cookie_str );
		$this->assertStringContainsStringIgnoringCase( 'HttpOnly', $set_cookie_str );
		$this->assertStringContainsStringIgnoringCase( 'Secure', $set_cookie_str );
		$this->assertStringContainsStringIgnoringCase( 'SameSite=Lax', $set_cookie_str );
		$this->assertStringContainsStringIgnoringCase( 'Path=/', $set_cookie_str );
		$this->assertStringContainsStringIgnoringCase( 'Max-Age=3600', $set_cookie_str );
	}

	/**
	 * @group security
	 */
	public function test_login_response_body_contains_token_and_expires_at(): void {
		$partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'plain-pa55!'
		);

		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode( [ 'slug' => 'jerry', 'password' => 'plain-pa55!' ] )
		);

		$response = \rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$body = $response->get_data();
		$this->assertArrayHasKey( 'token', $body );
		$this->assertArrayHasKey( 'expires_at', $body );
		$this->assertArrayHasKey( 'partner_id', $body );
		$this->assertSame( $partner_term_id, $body['partner_id'] );
	}
}
