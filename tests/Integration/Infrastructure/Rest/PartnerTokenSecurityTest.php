<?php
/**
 * Partner Token 安全 IT（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8.0
 *
 * 紅燈驗證：
 *   1. login 後 dump 所有 ps_partner_token_* transient → 內容不應含明文 token
 *   2. 過期 token（手動把 transient expires 改到過去）→ 後續呼叫 me 應 401
 *   3. revoke（logout）後 token 不可再用
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
 * Partner Token 安全儲存 + 過期 IT
 */
final class PartnerTokenSecurityTest extends TestCase {

	/**
	 * @group security
	 */
	public function test_plaintext_token_never_persists_in_transients(): void {
		$this->seed_partner( 'jerry', 'plain-pa55!' );

		$response = $this->login( 'jerry', 'plain-pa55!' );
		$this->assertSame( 200, $response->get_status() );
		$body  = $response->get_data();
		$token = $body['token'] ?? null;
		$this->assertNotEmpty( $token );

		// dump 所有 transient option（明文 token 不可出現）
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_ps_partner_token_%'
			)
		);

		$this->assertNotEmpty( $rows, 'login 後 transient 應存在；目前找不到代表 V2Api 還沒寫好（紅燈預期）' );

		foreach ( $rows as $row ) {
			$this->assertStringNotContainsString(
				$token,
				$row->option_name,
				'明文 token 不可出現在 transient key'
			);
			$this->assertStringNotContainsString(
				$token,
				(string) $row->option_value,
				'明文 token 不可出現在 transient value'
			);
		}
	}

	/**
	 * @group security
	 * @group edge
	 */
	public function test_expired_token_returns_401_on_me(): void {
		$this->seed_partner( 'jerry', 'pw' );

		$response = $this->login( 'jerry', 'pw' );
		$this->assertSame( 200, $response->get_status() );
		$token = $response->get_data()['token'] ?? '';
		$this->assertNotEmpty( $token );

		// 強制把所有 ps_partner_token_* transient 的 _transient_timeout_ 改到過去
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name LIKE %s",
				(string) ( time() - 100 ),
				'_transient_timeout_ps_partner_token_%'
			)
		);
		\wp_cache_flush();

		$me_request = new \WP_REST_Request( 'GET', '/power-shop/partner-auth/me' );
		$me_request->set_header( 'X-Partner-Token', $token );
		$me_response = \rest_do_request( $me_request );

		$this->assertSame( 401, $me_response->get_status() );
		$this->assertSame( 'unauthorized', $me_response->get_data()['code'] ?? null );
	}

	/**
	 * @group security
	 */
	public function test_revoked_token_cannot_be_reused(): void {
		$this->seed_partner( 'jerry', 'pw' );

		$response = $this->login( 'jerry', 'pw' );
		$token    = $response->get_data()['token'] ?? '';
		$this->assertNotEmpty( $token );

		// logout
		$logout = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/logout' );
		$logout->set_header( 'X-Partner-Token', $token );
		$logout_resp = \rest_do_request( $logout );
		$this->assertContains( $logout_resp->get_status(), [ 200, 204 ] );

		// 用同個 token 呼叫 me
		$me_request = new \WP_REST_Request( 'GET', '/power-shop/partner-auth/me' );
		$me_request->set_header( 'X-Partner-Token', $token );
		$me_response = \rest_do_request( $me_request );

		$this->assertSame( 401, $me_response->get_status() );
	}

	// ========== helper ==========

	private function seed_partner( string $slug, string $password ): int {
		return PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: ucfirst( $slug ),
				slug: new PartnerSlug( $slug ),
				contact_email: null
			),
			$password
		);
	}

	private function login( string $slug, string $password ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode( [ 'slug' => $slug, 'password' => $password ] )
		);
		return \rest_do_request( $request );
	}
}
