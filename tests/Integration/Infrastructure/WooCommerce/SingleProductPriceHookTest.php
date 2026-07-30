<?php
/**
 * SingleProductPriceHook 整合測試（Phase 9 第六-B 階段 / R-1）
 *
 * 對應規格：specs/plan/2026-07-30-profit-shop-variation-override-plan.md 第六-B 階段
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/SingleProductPriceHook.php
 *
 * 驗證重點：
 *   1. 單品頁帶 ?profit_shop_id 時，商品 / 變體價格顯示為賣場覆寫價
 *   2. 三道閘門（shop 不存在 / draft / 商品不在賣場）任一失敗都退回商品原價
 *   3. 價格語意與 CartPriceOverrideHook::resolve_effective_price() 一致
 *      （變體有覆寫就整組採用變體層，不與商品層逐欄混用）
 *   4. 變體價格快取 hash 併入 shop_id（否則不同賣場互相讀到對方快取價）
 *
 * 測試手法：直接呼叫 maybe_register_filters() 註冊 filter（避免依賴完整的
 * template_redirect / 主查詢流程），再以 WC_Product 讀值驗證 filter 生效。
 * is_product() / get_queried_object_id() 以 $wp_query 手動 set 模擬單品頁。
 *
 * @group profit_shop
 * @group infrastructure
 * @group woocommerce
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\VariationOverride;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\SingleProductPriceHook;
use Tests\Integration\TestCase;

/**
 * 單品頁價格 override 整合測試
 */
final class SingleProductPriceHookTest extends TestCase {

	private CptProfitShopRepository $repo;

	/**
	 * 本測試註冊過的 hook 實例（tear_down 時解除 filter，避免污染其他測試）
	 *
	 * @var SingleProductPriceHook[]
	 */
	private array $registered_hooks = [];

	public function set_up(): void {
		parent::set_up();
		$this->repo = CptProfitShopRepository::instance();
	}

	public function tear_down(): void {
		foreach ( $this->registered_hooks as $hook ) {
			$this->unregister_filters( $hook );
		}
		$this->registered_hooks = [];
		unset( $_GET['profit_shop_id'] );

		parent::tear_down();
	}

	// ========== Happy：覆寫價生效 ==========

	/**
	 * simple 商品：單品頁帶 profit_shop_id → get_price() 回覆寫特價
	 *
	 * @test
	 * @group happy
	 * @group smoke
	 */
	public function test_simple_product_price_is_overridden_on_single_product_page(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( '900', '850', null ),
					inflated_count: new InflatedCount( 0 )
				),
			]
		);

		$this->activate_hook_for( $product->get_id(), $shop_id );

		// 重新載入商品，確保讀到的是套過 filter 的 view context 值
		$fresh = \wc_get_product( $product->get_id() );
		$this->assertNotFalse( $fresh, '防空斷言：商品應可載入' );

		$this->assertSame( '850', (string) $fresh->get_price(), '結帳價應為覆寫特價' );
		$this->assertSame( '900', (string) $fresh->get_regular_price(), '原價應為覆寫原價' );
		$this->assertSame( '850', (string) $fresh->get_sale_price(), '特價應為覆寫特價' );
	}

	/**
	 * 只覆寫原價（未設特價）→ 商品原有的特價必須被清掉
	 *
	 * 否則畫面會顯示「原價劃掉 → 特價」但購物車實收覆寫原價，又是一次價格不一致。
	 *
	 * @test
	 * @group edge
	 */
	public function test_regular_only_override_clears_original_sale_price(): void {
		$product = $this->createSimpleProduct(
			[
				'regular_price' => '1000',
				'sale_price'    => '700',
			]
		);
		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( '900', null, null ),
					inflated_count: new InflatedCount( 0 )
				),
			]
		);

		$this->activate_hook_for( $product->get_id(), $shop_id );

		$fresh = \wc_get_product( $product->get_id() );
		$this->assertNotFalse( $fresh, '防空斷言：商品應可載入' );

		$this->assertSame( '900', (string) $fresh->get_price(), '實收價應為覆寫原價' );
		$this->assertSame( '900', (string) $fresh->get_regular_price() );
		$this->assertSame( '', (string) $fresh->get_sale_price(), '未設覆寫特價時應清掉商品原有特價' );
	}

	/**
	 * 變體有自己的覆寫 → 該變體套變體價，其他變體套商品層覆寫價
	 *
	 * @test
	 * @group happy
	 */
	public function test_variation_override_applies_to_that_variation_only(): void {
		$variable       = $this->createVariableProduct(
			[
				'variations' => [
					[ 'regular_price' => '1000' ],
					[ 'regular_price' => '1200' ],
				],
			]
		);
		$variation_ids  = array_values( array_map( 'intval', $variable->get_children() ) );
		$this->assertCount( 2, $variation_ids, '測試前置：應有 2 個變體' );

		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $variable->get_id(),
					override: new PriceOverride( '800', null, null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$variation_ids[0] => new VariationOverride(
							new PriceOverride( '600', '550', null ),
							new InflatedCount( 0 )
						),
					]
				),
			]
		);

		$this->activate_hook_for( $variable->get_id(), $shop_id );

		$v0 = \wc_get_product( $variation_ids[0] );
		$v1 = \wc_get_product( $variation_ids[1] );
		$this->assertNotFalse( $v0, '防空斷言：變體 0 應可載入' );
		$this->assertNotFalse( $v1, '防空斷言：變體 1 應可載入' );

		// 有變體覆寫 → 用變體層
		$this->assertSame( '550', (string) $v0->get_price() );
		$this->assertSame( '600', (string) $v0->get_regular_price() );

		// 無變體覆寫 → fallback 到商品層覆寫
		$this->assertSame( '800', (string) $v1->get_price() );
		$this->assertSame( '800', (string) $v1->get_regular_price() );
	}

	/**
	 * 【一致性鎖】變體只設原價、商品層有設特價時，必須整組採用變體層
	 *
	 * 這條直接對應 CartPriceOverrideHook::resolve_effective_price() 的語意：
	 * 變體有任一覆寫欄位就整組採用變體層，**不與商品層逐欄混用**。
	 * 若這裡改成逐欄 fallback，單品頁會顯示 500（商品層特價）而購物車實收 600
	 * （變體層原價）——正是本階段要消滅的價格跳動。
	 *
	 * @test
	 * @group edge
	 * @group smoke
	 */
	public function test_variation_override_does_not_merge_field_wise_with_parent(): void {
		$variable      = $this->createVariableProduct( [ 'variations' => [ [ 'regular_price' => '1000' ] ] ] );
		$variation_ids = array_values( array_map( 'intval', $variable->get_children() ) );
		$this->assertCount( 1, $variation_ids, '測試前置：應有 1 個變體' );

		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $variable->get_id(),
					// 商品層有特價 500
					override: new PriceOverride( '900', '500', null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						// 變體層只有原價 600、沒有特價
						$variation_ids[0] => new VariationOverride(
							new PriceOverride( '600', null, null ),
							new InflatedCount( 0 )
						),
					]
				),
			]
		);

		$this->activate_hook_for( $variable->get_id(), $shop_id );

		$v0 = \wc_get_product( $variation_ids[0] );
		$this->assertNotFalse( $v0, '防空斷言：變體應可載入' );

		$this->assertSame(
			'600',
			(string) $v0->get_price(),
			'變體有覆寫時應整組採用變體層（600），不得混用商品層特價（500）'
		);
		$this->assertSame( '', (string) $v0->get_sale_price(), '變體層未設特價 → 不應顯示特價' );
	}

	// ========== 三道閘門 ==========

	/**
	 * 閘門 1：shop 不存在 → 不介入，維持商品原價
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_nonexistent_shop_leaves_price_untouched(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );

		$this->activate_hook_for( $product->get_id(), 9999999 );

		$fresh = \wc_get_product( $product->get_id() );
		$this->assertNotFalse( $fresh, '防空斷言：商品應可載入' );
		$this->assertSame( '1000', (string) $fresh->get_price() );
	}

	/**
	 * 閘門 2：draft 賣場 → 不介入（促銷預覽價不得外洩給一般顧客）
	 *
	 * @test
	 * @group security
	 */
	public function test_draft_shop_leaves_price_untouched(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( '900', '100', null ),
					inflated_count: new InflatedCount( 0 )
				),
			],
			'draft'
		);

		$this->activate_hook_for( $product->get_id(), $shop_id );

		$fresh = \wc_get_product( $product->get_id() );
		$this->assertNotFalse( $fresh, '防空斷言：商品應可載入' );
		$this->assertSame( '1000', (string) $fresh->get_price(), 'draft 賣場的覆寫價不得套用' );
	}

	/**
	 * 閘門 3：商品不在賣場 items 內 → 不介入
	 *
	 * 防「持賣場 A 的 shop_id，套到未授權商品上」。
	 *
	 * @test
	 * @group security
	 */
	public function test_product_not_in_shop_leaves_price_untouched(): void {
		$in_shop  = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$outsider = $this->createSimpleProduct( [ 'regular_price' => '2000' ] );

		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $in_shop->get_id(),
					override: new PriceOverride( '900', '850', null ),
					inflated_count: new InflatedCount( 0 )
				),
			]
		);

		// 造訪的是「不在賣場內」的商品單品頁
		$this->activate_hook_for( $outsider->get_id(), $shop_id );

		$fresh = \wc_get_product( $outsider->get_id() );
		$this->assertNotFalse( $fresh, '防空斷言：商品應可載入' );
		$this->assertSame( '2000', (string) $fresh->get_price(), '不在賣場內的商品不得被覆寫' );
	}

	/**
	 * 沒有 profit_shop_id query string → 完全不註冊 filter
	 *
	 * @test
	 * @group edge
	 */
	public function test_without_query_string_no_filter_is_registered(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( '900', '850', null ),
					inflated_count: new InflatedCount( 0 )
				),
			]
		);
		unset( $shop_id );

		// 刻意不設 $_GET['profit_shop_id']
		$this->simulate_single_product( $product->get_id() );
		$hook = new SingleProductPriceHook( $this->repo );
		$hook->maybe_register_filters();
		$this->registered_hooks[] = $hook;

		$this->assertFalse(
			\has_filter( 'woocommerce_product_get_price', [ $hook, 'filter_price' ] ),
			'無 profit_shop_id 時不應註冊任何價格 filter'
		);

		$fresh = \wc_get_product( $product->get_id() );
		$this->assertNotFalse( $fresh, '防空斷言：商品應可載入' );
		$this->assertSame( '1000', (string) $fresh->get_price() );
	}

	/**
	 * 非單品頁（is_product() 為 false）→ 不註冊 filter
	 *
	 * 這是「不干擾購物車 / 結帳 / 商品列表」的核心保證。
	 *
	 * @test
	 * @group security
	 * @group smoke
	 */
	public function test_non_single_product_page_registers_no_filter(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( '900', '850', null ),
					inflated_count: new InflatedCount( 0 )
				),
			]
		);

		$_GET['profit_shop_id'] = (string) $shop_id;

		// 不模擬單品頁：$wp_query 維持預設（is_product() 為 false）
		global $wp_query;
		$wp_query->is_singular = false;
		$wp_query->queried_object_id = 0;

		$hook = new SingleProductPriceHook( $this->repo );
		$hook->maybe_register_filters();
		$this->registered_hooks[] = $hook;

		$this->assertFalse(
			\has_filter( 'woocommerce_product_get_price', [ $hook, 'filter_price' ] ),
			'非單品頁不應註冊價格 filter（避免干擾 cart / checkout 的簽章 fallback）'
		);
	}

	// ========== 變體價格快取 hash ==========

	/**
	 * 不同賣場必須算出不同的變體價格快取 hash
	 *
	 * WC 的 get_price_hash() 只納入 woocommerce_variation_prices_* 三個 filter 的
	 * 「callback 名稱」，不含賣場 ID——兩個賣場會算出同一個 hash，於是
	 * 賣場 B 的訪客會讀到賣場 A 快取的變體價格。本測試鎖住 shop_id 有被併進 hash。
	 *
	 * @test
	 * @group security
	 * @group smoke
	 */
	public function test_variation_prices_hash_differs_per_shop(): void {
		$variable = $this->createVariableProduct( [ 'variations' => [ [ 'regular_price' => '1000' ] ] ] );

		$make_hook = function ( int $shop_id ) use ( $variable ): SingleProductPriceHook {
			$this->simulate_single_product( $variable->get_id() );
			$_GET['profit_shop_id'] = (string) $shop_id;

			$hook = new SingleProductPriceHook( $this->repo );
			$hook->maybe_register_filters();
			$this->registered_hooks[] = $hook;

			return $hook;
		};

		$shop_a_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $variable->get_id(),
					override: new PriceOverride( '800', null, null ),
					inflated_count: new InflatedCount( 0 )
				),
			],
			'publish',
			'hash-shop-a'
		);
		$shop_b_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $variable->get_id(),
					override: new PriceOverride( '700', null, null ),
					inflated_count: new InflatedCount( 0 )
				),
			],
			'publish',
			'hash-shop-b'
		);

		$hook_a = $make_hook( $shop_a_id );
		$hash_a = $hook_a->filter_variation_prices_hash( [ false ], $variable, false );
		$this->unregister_filters( $hook_a );

		$hook_b = $make_hook( $shop_b_id );
		$hash_b = $hook_b->filter_variation_prices_hash( [ false ], $variable, false );
		$this->unregister_filters( $hook_b );

		$this->assertIsArray( $hash_a );
		$this->assertIsArray( $hash_b );
		$this->assertNotSame(
			\wp_json_encode( $hash_a ),
			\wp_json_encode( $hash_b ),
			'不同賣場必須產生不同的 hash，否則會互相讀到對方快取的變體價格'
		);
	}

	/**
	 * hash filter 對「非本次命中的商品」不介入（避免無意義的快取分桶）
	 *
	 * @test
	 * @group edge
	 */
	public function test_variation_prices_hash_untouched_for_other_products(): void {
		$variable = $this->createVariableProduct( [ 'variations' => [ [ 'regular_price' => '1000' ] ] ] );
		$other    = $this->createVariableProduct( [ 'variations' => [ [ 'regular_price' => '500' ] ] ] );

		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $variable->get_id(),
					override: new PriceOverride( '800', null, null ),
					inflated_count: new InflatedCount( 0 )
				),
			]
		);

		$hook = $this->activate_hook_for( $variable->get_id(), $shop_id );

		$original = [ false ];
		$this->assertSame(
			$original,
			$hook->filter_variation_prices_hash( $original, $other, false ),
			'非命中商品的 hash 不應被改動'
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立一個含指定 items 的賣場並回傳 ID
	 *
	 * @param OverrideItem[] $items  商品覆寫項目
	 * @param string         $status 賣場狀態
	 * @param string         $slug   賣場 slug
	 */
	private function create_shop( array $items, string $status = 'publish', string $slug = 'single-price-shop' ): int {
		$shop = new ProfitShop(
			id: 0,
			title: '單品頁價格測試賣場',
			slug: $slug,
			status: $status,
			mode: ShopMode::PAGE,
			partner_term_id: 1,
			rate: new ProfitRate( 10 ),
			items: $items,
			settings: []
		);

		$id = $this->repo->save( $shop );
		$this->assertGreaterThan( 0, $id, '測試前置：賣場應建立成功' );

		return $id;
	}

	/**
	 * 模擬「單品頁 + ?profit_shop_id」情境並註冊 filter
	 *
	 * @param int $product_id 當前單品頁的商品 ID
	 * @param int $shop_id    query string 帶入的賣場 ID
	 */
	private function activate_hook_for( int $product_id, int $shop_id ): SingleProductPriceHook {
		$this->simulate_single_product( $product_id );
		$_GET['profit_shop_id'] = (string) $shop_id;

		$hook = new SingleProductPriceHook( $this->repo );
		$hook->maybe_register_filters();
		$this->registered_hooks[] = $hook;

		return $hook;
	}

	/**
	 * 把主查詢偽裝成 product 單品頁，讓 is_product() / get_queried_object_id() 成立
	 *
	 * @param int $product_id 商品 ID
	 *
	 * @return void
	 */
	private function simulate_single_product( int $product_id ): void {
		global $wp_query, $post;

		$post = \get_post( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		\setup_postdata( $post );

		$wp_query->queried_object    = $post;
		$wp_query->queried_object_id = $product_id;
		$wp_query->is_singular       = true;
		$wp_query->is_single         = true;
		$wp_query->is_home           = false;
		$wp_query->is_archive        = false;
		$wp_query->set( 'post_type', 'product' );
	}

	/**
	 * 解除某個 hook 實例註冊過的所有 filter
	 *
	 * 這些 filter 是全域的，不解除會讓後續測試的商品價格被意外覆寫。
	 *
	 * @param SingleProductPriceHook $hook hook 實例
	 *
	 * @return void
	 */
	private function unregister_filters( SingleProductPriceHook $hook ): void {
		$map = [
			'woocommerce_product_get_price'                => 'filter_price',
			'woocommerce_product_get_regular_price'        => 'filter_regular_price',
			'woocommerce_product_get_sale_price'           => 'filter_sale_price',
			'woocommerce_product_variation_get_price'      => 'filter_price',
			'woocommerce_product_variation_get_regular_price' => 'filter_regular_price',
			'woocommerce_product_variation_get_sale_price' => 'filter_sale_price',
			'woocommerce_variation_prices_price'           => 'filter_variation_prices_price',
			'woocommerce_variation_prices_regular_price'   => 'filter_variation_prices_regular_price',
			'woocommerce_variation_prices_sale_price'      => 'filter_variation_prices_sale_price',
			'woocommerce_get_variation_prices_hash'        => 'filter_variation_prices_hash',
		];

		foreach ( $map as $filter_name => $method ) {
			\remove_filter( $filter_name, [ $hook, $method ], 10 );
		}
	}
}
