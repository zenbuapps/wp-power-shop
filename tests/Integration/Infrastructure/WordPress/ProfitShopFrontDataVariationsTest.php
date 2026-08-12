<?php
/**
 * ProfitShopFrontData::build_variations 整合測試（2026-08：無定價變體）
 *
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/WordPress/ProfitShopFrontData.php
 *
 * 修法前的症狀（站長 2026-08-06 回報）：
 *   變體自身沒有定價、賣場設了覆寫價 → `get_available_variations()` 在
 *   woocommerce/includes/class-wc-product-variable.php:335 就把變體過濾掉
 *   （`variation_is_visible()` 的條件含 `'' !== get_price()`）→ payload 的
 *   `variations` 是空陣列 → 卡片連價格節點都不印、Modal 永遠停在「此組合無法購買」，
 *   而且畫面與 console 都沒有任何線索。
 *
 * 覆寫價是「拿到變體清單**之後**才逐筆疊上去」的，清單空了就一滴都疊不上——
 * 因此修法是在取清單前暫時掛上覆寫價 filter，取完立刻解除。
 *
 * @group profit_shop
 * @group infrastructure
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\VariationOverride;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontData;
use Tests\Integration\TestCase;

/**
 * 賣場前台變體 payload 整合測試
 */
final class ProfitShopFrontDataVariationsTest extends TestCase {

	/**
	 * 遞增用的 slug 序號
	 *
	 * @var int
	 */
	private static int $slug_seq = 0;

	// ========== Happy：無定價變體被救回來 ==========

	/**
	 * 無定價變體 + 變體層覆寫 → 兩個變體都進 payload，價格為覆寫價
	 *
	 * 這條就是站長回報的案例（#730 = 30/27、#731 = 50/45）的最小重現。
	 *
	 * @test
	 * @group happy
	 * @group smoke
	 */
	public function test_priceless_variations_with_override_appear_with_override_prices(): void {
		[ $product, $ids ] = $this->create_priceless_variable_product();

		$products = ProfitShopFrontData::build_products(
			$this->make_shop(
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$ids[0] => new VariationOverride( new PriceOverride( '30', '27', null ), new InflatedCount( 0 ) ),
						$ids[1] => new VariationOverride( new PriceOverride( '50', '45', null ), new InflatedCount( 0 ) ),
					]
				)
			)
		);

		$this->assertCount( 1, $products, '應輸出一個商品' );
		$variations = $products[0]['variations'];
		$this->assertCount( 2, $variations, '兩個無定價變體都應被救回 payload（修法前這裡是 0）' );

		$by_id = [];
		foreach ( $variations as $variation ) {
			$by_id[ (int) $variation['variation_id'] ] = $variation;
		}

		$this->assertSame( '30', (string) $by_id[ $ids[0] ]['regularPrice'] );
		$this->assertSame( '27', (string) $by_id[ $ids[0] ]['salesPrice'] );
		$this->assertSame( '50', (string) $by_id[ $ids[1] ]['regularPrice'] );
		$this->assertSame( '45', (string) $by_id[ $ids[1] ]['salesPrice'] );
	}

	/**
	 * 【R-4】只設商品層覆寫時，站長從未定價的半成品變體**不得**出現在 payload
	 *
	 * ⚠️ 這條測試在第 4 輪是反向的（`test_parent_override_rescues_all_priceless_variations`）。
	 * 第 5 輪把「商品層覆寫連半成品變體一起上架」判為錯誤並反轉，理由見
	 * `OverridePriceFilters::resolve_for_product()` 的 R-4 註解。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_parent_override_does_not_expose_priceless_variations(): void {
		[ $product ] = $this->create_priceless_variable_product();

		$products = ProfitShopFrontData::build_products(
			$this->make_shop(
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( '500', null, null ),
					inflated_count: new InflatedCount( 0 )
				)
			)
		);

		$this->assertSame(
			[],
			$products[0]['variations'],
			'商品層覆寫不得讓站長從未定價的半成品變體變成可購買商品'
		);
	}

	/**
	 * 【F-2】`variable-subscription` 也必須被當成變體商品處理
	 *
	 * 舊版 `'variable' === $type` 的嚴格字串比較讓這個型別完全落在守門之外：
	 * 卡片價格區塊印成空的 `<p>`、`data-ps-disabled="0"` 可點、Modal 開得起來卻沒有
	 * 任何屬性選項，而加入購物車按鈕是啟用的——按下去整頁 reload、什麼都沒發生、
	 * 沒有任何錯誤訊息。
	 *
	 * @test
	 * @group edge
	 */
	public function test_variable_subscription_variations_are_built(): void {
		if ( ! class_exists( '\WC_Product_Variable_Subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未啟用，無法建立 variable-subscription 商品' );
		}

		$parent = new \WC_Product_Variable_Subscription();
		$parent->set_name( 'F-2 訂閱可變商品' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_status( 'publish' );
		$variation->set_regular_price( '888' );
		$variation->save();

		$reloaded = \wc_get_product( $parent->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $reloaded );
		$this->assertSame( 'variable-subscription', (string) $reloaded->get_type(), '前置條件：型別應為 variable-subscription' );

		$products = ProfitShopFrontData::build_products(
			$this->make_shop(
				new OverrideItem(
					product_id: $parent->get_id(),
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 )
				)
			)
		);

		$this->assertArrayHasKey( 'variations', $products[0], 'variable-subscription 也必須組出 variations' );
		$this->assertCount( 1, $products[0]['variations'], '應含那一個有定價的變體' );
		$this->assertSame( '888', (string) $products[0]['variations'][0]['regularPrice'] );
	}

	// ========== Edge：不該被救的維持排除 ==========

	/**
	 * 無定價且完全沒有覆寫 → variations 仍為空（合法的不可購買狀態）
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_priceless_variations_without_override_are_excluded(): void {
		[ $product ] = $this->create_priceless_variable_product();

		$products = ProfitShopFrontData::build_products(
			$this->make_shop(
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 )
				)
			)
		);

		$this->assertSame( [], $products[0]['variations'], '沒有任何覆寫時不得憑空產生可購買變體' );
	}

	/**
	 * 只覆寫其中一個變體 → payload 只含那一個
	 *
	 * 另一個變體在 Modal 會落進既有的第三態「此組合無法購買，請改選其他組合」，
	 * 那句話在這個情況下是**正確且誠實**的。
	 *
	 * @test
	 * @group edge
	 */
	public function test_partial_override_only_exposes_the_covered_variation(): void {
		[ $product, $ids ] = $this->create_priceless_variable_product();

		$products = ProfitShopFrontData::build_products(
			$this->make_shop(
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$ids[0] => new VariationOverride( new PriceOverride( '30', null, null ), new InflatedCount( 0 ) ),
					]
				)
			)
		);

		$variations = $products[0]['variations'];
		$this->assertCount( 1, $variations, '只有被覆寫的那個變體應進 payload' );
		$this->assertSame( $ids[0], (int) $variations[0]['variation_id'] );
	}

	/**
	 * 【回歸】一般有定價的變體行為完全不變
	 *
	 * @test
	 * @group happy
	 * @group smoke
	 */
	public function test_priced_variations_are_unaffected(): void {
		$product = $this->createVariableProduct(
			[
				'variations' => [
					[
						'regular_price' => '444',
						'sale_price'    => '400',
					],
					[ 'regular_price' => '888' ],
				],
			]
		);

		$products = ProfitShopFrontData::build_products(
			$this->make_shop(
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 )
				)
			)
		);

		$variations = $products[0]['variations'];
		$this->assertCount( 2, $variations, '有定價的變體應照舊全數輸出' );

		$prices = array_map( static fn( $v ) => (string) $v['regularPrice'], $variations );
		sort( $prices );
		$this->assertSame( [ '444', '888' ], $prices, '未設覆寫時價格應完全維持商品原值' );
	}

	// ========== Security：filter 零殘留 ==========

	/**
	 * 🔴 build_products() 回來之後不得殘留任何價格 filter
	 *
	 * 殘留等於讓一組未經 HMAC 簽章保護的覆寫價在同一 request 的購物車階段常駐生效，
	 * 會抵銷 CartPriceOverrideHook 在 priority 999 驗章失敗時 fallback 回原價的保護。
	 *
	 * @test
	 * @group security
	 * @group smoke
	 */
	public function test_build_products_leaves_no_price_filter_registered(): void {
		[ $product, $ids ] = $this->create_priceless_variable_product();

		ProfitShopFrontData::build_products(
			$this->make_shop(
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$ids[0] => new VariationOverride( new PriceOverride( '30', '27', null ), new InflatedCount( 0 ) ),
					]
				)
			)
		);

		$this->assertPricelessVariationNotRescued( $ids[0] );
	}

	/**
	 * get_available_variations() 內部丟例外時，filter 一樣必須被解除（try/finally）
	 *
	 * 這是「用完再解」與「finally」的差別：WooCommerce 在取變體清單的過程中會跑
	 * `woocommerce_hide_invisible_variations` 等第三方 filter，任何一個丟例外都會
	 * 跳過正常路徑，殘留的價格 filter 會一路帶到購物車階段。
	 *
	 * @test
	 * @group security
	 * @group error
	 */
	public function test_filters_are_disarmed_even_when_variation_lookup_throws(): void {
		[ $product, $ids ] = $this->create_priceless_variable_product();

		$exploder = static function () {
			throw new \RuntimeException( '第三方 filter 爆炸' );
		};
		\add_filter( 'woocommerce_hide_invisible_variations', $exploder, 10, 3 );

		$thrown = false;
		try {
			ProfitShopFrontData::build_products(
				$this->make_shop(
					new OverrideItem(
						product_id: $product->get_id(),
						override: new PriceOverride( null, null, null ),
						inflated_count: new InflatedCount( 0 ),
						variations: [
							$ids[0] => new VariationOverride( new PriceOverride( '30', '27', null ), new InflatedCount( 0 ) ),
						]
					)
				)
			);
		} catch ( \RuntimeException $e ) {
			$thrown = true;
		} finally {
			\remove_filter( 'woocommerce_hide_invisible_variations', $exploder, 10 );
		}

		$this->assertTrue( $thrown, '前置條件：例外應如預期往外拋（防假綠燈）' );
		$this->assertPricelessVariationNotRescued( $ids[0] );
	}

	// ========== Helpers ==========

	/**
	 * 斷言某個無定價變體目前**沒有**被任何殘留 filter 補上價格
	 *
	 * @param int $variation_id 變體 ID
	 *
	 * @return void
	 */
	private function assertPricelessVariationNotRescued( int $variation_id ): void {
		$variation = \wc_get_product( $variation_id );
		$this->assertInstanceOf( \WC_Product_Variation::class, $variation, '防空斷言：變體應可載入' );

		$this->assertSame(
			'',
			(string) $variation->get_price(),
			'build_products() 之後價格必須回到空字串，代表 filter 已完全解除'
		);
		$this->assertFalse(
			$variation->is_purchasable(),
			'build_products() 之後不得有任何殘留 filter 讓無定價變體變成可購買'
		);
	}

	/**
	 * 建立「兩個變體都沒有任何定價」的可變商品
	 *
	 * @return array{0: \WC_Product_Variable, 1: array<int, int>} [ 父商品, 變體 ID 清單 ]
	 */
	private function create_priceless_variable_product(): array {
		$product = $this->createVariableProduct(
			[
				'variations' => [
					[ 'regular_price' => '' ],
					[ 'regular_price' => '' ],
				],
			]
		);

		$ids = array_values( array_map( 'intval', $product->get_children() ) );
		$this->assertCount( 2, $ids, '測試前置：應建立兩個變體' );

		return [ $product, $ids ];
	}

	/**
	 * 組出一個只含單一 item 的 ProfitShop（不落地，build_products 只讀記憶體物件）
	 *
	 * @param OverrideItem $item 商品覆寫項目
	 */
	private function make_shop( OverrideItem $item ): ProfitShop {
		++self::$slug_seq;

		return new ProfitShop(
			id: 90000 + self::$slug_seq,
			title: '變體 payload 測試賣場',
			slug: 'front-data-variations-' . self::$slug_seq,
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: 1,
			rate: new ProfitRate( 10 ),
			items: [ $item ],
			settings: []
		);
	}
}
