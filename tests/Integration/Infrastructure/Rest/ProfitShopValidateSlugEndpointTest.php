<?php
/**
 * Profit Shops validate-slug endpoint IT（Phase 3-E T-9 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 * 對應實作（待 Green 階段建立）：
 *   - inc/classes/Domains/ProfitShop/Application/UseCase/Shop/ValidateSlugUseCase.php
 *   - inc/classes/Domains/ProfitShop/Application/DTO/SlugValidationOutput.php
 *   - inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php
 *       新增 endpoint:
 *         GET profit-shops/validate-slug?slug=xxx
 *         permission_callback = null（= ApiBase 預設 manage_options OR manage_woocommerce）
 *       新增 callback: get_profit_shops_validate_slug_callback
 *
 * Endpoint spec：
 *   GET /wp-json/power-shop/profit-shops/validate-slug?slug=xxx
 *   Response 200:
 *     { "code": "success", "data": {
 *         "available": true|false,
 *         "conflicts": [
 *           { "conflict_kind": "...", "conflicting_slug": "...",
 *             "conflicting_id": int|null, "conflicting_label": "..." },
 *           ...
 *         ]
 *     } }
 *   Response 400 / 401 / 403 對應 spec §7
 *
 * 注意：本檔在 Green 階段尚未實作前，IT 紅燈來自 endpoint 404（路由未註冊）。
 *
 * @group profit_shop
 * @group rest
 * @group phase_3e
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use Tests\Integration\TestCase;

/**
 * GET /profit-shops/validate-slug endpoint IT
 */
final class ProfitShopValidateSlugEndpointTest extends TestCase {

	/**
	 * happy：unique slug → 200 + available=true + conflicts=[]
	 *
	 * @test
	 * @group happy
	 */
	public function test_get_validate_slug_returns_200_with_available_true_for_unique_slug(): void {
		$this->login_admin();

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => 'totally-unique-slug-zxcvbnm-2026' ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'success', $body['code'] ?? null );
		$this->assertArrayHasKey( 'data', $body );

		$data = $body['data'];
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'available', $data );
		$this->assertArrayHasKey( 'conflicts', $data );
		$this->assertTrue( $data['available'], 'unique slug 應 available=true' );
		$this->assertSame( [], $data['conflicts'] );
	}

	/**
	 * error：撞既有 profit_partner term slug → 200 + available=false + conflicts 含 profit_partner kind
	 *
	 * @test
	 * @group error
	 */
	public function test_get_validate_slug_returns_200_with_conflicts_for_existing_partner_slug(): void {
		$this->login_admin();

		// 先建一個 partner slug = 'jerry-existing'
		$partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry-existing' ),
				contact_email: null
			),
			'pw'
		);
		$this->assertGreaterThan( 0, $partner_term_id );

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => 'jerry-existing' ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'success', $body['code'] ?? null );

		$data = $body['data'];
		$this->assertFalse( $data['available'], '撞既有 partner slug 應 available=false' );
		$this->assertNotEmpty( $data['conflicts'], 'conflicts 必須非空' );
	}

	/**
	 * BUG-1（HIGH）：撞既有 publish powershop CPT slug → 200 + available=false + conflicts 含 'powershop' kind
	 *
	 * 重現步驟（browser-tester 紀錄於 output/playwright/browser-test/test-report.md）：
	 *   1. admin SPA 建立 publish 賣場 'test-shop-alpha'
	 *   2. GET /power-shop/profit-shops/validate-slug?slug=test-shop-alpha
	 *   3. 預期 available=false 且 conflicts 含 'powershop' kind
	 *   4. BUG 前實際 available=true（因為 SlugConflictDetector 漏掉 spec §6.11 第 3 類）
	 *
	 * @test
	 * @group error
	 * @group bug_1
	 */
	public function test_get_validate_slug_returns_conflicts_for_existing_publish_powershop_slug(): void {
		$this->login_admin();

		$shop_id = self::factory()->post->create(
			[
				'post_type'   => CptRegistrar::POST_TYPE,
				'post_status' => 'publish',
				'post_name'   => 'test-shop-alpha',
				'post_title'  => '測試賣場 Alpha',
			]
		);
		$this->assertGreaterThan( 0, $shop_id, '測試前置：powershop CPT post 應建立成功' );

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => 'test-shop-alpha' ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'success', $body['code'] ?? null );

		$data = $body['data'];
		$this->assertFalse( $data['available'], 'BUG-1：撞既有 publish powershop slug 必須 available=false' );
		$this->assertNotEmpty( $data['conflicts'], 'BUG-1：conflicts 必須非空' );

		$kinds = array_column( $data['conflicts'], 'conflict_kind' );
		$this->assertContains(
			'powershop',
			$kinds,
			"BUG-1：conflicts 必須含 'powershop' kind，實際=" . implode( ',', $kinds )
		);

		// 進一步鎖死 conflicting_id 必為 publish shop 的 post ID
		$powershop_conflict = null;
		foreach ( $data['conflicts'] as $conflict ) {
			if ( 'powershop' === ( $conflict['conflict_kind'] ?? '' ) ) {
				$powershop_conflict = $conflict;
				break;
			}
		}
		$this->assertNotNull( $powershop_conflict );
		$this->assertSame( $shop_id, $powershop_conflict['conflicting_id'] );
	}

	/**
	 * BUG-1 邊界：撞既有 draft powershop slug → 同樣 available=false
	 *
	 * 因為 4-C1 ProfitShopRenderer 對 draft shop 走 edit_post capability 預覽，
	 * draft slug 已被佔用，不能讓新賣場用同樣 slug 覆蓋預覽流程。
	 *
	 * @test
	 * @group error
	 * @group bug_1
	 * @group edge
	 */
	public function test_get_validate_slug_returns_conflicts_for_existing_draft_powershop_slug(): void {
		$this->login_admin();

		self::factory()->post->create(
			[
				'post_type'   => CptRegistrar::POST_TYPE,
				'post_status' => 'draft',
				'post_name'   => 'draft-shop-beta',
				'post_title'  => '草稿賣場 Beta',
			]
		);

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => 'draft-shop-beta' ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body  = $response->get_data();
		$kinds = array_column( $body['data']['conflicts'] ?? [], 'conflict_kind' );

		$this->assertFalse( $body['data']['available'], 'draft 賣場 slug 也應視為已佔用' );
		$this->assertContains( 'powershop', $kinds );
	}

	/**
	 * BUG-1 邊界：trashed powershop slug 不應被視為衝突（slug 釋放回池）
	 *
	 * @test
	 * @group happy
	 * @group bug_1
	 * @group edge
	 */
	public function test_get_validate_slug_does_not_block_trashed_powershop_slug(): void {
		$this->login_admin();

		self::factory()->post->create(
			[
				'post_type'   => CptRegistrar::POST_TYPE,
				'post_status' => 'trash',
				'post_name'   => 'trashed-shop-gamma',
				'post_title'  => '垃圾桶賣場 Gamma',
			]
		);

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => 'trashed-shop-gamma' ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertTrue(
			$body['data']['available'],
			'trashed slug 應釋放回池，validate-slug 必須回 available=true'
		);
	}

	/**
	 * error：空 slug → 400 validation_failed
	 *
	 * @test
	 * @group error
	 */
	public function test_get_validate_slug_returns_400_for_empty_slug(): void {
		$this->login_admin();

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => '' ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 400, $response->get_status(), '空 slug 應回 400' );
		$body = $response->get_data();
		$this->assertSame( 'validation_failed', $body['code'] ?? null );
	}

	/**
	 * error：含特殊字元的 slug → 400 validation_failed
	 *
	 * @test
	 * @group error
	 */
	public function test_get_validate_slug_returns_400_for_invalid_format_slug(): void {
		$this->login_admin();

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => 'foo bar!' ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'validation_failed', $body['code'] ?? null );
	}

	/**
	 * security：未登入 → 401 / 403
	 *
	 * @test
	 * @group security
	 */
	public function test_get_validate_slug_requires_manage_woocommerce_capability(): void {
		\wp_set_current_user( 0 );

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => 'whatever' ] );

		$response = \rest_do_request( $request );

		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'未登入應回 401 或 403'
		);
	}

	/**
	 * happy：response 結構完整符合 spec
	 *
	 * 衝突項目應有完整四欄位：
	 *   conflict_kind / conflicting_slug / conflicting_id / conflicting_label
	 *
	 * @test
	 * @group happy
	 */
	public function test_get_validate_slug_response_structure_matches_spec(): void {
		$this->login_admin();

		// 建一個一定會撞的 partner slug
		PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Spec Conflict',
				slug: new PartnerSlug( 'spec-conflict-target' ),
				contact_email: null
			),
			'pw'
		);

		$request = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/validate-slug' );
		$request->set_query_params( [ 'slug' => 'spec-conflict-target' ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'success', $body['code'] ?? null );

		$data = $body['data'];
		$this->assertIsArray( $data );

		// available 必為 bool
		$this->assertArrayHasKey( 'available', $data );
		$this->assertIsBool( $data['available'] );

		// conflicts 必為 array
		$this->assertArrayHasKey( 'conflicts', $data );
		$this->assertIsArray( $data['conflicts'] );

		if ( ! empty( $data['conflicts'] ) ) {
			$first = $data['conflicts'][0];
			$this->assertIsArray( $first );
			$this->assertArrayHasKey( 'conflict_kind', $first, 'conflict 必含 conflict_kind' );
			$this->assertArrayHasKey( 'conflicting_slug', $first, 'conflict 必含 conflicting_slug' );
			$this->assertArrayHasKey( 'conflicting_id', $first, 'conflict 必含 conflicting_id（可為 null）' );
			$this->assertArrayHasKey( 'conflicting_label', $first, 'conflict 必含 conflicting_label' );

			$this->assertIsString( $first['conflict_kind'] );
			$this->assertIsString( $first['conflicting_slug'] );
			$this->assertIsString( $first['conflicting_label'] );
			// conflicting_id 允許 int 或 null
			$this->assertTrue(
				is_int( $first['conflicting_id'] ) || null === $first['conflicting_id'],
				'conflicting_id 必為 int 或 null'
			);
		}
	}

	/**
	 * security：admin 可成功存取
	 *
	 * @test
	 * @group security
	 */
	public function test_get_validate_slug_route_is_registered(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey(
			'/power-shop/profit-shops/validate-slug',
			$routes,
			'spec §6.11：profit-shops/validate-slug route 應註冊'
		);
	}

	/**
	 * 將當前使用者切換為 admin
	 */
	private function login_admin(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}
}
