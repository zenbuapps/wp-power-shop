<?php
/**
 * LegacyImporter IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * @group profit_shop
 * @group rest
 * @group application
 * @group migration
 */

declare( strict_types=1 );

namespace Tests\Integration\Application;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use Tests\Integration\TestCase;

/**
 * LegacyImporter IT
 */
final class LegacyImporterTest extends TestCase {

	private int $partner_term_id;

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Importer',
				slug: new PartnerSlug( 'importer-kol' ),
				contact_email: null
			),
			'pw'
		);
	}

	/**
	 * GET /profit-migration/legacy-shops → 200 + 列表
	 *
	 * @test
	 * @group happy
	 */
	public function test_list_legacy_shops_returns_200(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-migration/legacy-shops' );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * POST /profit-migration/import 缺 partner_term_id → 422 legacy_unimportable
	 *
	 * @test
	 * @group error
	 */
	public function test_import_without_partner_term_id_returns_422(): void {
		// 先建一個舊版 power-shop CPT 賣場（legacy）
		$legacy_id = self::factory()->post->create(
			[
				'post_type'   => 'power-shop',
				'post_status' => 'publish',
				'post_title'  => '舊版賣場',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-migration/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'legacy_id'       => $legacy_id,
					'partner_term_id' => 0,
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'legacy_unimportable', $body['code'] ?? null );
	}

	/**
	 * POST /profit-migration/import 帶合法 partner_term_id → 201 + 新賣場 ID
	 *
	 * @test
	 * @group happy
	 */
	public function test_import_with_partner_creates_new_profit_shop(): void {
		$legacy_id = self::factory()->post->create(
			[
				'post_type'   => 'power-shop',
				'post_status' => 'publish',
				'post_title'  => '舊版賣場待匯入',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-migration/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'legacy_id'       => $legacy_id,
					'partner_term_id' => $this->partner_term_id,
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		$this->assertGreaterThan( 0, $body['data']['id'] ?? 0 );
		$this->assertSame( 'page', $body['data']['mode'] ?? null, 'spec §4.7：匯入後 mode=page' );
	}
}
