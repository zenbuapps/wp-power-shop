<?php
/**
 * Partner CRUD round-trip IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2、§6.3
 *
 * @group profit_shop
 * @group rest
 * @group application
 */

declare( strict_types=1 );

namespace Tests\Integration\Application;

use Tests\Integration\TestCase;

/**
 * Partner CRUD IT
 */
final class PartnerCrudTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/**
	 * POST /profit-partners → 201 + body 不含密碼
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_create_partner_returns_output_without_password(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'name'          => 'Mary',
					'slug'          => 'mary',
					'contact_email' => 'mary@example.com',
					'password'      => 'secret-pa55!',
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'Mary', $body['data']['name'] );
		$this->assertArrayNotHasKey(
			'password',
			$body['data'],
			'安全性違規：API 回應不應暴露密碼'
		);
	}

	/**
	 * 同一個 slug 二次建立 → 第二次應失敗（slug 唯一性 / 422 或 409）
	 *
	 * @test
	 * @group error
	 */
	public function test_duplicate_slug_is_rejected(): void {
		$body = [
			'name'     => 'Jerry',
			'slug'     => 'jerry',
			'password' => 'pw',
		];

		$first = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$first->set_header( 'Content-Type', 'application/json' );
		$first->set_body( \wp_json_encode( $body ) );
		$firstResp = \rest_do_request( $first );
		$this->assertSame( 201, $firstResp->get_status() );

		$second = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$second->set_header( 'Content-Type', 'application/json' );
		$second->set_body( \wp_json_encode( $body ) );
		$secondResp = \rest_do_request( $second );

		$this->assertContains(
			$secondResp->get_status(),
			[ 409, 422 ],
			'重複 slug 應被拒絕（409 conflict 或 422 unprocessable）'
		);
	}

	/**
	 * GET /profit-partners → 200 + 多筆資料
	 *
	 * @test
	 * @group happy
	 */
	public function test_list_partners_returns_collection(): void {
		// 先建兩個
		$this->create_partner_via_api( 'Alpha', 'alpha' );
		$this->create_partner_via_api( 'Beta', 'beta' );

		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-partners' );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'data', $body );
		$this->assertGreaterThanOrEqual( 2, count( $body['data'] ) );
	}

	/**
	 * M6：partner POST 寫入需 manage_options（manage_woocommerce only 的 user 應被擋）
	 *
	 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2、§6.3
	 * 對應 reviewer 必修：partner 涉及密碼系統，寫入操作必須 admin-only
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_create_partner_requires_manage_options(): void {
		// shop manager 有 manage_woocommerce 但無 manage_options
		$shop_manager_id = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		\wp_set_current_user( $shop_manager_id );

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'name'     => 'Eve',
					'slug'     => 'eve',
					'password' => 'secret',
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'shop_manager（無 manage_options）不應被允許建立 partner'
		);
	}

	/**
	 * 透過 API 建立 partner，回傳 term_id
	 */
	private function create_partner_via_api( string $name, string $slug ): int {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'name'     => $name,
					'slug'     => $slug,
					'password' => 'pw',
				]
			)
		);
		$response = \rest_do_request( $request );
		$body     = $response->get_data();
		return (int) ( $body['data']['id'] ?? 0 );
	}
}
