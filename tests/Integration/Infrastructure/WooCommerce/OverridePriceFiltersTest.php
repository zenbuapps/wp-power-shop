<?php
/**
 * OverridePriceFilters 整合測試（2026-08：救回「商品自身無定價」的變體）
 *
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/OverridePriceFilters.php
 *
 * 驗證重點：
 *   1. arm 之後，「自身無定價但賣場有覆寫」的變體，variation_is_visible() /
 *      is_purchasable() 由 false 翻成 true——這是 WooCommerce 四道閘門的共同條件
 *      （'' !== get_price()），修它一次四道全開
 *   2. 「自身無定價且完全沒有覆寫」的變體維持 false（合法的不可購買狀態，不得被誤放行）
 *   3. disarm 之後 filter 全部解除、價格完整回到原值（零殘留）——這條是安全性關鍵：
 *      filter 若殘留到同一 request 的購物車階段，等於讓未經簽章保護的價格常駐生效
 *   4. 重複 arm 不留下上一組情境
 *
 * @group profit_shop
 * @group infrastructure
 * @group woocommerce
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\OverridePriceResolver;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\VariationOverride;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\OverridePriceFilters;
use Tests\Integration\TestCase;

/**
 * 覆寫價 filter 組整合測試
 */
final class OverridePriceFiltersTest extends TestCase {

	/**
	 * 本測試 arm 過的實例（tear_down 一律 disarm，避免污染其他測試）
	 *
	 * @var OverridePriceFilters[]
	 */
	private array $armed = [];

	public function tear_down(): void {
		foreach ( $this->armed as $filters ) {
			$filters->disarm();
		}
		$this->armed = [];

		parent::tear_down();
	}

	// ========== Happy：無定價變體被救回來 ==========

	/**
	 * 無定價變體 + 變體層覆寫 → 可見且可購買，價格為覆寫價
	 *
	 * 這正是站長回報的情境：後台「目前價格」是空的、覆寫價設好了，
	 * 但前台連價格都不顯示、Modal 永遠停在「此組合無法購買」。
	 *
	 * @test
	 * @group happy
	 * @group smoke
	 */
	public function test_armed_filters_make_priceless_variation_visible_and_purchasable(): void {
		[ $product, $variation_ids ] = $this->create_priceless_variable_product();
		$vid                         = $variation_ids[0];

		$before = \wc_get_product( $vid );
		$this->assertInstanceOf( \WC_Product_Variation::class, $before, '防空斷言：變體應可載入' );
		$this->assertFalse( $before->variation_is_visible(), '前置條件：無定價變體本來就不可見' );
		$this->assertFalse( $before->is_purchasable(), '前置條件：無定價變體本來就不可購買' );

		$this->arm_for(
			$product->get_id(),
			[
				$vid => new VariationOverride( new PriceOverride( '30', '27', null ), new InflatedCount( 0 ) ),
			]
		);

		$after = \wc_get_product( $vid );
		$this->assertInstanceOf( \WC_Product_Variation::class, $after );

		$this->assertSame( '27', (string) $after->get_price(), 'get_price() 應為覆寫特價' );
		$this->assertSame( '30', (string) $after->get_regular_price(), 'get_regular_price() 應為覆寫原價' );
		$this->assertTrue( $after->variation_is_visible(), 'arm 後應通過 class-wc-product-variable.php:335 的可見性過濾' );
		$this->assertTrue( $after->is_purchasable(), 'arm 後應通過 class-wc-cart.php:1229 的 is_purchasable 檢查' );
	}

	/**
	 * 【R-4】只有商品層覆寫時，**站長從未定價的半成品變體不得**變成商品
	 *
	 * ⚠️ 這條測試在第 4 輪是反向的（`test_parent_level_override_rescues_priceless_variation`，
	 * 斷言商品層覆寫會把無定價變體整組救回來）。第 5 輪把那個行為判為**錯誤**並反轉：
	 *
	 * 站長在賣場設「商品層」覆寫的意圖是「這個商品在我的賣場賣 X 元」，不會預期連沒填價格
	 * 的半成品變體都一起上架（實測案例：變體 17 有價、變體 18 是空的半成品，只設商品層
	 * 覆寫 500 就讓 18 以 500 元進到前台 payload 並可購買）。ItemsEditor 的展開列本來就
	 * 提供逐變體設定的入口，要救就明確設變體層覆寫。
	 *
	 * ⚠️ 這**只**影響「變體自身無定價」的情況，見下一條測試。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_parent_level_override_does_not_rescue_priceless_variation(): void {
		[ $product, $variation_ids ] = $this->create_priceless_variable_product();

		$this->arm_for( $product->get_id(), [], new PriceOverride( '500', null, null ) );

		foreach ( $variation_ids as $vid ) {
			$variation = \wc_get_product( $vid );
			$this->assertInstanceOf( \WC_Product_Variation::class, $variation );
			$this->assertSame( '', (string) $variation->get_price(), '站長從未定價的變體不得因商品層覆寫而取得價格' );
			$this->assertFalse( $variation->variation_is_visible(), '同上：不得變成前台可見' );
			$this->assertFalse( $variation->is_purchasable(), '同上：不得變成可購買' );
		}
	}

	/**
	 * 【R-4 邊界】變體自身**有**定價時，商品層 fallback 語意完全不變
	 *
	 * 這條是上一條的對照組，也是「R-4 守門沒有誤傷 Phase 13 唯一取價真相」的直接證據：
	 * OverridePriceResolver 的「變體層沒設就退回商品層」在變體有價時一個字都沒有變。
	 *
	 * @test
	 * @group happy
	 */
	public function test_parent_level_override_still_applies_to_priced_variation(): void {
		$product = $this->createVariableProduct(
			[
				'variations' => [
					[ 'regular_price' => '100' ],
					[ 'regular_price' => '200' ],
				],
			]
		);

		$this->arm_for( $product->get_id(), [], new PriceOverride( '500', null, null ) );

		foreach ( $product->get_children() as $vid ) {
			$variation = \wc_get_product( (int) $vid );
			$this->assertInstanceOf( \WC_Product_Variation::class, $variation );
			$this->assertSame( '500', (string) $variation->get_price(), '有定價的變體仍應整組退回商品層覆寫價' );
			$this->assertTrue( $variation->is_purchasable(), '有定價的變體本來就可購買' );
		}
	}

	/**
	 * 【F-1】覆寫值改變時，變體價格快取的 hash 必須跟著改變
	 *
	 * WC 把變體價格陣列存在 transient，hash 來源只有「掛在 woocommerce_variation_prices_*
	 * 上的 callback 名稱」（abstract-wc-product-data-store-cpt.php 的 get_price_hash()）；
	 * 而站長改的是**賣場** post 的 _profit_shop_items，不會觸發 WooCommerce 對**商品**
	 * transient 的任何清除 ⇒ 只放 shop_id 的話，改完覆寫價，單品頁的價格區間會繼續顯示
	 * 已不存在的舊價格（實測踩到）。
	 *
	 * @test
	 * @group edge
	 */
	public function test_variation_prices_hash_changes_when_override_value_changes(): void {
		$product = $this->createVariableProduct( [ 'variations' => [ [ 'regular_price' => '100' ] ] ] );

		$first  = $this->arm_for( $product->get_id(), [], new PriceOverride( '500', null, null ) );
		$hash_a = $first->filter_variation_prices_hash( [ 'base' ], $product );
		$first->disarm();

		$second = $this->arm_for( $product->get_id(), [], new PriceOverride( '600', null, null ) );
		$hash_b = $second->filter_variation_prices_hash( [ 'base' ], $product );

		$this->assertNotEquals( $hash_a, $hash_b, '覆寫值改變時 hash 必須落到不同的快取分桶' );
	}

	// ========== Edge / Security：不該被救的維持不可購買 ==========

	/**
	 * 無定價且完全沒有覆寫 → 仍然不可見、不可購買
	 *
	 * 這是**合法**的不可購買狀態（站長沒替它設價，商家本來就沒打算賣它）。
	 * 誤放行會讓一個 0 元品項進到購物車。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_priceless_variation_without_override_stays_unpurchasable(): void {
		[ $product, $variation_ids ] = $this->create_priceless_variable_product();

		// 只覆寫第一個變體，第二個刻意留空
		$this->arm_for(
			$product->get_id(),
			[
				$variation_ids[0] => new VariationOverride( new PriceOverride( '30', null, null ), new InflatedCount( 0 ) ),
			]
		);

		$covered = \wc_get_product( $variation_ids[0] );
		$naked   = \wc_get_product( $variation_ids[1] );
		$this->assertInstanceOf( \WC_Product_Variation::class, $covered );
		$this->assertInstanceOf( \WC_Product_Variation::class, $naked );

		$this->assertTrue( $covered->is_purchasable(), '有覆寫的變體應可購買（對照組，防假綠燈）' );

		$this->assertSame( '', (string) $naked->get_price(), '沒有覆寫的無定價變體，價格必須維持空字串' );
		$this->assertFalse( $naked->variation_is_visible(), '沒有覆寫的無定價變體不得變成可見' );
		$this->assertFalse( $naked->is_purchasable(), '沒有覆寫的無定價變體不得變成可購買' );
	}

	/**
	 * disarm 後所有 filter 解除、價格零殘留
	 *
	 * 🔴 這是本次改動最重要的安全性斷言：filter 若殘留到同一 request 的購物車階段，
	 * 等於讓一組未經 HMAC 簽章保護的覆寫價常駐生效，會抵銷 CartPriceOverrideHook
	 * 在 priority 999 驗章失敗時 fallback 回原價的保護。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_disarm_removes_every_filter_and_restores_original_prices(): void {
		$product = $this->createVariableProduct(
			[
				'variations' => [
					[ 'regular_price' => '100' ],
					[ 'regular_price' => '200' ],
				],
			]
		);
		$vid     = (int) $product->get_children()[0];

		$filters = new OverridePriceFilters( new OverridePriceResolver() );
		$filters->arm(
			1234,
			$product->get_id(),
			new OverrideItem(
				product_id: $product->get_id(),
				override: new PriceOverride( '999', null, null ),
				inflated_count: new InflatedCount( 0 )
			)
		);

		$this->assertTrue( $filters->is_armed(), '前置條件：應已 arm' );
		$armed = \wc_get_product( $vid );
		$this->assertInstanceOf( \WC_Product_Variation::class, $armed );
		$this->assertSame( '999', (string) $armed->get_price(), '前置條件：arm 後價格應被覆寫（防假綠燈）' );

		$filters->disarm();

		$this->assertFalse( $filters->is_armed(), 'disarm 後不得仍處於 arm 狀態' );

		foreach ( self::EXPECTED_HOOKS as $hook => $method ) {
			$this->assertFalse(
				\has_filter( $hook, [ $filters, $method ] ),
				\sprintf( 'disarm 後 %s 不得殘留 %s', $hook, $method )
			);
		}

		$restored = \wc_get_product( $vid );
		$this->assertInstanceOf( \WC_Product_Variation::class, $restored );
		$this->assertSame( '100', (string) $restored->get_price(), 'disarm 後價格必須完整回到商品原值' );
	}

	/**
	 * 重複 arm 不留下上一組情境（不支援巢狀）
	 *
	 * @test
	 * @group edge
	 */
	public function test_re_arm_replaces_previous_context(): void {
		$first  = $this->createVariableProduct( [ 'variations' => [ [ 'regular_price' => '100' ] ] ] );
		$second = $this->createVariableProduct( [ 'variations' => [ [ 'regular_price' => '200' ] ] ] );

		$first_vid  = (int) $first->get_children()[0];
		$second_vid = (int) $second->get_children()[0];

		$filters       = new OverridePriceFilters( new OverridePriceResolver() );
		$this->armed[] = $filters;

		$filters->arm( 1, $first->get_id(), $this->item_with( $first->get_id(), new PriceOverride( '11', null, null ) ) );
		$filters->arm( 2, $second->get_id(), $this->item_with( $second->get_id(), new PriceOverride( '22', null, null ) ) );

		$first_variation  = \wc_get_product( $first_vid );
		$second_variation = \wc_get_product( $second_vid );
		$this->assertInstanceOf( \WC_Product_Variation::class, $first_variation );
		$this->assertInstanceOf( \WC_Product_Variation::class, $second_variation );

		$this->assertSame( '22', (string) $second_variation->get_price(), '第二次 arm 的情境應生效' );
		$this->assertSame( '100', (string) $first_variation->get_price(), '第一次 arm 的情境不得殘留' );

		// 重複 arm 不得讓同一個 callback 被註冊兩次（否則 disarm 一次解不乾淨）
		$filters->disarm();
		$this->assertFalse(
			\has_filter( 'woocommerce_product_variation_get_price', [ $filters, 'filter_price' ] ),
			'重複 arm 之後單次 disarm 必須完全解除'
		);
	}

	// ========== Helpers ==========

	/**
	 * 預期會被註冊 / 解除的 hook 對照表（與 production 的 FILTER_MAP 逐字對應）
	 *
	 * @var array<string, string>
	 */
	private const EXPECTED_HOOKS = [
		'woocommerce_product_get_price'                   => 'filter_price',
		'woocommerce_product_get_regular_price'           => 'filter_regular_price',
		'woocommerce_product_get_sale_price'              => 'filter_sale_price',
		'woocommerce_product_variation_get_price'         => 'filter_price',
		'woocommerce_product_variation_get_regular_price' => 'filter_regular_price',
		'woocommerce_product_variation_get_sale_price'    => 'filter_sale_price',
		'woocommerce_variation_prices_price'              => 'filter_variation_prices_price',
		'woocommerce_variation_prices_regular_price'      => 'filter_variation_prices_regular_price',
		'woocommerce_variation_prices_sale_price'         => 'filter_variation_prices_sale_price',
		'woocommerce_get_variation_prices_hash'           => 'filter_variation_prices_hash',
	];

	/**
	 * 建立「兩個變體都沒有任何定價」的可變商品
	 *
	 * 這正是站長站台上 #729 TEST 的形狀：變體存在、屬性存在，但 `_price` 是空的。
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

		$ids = array_map( 'intval', $product->get_children() );
		$this->assertCount( 2, $ids, '測試前置：應建立兩個變體' );

		return [ $product, array_values( $ids ) ];
	}

	/**
	 * arm 一組 filter（並登記到 tear_down 的解除清單）
	 *
	 * @param int                            $product_id       父商品 ID
	 * @param array<int, VariationOverride>  $variations       變體覆寫
	 * @param PriceOverride|null             $parent_override  商品層覆寫（預設無）
	 */
	private function arm_for( int $product_id, array $variations, ?PriceOverride $parent_override = null ): OverridePriceFilters {
		$filters       = new OverridePriceFilters( new OverridePriceResolver() );
		$this->armed[] = $filters;

		$filters->arm(
			4321,
			$product_id,
			new OverrideItem(
				product_id: $product_id,
				override: $parent_override ?? new PriceOverride( null, null, null ),
				inflated_count: new InflatedCount( 0 ),
				variations: $variations
			)
		);

		return $filters;
	}

	/**
	 * 產生只有商品層覆寫的 OverrideItem
	 *
	 * @param int           $product_id 商品 ID
	 * @param PriceOverride $override   商品層覆寫
	 */
	/**
	 * 🔴 不變量：顯示層的價格 filter 永遠不得影響任何購物車品項
	 *
	 * 對應規格：apply-single-product-override-price.feature「隔離（購物車）」
	 *          authorize-priceless-cart-item.feature「不變量（隔離）」
	 *
	 * ── 這條測試在 2026-08-11 之前不存在，而它守的是一條真實漏洞（TD-62）──
	 *
	 * `SingleProductPriceHook` 在 template_redirect prio 20 掛上 filter 之後
	 * **整個 request 都不解除**，而購物車與商品頁**在同一個 request 裡並存**：
	 * 佈景主題 / 外掛（mini-cart fragments、免運進度條、幣別切換器）只要在渲染期
	 * 再呼叫一次 calculate_totals()，一筆**完全沒有 _profit_* meta、沒有簽章**的
	 * cart item 就會被套上覆寫價（文件實測 1000 → 27）——整條 HMAC 保護被繞過。
	 *
	 * 曾經的安全論證是「購物車**請求**不會走到顯示層」，那個前提是「請求類型」，
	 * 而請求類型不是隔離邊界。正解是 `is_cart_item_object()` 的**物件身分**比對：
	 * `WC_Product_Factory::get_product()` 每次都 `new`、不做實例快取 ⇒ 購物車裡的
	 * 實例與單品頁拿到的必然是不同物件，零誤判。
	 *
	 * ⚠️ 實測：把那道守門中性化之後，**415 條 Integration 測試全部通過**。
	 * 這條測試存在的唯一理由就是讓那件事不再可能。
	 *
	 * @test
	 * @group security
	 */
	public function test_armed_filters_never_touch_a_cart_item_object(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );

		\WC()->cart->empty_cart();
		$key = \WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertIsString( $key, '測試前置：商品應加入購物車' );

		// 購物車品項**自己的** WC_Product 實例（與上面那個 $product 是不同物件）
		$cart_object = \WC()->cart->cart_contents[ $key ]['data'];
		$this->assertNotSame(
			$product,
			$cart_object,
			'測試前提：wc_get_product() 不做實例快取，購物車拿到的必須是另一個物件'
		);

		$filters = new OverridePriceFilters( new OverridePriceResolver() );
		$filters->arm(
			1932,
			$product->get_id(),
			$this->item_with( $product->get_id(), new PriceOverride( null, '27', null ) )
		);

		try {
			// 購物車品項：即使 filter 已 armed、product_id 也完全相同，一律不得被改價
			$this->assertSame(
				'1000',
				(string) $filters->filter_price( '1000', $cart_object ),
				'🔴 購物車品項的價格由 CartPriceOverrideHook + HMAC 簽章獨佔決定，顯示層 filter 不得插手'
			);
			$this->assertSame(
				'1000',
				(string) $filters->filter_regular_price( '1000', $cart_object )
			);
			$this->assertSame(
				'1000',
				(string) $filters->filter_sale_price( '1000', $cart_object )
			);

			// 對照組：**同一個商品**在單品頁（另一個實例）仍必須套用覆寫價。
			// 兩者必須同時成立，才證明判準是「物件身分」而不是 product ID——
			// 用 ID 比對會把單品頁一起排掉，等於廢掉整個 Phase 9。
			$display_object = \wc_get_product( $product->get_id() );
			$this->assertSame(
				'27',
				(string) $filters->filter_price( '1000', $display_object ),
				'單品頁的實例仍必須套用覆寫價（否則判準退化成 product ID 比對）'
			);
		} finally {
			$filters->disarm();
			\WC()->cart->empty_cart();
		}
	}

	/**
	 * 購物車內有**多筆**品項時，每一筆都受保護
	 *
	 * 防止「只比對第一筆」這種退化：`is_cart_item_object()` 是 foreach 全部 cart_contents，
	 * 改成只看某一筆同樣不會被上面那條測試抓到。
	 *
	 * @test
	 * @group security
	 */
	public function test_every_cart_item_is_protected_not_just_the_first(): void {
		$a = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$b = $this->createSimpleProduct( [ 'regular_price' => '2000' ] );

		\WC()->cart->empty_cart();
		$key_a = \WC()->cart->add_to_cart( $a->get_id(), 1 );
		$key_b = \WC()->cart->add_to_cart( $b->get_id(), 1 );
		$this->assertIsString( $key_a );
		$this->assertIsString( $key_b );

		$filters = new OverridePriceFilters( new OverridePriceResolver() );
		$filters->arm( 1932, $b->get_id(), $this->item_with( $b->get_id(), new PriceOverride( null, '27', null ) ) );

		try {
			// 第二筆（非第一筆）同樣不得被改價
			$this->assertSame(
				'2000',
				(string) $filters->filter_price( '2000', \WC()->cart->cart_contents[ $key_b ]['data'] ),
				'購物車第二筆品項同樣必須受保護'
			);
		} finally {
			$filters->disarm();
			\WC()->cart->empty_cart();
		}
	}

	private function item_with( int $product_id, PriceOverride $override ): OverrideItem {
		return new OverrideItem(
			product_id: $product_id,
			override: $override,
			inflated_count: new InflatedCount( 0 )
		);
	}
}
