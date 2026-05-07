<?php
/**
 * ProfitShop CRUD round-trip IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 *
 * 驗證 V2Api 端點 + Application UseCase + Infrastructure Repository 全鏈路。
 *
 * @group profit_shop
 * @group rest
 * @group application
 */

declare( strict_types=1 );

namespace Tests\Integration\Application;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use Tests\Integration\TestCase;

/**
 * ProfitShop CRUD IT
 */
final class ProfitShopCrudTest extends TestCase {

	private int $admin_id;
	private int $partner_term_id;
	private int $product_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		// 建一個 partner term（IT 透過真實 PartnerTermRepository）
		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: 'jerry@example.com'
			),
			'pa55word!'
		);

		// 建一個 WC 商品做 items
		$product          = $this->createSimpleProduct( [ 'regular_price' => '999' ] );
		$this->product_id = $product->get_id();
	}

	/**
	 * POST /profit-shops → 201 + body 包含新賣場 ID
	 *
	 * @test
	 * @group happy
	 */
	public function test_create_shop_returns_201_with_new_id(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-shops' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'title'           => 'IT 賣場',
					'slug'            => 'it-shop',
					'status'          => 'draft',
					'mode'            => 'page',
					'partner_term_id' => $this->partner_term_id,
					'rate'            => 10,
					'items'           => [
						[
							'product_id'     => $this->product_id,
							'inflated_count' => 0,
							'override'       => [
								'regular_price' => '888',
								'sale_price'    => null,
								'signup_fee'    => null,
							],
						],
					],
					'settings'        => [],
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'data', $body );
		$this->assertGreaterThan( 0, $body['data']['id'] ?? 0 );
	}

	/**
	 * GET /profit-shops/{id} → 200 + 完整 ProfitShopOutput
	 *
	 * @test
	 * @group happy
	 */
	public function test_get_shop_returns_full_output(): void {
		// 先建一個
		$shop_id = $this->create_shop_via_api( 'get-test', '取得測試' );

		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/' . $shop_id );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( $shop_id, $body['data']['id'] );
		$this->assertSame( '取得測試', $body['data']['title'] );
	}

	/**
	 * PUT /profit-shops/{id} → 200 + 更新後內容
	 *
	 * @test
	 * @group happy
	 */
	public function test_update_shop_persists_changes(): void {
		$shop_id = $this->create_shop_via_api( 'update-test', '原標題' );

		$request = new \WP_REST_Request( 'PUT', '/power-shop/profit-shops/' . $shop_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'title'           => '新標題',
					'slug'            => 'update-test',
					'status'          => 'publish',
					'mode'            => 'page',
					'partner_term_id' => $this->partner_term_id,
					'rate'            => 30,
					'items'           => [],
					'settings'        => [],
				]
			)
		);
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( '新標題', $body['data']['title'] );
		$this->assertSame( 30, $body['data']['rate'] );
	}

	/**
	 * DELETE /profit-shops/{id} → 200，後續 GET 回 404
	 *
	 * @test
	 * @group happy
	 */
	public function test_delete_shop_makes_subsequent_get_return_404(): void {
		$shop_id = $this->create_shop_via_api( 'delete-test', '即將刪除' );

		$delete  = new \WP_REST_Request( 'DELETE', '/power-shop/profit-shops/' . $shop_id );
		$delResp = \rest_do_request( $delete );
		$this->assertContains( $delResp->get_status(), [ 200, 204 ] );

		$get     = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/' . $shop_id );
		$getResp = \rest_do_request( $get );
		$this->assertSame( 404, $getResp->get_status() );
	}

	// ========== helper ==========

	/**
	 * 透過 API 建立賣場，回傳新 ID
	 */
	private function create_shop_via_api( string $slug, string $title ): int {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-shops' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'title'           => $title,
					'slug'            => $slug,
					'status'          => 'draft',
					'mode'            => 'page',
					'partner_term_id' => $this->partner_term_id,
					'rate'            => 10,
					'items'           => [],
					'settings'        => [],
				]
			)
		);

		$response = \rest_do_request( $request );
		$body     = $response->get_data();
		return (int) ( $body['data']['id'] ?? 0 );
	}
}
