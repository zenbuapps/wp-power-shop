<?php
/**
 * Domain Exception → HTTP 對照整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §7.1、§7.2、§7.3
 *
 * 驗證 master 即將實作的 ExceptionMapper：
 *   inc/classes/Domains/ProfitShop/Infrastructure/Rest/ExceptionMapper.php
 *
 * 對照表（必須完整覆蓋）：
 *   ProfitShopNotFound / PartnerNotFound / ProductNotFound  → 404 not_found
 *   InvalidPriceOverride / InvalidProfitRate / InvalidPartnerSlug / InvalidVariation → 400 validation_failed
 *   SlugConflictException                                     → 409 slug_conflict（payload 帶 conflicts）
 *   InvalidStatusTransition                                   → 422 invalid_state_transition
 *   PartnerStillInUseException                                → 409 partner_in_use
 *   InvalidCredentials                                        → 401 unauthorized
 *   TooManyAttempts / RateLimitExceeded                       → 429 rate_limited（含 Retry-After header）
 *   Forbidden                                                  → 403 forbidden
 *   LegacyShopNotImportable                                    → 422 legacy_unimportable
 *   PersistenceFailure                                         → 500 internal_error
 *
 * IT 走 \rest_do_request 觸發 ExceptionMapper（透過實際 callback 內 throw）。
 *
 * @group profit_shop
 * @group rest
 * @group exception_mapper
 */

declare( strict_types=1 );

namespace Tests\Integration\Rest;

use Tests\Integration\TestCase;

/**
 * ExceptionMapper IT
 */
final class ExceptionMapperTest extends TestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );
	}

	/**
	 * GET 不存在的賣場 → 404 not_found
	 *
	 * @test
	 * @group error
	 */
	public function test_profit_shop_not_found_maps_to_404(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/9999999' );
		$response = \rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'not_found', $body['code'] ?? null );
	}

	/**
	 * GET 不存在的 partner → 404 not_found
	 *
	 * @test
	 * @group error
	 */
	public function test_partner_not_found_maps_to_404(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-partners/9999999' );
		$response = \rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'not_found', $body['code'] ?? null );
	}

	/**
	 * POST profit-shops with rate=200 → 400 validation_failed（InvalidProfitRate）
	 *
	 * @test
	 * @group error
	 */
	public function test_invalid_profit_rate_maps_to_400(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-shops' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'title'           => '違法 rate',
					'slug'            => 'invalid-rate-shop',
					'status'          => 'draft',
					'mode'            => 'page',
					'partner_term_id' => 0,
					'rate'            => 200,
					'items'           => [],
					'settings'        => [],
				]
			)
		);
		$response = \rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'validation_failed', $body['code'] ?? null );
	}

	/**
	 * POST profit-partners with reserved slug 'admin' → 400 validation_failed（InvalidPartnerSlug）
	 *
	 * @test
	 * @group error
	 */
	public function test_invalid_partner_slug_maps_to_400(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'name'     => 'X',
					'slug'     => 'admin',
					'password' => 'whatever',
				]
			)
		);
		$response = \rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'validation_failed', $body['code'] ?? null );
	}

	/**
	 * POST profit-migration/import 缺 partner_term_id → 422 legacy_unimportable
	 *
	 * @test
	 * @group error
	 */
	public function test_legacy_shop_not_importable_maps_to_422(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-migration/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'legacy_id'       => 1,
					'partner_term_id' => 0, // 缺 partner_term_id
				]
			)
		);
		$response = \rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'legacy_unimportable', $body['code'] ?? null );
	}
}
