<?php
/**
 * Slug 衝突偵測 IT（spec §6.11 五類衝突）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 * 對應 ExceptionMapper：SlugConflictException → 409 slug_conflict
 *
 * 五類衝突：
 *   1. WP 保留字
 *   2. WooCommerce 核心 page slugs（shop/cart/checkout/my-account）
 *   3. 其他已註冊 CPT rewrite slug
 *   4. 既有 page slugs
 *   5. 其他自訂 rewrite rules prefix
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
 * Slug 衝突 IT
 */
final class SlugConflictTest extends TestCase {

	private int $partner_term_id;

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'P',
				slug: new PartnerSlug( 'p-test' ),
				contact_email: null
			),
			'pw'
		);
	}

	/**
	 * 衝突 1：WP 保留字 'feed' → 拒絕
	 *
	 * @test
	 * @group error
	 */
	public function test_rejects_wp_reserved_word_slug(): void {
		$response = $this->post_create_shop( 'feed' );

		$this->assertSame( 409, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'slug_conflict', $body['code'] ?? null );
	}

	/**
	 * 衝突 2：WooCommerce 商店頁 slug（'shop'）→ 拒絕
	 *
	 * @test
	 * @group error
	 */
	public function test_rejects_woocommerce_shop_page_slug(): void {
		$response = $this->post_create_shop( 'shop' );

		$this->assertSame( 409, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'slug_conflict', $body['code'] ?? null );
	}

	/**
	 * 衝突 3：另一個 CPT 的 rewrite slug → 拒絕
	 *
	 * 註：此測試先註冊一個 fake CPT 'event'，再試圖以 'event' 作為賣場 slug。
	 *
	 * @test
	 * @group error
	 */
	public function test_rejects_other_cpt_rewrite_slug(): void {
		\register_post_type(
			'fake_event',
			[
				'public'      => true,
				'rewrite'     => [ 'slug' => 'fake-event' ],
				'has_archive' => true,
			]
		);

		$response = $this->post_create_shop( 'fake-event' );

		$this->assertSame( 409, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'slug_conflict', $body['code'] ?? null );
	}

	/**
	 * 衝突 4：既有 page slug 'about-page' → 拒絕
	 *
	 * @test
	 * @group error
	 */
	public function test_rejects_existing_page_slug(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'about-page',
				'post_title'  => '關於我們',
			]
		);

		$response = $this->post_create_shop( 'about-page' );

		$this->assertSame( 409, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'slug_conflict', $body['code'] ?? null );
	}

	/**
	 * 衝突 5：自訂 rewrite rule prefix
	 *
	 * 註：直接 add_rewrite_rule 模擬 plugin 註冊的 rewrite。
	 *
	 * @test
	 * @group error
	 */
	public function test_rejects_custom_rewrite_rule_prefix(): void {
		global $wp_rewrite;
		\add_rewrite_rule( '^newsletter/?$', 'index.php?pagename=newsletter', 'top' );
		$wp_rewrite->flush_rules( false );

		$response = $this->post_create_shop( 'newsletter' );

		$this->assertSame( 409, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'slug_conflict', $body['code'] ?? null );
	}

	/**
	 * 透過 POST /profit-shops 嘗試以指定 slug 建立賣場
	 */
	private function post_create_shop( string $slug ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-shops' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'title'           => '衝突測試',
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
		return \rest_do_request( $request );
	}
}
