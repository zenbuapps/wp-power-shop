<?php
/**
 * DeletePartner on Trashed Shop IT（Phase 3-B observation 4 補強）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2、OQ-2
 *
 * 紅燈合約：
 *   - 賣場 trashed（status=trash）後，PartnerRepository::is_in_use() → false
 *   - 因此 DeletePartnerUseCase 對「只綁定 trashed shop 的 partner」應該成功（不拋）
 *   - 對 publish/draft 的 shop 仍然應該保護（is_in_use 回 true）
 *
 * @group profit_shop
 * @group rest
 * @group application
 */

declare( strict_types=1 );

namespace Tests\Integration\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\DeletePartner;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use Tests\Integration\TestCase;

/**
 * DeletePartner on Trashed Shop IT
 */
final class DeletePartnerOnTrashedShopTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/**
	 * @group happy
	 */
	public function test_partner_with_only_trashed_shops_is_not_in_use(): void {
		$partnerRepo = PartnerTermRepository::instance();

		$partner_term_id = $partnerRepo->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'pw'
		);

		// 建一個 shop 掛在這個 partner
		$shop = new ProfitShop(
			id: 0,
			title: '即將被丟到 trash 的賣場',
			slug: 'will-be-trashed',
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: $partner_term_id,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: []
		);
		$shop_id = CptProfitShopRepository::instance()->save( $shop );
		$this->assertGreaterThan( 0, $shop_id );

		// 確認尚未 trash 之前 partner 是 in_use
		$this->assertTrue( $partnerRepo->is_in_use( $partner_term_id ), '尚未 trash 前 is_in_use 應為 true' );

		// trash 該 shop
		\wp_trash_post( $shop_id );
		$post = \get_post( $shop_id );
		$this->assertSame( 'trash', $post?->post_status );

		// 現在應 is_in_use = false
		$this->assertFalse(
			$partnerRepo->is_in_use( $partner_term_id ),
			'已 trash 的 shop 不應算 partner 仍在使用（OQ-2）'
		);
	}

	/**
	 * @group happy
	 */
	public function test_delete_partner_succeeds_when_only_trashed_shops_remain(): void {
		$partnerRepo = PartnerTermRepository::instance();

		$partner_term_id = $partnerRepo->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'pw'
		);

		$shop = new ProfitShop(
			id: 0,
			title: 'trashed shop',
			slug: 'trashed-shop',
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: $partner_term_id,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: []
		);
		$shop_id = CptProfitShopRepository::instance()->save( $shop );
		\wp_trash_post( $shop_id );

		$useCase = new DeletePartner( partnerRepo: $partnerRepo );

		// 不應拋（spec OQ-2）
		$useCase->execute( id: $partner_term_id );

		$this->assertNull( $partnerRepo->find_by_id( $partner_term_id ), 'partner 應已被刪除' );
	}

	/**
	 * @group error
	 */
	public function test_delete_partner_still_blocked_when_publish_shop_remains(): void {
		$partnerRepo = PartnerTermRepository::instance();

		$partner_term_id = $partnerRepo->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'pw'
		);

		$shop = new ProfitShop(
			id: 0,
			title: 'live shop',
			slug: 'live-shop',
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: $partner_term_id,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: []
		);
		CptProfitShopRepository::instance()->save( $shop );

		// publish shop 仍存在 → is_in_use=true → DeletePartner 應拋
		$this->assertTrue( $partnerRepo->is_in_use( $partner_term_id ) );

		$useCase = new DeletePartner( partnerRepo: $partnerRepo );

		$this->expectException( \J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerStillInUseException::class );
		$useCase->execute( id: $partner_term_id );
	}

	public function tear_down(): void {
		// 清空 profit_shop CPT
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->posts} WHERE post_type = %s",
				CptRegistrar::POST_TYPE
			)
		);
		parent::tear_down();
	}
}
