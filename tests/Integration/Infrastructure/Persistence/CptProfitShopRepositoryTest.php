<?php
/**
 * CptProfitShopRepository 整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.2、§2.3、§10
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/Persistence/CptProfitShopRepository.php
 *
 * 驗證 ProfitShop 聚合根透過 powershop CPT + post_meta 的 round-trip 正確性。
 *
 * @group profit_shop
 * @group infrastructure
 * @group persistence
 * @group repository
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use Tests\Integration\TestCase;

/**
 * 透過真實 WP DB 驗證 ProfitShop 聚合根的存取行為
 */
final class CptProfitShopRepositoryTest extends TestCase {

	private CptProfitShopRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = CptProfitShopRepository::instance();
	}

	// ========== Happy ==========

	/**
	 * save() 應建立 powershop post + 寫入 5 筆 _profit_* meta
	 *
	 * @test
	 * @group happy
	 */
	public function test_save_creates_post_and_meta(): void {
		$shop = $this->build_shop_aggregate();

		$id = $this->repo->save( $shop );

		$this->assertGreaterThan( 0, $id, 'save() 應回傳新的 post id' );

		$post = \get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( CptRegistrar::POST_TYPE, $post->post_type );
		$this->assertSame( '蘿蔔賣場', $post->post_title );
		$this->assertSame( 'publish', $post->post_status );

		$this->assertSame( 'page', \get_post_meta( $id, '_profit_shop_mode', true ) );
		$this->assertSame( '7', (string) \get_post_meta( $id, '_profit_partner_term_id', true ) );
		$this->assertSame( '20', (string) \get_post_meta( $id, '_profit_rate', true ) );

		$items_json = (string) \get_post_meta( $id, '_profit_shop_items', true );
		$items_arr  = json_decode( $items_json, true );
		$this->assertIsArray( $items_arr );
		$this->assertCount( 1, $items_arr );
		$this->assertSame( 100, $items_arr[0]['product_id'] );
	}

	/**
	 * find() 應從 post + meta 完整重建 ProfitShop 聚合根
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_returns_aggregate_with_items(): void {
		$shop = $this->build_shop_aggregate();
		$id   = $this->repo->save( $shop );

		$loaded = $this->repo->find( $id );

		$this->assertInstanceOf( ProfitShop::class, $loaded );
		$this->assertSame( $id, $loaded->id );
		$this->assertSame( '蘿蔔賣場', $loaded->title );
		$this->assertSame( ShopMode::PAGE, $loaded->mode );
		$this->assertSame( 7, $loaded->partner_term_id );
		$this->assertSame( 20, $loaded->rate->value() );

		$items = $loaded->items();
		$this->assertCount( 1, $items );
		$this->assertSame( 100, $items[0]->product_id );
		$this->assertSame( '888', $items[0]->override->regular_price );
		$this->assertSame( '666', $items[0]->override->sale_price );
		$this->assertSame( 50, $items[0]->inflated_count->value() );
	}

	/**
	 * 同 ID 第二次 save() 必須是 update 而非 insert（不會產生第二筆 post）
	 *
	 * @test
	 * @group happy
	 */
	public function test_save_updates_existing_post(): void {
		$shop = $this->build_shop_aggregate();
		$id1  = $this->repo->save( $shop );

		// 從 DB 載回，模擬編輯流程
		$loaded = $this->repo->find( $id1 );
		$this->assertNotNull( $loaded );
		$loaded->title = '蘿蔔賣場 v2';
		$loaded->change_rate( new ProfitRate( 35 ) );

		$id2 = $this->repo->save( $loaded );

		$this->assertSame( $id1, $id2, '同 ID save() 應更新而非新增' );

		$reloaded = $this->repo->find( $id1 );
		$this->assertNotNull( $reloaded );
		$this->assertSame( '蘿蔔賣場 v2', $reloaded->title );
		$this->assertSame( 35, $reloaded->rate->value() );

		// 確認 DB 沒有產生第二筆 powershop post
		$count = $this->count_powershop_posts();
		$this->assertSame( 1, $count, '不應出現第二筆 powershop post' );
	}

	/**
	 * find_by_partner() 應回傳該 partner 旗下的所有賣場
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_by_partner_returns_matching_shops(): void {
		$jerry_term_id = 9001;

		$shop_a = $this->build_shop_aggregate(
			[
				'title'           => 'Jerry 賣場 A',
				'slug'            => 'jerry-a',
				'partner_term_id' => $jerry_term_id,
			]
		);
		$shop_b = $this->build_shop_aggregate(
			[
				'title'           => 'Jerry 賣場 B',
				'slug'            => 'jerry-b',
				'partner_term_id' => $jerry_term_id,
			]
		);
		$shop_c = $this->build_shop_aggregate(
			[
				'title'           => 'Other 賣場',
				'slug'            => 'other',
				'partner_term_id' => 9999,
			]
		);

		$this->repo->save( $shop_a );
		$this->repo->save( $shop_b );
		$this->repo->save( $shop_c );

		$found = $this->repo->find_by_partner( $jerry_term_id );

		$this->assertCount( 2, $found, 'Jerry 應有 2 個賣場' );
		$titles = array_map( static fn( ProfitShop $s ): string => $s->title, $found );
		sort( $titles );
		$this->assertSame( [ 'Jerry 賣場 A', 'Jerry 賣場 B' ], $titles );
	}

	/**
	 * variable 商品的 variations 覆寫必須能 round-trip（write → read → 結構完整）
	 *
	 * @test
	 * @group happy
	 */
	public function test_items_with_variations_round_trip(): void {
		$item = new OverrideItem(
			product_id: 200,
			override: new PriceOverride( '1000', null, null ),
			inflated_count: new InflatedCount( 0 ),
			variations: [
				301 => new PriceOverride( '900', '850', null ),
				302 => new PriceOverride( '950', null, null ),
			]
		);

		$shop = new ProfitShop(
			id: 0,
			title: 'Variable 賣場',
			slug: 'variable-shop',
			status: 'draft',
			mode: ShopMode::SHORTCODE,
			partner_term_id: 11,
			rate: new ProfitRate( 15 ),
			items: [ $item ],
			settings: []
		);

		$id     = $this->repo->save( $shop );
		$loaded = $this->repo->find( $id );

		$this->assertNotNull( $loaded );
		$loaded_items = $loaded->items();
		$this->assertCount( 1, $loaded_items );

		$loaded_item = $loaded_items[0];
		$this->assertSame( 200, $loaded_item->product_id );
		$this->assertCount( 2, $loaded_item->variations );

		$v301 = $loaded_item->get_variation_override( 301 );
		$this->assertNotNull( $v301 );
		$this->assertSame( '900', $v301->regular_price );
		$this->assertSame( '850', $v301->sale_price );

		$v302 = $loaded_item->get_variation_override( 302 );
		$this->assertNotNull( $v302 );
		$this->assertSame( '950', $v302->regular_price );
		$this->assertNull( $v302->sale_price );
	}

	// ========== Edge ==========

	/**
	 * find() 對不存在的 ID 應回傳 null（合約：null 而非 throw）
	 *
	 * @test
	 * @group edge
	 * @group error
	 */
	public function test_find_returns_null_for_unknown_id(): void {
		$this->assertNull( $this->repo->find( 9999999 ) );
	}

	/**
	 * find() 對非 powershop CPT 的 post 應回傳 null（型別過濾）
	 *
	 * @test
	 * @group edge
	 */
	public function test_find_returns_null_for_non_powershop_post(): void {
		$post_id = $this->factory()->post->create( [ 'post_type' => 'post' ] );

		$this->assertNull(
			$this->repo->find( $post_id ),
			'find() 不應將 wp_posts 中其他 post_type 視為分潤賣場'
		);
	}

	/**
	 * find() 對已被丟入回收桶（trash）的賣場應回傳 null
	 *
	 * 即使 DB 中 wp_posts 仍有該紀錄，trashed 賣場在業務語意上等同於已刪除：
	 * - V2Api / UseCase 不該再把它當作有效賣場
	 * - hydrate_from_post() 直接吃 'trash' status 會 throw InvalidStatusTransition
	 *
	 * 故 Repository 層提前過濾，回 null 即是「找不到」。
	 *
	 * @test
	 * @group edge
	 */
	public function test_find_returns_null_for_trashed_shop(): void {
		$shop = $this->build_shop_aggregate();
		$id   = $this->repo->save( $shop );

		$this->assertNotNull(
			$this->repo->find( $id ),
			'前置條件：trash 前 find() 應能找到賣場'
		);

		\wp_trash_post( $id );

		$this->assertNull(
			$this->repo->find( $id ),
			'已 trash 的賣場 find() 應回 null'
		);
	}

	/**
	 * save() 應能將 publish 賣場切換為 draft，且 meta 完整保留
	 *
	 * 驗證 update path 的 post_status 正確寫入，不會因為 status 切換造成
	 * meta 遺失或不一致（spec §10 status transitions）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_save_changes_status_publish_to_draft(): void {
		$shop = $this->build_shop_aggregate( [ 'status' => 'publish' ] );
		$id   = $this->repo->save( $shop );

		$loaded = $this->repo->find( $id );
		$this->assertNotNull( $loaded );
		$this->assertSame( 'publish', $loaded->status );

		// 切換到 draft（透過 Entity 的 unpublish() 或直接修改 status，視 Entity API）
		$loaded->status = 'draft';
		$this->repo->save( $loaded );

		$reloaded = $this->repo->find( $id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( 'draft', $reloaded->status, 'status 應切換為 draft' );

		// 確認 meta 完整保留（rate / partner / items 不應因 status 切換而消失）
		$this->assertSame( 20, $reloaded->rate->value() );
		$this->assertSame( 7, $reloaded->partner_term_id );
		$this->assertCount( 1, $reloaded->items() );
	}

	/**
	 * 中文字符 round-trip：驗證 JSON_UNESCAPED_UNICODE 後寫入 / 讀回的字串完全一致
	 *
	 * 對應 B-1 修法：拿掉 wp_slash 包裹後，必須確認中文不會被 \uXXXX 化，
	 * 且 read-back 與原始字串完全相同（DB 可讀性 + 容量都應改善）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_save_round_trip_with_chinese_characters(): void {
		$item = new OverrideItem(
			product_id: 100,
			override: new PriceOverride( '888', '666', null ),
			inflated_count: new InflatedCount( 50 ),
			variations: []
		);

		$shop = new ProfitShop(
			id: 0,
			title: '分潤賣場：林北的甜點店',
			slug: 'lin-bei-dessert',
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: 7,
			rate: new ProfitRate( 20 ),
			items: [ $item ],
			settings: [
				'shop_description' => '台北最強甜點，限定供應',
				'note'             => '保留中文 "引號" 與符號 / \\ 應原樣保留',
			]
		);

		$id     = $this->repo->save( $shop );
		$loaded = $this->repo->find( $id );

		$this->assertNotNull( $loaded );
		$this->assertSame(
			'分潤賣場：林北的甜點店',
			$loaded->title,
			'中文 title round-trip 應完全一致'
		);

		// 驗證 _profit_shop_settings 中的中文值（透過 settings array）
		$this->assertSame( '台北最強甜點，限定供應', $loaded->settings['shop_description'] ?? null );
		$this->assertSame( '保留中文 "引號" 與符號 / \\ 應原樣保留', $loaded->settings['note'] ?? null );

		// 驗證 _profit_shop_settings DB 內容真的是原字元（沒有 \uXXXX，驗證 JSON_UNESCAPED_UNICODE 已生效）
		$raw_settings_json = (string) \get_post_meta( $id, '_profit_shop_settings', true );
		$this->assertStringContainsString(
			'台北最強甜點',
			$raw_settings_json,
			'_profit_shop_settings 內中文應原字元儲存'
		);
		$this->assertStringNotContainsString(
			'\\u53f0',
			$raw_settings_json,
			'_profit_shop_settings 中文不應被轉成 \\uXXXX 形式'
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立一個典型的 ProfitShop 聚合根（可覆寫部分欄位）
	 *
	 * @param array<string, mixed> $overrides 覆寫欄位
	 */
	private function build_shop_aggregate( array $overrides = [] ): ProfitShop {
		$item = new OverrideItem(
			product_id: 100,
			override: new PriceOverride( '888', '666', null ),
			inflated_count: new InflatedCount( 50 ),
			variations: []
		);

		return new ProfitShop(
			id: (int) ( $overrides['id'] ?? 0 ),
			title: (string) ( $overrides['title'] ?? '蘿蔔賣場' ),
			slug: (string) ( $overrides['slug'] ?? 'radish-shop' ),
			status: (string) ( $overrides['status'] ?? 'publish' ),
			mode: $overrides['mode'] ?? ShopMode::PAGE,
			partner_term_id: (int) ( $overrides['partner_term_id'] ?? 7 ),
			rate: $overrides['rate'] ?? new ProfitRate( 20 ),
			items: $overrides['items'] ?? [ $item ],
			settings: (array) ( $overrides['settings'] ?? [] )
		);
	}

	/**
	 * 統計 wp_posts 中 powershop 類型的有效 post 數量（排除 trash/auto-draft）
	 */
	private function count_powershop_posts(): int {
		global $wpdb;
		$type    = CptRegistrar::POST_TYPE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private')",
				$type
			)
		);
		return $count;
	}
}
