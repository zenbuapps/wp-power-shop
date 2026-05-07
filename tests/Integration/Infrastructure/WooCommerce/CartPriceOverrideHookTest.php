<?php
/**
 * CartPriceOverrideHook 整合測試（Phase 3-D 紅燈 — Task T-8）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3、§5、§6（AddToCart Hook + 防竄改）
 * 對應實作（待綠燈建立）：
 *   inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/CartPriceOverrideHook.php
 *
 * 此 hook 為 Phase 3-D **最高風險** 元件之一——前台 cart 寫入會影響真實訂單金額。
 *
 * 紅燈合約：
 *   - final class CartPriceOverrideHook（含 SingletonTrait::instance()）
 *   - constructor 注入 ProfitShopRepositoryInterface + CartPriceSignatureService
 *   - 在非 admin（或 admin 但 wp_doing_ajax）時，於 constructor 註冊 3 個 WC filter：
 *       1. woocommerce_add_cart_item_data（priority 10）
 *          → 從 $cart_item_data['profit_shop_id'] 解析賣場、寫入 4 筆 cart_item meta：
 *              _profit_shop_id / _profit_partner_term_id /
 *              _profit_price_override / _profit_signature
 *       2. woocommerce_get_cart_item_from_session（priority 10）
 *          → session round-trip 後重新將 4 筆 meta 套回 cart_item（若 session 已含）
 *       3. woocommerce_before_calculate_totals（priority 999）
 *          → 對含 _profit_signature 的 cart_item 驗章；通過則 set_price，
 *            失敗則 fallback 並寫 error_log（不告知前端避免 oracle）
 *
 *   - effective price = sale_price 優先，否則 regular_price（與 PriceCalculator 對齊）
 *
 * 紅燈狀態：
 *   CartPriceOverrideHook class 尚未存在 → autoload 找不到 → fatal / class not found
 *   所有測試直接 fail。下一棒（@zenbu-powers:wordpress-master）負責綠燈實作。
 *
 * @group profit_shop
 * @group infrastructure
 * @group woocommerce
 * @group cart
 * @group security
 * @group phase_3d
 */

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\CartPriceOverrideHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpSaltProvider;
use Tests\Integration\TestCase;

/**
 * CartPriceOverrideHook 紅燈整合測試
 *
 * 透過真實 WC()->cart 與 ProfitShop CPT 驗證：
 *   1. add_to_cart 寫入 4 筆 _profit_* meta（含 signature）
 *   2. before_calculate_totals 驗章後 set_price 為 override 價
 *   3. signature 被竄改 → fallback 原價（並寫 error_log）
 *   4. session round-trip → meta 不流失
 *   5. admin context guard：純 admin 不註冊 hook，admin-ajax 才註冊
 *   6. 多商品多 shop 共存
 *   7. shop 不存在 / 商品不在 shop → 退回原價
 *   8. coupon / promotion 與 override 共存
 *   9. priority 999 確保我方 hook 後執行（不被其他 plugin 覆蓋）
 */
final class CartPriceOverrideHookTest extends TestCase {

	/**
	 * 簽章服務（共用 production WpSaltProvider，與 hook 內部一致）
	 */
	private CartPriceSignatureService $signature_service;

	/**
	 * Hook 實例
	 */
	private CartPriceOverrideHook $hook;

	/**
	 * 賣場 Repository
	 */
	private CptProfitShopRepository $repo;

	/**
	 * 預設 partner term id
	 *
	 * @var int
	 */
	private int $partner_term_id;

	/**
	 * setUp：建立 hook、清空 cart、建立預設 partner term
	 */
	public function set_up(): void {
		parent::set_up();

		$this->signature_service = new CartPriceSignatureService( new WpSaltProvider() );
		$this->repo              = CptProfitShopRepository::instance();

		// 紅燈：CartPriceOverrideHook 尚未存在 → 此行 instantiation 直接 fatal
		$this->hook = new CartPriceOverrideHook( $this->repo, $this->signature_service );

		// 確保 cart 為空
		if ( null !== \WC()->cart ) {
			\WC()->cart->empty_cart();
		}

		// 建立 partner term（每個測試共用）
		$term                  = \wp_insert_term( '測試夥伴 ' . uniqid(), 'profit_partner' );
		$this->partner_term_id = is_array( $term ) ? (int) $term['term_id'] : 0;
	}

	/**
	 * tearDown：清 cart、清 partner term、父類清 _profit_* meta
	 */
	public function tear_down(): void {
		if ( null !== \WC()->cart ) {
			\WC()->cart->empty_cart();
		}
		if ( $this->partner_term_id > 0 ) {
			\wp_delete_term( $this->partner_term_id, 'profit_partner' );
		}
		parent::tear_down();
	}

	// ====================================================================
	//  A. 基本 add-to-cart override
	// ====================================================================

	/**
	 * add_to_cart with profit_shop_id 應寫入 4 筆 _profit_* meta，且 signature 可被驗證通過
	 *
	 * @test
	 * @group happy
	 */
	public function test_add_to_cart_with_profit_shop_id_writes_override_meta_to_cart_item(): void {
		$product  = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id  = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		$this->assertNotEmpty( $cart_item_key, 'add_to_cart 應回傳合法的 cart_item_key' );

		$contents  = \WC()->cart->cart_contents;
		$cart_item = $contents[ $cart_item_key ];

		$this->assertSame( $shop_id, (int) $cart_item['_profit_shop_id'], '應寫入 _profit_shop_id' );
		$this->assertSame(
			$this->partner_term_id,
			(int) $cart_item['_profit_partner_term_id'],
			'應寫入 _profit_partner_term_id（來自 shop 的 partner_term_id）'
		);
		$this->assertSame( '888', (string) $cart_item['_profit_price_override'], '應寫入 effective override 價格' );
		$this->assertNotEmpty( $cart_item['_profit_signature'], '應寫入 _profit_signature' );

		// signature 必須通過驗證
		$valid = $this->signature_service->verify(
			$shop_id,
			$this->partner_term_id,
			'888',
			(string) $cart_item['_profit_signature']
		);
		$this->assertTrue( $valid, '寫入的 signature 必須能通過 verify' );
	}

	// ====================================================================
	//  B. before_calculate_totals 套用 override
	// ====================================================================

	/**
	 * signature 通過時，calculate_totals 後 product->get_price() 應為 override 價
	 *
	 * @test
	 * @group happy
	 */
	public function test_before_calculate_totals_sets_product_price_to_override_when_signature_valid(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		\WC()->cart->calculate_totals();

		$cart_item = \WC()->cart->cart_contents[ $cart_item_key ];
		$this->assertSame(
			'888',
			(string) $cart_item['data']->get_price(),
			'通過驗章的 cart_item，product->get_price() 應為 override 價'
		);
	}

	/**
	 * signature 被竄改 → 退回原價，且 error_log 應記錄 mismatch warning
	 *
	 * @test
	 * @group security
	 */
	public function test_before_calculate_totals_falls_back_to_original_when_signature_invalid(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		// 攻擊：把 _profit_price_override 從 888 改成 1（但 signature 沒變）
		\WC()->cart->cart_contents[ $cart_item_key ]['_profit_price_override'] = '1';

		// 攔截 error_log 輸出
		$log_file = tempnam( sys_get_temp_dir(), 'profit_shop_log_' );
		ini_set( 'error_log', $log_file );

		try {
			\WC()->cart->calculate_totals();

			$cart_item = \WC()->cart->cart_contents[ $cart_item_key ];
			$price     = (string) $cart_item['data']->get_price();

			$this->assertNotSame( '1', $price, '篡改後的價格絕不可生效' );
			$this->assertSame( '1200', $price, '應 fallback 至商品原價（regular_price）' );

			$logged = file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';
			$this->assertStringContainsString(
				'cart signature mismatch',
				$logged,
				'signature 失敗時必須寫 error_log 警告（含 "[ProfitShop] cart signature mismatch"）'
			);
		} finally {
			ini_restore( 'error_log' );
			if ( file_exists( $log_file ) ) {
				unlink( $log_file );
			}
		}
	}

	/**
	 * cart_item 含 _profit_shop_id 但 _profit_signature 缺失 → fallback 原價
	 *
	 * @test
	 * @group security
	 */
	public function test_before_calculate_totals_falls_back_to_original_when_signature_missing(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		// 攻擊：把 _profit_signature 整個移除，但 _profit_price_override 保留
		unset( \WC()->cart->cart_contents[ $cart_item_key ]['_profit_signature'] );

		\WC()->cart->calculate_totals();

		$cart_item = \WC()->cart->cart_contents[ $cart_item_key ];
		$this->assertSame(
			'1200',
			(string) $cart_item['data']->get_price(),
			'缺 signature 時應 fallback 至原價'
		);
	}

	// ====================================================================
	//  C. session round-trip
	// ====================================================================

	/**
	 * cart_item meta 應在 session 序列化 → 反序列化後仍生效
	 *
	 * @test
	 * @group happy
	 */
	public function test_cart_item_meta_survives_session_serialization(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		// 模擬 session round-trip：把 cart 持久化（set_session）→ 清記憶體 → 重載
		\WC()->cart->set_session();
		$snapshot = \WC()->session->get( 'cart' );
		$this->assertIsArray( $snapshot );
		$this->assertArrayHasKey( $cart_item_key, $snapshot );
		$this->assertArrayHasKey(
			'_profit_signature',
			$snapshot[ $cart_item_key ],
			'session 中的 cart_item 應保留 _profit_signature'
		);

		// 模擬「下個 request」：丟掉現有 cart 物件，建立新的，從 session reload
		// （避免使用 empty_cart(false) 這種會被 WC destroy_cart_session 副作用影響的繞道）
		\WC()->cart = new \WC_Cart();
		\WC()->cart->get_cart_from_session();

		$reloaded = \WC()->cart->cart_contents[ $cart_item_key ] ?? null;
		$this->assertNotNull( $reloaded, 'session 重載後 cart_item 應仍存在' );
		$this->assertSame( $shop_id, (int) $reloaded['_profit_shop_id'] );
		$this->assertNotEmpty( $reloaded['_profit_signature'] );

		\WC()->cart->calculate_totals();
		$this->assertSame(
			'888',
			(string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_price(),
			'session round-trip 後 override 仍生效'
		);
	}

	// ====================================================================
	//  D. admin context guard
	// ====================================================================

	/**
	 * 純 admin context（非 ajax）時，hook 不應註冊 before_calculate_totals
	 *
	 * @test
	 * @group edge
	 */
	public function test_hook_does_not_register_in_admin_context(): void {
		// 先把 setUp 註冊的 hook 卸掉
		\remove_all_actions( 'woocommerce_before_calculate_totals' );
		\remove_all_filters( 'woocommerce_add_cart_item_data' );

		\set_current_screen( 'edit-post' ); // 進入 admin context（is_admin() === true）

		try {
			// 在 admin context 重新建立 hook
			new CartPriceOverrideHook( $this->repo, $this->signature_service );

			$this->assertFalse(
				\has_action( 'woocommerce_before_calculate_totals' ) !== false
				&& \has_action( 'woocommerce_before_calculate_totals' ) !== 0,
				'純 admin context（非 ajax）時不應註冊 before_calculate_totals'
			);
		} finally {
			\set_current_screen( 'front' );
		}
	}

	/**
	 * admin-ajax context（是 admin 但 wp_doing_ajax() === true）時，hook 仍需註冊
	 *
	 * 理由：wp-ajax 加入購物車（add_to_cart_ajax）會走 admin endpoint，
	 *       若 guard 太嚴會讓 ajax 加購完全沒套 override。
	 *
	 * @test
	 * @group edge
	 */
	public function test_hook_registers_in_ajax_admin_context(): void {
		\remove_all_actions( 'woocommerce_before_calculate_totals' );
		\remove_all_filters( 'woocommerce_add_cart_item_data' );

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		\set_current_screen( 'edit-post' );

		try {
			new CartPriceOverrideHook( $this->repo, $this->signature_service );

			$this->assertNotFalse(
				\has_filter( 'woocommerce_add_cart_item_data' ),
				'admin-ajax context 時 hook 仍需註冊（讓 ajax 加購正常）'
			);
		} finally {
			\set_current_screen( 'front' );
		}
	}

	// ====================================================================
	//  E. 多商品 + 多 shop
	// ====================================================================

	/**
	 * 同一 cart 中含 shop_A.product_1（888）+ shop_B.product_2（666）→ 兩個 override 都正確
	 *
	 * @test
	 * @group happy
	 */
	public function test_multiple_cart_items_from_different_shops(): void {
		$product_1 = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$product_2 = $this->createSimpleProduct( [ 'regular_price' => '999' ] );

		$shop_a = $this->create_shop_with_item(
			$product_1->get_id(),
			new PriceOverride( null, '888', null )
		);
		$shop_b = $this->create_shop_with_item(
			$product_2->get_id(),
			new PriceOverride( null, '666', null )
		);

		$key_a = $this->add_to_cart_via_shop( $product_1->get_id(), $shop_a );
		$key_b = $this->add_to_cart_via_shop( $product_2->get_id(), $shop_b );

		\WC()->cart->calculate_totals();

		$this->assertSame( '888', (string) \WC()->cart->cart_contents[ $key_a ]['data']->get_price() );
		$this->assertSame( '666', (string) \WC()->cart->cart_contents[ $key_b ]['data']->get_price() );
	}

	/**
	 * 同一 product 來自不同 shop 應拆成兩筆 cart_item（spec §6.5）
	 *
	 * @test
	 * @group edge
	 */
	public function test_same_product_from_different_shops_treats_as_different_cart_items(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );

		$shop_a = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);
		$shop_b = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '666', null )
		);

		$key_a = $this->add_to_cart_via_shop( $product->get_id(), $shop_a );
		$key_b = $this->add_to_cart_via_shop( $product->get_id(), $shop_b );

		$this->assertNotSame( $key_a, $key_b, '同一商品來自不同賣場應拆成兩筆 cart_item' );
		$this->assertCount( 2, \WC()->cart->cart_contents, 'cart 應有 2 筆獨立 line item' );

		\WC()->cart->calculate_totals();

		$this->assertSame( '888', (string) \WC()->cart->cart_contents[ $key_a ]['data']->get_price() );
		$this->assertSame( '666', (string) \WC()->cart->cart_contents[ $key_b ]['data']->get_price() );
	}

	// ====================================================================
	//  F. shop 不存在 / 商品不在 shop
	// ====================================================================

	/**
	 * 帶不存在的 profit_shop_id 加入 cart → 不寫 _profit_* meta，照原價走
	 *
	 * @test
	 * @group error
	 */
	public function test_add_to_cart_with_nonexistent_profit_shop_id_falls_back_to_original_price(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );

		$cart_item_key = \WC()->cart->add_to_cart(
			$product->get_id(),
			1,
			0,
			[],
			[ 'profit_shop_id' => 99999 ] // 不存在
		);

		$this->assertNotEmpty( $cart_item_key );

		$cart_item = \WC()->cart->cart_contents[ $cart_item_key ];
		$this->assertArrayNotHasKey(
			'_profit_signature',
			$cart_item,
			'shop 不存在時不應寫入 signature'
		);

		\WC()->cart->calculate_totals();

		$this->assertSame(
			'1200',
			(string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_price(),
			'shop 不存在 → 走商品原價'
		);
	}

	/**
	 * 商品不在指定 shop 內 → 不套 override，照原價
	 *
	 * @test
	 * @group error
	 */
	public function test_add_to_cart_when_product_not_in_shop_falls_back(): void {
		$product_in_shop  = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$product_not_in   = $this->createSimpleProduct( [ 'regular_price' => '500' ] );

		$shop_id = $this->create_shop_with_item(
			$product_in_shop->get_id(),
			new PriceOverride( null, '888', null )
		);

		// 用 shop_id 加入「不在 shop 內」的商品
		$cart_item_key = \WC()->cart->add_to_cart(
			$product_not_in->get_id(),
			1,
			0,
			[],
			[ 'profit_shop_id' => $shop_id ]
		);

		$this->assertNotEmpty( $cart_item_key );
		$cart_item = \WC()->cart->cart_contents[ $cart_item_key ];

		$this->assertArrayNotHasKey(
			'_profit_signature',
			$cart_item,
			'商品不在 shop 內時不應寫入 signature'
		);

		\WC()->cart->calculate_totals();
		$this->assertSame(
			'500',
			(string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_price(),
			'商品不在 shop 內 → 原價'
		);
	}

	/**
	 * draft 狀態的 shop 不應套 override 價（攻擊者已知 shop_id 但 shop 為 draft → 偷看 partner 預覽價）
	 *
	 * @test
	 * @group security
	 */
	public function test_add_to_cart_with_draft_profit_shop_falls_back_to_original_price(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );

		// 直接建立 draft 狀態的 shop
		$shop = new ProfitShop(
			id: 0,
			title: '草稿賣場 ' . uniqid(),
			slug: 'draft-shop-' . uniqid(),
			status: 'draft',
			mode: ShopMode::PAGE,
			partner_term_id: $this->partner_term_id,
			rate: new ProfitRate( 20 ),
			items: [
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( null, '888', null ),
					inflated_count: new InflatedCount( 0 )
				),
			]
		);
		$shop_id = $this->repo->save( $shop );

		$cart_item_key = \WC()->cart->add_to_cart(
			$product->get_id(),
			1,
			0,
			[],
			[ 'profit_shop_id' => $shop_id ]
		);

		$this->assertNotEmpty( $cart_item_key );

		$cart_item = \WC()->cart->cart_contents[ $cart_item_key ];
		$this->assertArrayNotHasKey(
			'_profit_signature',
			$cart_item,
			'draft 狀態的 shop 不應寫入 signature'
		);
		$this->assertArrayNotHasKey(
			'_profit_price_override',
			$cart_item,
			'draft 狀態的 shop 不應寫入 price override'
		);

		\WC()->cart->calculate_totals();

		$this->assertSame(
			'1200',
			(string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_price(),
			'draft shop → 走商品原價（不洩漏 partner 預覽促銷價）'
		);
	}

	// ====================================================================
	//  G. promotion / coupon 共存（spec Q2=A 行為驗證）
	// ====================================================================

	/**
	 * 套用 10% off coupon 時，折扣應計算於 override 價之上（不是原價）
	 *
	 * 期望：override=888，10% off → small total = 799.20（容許浮點誤差）
	 *
	 * @test
	 * @group happy
	 */
	public function test_coupon_percentage_discount_applies_on_top_of_override(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);

		$this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		// 建立 10% off coupon
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'profit10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10.0 );
		$coupon->save();

		$applied = \WC()->cart->apply_coupon( 'profit10' );
		$this->assertTrue( $applied, '套用 coupon 必須成功' );

		\WC()->cart->calculate_totals();

		$discount_total = (float) \WC()->cart->get_discount_total();
		$this->assertEqualsWithDelta(
			88.8,
			$discount_total,
			0.01,
			'10% 折扣應作用於 override 價（888 × 10% = 88.8），而非原價（1200 × 10%）'
		);

		// 清掉 coupon 避免污染其他測試
		\WC()->cart->remove_coupons();
		$coupon->delete( true );
	}

	// ====================================================================
	//  H. priority 999 確保不被其他 plugin 覆蓋
	// ====================================================================

	/**
	 * 其他 plugin 在 priority 500 也設了 set_price，我方 hook 在 999 必須後到 → 最終 = override
	 *
	 * @test
	 * @group security
	 */
	public function test_override_remains_after_other_plugins_run_before_calculate_totals(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		// 模擬另一個 plugin 在 priority 500 把所有商品改 1 元
		$evil_callback = static function ( \WC_Cart $cart ): void {
			foreach ( $cart->cart_contents as $item ) {
				if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
					$item['data']->set_price( '1' );
				}
			}
		};
		\add_action( 'woocommerce_before_calculate_totals', $evil_callback, 500 );

		try {
			\WC()->cart->calculate_totals();
			$price = (string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_price();
			$this->assertSame(
				'888',
				$price,
				'我方 priority=999 必須晚於其他 plugin（500），最終 price 為 override'
			);
		} finally {
			\remove_action( 'woocommerce_before_calculate_totals', $evil_callback, 500 );
		}
	}

	// ====================================================================
	//  Helper：建立 shop / 加入購物車
	// ====================================================================

	/**
	 * 建立含單一商品的 ProfitShop
	 *
	 * @param int           $product_id     商品 ID
	 * @param PriceOverride $override       價格覆寫
	 *
	 * @return int 賣場 ID
	 */
	private function create_shop_with_item( int $product_id, PriceOverride $override ): int {
		$shop = new ProfitShop(
			id: 0,
			title: '測試賣場 ' . uniqid(),
			slug: 'test-shop-' . uniqid(),
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: $this->partner_term_id,
			rate: new ProfitRate( 20 ),
			items: [
				new OverrideItem(
					product_id: $product_id,
					override: $override,
					inflated_count: new InflatedCount( 0 )
				),
			]
		);

		return $this->repo->save( $shop );
	}

	/**
	 * 模擬從 profit-shop 頁面加入購物車（帶 profit_shop_id）
	 *
	 * @param int $product_id 商品 ID
	 * @param int $shop_id    賣場 ID
	 *
	 * @return string cart_item_key
	 */
	private function add_to_cart_via_shop( int $product_id, int $shop_id ): string {
		$key = \WC()->cart->add_to_cart(
			$product_id,
			1,
			0,
			[],
			[ 'profit_shop_id' => $shop_id ]
		);

		return is_string( $key ) ? $key : '';
	}
}
