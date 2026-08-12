<?php
/**
 * Partner Token vs Admin Nonce 嚴格分離 IT（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8
 *
 * 紅燈合約：
 *   - admin（已 wp_set_current_user）+ X-WP-Nonce 不可呼叫 partner-reports/* → 401
 *   - partner token 不可呼叫 admin-only endpoint（如 profit-partners POST/DELETE）→ 401/403
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
 * Partner / Admin 認證隔離 IT
 */
final class PartnerVsAdminAuthIsolationTest extends TestCase {

	/**
	 * @group security
	 */
	public function test_admin_with_wp_nonce_cannot_access_partner_reports(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$request = new \WP_REST_Request( 'GET', '/power-shop/partner-reports/kpi' );
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );
		// 故意不帶 X-Partner-Token

		$response = \rest_do_request( $request );

		$this->assertSame(
			401,
			$response->get_status(),
			'admin 即使帶 wp nonce 也不可呼叫 partner-reports/*（partner token 必須獨立）'
		);
	}

	/**
	 * @group security
	 */
	public function test_partner_token_cannot_access_admin_only_partner_endpoints(): void {
		// 建一個 partner、登入取 token
		PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'pw'
		);
		\wp_set_current_user( 0 );

		$login = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$login->set_header( 'Content-Type', 'application/json' );
		$login->set_body( \wp_json_encode( [ 'slug' => 'jerry', 'password' => 'pw' ] ) );
		$login_resp = \rest_do_request( $login );
		$token      = $login_resp->get_data()['token'] ?? '';

		// 嘗試用 partner token 呼叫 admin-only endpoint：DELETE /profit-partners/{id}
		$delete = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$delete->set_header( 'X-Partner-Token', $token );
		$delete->set_header( 'Content-Type', 'application/json' );
		$delete->set_body(
			\wp_json_encode(
				[
					'name'     => 'Mallory',
					'slug'     => 'mallory',
					'password' => 'mwhat',
				]
			)
		);
		$response = \rest_do_request( $delete );

		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'partner token 不可呼叫 admin-only endpoint（必須回 401/403）'
		);
	}
}
