<?php
/**
 * DeletePartner 拒絕（partner 仍被任何賣場引用）IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 * 對應 ExceptionMapper：PartnerStillInUseException → 409 partner_in_use
 *
 * @group profit_shop
 * @group rest
 * @group application
 */

declare( strict_types=1 );

namespace Tests\Integration\Application;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use Tests\Integration\TestCase;

/**
 * Partner 仍被使用時刪除 IT
 */
final class PartnerInUseTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/**
	 * Partner 仍掛在賣場 → DELETE 回 409 + code=partner_in_use
	 *
	 * @test
	 * @group error
	 */
	public function test_delete_partner_with_active_shop_returns_409(): void {
		// 建一個 partner
		$partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'pw'
		);

		// 建一個賣場掛在他身上
		$shop = new ProfitShop(
			id: 0,
			title: '掛載中的賣場',
			slug: 'still-attached',
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: $partner_term_id,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: []
		);
		CptProfitShopRepository::instance()->save( $shop );

		// DELETE partner
		$request  = new \WP_REST_Request( 'DELETE', '/power-shop/profit-partners/' . $partner_term_id );
		$response = \rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'partner_in_use', $body['code'] ?? null );
	}

	/**
	 * Partner 沒掛在任何賣場 → DELETE 成功
	 *
	 * @test
	 * @group happy
	 */
	public function test_delete_idle_partner_succeeds(): void {
		$partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Idle',
				slug: new PartnerSlug( 'idle-partner' ),
				contact_email: null
			),
			'pw'
		);

		$request  = new \WP_REST_Request( 'DELETE', '/power-shop/profit-partners/' . $partner_term_id );
		$response = \rest_do_request( $request );

		$this->assertContains( $response->get_status(), [ 200, 204 ] );
	}
}
