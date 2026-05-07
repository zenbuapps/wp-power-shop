<?php
/**
 * Profit Shop V2Api Routing 整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1、§4.2、§4.7、§4.8
 * 對應實作（Phase 3-B Green 階段建立）：
 *   inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php
 *   並在 inc/classes/Domains/ProfitShop/Loader.php 註冊
 *
 * 驗證所有 spec §4 列出的 endpoint 都有註冊到 WP REST API 路由表。
 *
 * @group profit_shop
 * @group rest
 * @group routing
 */

declare( strict_types=1 );

namespace Tests\Integration\Rest;

use Tests\Integration\TestCase;

/**
 * V2Api Routing 測試
 */
final class V2ApiRoutingTest extends TestCase {

	/**
	 * Spec §4.1：profit-shops resource
	 *
	 * @test
	 * @group happy
	 * @group routing
	 */
	public function test_profit_shops_routes_are_registered(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey(
			'/power-shop/profit-shops',
			$routes,
			'spec §4.1：profit-shops collection route 應註冊'
		);
		$this->assertArrayHasKey(
			'/power-shop/profit-shops/(?P<id>\d+)',
			$routes,
			'spec §4.1：profit-shops/{id} route 應註冊'
		);
		$this->assertArrayHasKey(
			'/power-shop/profit-shops/(?P<id>\d+)/duplicate',
			$routes,
			'spec §4.1：duplicate route 應註冊'
		);
	}

	/**
	 * Spec §4.2：profit-partners resource
	 *
	 * @test
	 * @group happy
	 * @group routing
	 */
	public function test_profit_partners_routes_are_registered(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey(
			'/power-shop/profit-partners',
			$routes,
			'spec §4.2：profit-partners collection route 應註冊'
		);
		$this->assertArrayHasKey(
			'/power-shop/profit-partners/(?P<id>\d+)',
			$routes,
			'spec §4.2：profit-partners/{id} route 應註冊'
		);
	}

	/**
	 * Spec §4.7：profit-migration resource
	 *
	 * @test
	 * @group happy
	 * @group routing
	 */
	public function test_profit_migration_routes_are_registered(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey(
			'/power-shop/profit-migration/legacy-shops',
			$routes,
			'spec §4.7：legacy-shops route 應註冊'
		);
		$this->assertArrayHasKey(
			'/power-shop/profit-migration/import',
			$routes,
			'spec §4.7：import route 應註冊'
		);
	}

	/**
	 * Spec §4.8：profit-settings resource
	 *
	 * @test
	 * @group happy
	 * @group routing
	 */
	public function test_profit_settings_routes_are_registered(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey(
			'/power-shop/profit-settings',
			$routes,
			'spec §4.8：profit-settings collection route 應註冊'
		);
	}

	/**
	 * Spec §6.1：未登入或缺 capability 不能列出賣場（401/403）
	 *
	 * @test
	 * @group security
	 */
	public function test_profit_shops_list_rejects_unauthenticated_user(): void {
		\wp_set_current_user( 0 );

		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-shops' );
		$response = \rest_do_request( $request );

		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'未登入時應回 401 或 403'
		);
	}

	/**
	 * Admin 可以打 list endpoint
	 *
	 * @test
	 * @group security
	 */
	public function test_profit_shops_list_allows_admin(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-shops' );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'admin 應能存取 list endpoint' );
	}
}
