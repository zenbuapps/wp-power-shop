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
 *     - Max-Age：不斷言字面值，改斷言「與 body 的 expires_at 同源」+「至少天級」
 *       （2026-08 TTL 由 1 小時改為 14 天，理由見 V2Api::PARTNER_TOKEN_TTL_DEFAULT_SECONDS）
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

		/*
		 * Max-Age 刻意**不斷言字面值**。
		 *
		 * 這裡原本寫死 `Max-Age=3600`，2026-08 把 TTL 調整為 14 天（對齊 WordPress
		 * 「保持登入」）時它就轉紅了——但那個數字從來不是這條測試要保護的東西，
		 * 寫死只會讓每次調整 TTL 都得回來改測試，而真正該守的性質反而沒被測到。
		 *
		 * 改為斷言兩件更重要的事：
		 *   1. Max-Age 與 response body 的 expires_at **同源**——兩者若脫鉤，
		 *      會出現「瀏覽器以為還登入著、後端卻已經不認」的 401 認知落差。
		 *   2. 至少是天級——防止有人把 partner session 改回小時級而沒有人發現
		 *      （那正是這次調整要解決的問題：唯讀報表卻要一小時重登一次）。
		 */
		preg_match( '/Max-Age=(\d+)/i', $set_cookie_str, $matches );
		$this->assertNotEmpty( $matches, 'Set-Cookie 必須含 Max-Age' );

		$max_age    = (int) $matches[1];
		$body       = $response->get_data();
		$expires_in = (int) $body['expires_at'] - time();

		$this->assertGreaterThanOrEqual(
			DAY_IN_SECONDS,
			$max_age,
			'partner session 至少應為天級（唯讀報表不該一小時就要重新登入）'
		);
		$this->assertLessThanOrEqual(
			5,
			abs( $max_age - $expires_in ),
			'Cookie Max-Age 必須與 token expires_at 同源，否則會出現 401 認知落差'
		);
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
