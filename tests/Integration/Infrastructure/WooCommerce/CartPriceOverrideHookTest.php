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
 * ═══ Phase 13 更新 ═══
 *
 * 1. 價格斷言由 '888' / '666' 改為 '888.00' / '666.00'。
 *    這**不是**「改測試遷就實作」，而是原本的斷言從一開始就寫錯：
 *    `normalize_price_for_signature()` 用的 `wc_format_decimal( $x, '' )` 會**補足**到
 *    wc_get_price_decimals() 位小數（第三參數 $trim_zeros 預設 false 且未傳 true），
 *    實際輸出就是 '888.00'。這 6 條紅燈是 `specs/tech-debt.md` TD-1 記載的
 *    pre-existing 失敗，成因於 Phase 13 查明。
 *    ⚠️ **不要**反過來去改 normalize_price_for_signature() 的行為讓它符合舊斷言——
 *    那會讓所有既有 v1 簽章的 payload 價格字串改變、既有購物車驗章全數失敗，
 *    等於分潤價靜默消失。詳見該方法的 docblock 與 `profit-shop.rule.md`。
 *
 * 2. cart_item meta 由 4 筆擴充為 6 筆（新增 _profit_regular_price_override /
 *    _profit_sale_price_override），簽章升級為 v2 並涵蓋 regular / sale。
 *    v1 簽章仍必須驗得過（既有購物車相容），見 J 區的 legacy 測試。
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
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\VariationOverride;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\CartPriceOverrideHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\PricelessRescuePolicy;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontData;
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
		$this->assertSame( '888.00', (string) $cart_item['_profit_price_override'], '應寫入 effective override 價格' );
		$this->assertNotEmpty( $cart_item['_profit_signature'], '應寫入 _profit_signature' );

		// signature 必須通過驗證（v3：regular / sale 與商品身分都納入 payload）
		$valid = $this->signature_service->verify(
			$shop_id,
			$this->partner_term_id,
			'888.00',
			(string) $cart_item['_profit_signature'],
			(string) $cart_item['_profit_regular_price_override'],
			(string) $cart_item['_profit_sale_price_override'],
			$product->get_id(),
			0
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
			'888.00',
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
			'888.00',
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

		$this->assertSame( '888.00', (string) \WC()->cart->cart_contents[ $key_a ]['data']->get_price() );
		$this->assertSame( '666.00', (string) \WC()->cart->cart_contents[ $key_b ]['data']->get_price() );
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

		$this->assertSame( '888.00', (string) \WC()->cart->cart_contents[ $key_a ]['data']->get_price() );
		$this->assertSame( '666.00', (string) \WC()->cart->cart_contents[ $key_b ]['data']->get_price() );
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
				'888.00',
				$price,
				'我方 priority=999 必須晚於其他 plugin（500），最終 price 為 override'
			);
		} finally {
			\remove_action( 'woocommerce_before_calculate_totals', $evil_callback, 500 );
		}
	}

	// ====================================================================
	//  I. Phase 13：cart 也要套 regular / sale（本 bug 的直接回歸鎖）
	// ====================================================================

	/**
	 * 🔴 本 bug 的直接回歸鎖：cart_item 的劃線原價必須是**賣場覆寫後**的原價
	 *
	 * 改版前本 hook 只呼叫 set_price()，於是 Cart Block / Store API 讀
	 * $product->get_regular_price() 時拿到商品的**原始** regular_price（1000），
	 * 顧客看到「原價 1000 劃掉 → 現價 600」，而賣場設定的原價其實是 800。
	 * 實收價正確、劃線價錯誤，且沒有任何錯誤訊息。
	 *
	 * @test
	 * @group happy
	 * @group smoke
	 */
	public function test_cart_item_regular_and_sale_price_reflect_shop_override(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '800', '600', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		\WC()->cart->calculate_totals();

		$data = \WC()->cart->cart_contents[ $cart_item_key ]['data'];

		$this->assertSame( '800.00', (string) $data->get_regular_price(), '劃線原價必須是賣場覆寫後的原價（不是商品原始 1000）' );
		$this->assertSame( '600.00', (string) $data->get_sale_price(), '特價必須是賣場覆寫後的特價' );
		$this->assertSame( '600.00', (string) $data->get_price(), '實收價維持覆寫特價' );
	}

	/**
	 * 只覆寫特價（未設原價）→ 保留商品原本的原價
	 *
	 * regular === '' 的語意是「不覆寫原價」，不可寫入。
	 *
	 * @test
	 * @group edge
	 */
	public function test_only_sale_overridden_keeps_product_original_regular_price(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '600', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		\WC()->cart->calculate_totals();

		$data = \WC()->cart->cart_contents[ $cart_item_key ]['data'];

		$this->assertSame( '1000', (string) $data->get_regular_price(), '未覆寫原價時應保留商品原本的原價' );
		$this->assertSame( '600.00', (string) $data->get_sale_price() );
		$this->assertSame( '600.00', (string) $data->get_price() );
	}

	/**
	 * 只覆寫原價（未設特價）→ 必須清掉商品原本的特價
	 *
	 * sale === '' 的語意是「沒有特價」，**必須寫入**。不清掉會出現
	 * 「原價劃掉 → 特價 700」但實收 800 的矛盾畫面（F-8）。
	 *
	 * @test
	 * @group edge
	 * @group smoke
	 */
	public function test_only_regular_overridden_clears_product_sale_price(): void {
		$product = $this->createSimpleProduct(
			[
				'regular_price' => '1000',
				'sale_price'    => '700',
			]
		);
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '800', null, null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		\WC()->cart->calculate_totals();

		$data = \WC()->cart->cart_contents[ $cart_item_key ]['data'];

		$this->assertSame( '800.00', (string) $data->get_regular_price() );
		$this->assertSame( '', (string) $data->get_sale_price(), '未設覆寫特價時必須清掉商品原本的特價 700' );
		$this->assertSame( '800.00', (string) $data->get_price(), '實收價為覆寫原價' );
	}

	/**
	 * 🔴 F-3 鎖：未覆寫的 regular 必須寫成 ''，絕不可是 '0.00'
	 *
	 * wc_format_decimal( '', '' ) 會回 '0.00'（不是 ''）。若「未覆寫原價」的 null
	 * 誤走了那條路徑，購物車會顯示「原價 NT$0」的劃線價，且沒有任何錯誤訊息。
	 *
	 * @test
	 * @group security
	 * @group edge
	 */
	public function test_null_regular_override_is_not_normalized_into_zero(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );
		$cart_item     = \WC()->cart->cart_contents[ $cart_item_key ];

		$this->assertSame(
			'',
			(string) $cart_item['_profit_regular_price_override'],
			'未覆寫原價時 meta 必須是空字串，不可被 wc_format_decimal 轉成 0.00'
		);
		$this->assertNotSame( '0.00', (string) $cart_item['_profit_regular_price_override'] );

		\WC()->cart->calculate_totals();
		$this->assertSame(
			'1000',
			(string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_regular_price(),
			'劃線價必須是商品原價 1000，不可變成 0'
		);
	}

	/**
	 * 變體覆寫要正確落在該變體的 regular / sale 上
	 *
	 * cart item 的 data 是 WC_Product_Variation（不是 WC_Product_Variable），
	 * Store API 對它走 get_regular_price() / get_sale_price() 分支。
	 *
	 * @test
	 * @group happy
	 */
	public function test_variation_override_sets_variation_regular_and_sale_price(): void {
		$variable      = $this->createVariableProduct( [ 'variations' => [ [ 'regular_price' => '1000' ] ] ] );
		$variation_ids = array_values( array_map( 'intval', $variable->get_children() ) );
		$this->assertCount( 1, $variation_ids, '測試前置：應有 1 個變體' );

		$shop = new ProfitShop(
			id: 0,
			title: '變體賣場 ' . uniqid(),
			slug: 'variation-shop-' . uniqid(),
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: $this->partner_term_id,
			rate: new ProfitRate( 20 ),
			items: [
				new OverrideItem(
					product_id: $variable->get_id(),
					override: new PriceOverride( '900', null, null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$variation_ids[0] => new VariationOverride(
							new PriceOverride( '500', '450', null ),
							new InflatedCount( 0 )
						),
					]
				),
			]
		);
		$shop_id = $this->repo->save( $shop );

		$key = \WC()->cart->add_to_cart(
			$variable->get_id(),
			1,
			$variation_ids[0],
			[],
			[ 'profit_shop_id' => $shop_id ]
		);
		$this->assertNotEmpty( $key, '變體應可加入購物車' );

		\WC()->cart->calculate_totals();

		$data = \WC()->cart->cart_contents[ $key ]['data'];
		$this->assertInstanceOf( \WC_Product_Variation::class, $data, 'cart item 的 data 應為 WC_Product_Variation' );

		$this->assertSame( '500.00', (string) $data->get_regular_price(), '應套用變體層覆寫原價（非商品層 900）' );
		$this->assertSame( '450.00', (string) $data->get_sale_price() );
		$this->assertSame( '450.00', (string) $data->get_price() );
	}

	// ====================================================================
	//  J. Phase 13：v1 legacy 相容與版本判斷安全鎖
	// ====================================================================

	/**
	 * 🔴 **本次改動最重要的一條**：改版前既有的 v1 購物車必須維持原本行為
	 *
	 * 登入使用者的持久化購物車（usermeta）**永不過期**。若 v1 簽章不再驗得過，
	 * 那些顧客回到購物車時分潤價會直接退回商品原價——沒有錯誤訊息、沒有前端提示，
	 * 站長通常要等到對帳才會發現。
	 *
	 * 期望：price 正常套用、**不寫 error_log**、meta 不被 strip、
	 *       regular / sale 維持商品原值（v1 沒有這兩個欄位）。
	 *
	 * @test
	 * @group security
	 * @group smoke
	 */
	public function test_legacy_v1_cart_item_still_applies_price_and_is_not_treated_as_tampering(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '1000', '888', null )
		);

		$cart_item_key = $this->add_legacy_v1_cart_item( $product->get_id(), $shop_id, '888.00' );

		$log_file = tempnam( sys_get_temp_dir(), 'profit_shop_log_' );
		ini_set( 'error_log', $log_file );

		try {
			\WC()->cart->calculate_totals();

			$cart_item = \WC()->cart->cart_contents[ $cart_item_key ];

			$this->assertSame( '888.00', (string) $cart_item['data']->get_price(), 'v1 簽章的 price 必須照常套用' );
			$this->assertArrayHasKey( '_profit_shop_id', $cart_item, 'v1 cart_item 的 meta 不可被 strip' );
			$this->assertArrayHasKey( '_profit_signature', $cart_item );

			// v1 沒有 regular/sale 概念 → 完全不碰，維持商品原值（與改版前行為完全相同）
			$this->assertSame( '1200', (string) $cart_item['data']->get_regular_price(), 'v1 路徑不得改動 regular_price' );

			$logged = file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';
			$this->assertStringNotContainsString(
				'cart signature mismatch',
				$logged,
				'合法的 v1 cart_item 絕不可被判成 tampering'
			);
		} finally {
			ini_restore( 'error_log' );
			if ( file_exists( $log_file ) ) {
				unlink( $log_file );
			}
		}
	}

	/**
	 * 🔴 F-4 安全鎖：v1 簽章 + 注入偽造的 regular meta → 假原價**不得**生效
	 *
	 * 攻擊情境：攻擊者保留合法的 v1 簽章（驗得過），額外注入一筆
	 * _profit_regular_price_override = 99999。若我方用「meta 在不在」判斷版本，
	 * 就會套用一個從未被簽章保護的假原價。因此版本一律由 payload_version() 決定，
	 * 且 v1 分支無條件忽略這兩筆 meta。
	 *
	 * @test
	 * @group security
	 */
	public function test_legacy_v1_signature_ignores_injected_regular_price_meta(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '1000', '888', null )
		);

		$cart_item_key = $this->add_legacy_v1_cart_item( $product->get_id(), $shop_id, '888.00' );

		// 攻擊：注入從未被簽章保護的假原價
		\WC()->cart->cart_contents[ $cart_item_key ]['_profit_regular_price_override'] = '99999';
		\WC()->cart->cart_contents[ $cart_item_key ]['_profit_sale_price_override']    = '1';

		\WC()->cart->calculate_totals();

		$data = \WC()->cart->cart_contents[ $cart_item_key ]['data'];

		$this->assertSame( '1200', (string) $data->get_regular_price(), '注入的假原價絕不可生效' );
		$this->assertSame( '', (string) $data->get_sale_price(), '注入的假特價絕不可生效' );
		$this->assertSame( '888.00', (string) $data->get_price(), 'price 仍照 v1 簽章正常套用' );
	}

	/**
	 * 跨版本不可互通：v1 簽章硬改成 v2 前綴 → fallback 原價
	 *
	 * @test
	 * @group security
	 */
	public function test_v1_signature_with_forged_v2_prefix_falls_back_to_original_price(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '1000', '888', null )
		);

		$cart_item_key = $this->add_legacy_v1_cart_item( $product->get_id(), $shop_id, '888.00' );

		// 補齊 v2 必備 meta 並把前綴改成 v2.，讓流程確實走到「v2 驗章」而非「meta 不齊」
		$signature = (string) \WC()->cart->cart_contents[ $cart_item_key ]['_profit_signature'];
		\WC()->cart->cart_contents[ $cart_item_key ]['_profit_signature']              = 'v2.' . substr( $signature, 3 );
		\WC()->cart->cart_contents[ $cart_item_key ]['_profit_regular_price_override'] = '';
		\WC()->cart->cart_contents[ $cart_item_key ]['_profit_sale_price_override']    = '';

		\WC()->cart->calculate_totals();

		$this->assertSame(
			'1200',
			(string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_price(),
			'v1 簽章換上 v2 前綴必須驗章失敗並 fallback 原價'
		);
	}

	/**
	 * v2 簽章涵蓋 regular：竄改 regular meta → fallback 原價
	 *
	 * 這是把 regular/sale 納入簽章的目的——它們會影響 is_on_sale()，
	 * 進而影響「排除特價商品」優惠券，是有真實金額影響的。
	 *
	 * @test
	 * @group security
	 */
	public function test_v2_cart_item_with_tampered_regular_price_falls_back_to_original(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '1000', '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		\WC()->cart->cart_contents[ $cart_item_key ]['_profit_regular_price_override'] = '99999';

		\WC()->cart->calculate_totals();

		$data = \WC()->cart->cart_contents[ $cart_item_key ]['data'];
		$this->assertSame( '1200', (string) $data->get_price(), '竄改 regular 後必須 fallback 原價' );
		$this->assertSame( '1200', (string) $data->get_regular_price(), '竄改的 regular 絕不可生效' );
	}

	/**
	 * v2 簽章但 regular meta 被刪 → has_all_profit_meta 判失敗 → fallback 原價
	 *
	 * @test
	 * @group security
	 */
	public function test_v2_cart_item_with_missing_regular_meta_falls_back_to_original(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '1000', '888', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		unset( \WC()->cart->cart_contents[ $cart_item_key ]['_profit_regular_price_override'] );

		\WC()->cart->calculate_totals();

		$this->assertSame(
			'1200',
			(string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_price(),
			'v2 簽章缺 regular meta 時應 fallback 原價'
		);
	}

	/**
	 * 過渡期必然情境：同一台購物車內 v1 與 v2 品項並存，各自獨立判版
	 *
	 * 顧客改版前加了 A（v1）、改版後又加了 B（v2），兩者都必須正確。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_mixed_v1_and_v2_cart_items_are_evaluated_independently(): void {
		$product_v2 = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$product_v1 = $this->createSimpleProduct( [ 'regular_price' => '999' ] );

		$shop_v2 = $this->create_shop_with_item(
			$product_v2->get_id(),
			new PriceOverride( '800', '600', null )
		);
		$shop_v1 = $this->create_shop_with_item(
			$product_v1->get_id(),
			new PriceOverride( '700', '666', null )
		);

		$key_v2 = $this->add_to_cart_via_shop( $product_v2->get_id(), $shop_v2 );
		$key_v1 = $this->add_legacy_v1_cart_item( $product_v1->get_id(), $shop_v1, '666.00' );

		\WC()->cart->calculate_totals();

		$data_v2 = \WC()->cart->cart_contents[ $key_v2 ]['data'];
		$data_v1 = \WC()->cart->cart_contents[ $key_v1 ]['data'];

		// v2：price + regular + sale 全套用
		$this->assertSame( '600.00', (string) $data_v2->get_price() );
		$this->assertSame( '800.00', (string) $data_v2->get_regular_price() );

		// v1：只套 price，regular 維持商品原值
		$this->assertSame( '666.00', (string) $data_v1->get_price() );
		$this->assertSame( '999', (string) $data_v1->get_regular_price(), 'v1 品項不得被 v2 邏輯影響' );
	}

	// ====================================================================
	//  K. Phase 13：連帶行為
	// ====================================================================

	/**
	 * F-5：驗章失敗時必須連 regular / sale 一起還原，不能只還原 price
	 *
	 * 同一 request 內 calculate_totals() 會被呼叫多次。若第一次驗章通過（已寫入
	 * 覆寫 regular/sale）、第二次因竄改而失敗，只還原 price 會讓錯誤的劃線價殘留。
	 *
	 * @test
	 * @group security
	 */
	public function test_signature_failure_also_restores_regular_and_sale_price(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '800', '600', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		// 第一輪：驗章通過，product 物件已被寫入覆寫 regular / sale
		\WC()->cart->calculate_totals();
		$this->assertSame(
			'800.00',
			(string) \WC()->cart->cart_contents[ $cart_item_key ]['data']->get_regular_price(),
			'測試前置：第一輪應已套用覆寫原價'
		);

		// 第二輪：竄改 price meta → 驗章失敗
		\WC()->cart->cart_contents[ $cart_item_key ]['_profit_price_override'] = '1';
		\WC()->cart->calculate_totals();

		$data = \WC()->cart->cart_contents[ $cart_item_key ]['data'];
		$this->assertSame( '1000', (string) $data->get_regular_price(), '驗章失敗必須還原 regular_price，不可殘留 800' );
		$this->assertSame( '', (string) $data->get_sale_price(), '驗章失敗必須還原 sale_price，不可殘留 600' );
		$this->assertSame( '1000', (string) $data->get_price() );
	}

	/**
	 * F-9【行為變更鎖】覆寫 regular/sale 之後 is_on_sale() 會變 true，
	 * 「排除特價商品」的優惠券因此不再適用於分潤賣場商品
	 *
	 * 這是**刻意接受**的行為變更：該品項確實在特價中，語意正確，且
	 * SingleProductPriceHook 在單品頁早就是這個行為，改完反而兩邊一致。
	 * 但它是站長可感知的變化，用測試鎖住以確保它是刻意的、不是意外的。
	 *
	 * @test
	 * @group edge
	 */
	public function test_override_makes_cart_item_on_sale_for_exclude_sale_items_coupon(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '800', '600', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		\WC()->cart->calculate_totals();

		$data = \WC()->cart->cart_contents[ $cart_item_key ]['data'];

		// 改版前 cart item 只有 set_price()，is_on_sale() 讀的是商品原始 regular/sale → false
		$this->assertTrue(
			$data->is_on_sale(),
			'套用覆寫 regular/sale 後，cart item 會被視為特價中（行為變更，刻意接受）'
		);

		$excluding = new \WC_Coupon();
		$excluding->set_code( 'profit_excl_' . uniqid() );
		$excluding->set_discount_type( 'fixed_product' );
		$excluding->set_amount( 100.0 );
		$excluding->set_exclude_sale_items( true );
		$excluding->save();

		$including = new \WC_Coupon();
		$including->set_code( 'profit_incl_' . uniqid() );
		$including->set_discount_type( 'fixed_product' );
		$including->set_amount( 100.0 );
		$including->set_exclude_sale_items( false );
		$including->save();

		try {
			$this->assertFalse(
				$excluding->is_valid_for_product( $data ),
				'exclude_sale_items 的優惠券對分潤商品不再適用（站長可感知的行為變更）'
			);
			$this->assertTrue(
				$including->is_valid_for_product( $data ),
				'對照組：未排除特價商品的優惠券仍然適用'
			);
		} finally {
			$excluding->delete( true );
			$including->delete( true );
		}
	}

	// ====================================================================
	//  K. 無定價商品的購物車生命週期救援（2026-08）
	// ====================================================================

	/**
	 * 自身無定價的 simple 商品 + 賣場覆寫價 → 加得進購物車，且實收為覆寫價
	 *
	 * 修法前會在 woocommerce/includes/class-wc-cart.php:1229 被
	 * `! $product_data->is_purchasable()` 擋下（條件含 `'' !== get_price()`）。
	 *
	 * @test
	 * @group happy
	 * @group smoke
	 */
	public function test_priceless_simple_product_with_override_can_be_added_to_cart(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$this->assertFalse( $product->is_purchasable(), '前置條件：無定價商品本來就不可購買' );

		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '30', '27', null )
		);

		$cart_item_key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );
		$this->assertNotEmpty( $cart_item_key, '有覆寫價的無定價商品應可加入購物車' );

		\WC()->cart->calculate_totals();

		$cart_item = \WC()->cart->cart_contents[ $cart_item_key ];
		$this->assertSame( '27.00', (string) $cart_item['data']->get_price(), '實收應為覆寫特價' );
		$this->assertSame( '30.00', (string) $cart_item['data']->get_regular_price(), '劃線原價應為覆寫原價' );
		$this->assertNotEmpty( $cart_item['_profit_signature'], '分潤簽章仍必須寫入（三道閘門未被繞過）' );
	}

	/**
	 * 自身無定價的**變體** + 變體層覆寫 → 加得進購物車
	 *
	 * @test
	 * @group happy
	 */
	public function test_priceless_variation_with_override_can_be_added_to_cart(): void {
		[ $parent, $variation_ids ] = $this->create_priceless_variable_product();
		$vid                        = $variation_ids[0];

		$shop_id = $this->create_shop_with_variation_override(
			$parent->get_id(),
			$vid,
			new PriceOverride( '30', '27', null )
		);

		$key = \WC()->cart->add_to_cart(
			$parent->get_id(),
			1,
			$vid,
			[],
			[ 'profit_shop_id' => $shop_id ]
		);

		$this->assertIsString( $key, '有覆寫價的無定價變體應可加入購物車' );
		$this->assertNotEmpty( $key );

		\WC()->cart->calculate_totals();
		$this->assertSame(
			'27.00',
			(string) \WC()->cart->cart_contents[ $key ]['data']->get_price(),
			'變體實收應為變體層覆寫特價'
		);
	}

	/**
	 * 自身無定價且賣場**沒有**覆寫 → 仍然加不進購物車
	 *
	 * 這是合法的不可購買狀態，救援機制不得誤放行（否則會出現 0 元品項）。
	 *
	 * @test
	 * @group security
	 * @group error
	 */
	public function test_priceless_product_without_override_cannot_be_added_to_cart(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, null, null )
		);

		$key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );

		$this->assertSame( '', $key, '沒有覆寫價的無定價商品不得被加入購物車' );
		$this->assertCount( 0, \WC()->cart->get_cart(), '購物車應維持為空' );
	}

	/**
	 * 【③ 回歸】已在 session 內的無定價品項，重新載入購物車時不得被移除
	 *
	 * woocommerce/includes/class-wc-cart-session.php:183 對每個載入購物車的請求都會跑
	 * `! apply_filters( 'woocommerce_cart_item_is_purchasable', $product->is_purchasable(), $key, $values, $product )`，
	 * false 就把品項移除並顯示「已無法購買」。只在加購那一刻放行 = 加得進去、下一頁就不見了。
	 *
	 * 本測試直接以 WooCommerce 的簽章呼叫該 filter（$values 帶著該筆自己的 _profit_* meta），
	 * 並額外斷言 $product **這個實例**已被就地補上價格——後者證明閘門 ④
	 * （src/StoreApi/Utilities/CartController.php:681-688 讀 $cart_item['data']，
	 * 而 :234 存進去的正是同一個實例）一併解決。
	 *
	 * @test
	 * @group happy
	 * @group edge
	 */
	public function test_priceless_cart_item_survives_session_reload_via_per_item_hook(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '30', '27', null )
		);

		$values   = $this->make_signed_cart_values( $product->get_id(), 0, $shop_id, '27.00', '30.00', '27.00' );
		$instance = \wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $instance );
		$this->assertFalse( $instance->is_purchasable(), '前置條件：無定價商品本來就不可購買' );

		$result = \apply_filters(
			'woocommerce_cart_item_is_purchasable',
			$instance->is_purchasable(),
			'fixture-key',
			$values,
			$instance
		);

		$this->assertTrue( (bool) $result, '已驗章的 cart item 在重新載入購物車時必須維持可購買' );
		$this->assertSame( '27.00', (string) $instance->get_price(), '應就地補上覆寫價（同一個實例 ⇒ 閘門 ④ 一併解決）' );
		$this->assertSame( '30.00', (string) $instance->get_regular_price(), '劃線原價亦應為覆寫原價' );
	}

	/**
	 * 【安全】session 內的價格被竄改 → filter 回 false，且不得留下 0 元品項
	 *
	 * 驗章失敗時回傳的是**原始** $purchasable（false），由 WooCommerce 自己在
	 * class-wc-cart-session.php:184-214 移除品項（fail-closed）。
	 * 另外斷言 apply_override() 的 fallback 路徑會把該筆移出購物車，而不是
	 * set_price('') 留下一筆 0 元品項——那會把「竄改 ⇒ 付原價」倒轉成「竄改 ⇒ 免費」。
	 *
	 * @test
	 * @group security
	 */
	public function test_tampered_priceless_cart_item_is_removed_not_zeroed(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '30', '27', null )
		);

		// 簽章照 27.00 產生，但 meta 被改成 1.00
		$values   = $this->make_signed_cart_values( $product->get_id(), 0, $shop_id, '27.00', '30.00', '27.00', '1.00' );
		$instance = \wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $instance );

		$result = \apply_filters(
			'woocommerce_cart_item_is_purchasable',
			$instance->is_purchasable(),
			'fixture-key',
			$values,
			$instance
		);

		$this->assertFalse( (bool) $result, '驗章失敗必須維持不可購買（fail-closed）' );
		$this->assertSame( '', (string) $instance->get_price(), '驗章失敗時不得補上任何價格（更不可補成被竄改的 1.00）' );

		// 再驗 999 的 fallback：竄改的無定價品項必須被移出購物車，不可留下 0 元品項。
		$key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );
		$this->assertNotEmpty( $key, '前置條件：合法加購應成功' );

		\WC()->cart->cart_contents[ $key ][ '_profit_price_override' ] = '1.00';
		\WC()->cart->calculate_totals();

		$this->assertArrayNotHasKey( $key, \WC()->cart->cart_contents, '竄改後的無定價品項必須被移除，而不是變成 0 元' );
	}

	/**
	 * 【回歸】本來就有定價的商品完全不受 per-item 授權影響
	 *
	 * authorize_cart_item() 的前兩道早退（$purchasable 已為 true、商品自身有價格）
	 * 是整套機制的作用範圍收斂點。
	 *
	 * @test
	 * @group smoke
	 * @group edge
	 */
	public function test_priced_products_are_untouched_by_authorization(): void {
		$priceless = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$normal    = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );

		$shop_id = $this->create_shop_with_item(
			$priceless->get_id(),
			new PriceOverride( '30', '27', null )
		);

		$values = $this->make_signed_cart_values( $priceless->get_id(), 0, $shop_id, '27.00', '30.00', '27.00' );

		$rescued = \wc_get_product( $priceless->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $rescued );
		\apply_filters( 'woocommerce_cart_item_is_purchasable', $rescued->is_purchasable(), 'k', $values, $rescued );
		$this->assertSame( '27.00', (string) $rescued->get_price(), '對照組：無定價商品確實被授權（防假綠燈）' );

		// 拿同一組（合法的）$values 去套一個**有定價**的商品：必須完全不介入。
		$untouched = \wc_get_product( $normal->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $untouched );
		\apply_filters( 'woocommerce_cart_item_is_purchasable', $untouched->is_purchasable(), 'k', $values, $untouched );

		$this->assertSame( '1200', (string) $untouched->get_price(), '有定價的商品價格不得被授權機制改動' );
		$this->assertSame( '1200', (string) $untouched->get_regular_price(), '原價亦不得被改動' );
	}

	// ====================================================================
	//  L. per-cart-item 授權：不得外溢（第 5 輪）
	// ====================================================================

	/**
	 * 【R-1 回歸鎖】購物車有賣場品項時，同一商品在一般情境的價格不得被污染
	 *
	 * 第 4 輪用「product ID → 價格」的 request 級救援表 + 常駐全域價格 filter，
	 * 而守門是**逐欄位**判空字串——絕大多數正常商品的 get_sale_price() 本來就回 ''，
	 * 於是「購物車裡有一筆賣場品項」就會讓同一商品在**任何地方**的 get_sale_price()
	 * 變成賣場特價：一般單品頁顯示「$999 劃掉 → $999」、is_on_sale() 由 false 翻 true
	 * ⇒ 帶 exclude_sale_items 的優惠券失效。
	 *
	 * 新設計只 mutate「那一筆 cart item 自己的 WC_Product 實例」，而
	 * WC_Product_Factory::get_product()（includes/class-wc-product-factory.php）
	 * 每次都 new 一個新物件、不做實例快取 ⇒ 外溢在結構上不可能發生。
	 *
	 * @test
	 * @group security
	 */
	public function test_shop_cart_item_does_not_pollute_other_product_instances(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '999' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '500', '450', null )
		);

		$key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );
		$this->assertNotEmpty( $key, '前置條件：商品應可加入購物車' );

		\WC()->cart->calculate_totals();

		// 對照組（防假綠燈）：購物車那一筆確實已套上覆寫價
		$in_cart = \WC()->cart->cart_contents[ $key ]['data'];
		$this->assertSame( '450.00', (string) $in_cart->get_price(), '對照組：購物車品項應為覆寫特價' );
		$this->assertSame( '500.00', (string) $in_cart->get_regular_price(), '對照組：購物車品項應為覆寫原價' );

		// 一般情境（不帶 profit_shop_id）：全新實例必須完全乾淨
		$elsewhere = \wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $elsewhere );

		$this->assertSame( '', (string) $elsewhere->get_sale_price(), '一般情境的特價不得被賣場覆寫價污染' );
		$this->assertSame( '999', (string) $elsewhere->get_regular_price(), '一般情境的原價不得被改動' );
		$this->assertSame( '999', (string) $elsewhere->get_price(), '一般情境的售價不得被改動' );
		$this->assertFalse( $elsewhere->is_on_sale(), 'is_on_sale() 不得被翻轉（否則 exclude_sale_items 優惠券會失效）' );
	}

	/**
	 * 【R-2 回歸鎖】不帶 profit_shop_id 時，無定價商品必須加不進購物車
	 *
	 * 第 4 輪的救援表讓「購物車裡有一筆合法賣場品項」等同於「這個 product 在本 request
	 * 內到處都有價格」，於是同一商品可以在一般單品頁被再加購一次——顧客付到分潤價、
	 * 夥伴卻拿不到任何結算紀錄（無歸屬銷售），而且那個價格完全在 HMAC 簽章機制之外。
	 *
	 * 強化版：**在同一個請求內**先合法加購一次建立賣場情境，再不帶 profit_shop_id
	 * 加購同一商品，仍必須被擋下。
	 *
	 * @test
	 * @group security
	 */
	public function test_priceless_variation_cannot_be_added_without_profit_shop_id(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '30', '27', null )
		);

		// (1) 完全沒有賣場情境時就加不進去
		$naked = \WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertFalse( $naked, '無定價商品在沒有賣場情境時不得被加入購物車' );
		$this->assertCount( 0, \WC()->cart->get_cart(), '購物車應維持為空' );

		// (2) 先合法加購一次（建立賣場情境）
		$legit = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );
		$this->assertNotEmpty( $legit, '前置條件：帶合法 shop_id 應可加購' );

		// (3) 同一個請求內，不帶 profit_shop_id 再加購一次 → 仍必須被擋下
		$leaked = \WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertFalse( $leaked, '購物車已有賣場品項，也不得讓同一商品在無賣場情境下變成可購買' );
		$this->assertCount( 1, \WC()->cart->get_cart(), '購物車應仍只有那一筆合法品項' );
	}

	/**
	 * 【A-3 回歸鎖】已結束販售的賣場，手工 POST 加購無定價變體必須被拒
	 *
	 * `AddToCartHook` 的閘門在第 5 輪之前**完全不檢查販售起訖時間**——前台靠 Phase 16 的
	 * 「Modal 整段不印」擋住，但那只是 UI 層防呆，接不住手工 POST。既有測試只驗
	 * DOM 不印表單（ProfitShopShortcodeTest），覆蓋不到這條。
	 *
	 * 期望：不注入 profit_shop_id → 不寫 meta → 不 arm 一次性授權 →
	 * 無定價商品在 includes/class-wc-cart.php:1228 被 WooCommerce 自己擋下。
	 *
	 * @test
	 * @group security
	 */
	public function test_closed_shop_rejects_hand_crafted_post_for_priceless_variation(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '30', '27', null ),
			[
				// 已於一小時前結束販售
				'endTime' => ( \time() - 3600 ) * 1000,
			]
		);

		$key = $this->add_to_cart_via_request( $product->get_id(), $shop_id );

		$this->assertSame( '', $key, '已結束販售的賣場不得讓無定價商品被加入購物車' );
		$this->assertCount( 0, \WC()->cart->get_cart(), '購物車應維持為空' );
	}

	/**
	 * 【A-3 回歸鎖】已結束販售的賣場，**有定價**商品加得進去但拿不到分潤價
	 *
	 * 與偽造 shop_id 的既有行為一致：cart 仍正常加入、走商品原價、零 _profit_* meta。
	 *
	 * @test
	 * @group security
	 */
	public function test_closed_shop_rejects_hand_crafted_post_for_priced_product(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null ),
			[ 'endTime' => ( \time() - 3600 ) * 1000 ]
		);

		$key = $this->add_to_cart_via_request( $product->get_id(), $shop_id );
		$this->assertNotEmpty( $key, '有定價的商品仍可加入購物車（只是拿不到分潤價）' );

		$cart_item = \WC()->cart->cart_contents[ $key ];
		$this->assertArrayNotHasKey( '_profit_shop_id', $cart_item, '已結束販售的賣場不得寫入任何分潤 meta' );
		$this->assertArrayNotHasKey( '_profit_signature', $cart_item, '不得寫入簽章' );

		\WC()->cart->calculate_totals();
		$this->assertSame( '1200', (string) $cart_item['data']->get_price(), '應走商品原價' );
	}

	/**
	 * 【邊界】尚未開賣的賣場同樣被拒；未設排程的賣場不受限制
	 *
	 * @test
	 * @group edge
	 */
	public function test_sale_window_gate_rejects_before_start_and_ignores_unset_schedule(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );

		$coming = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '30', '27', null ),
			[ 'startTime' => ( \time() + 86400 ) * 1000 ]
		);
		$this->assertSame( '', $this->add_to_cart_via_request( $product->get_id(), $coming ), '尚未開賣的賣場必須被拒' );

		\WC()->cart->empty_cart();

		// 完全沒設排程（絕大多數賣場）→ 不限制
		$open = $this->create_shop_with_item( $product->get_id(), new PriceOverride( '30', '27', null ) );
		$this->assertNotSame( '', $this->add_to_cart_via_request( $product->get_id(), $open ), '未設排程的賣場不得受此閘門影響' );
	}

	/**
	 * 【安全】加購成功後，一次性授權必須立即關閉
	 *
	 * 關閉點 (2)：woocommerce_add_to_cart（includes/class-wc-cart.php:1338）priority 1。
	 *
	 * @test
	 * @group security
	 */
	public function test_pending_add_authorization_is_cleared_after_add(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '30', '27', null )
		);

		$key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );
		$this->assertNotEmpty( $key, '前置條件：應可加購' );

		$fresh = \wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $fresh );
		$this->assertSame( '', (string) $fresh->get_price(), '加購完成後一次性授權必須已關閉' );
		$this->assertFalse( $fresh->is_purchasable(), '關閉後該商品在一般情境仍不可購買' );
	}

	/**
	 * 【安全】一次性授權只涵蓋「當次那一個變體」，不涵蓋同父商品的其他變體
	 *
	 * 比對鍵是 `variation_id ?: product_id`（＝ includes/class-wc-cart.php:1073 拿去
	 * wc_get_product() 的那個 ID）。用父商品 ID 比對會讓兄弟變體一起被放行。
	 *
	 * @test
	 * @group security
	 */
	public function test_pending_add_authorization_does_not_cover_sibling_variation(): void {
		[ $parent, $variation_ids ] = $this->create_priceless_variable_product();
		[ $covered, $sibling ]      = $variation_ids;

		$shop_id = $this->create_shop_with_variation_override(
			$parent->get_id(),
			$covered,
			new PriceOverride( '30', '27', null )
		);

		$key = \WC()->cart->add_to_cart( $parent->get_id(), 1, $covered, [], [ 'profit_shop_id' => $shop_id ] );
		$this->assertIsString( $key );
		$this->assertNotEmpty( $key, '前置條件：被覆寫的變體應可加購' );

		$sibling_product = \wc_get_product( $sibling );
		$this->assertInstanceOf( \WC_Product_Variation::class, $sibling_product );
		$this->assertSame( '', (string) $sibling_product->get_price(), '兄弟變體不得被一次性授權涵蓋' );
		$this->assertFalse( $sibling_product->is_purchasable(), '兄弟變體必須維持不可購買' );
	}

	/**
	 * 【A-1 回歸鎖】我方永遠不自行合併登入使用者的持久化購物車
	 *
	 * 第 4 輪的 read_cart_source() **無條件**把
	 * `_woocommerce_persistent_cart_{blog_id}` usermeta 合併進救援表，而 WooCommerce
	 * 自己的條件（includes/class-wc-cart-session.php:116）是
	 * `if ( is_null( $cart ) || $merge_saved_cart )`。usermeta 永不過期、簽章也沒有時效
	 * ⇒ 促銷期加購 → 登出 → 站長改價 → 重新登入，舊價格仍會在整個 request 內復活。
	 *
	 * 新設計不再自己讀任何購物車來源（$values 由 WooCommerce 逐項傳入），
	 * 因此「合併條件」這個東西根本不存在、也就無從寫錯。
	 *
	 * @test
	 * @group security
	 */
	public function test_persistent_cart_usermeta_is_never_merged_by_us(): void {
		$user_id = self::factory()->user->create();
		\wp_set_current_user( $user_id );

		$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '30', '27', null )
		);

		// 一筆帶「合法舊簽章」的持久化購物車，且 session cart 非 null、無登入合併旗標
		\update_user_meta(
			$user_id,
			'_woocommerce_persistent_cart_' . \get_current_blog_id(),
			[
				'cart' => [
					'stale-key' => $this->make_signed_cart_values( $product->get_id(), 0, $shop_id, '27.00', '30.00', '27.00' ),
				],
			]
		);

		$fresh = \wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $fresh );
		$this->assertSame( '', (string) $fresh->get_price(), '持久化購物車的舊簽章不得讓價格在本 request 內復活' );
		$this->assertFalse( $fresh->is_purchasable(), '同上（無歸屬銷售的攻擊鏈第一步必須不成立）' );

		\wp_set_current_user( 0 );
		\delete_user_meta( $user_id, '_woocommerce_persistent_cart_' . \get_current_blog_id() );
	}

	// ====================================================================
	//  L. 2026-08 第 6 輪收斂：S-1 / S-3 / S-4 / S-5 回歸鎖
	// ====================================================================

	/**
	 * 【S-1 / TD-56 回歸鎖】賣場只設**商品層**覆寫時，半成品變體不得被加進購物車
	 *
	 * 這是站長設定完全正常的情境：一個變體有價、一個是從未定價的半成品，
	 * 賣場只設商品層覆寫。顯示層（OverridePriceFiltersTest）早就鎖住了「不顯示」，
	 * 但**沒有任何測試嘗試過加購**——測試套件與程式碼有同一個盲點。
	 *
	 * 三段：(a) 手工 POST（攻擊者實際能操作的路徑，四道閘門完整跑一次）
	 *       (b) 上游直接帶 profit_shop_id（AddToCartHook short-circuit，守門在 CartPriceOverrideHook）
	 *       (c) 對照組：改設**變體層**覆寫必須救得起來（避免把守門寫成一律拒絕的假綠燈）
	 *
	 * @test
	 * @group security
	 */
	public function test_parent_level_override_never_rescues_priceless_variation_on_add_to_cart(): void {
		[ $parent, $vids ] = $this->create_priceless_variable_product();

		$shop_id = $this->create_shop_with_item(
			$parent->get_id(),
			new PriceOverride( '500', '450', null )
		);

		// (a) 手工 POST
		$key = $this->add_variation_to_cart_via_request( $parent->get_id(), $vids[0], $shop_id );
		$this->assertSame( '', $key, '半成品變體不得靠商品層覆寫被加進購物車' );
		$this->assertCount( 0, \WC()->cart->get_cart(), '購物車應維持為空' );

		// (b) 上游直接帶 profit_shop_id（跳過 AddToCartHook 的四道閘門）
		$key2 = \WC()->cart->add_to_cart( $parent->get_id(), 1, $vids[0], [], [ 'profit_shop_id' => $shop_id ] );
		$this->assertFalse( $key2, 'short-circuit 路徑同樣不得放行（守門在 CartPriceOverrideHook 內）' );

		\WC()->cart->empty_cart();

		// (c) 對照組：明確設變體層覆寫 → 必須救得起來
		$ok_shop = $this->create_shop_with_variation_override(
			$parent->get_id(),
			$vids[0],
			new PriceOverride( '30', '27', null )
		);

		$this->assertNotSame(
			'',
			$this->add_variation_to_cart_via_request( $parent->get_id(), $vids[0], $ok_shop ),
			'變體層覆寫仍必須能救回半成品變體（守門不可寫成一律拒絕）'
		);
	}

	/**
	 * 【S-4 / TD-60 回歸鎖】`_price` 有值但 regular/sale 皆空的商品，驗章失敗必須 fallback 原價
	 *
	 * 觸發不需要攻擊者：輪替 AUTH_SALT（標準資安事件處置動作）會讓全站簽章一次失效。
	 * 舊守門（`'' === sale && '' === reg`）會把這類**完全可正常販售**的商品
	 * **直接刪出所有顧客的購物車**，直接違反核心不變量
	 * 「驗章失敗一律 fallback 至商品原始價格」。
	 *
	 * @test
	 * @group security
	 * @group edge
	 */
	public function test_price_only_product_survives_signature_failure_and_falls_back_to_own_price(): void {
		$product = $this->create_price_only_product( '500' );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( '400', '350', null )
		);

		$key = $this->add_to_cart_via_request( $product->get_id(), $shop_id );
		$this->assertNotEmpty( $key, '前置：可正常販售的商品應加得進購物車' );

		// 竄改簽章（模擬 AUTH_SALT 輪替 / meta 被改）
		\WC()->cart->cart_contents[ $key ]['_profit_signature'] = 'v3.' . str_repeat( 'a', 64 );
		\WC()->cart->calculate_totals();

		$this->assertArrayHasKey( $key, \WC()->cart->cart_contents, '🔴 品項必須留在購物車，不得被刪除' );
		$this->assertCount( 1, \WC()->cart->get_cart(), '購物車件數不變' );

		$price_after = (string) \WC()->cart->cart_contents[ $key ]['data']->get_price( 'edit' );

		// 只改守門、不改還原值時第一條會過、第二條會抓到（set_price('') 讓它下一個請求被移除）
		$this->assertNotSame( '', $price_after, '還原值不得是空字串（否則只是把刪除延後到下一個請求）' );
		$this->assertSame( '500', $price_after, '必須 fallback 回商品自身的 _price' );
	}

	/**
	 * 【S-3 / TD-59 回歸鎖】已結束販售 + 上游直接帶 profit_shop_id → 不得寫入分潤 meta
	 *
	 * 這條路徑跳過 AddToCartHook 的四道閘門（short-circuit），因此
	 * CartPriceOverrideHook 必須自己重驗販售期間——閘門 1 / 2 / 4 它本來就重驗了，
	 * 只有 3 漏掉，是四道中唯一單層的一道。
	 *
	 * @test
	 * @group security
	 */
	public function test_closed_shop_rejects_upstream_supplied_profit_shop_id(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop_with_item(
			$product->get_id(),
			new PriceOverride( null, '888', null ),
			[ 'endTime' => ( \time() - 3600 ) * 1000 ]
		);

		$key = $this->add_to_cart_via_shop( $product->get_id(), $shop_id );
		$this->assertNotEmpty( $key, '商品本身有定價，仍可加入購物車' );

		$cart_item = \WC()->cart->cart_contents[ $key ];
		$this->assertArrayNotHasKey( '_profit_shop_id', $cart_item, '已結束販售的賣場不得寫入分潤 meta' );
		$this->assertArrayNotHasKey( '_profit_signature', $cart_item, '不得寫入簽章' );

		\WC()->cart->calculate_totals();
		$this->assertSame( '1200', (string) $cart_item['data']->get_price(), '應走商品原價' );
	}

	/**
	 * 【S-5 / TD-61 回歸鎖】賣場下架 / 商品移出賣場後，只靠救援才有價的品項失去授權
	 *
	 * 對照組（同一個測試內）：一般**有定價**商品不受影響——那是 Phase 3-D 的既有邊界
	 * 「published 期間加入的品項之後下架也不撤銷」，本輪刻意不動。
	 *
	 * @test
	 * @group security
	 */
	public function test_rescued_item_loses_authorization_when_shop_unpublished_or_product_removed(): void {
		$priceless = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$priced    = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );

		$shop_id = $this->create_shop_with_item(
			$priceless->get_id(),
			new PriceOverride( '30', '27', null )
		);

		$values_rescued = $this->make_signed_cart_values( $priceless->get_id(), 0, $shop_id, '27.00', '30.00', '27.00' );
		$values_priced  = $this->make_signed_cart_values( $priced->get_id(), 0, $shop_id, '888.00', '1200.00', '888.00' );

		$p1 = \wc_get_product( $priceless->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $p1 );
		$this->assertTrue(
			(bool) $this->hook->authorize_cart_item( false, 'k1', $values_rescued, $p1 ),
			'前置：賣場仍 publish 且商品仍在賣場內時應授權'
		);

		// ① 賣場下架
		$shop = $this->repo->find( $shop_id );
		$this->assertInstanceOf( ProfitShop::class, $shop );
		$shop->change_status( 'draft' );
		$this->repo->save( $shop );
		\clean_post_cache( $shop_id );

		$p2 = \wc_get_product( $priceless->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $p2 );
		$this->assertFalse(
			(bool) $this->hook->authorize_cart_item( false, 'k1', $values_rescued, $p2 ),
			'🔴 賣場下架後，只靠救援才有價的品項必須失去授權'
		);

		// ② 對照組：有定價商品不受本方法影響（第一道早退 $purchasable=true 直接原樣回傳）
		$p3 = \wc_get_product( $priced->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $p3 );
		$this->assertTrue(
			(bool) $this->hook->authorize_cart_item( true, 'k2', $values_priced, $p3 ),
			'有定價商品維持 Phase 3-D 既有邊界，不因賣場下架而被移除'
		);

		// ③ 商品移出賣場（賣場改回 publish、items 清空）
		$shop2 = $this->repo->find( $shop_id );
		$this->assertInstanceOf( ProfitShop::class, $shop2 );
		$shop2->change_status( 'publish' );
		$shop2->items = [];
		$this->repo->save( $shop2 );
		\clean_post_cache( $shop_id );

		$p4 = \wc_get_product( $priceless->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $p4 );
		$this->assertFalse(
			(bool) $this->hook->authorize_cart_item( false, 'k1', $values_rescued, $p4 ),
			'🔴 商品移出賣場後同樣必須失去授權'
		);
	}

	/**
	 * 【S-2 / TD-58 回歸鎖】v3 簽章綁定商品身分：搬到別的商品必須驗不過
	 *
	 * 修法前：把 A 商品的整組 _profit_* meta 原封套到「不在任何賣場」的 B 商品上，
	 * authorize 回 true、B 的 get_price() 變成 '27.00'。
	 *
	 * @test
	 * @group security
	 */
	public function test_v3_signature_cannot_be_transplanted_to_another_product(): void {
		$a = $this->createSimpleProduct( [ 'regular_price' => '' ] );
		$b = $this->createSimpleProduct( [ 'regular_price' => '' ] );

		$shop_id = $this->create_shop_with_item(
			$a->get_id(),
			new PriceOverride( '30', '27', null )
		);

		$values_a = $this->make_signed_cart_values( $a->get_id(), 0, $shop_id, '27.00', '30.00', '27.00' );

		$pa = \wc_get_product( $a->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $pa );
		$this->assertTrue(
			(bool) $this->hook->authorize_cart_item( false, 'ka', $values_a, $pa ),
			'前置：A 商品自己的簽章必須驗得過'
		);

		// 把 A 的簽章與 meta 原封搬到 B（只改 product_id 欄位讓它「看起來」是 B 的）
		$stolen               = $values_a;
		$stolen['product_id'] = $b->get_id();

		$pb = \wc_get_product( $b->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $pb );
		$this->assertFalse(
			(bool) $this->hook->authorize_cart_item( false, 'kb', $stolen, $pb ),
			'🔴 改掉 product_id 後簽章必須驗不過'
		);
		$this->assertSame( '', (string) $pb->get_price(), 'B 商品不得因此取得價格' );

		// 連 product_id 都不改（保留 A 的身分）也必須被身分交叉比對擋下
		$pb2 = \wc_get_product( $b->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $pb2 );
		$this->assertFalse(
			(bool) $this->hook->authorize_cart_item( false, 'kb2', $values_a, $pb2 ),
			'🔴 陣列欄位與實際被定價的物件脫鉤時必須 fail-closed'
		);
	}

	// ====================================================================
	//  M. 2026-08 第 7 輪收斂：空條目判準 + apply_override 重入
	// ====================================================================

	/**
	 * 【第 7 輪 BLOCKING 回歸鎖】「全 null 空條目」不得被當成變體層覆寫
	 *
	 * PricelessRescuePolicy 曾用「變體條目**存在**」判斷，而真正決定價格的
	 * OverridePriceResolver 用「條目存在**且有 ≥1 個非 null 價格**」——判準不一致。
	 * `OverrideItem::get_variation_override()` 回的是 `$this->variations[$vid]?->override`，
	 * **鍵存在就回一個 PriceOverride 物件**，即使三個價格欄位全是 null，
	 * 於是空條目被政策放行、再被 resolver fallback 到**商品層**覆寫
	 * ⇒ 正是 R-4 / S-1 禁止的「商品層覆寫救半成品變體」。
	 *
	 * ⚠️ 空條目不需要竄改，是站長正常操作的產物：`ItemsEditor.tsx` 在設定某個變體的
	 *    **灌水購買人數**時會用 `emptyVariationOverride()`（三個價格皆 null）建出條目。
	 *    「設了灌水人數但不覆寫價格」是合法狀態——要守的是判準，不是資料，
	 *    所以本測試的 fixture 刻意帶 `inflated_count = 7`。
	 *
	 * 第 6 輪的 test_parent_level_override_never_rescues_priceless_variation_on_add_to_cart
	 * 建的賣場**完全沒有 variations 條目**，只走到 null 分支，因此抓不到這個缺口。
	 *
	 * 三個消費端一起鎖（政策 / 加購 / 前台 payload），避免只修其中一條路徑。
	 *
	 * @test
	 * @group security
	 */
	public function test_empty_variation_entry_is_not_treated_as_variation_level_override(): void {
		[ $parent, $vids ] = $this->create_priceless_variable_product();

		$shop_id = $this->create_shop_with_empty_variation_entry(
			(int) $parent->get_id(),
			$vids[0],
			new PriceOverride( '500', '450', null )
		);

		$shop = $this->repo->find( $shop_id );
		$this->assertInstanceOf( ProfitShop::class, $shop );
		$item = $shop->items[ (int) $parent->get_id() ] ?? null;
		$this->assertInstanceOf( OverrideItem::class, $item );

		// 前置：確認 fixture 真的建出了「空條目」而不是根本沒存進去
		$this->assertNotNull(
			$item->get_variation_override( $vids[0] ),
			'fixture 前置：空條目必須真的存在（否則本測試會退化成第 6 輪那條）'
		);
		$this->assertSame(
			7,
			$item->get_variation_inflated_count( $vids[0] )?->value(),
			'fixture 前置：空條目是「只設灌水人數」的正常後台操作產物'
		);

		// (a) 政策：空條目不得放行
		$variation = \wc_get_product( $vids[0] );
		$this->assertInstanceOf( \WC_Product::class, $variation );
		$this->assertFalse(
			PricelessRescuePolicy::should_rescue_priceless( $variation, $item ),
			'🔴 空條目不是變體層覆寫價，不得放行'
		);

		// (b) 加購：手工 POST 必須被擋下
		$this->assertSame(
			'',
			$this->add_variation_to_cart_via_request( (int) $parent->get_id(), $vids[0], $shop_id ),
			'🔴 帶空條目的半成品變體不得靠商品層覆寫被加進購物車'
		);
		$this->assertCount( 0, \WC()->cart->get_cart(), '購物車應維持為空' );

		// (b2) 上游直接帶 profit_shop_id（跳過 AddToCartHook 四道閘門）同樣不得放行
		$this->assertFalse(
			\WC()->cart->add_to_cart( (int) $parent->get_id(), 1, $vids[0], [], [ 'profit_shop_id' => $shop_id ] ),
			'🔴 short-circuit 路徑同樣不得放行'
		);

		// (c) 前台 payload：該變體不得出現在賣場卡片 / Modal 的可選清單內
		$products = ProfitShopFrontData::build_products( $shop );
		$this->assertCount( 1, $products, '前置：賣場應有一個商品' );
		$this->assertSame(
			[],
			$products[0]['variations'] ?? null,
			'🔴 顧客在賣場頁不得看到這個變體（否則不需要偽造請求就能下單）'
		);
	}

	/**
	 * 【第 7 輪 BLOCKING 正向對照】真正的變體層覆寫價仍必須救得起來
	 *
	 * 與上一條共用同一組 fixture，只把空條目換成有價格的覆寫。
	 * 少了這條，把守門寫成「一律拒絕」也會全綠。
	 *
	 * @test
	 * @group happy
	 */
	public function test_real_variation_level_override_still_rescues_priceless_variation(): void {
		[ $parent, $vids ] = $this->create_priceless_variable_product();

		$shop_id = $this->create_shop_with_empty_variation_entry(
			(int) $parent->get_id(),
			$vids[0],
			new PriceOverride( '500', '450', null ),
			new PriceOverride( '30', '27', null )
		);

		$shop = $this->repo->find( $shop_id );
		$this->assertInstanceOf( ProfitShop::class, $shop );
		$item = $shop->items[ (int) $parent->get_id() ] ?? null;
		$this->assertInstanceOf( OverrideItem::class, $item );

		$variation = \wc_get_product( $vids[0] );
		$this->assertInstanceOf( \WC_Product::class, $variation );
		$this->assertTrue(
			PricelessRescuePolicy::should_rescue_priceless( $variation, $item ),
			'有變體層覆寫價時必須放行'
		);

		$key = $this->add_variation_to_cart_via_request( (int) $parent->get_id(), $vids[0], $shop_id );
		$this->assertNotSame( '', $key, '有變體層覆寫價的半成品變體必須加得進購物車' );

		\WC()->cart->calculate_totals();
		$this->assertSame(
			'27.00',
			(string) \WC()->cart->cart_contents[ $key ]['data']->get_price( 'edit' ),
			'應套用變體層覆寫特價，而非商品層的 450'
		);

		$products = ProfitShopFrontData::build_products( $shop );
		$this->assertCount( 1, $products[0]['variations'] ?? [], '該變體必須出現在賣場可選清單內' );
	}

	/**
	 * 【第 7 輪 MAJOR 回歸鎖】apply_override() 不得巢狀重入
	 *
	 * `reset_to_original_price()` 對無定價商品呼叫 `WC_Cart::remove_cart_item()`，
	 * 而該方法 **會** 觸發 `woocommerce_cart_item_removed`
	 *（includes/class-wc-cart.php:1372）→ `WC_Cart::calculate_totals()`（同檔 :131 註冊）
	 * → `woocommerce_before_calculate_totals` → 本 hook。本檔原本斷言它「不會」，
	 * 實測相反：修正前巢狀深度與失敗品項數 **1:1 成長**（N=2/6/12 → 深度 2/6/12），
	 * 每層都是完整的 calculate_totals()（含稅金 / 優惠券 / 運費）。
	 *
	 * 觸發不需要攻擊者：輪替 AUTH_SALT 會讓全站簽章一次失效。
	 *
	 * 本測試以 reflection 讀私有旗標，區分「hook 被呼叫」與「body 真的執行」，
	 * 並斷言兩件事：body 巢狀深度為 1（不得重入）、body **執行次數不隨 N 成長**
	 *（修正前 N 筆失敗品項 ⇒ body 執行 N 次，每次都是完整的一輪驗章 + 套價）。
	 *
	 * ⚠️ 刻意**不**斷言 `woocommerce_before_calculate_totals` 的原始巢狀深度：
	 *    set_up() 用 `new CartPriceOverrideHook(...)` 另外建了一個實例，而建構子
	 *    （見該檔 :206）會自己 add_action，於是測試環境同時有**兩個**已註冊實例、
	 *    各有各的旗標，原始深度因此比正式環境多一層。正式環境只有 Loader 註冊的
	 *    singleton。用「本實例的 body 深度 / 次數」量測不受這個測試環境差異影響。
	 *
	 * @test
	 * @group edge
	 */
	public function test_apply_override_never_re_enters_itself(): void {
		$measure = function ( int $n ): int {
			\WC()->cart->empty_cart();

			$keys = [];
			for ( $i = 0; $i < $n; $i++ ) {
				$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
				$shop_id = $this->create_shop_with_item(
					(int) $product->get_id(),
					new PriceOverride( '500', '450', null )
				);
				$key = $this->add_to_cart_via_request( (int) $product->get_id(), $shop_id );
				$this->assertNotSame( '', $key, '前置：無定價商品 + 覆寫價應可加購' );
				$keys[] = $key;
			}

			// 全部竄改簽章（模擬 AUTH_SALT 輪替）→ 每一筆都會走 reset_to_original_price()
			foreach ( $keys as $key ) {
				\WC()->cart->cart_contents[ $key ]['_profit_signature'] = 'v3.' . str_repeat( 'a', 64 );
			}

			/*
			* ⚠️ set_up() 用 `new CartPriceOverrideHook(...)` 另外建了一個實例，而建構子
			* （該檔 :206）會自己 add_action ⇒ 測試環境同時有**兩個**已註冊實例，
			* 巢狀深度會比正式環境多一層。這裡先把重複的那個拿掉，
			* 讓量測與正式環境（只有 Loader 註冊的 singleton）一致。
			*/
			\remove_action( 'woocommerce_before_calculate_totals', [ $this->hook, 'apply_override' ], 999 );

			$depth     = 0;
			$max_depth = 0;

			/*
			* 🔴 量測「apply_override 這一層的實際巢狀深度」，**不可**改成讀私有旗標。
			*    讀旗標會產生假綠燈：把守門改成 `if ( false )` 之後旗標仍會被設成 true，
			*    body 明明重入了，靠旗標判斷卻仍然回報「深度 1」。實測驗證過這一點。
			*    夾在 998 / 1000 量測真實堆疊深度則無法被這樣騙過，
			*    而且與實作無關（改成「收集 key、迴圈後再刪」同樣適用）。
			*/
			$enter = function () use ( &$depth, &$max_depth ) {
				++$depth;
				$max_depth = max( $max_depth, $depth );
			};
			$leave = function () use ( &$depth ) {
				--$depth;
			};

			\add_action( 'woocommerce_before_calculate_totals', $enter, 998 );
			\add_action( 'woocommerce_before_calculate_totals', $leave, 1000 );

			try {
				\WC()->cart->calculate_totals();
			} finally {
				\remove_action( 'woocommerce_before_calculate_totals', $enter, 998 );
				\remove_action( 'woocommerce_before_calculate_totals', $leave, 1000 );
				\add_action( 'woocommerce_before_calculate_totals', [ $this->hook, 'apply_override' ], 999 );
			}

			return $max_depth;
		};

		$small = $measure( 2 );
		$large = $measure( 8 );

		/*
		* 深度 2 = 最外層那一次 calculate_totals + remove_cart_item 觸發的那一層。
		* 後者是 WooCommerce 的行為（class-wc-cart.php:1372 → :131），我方不改它；
		* 要守的是「它不再堆疊」。
		*/
		$this->assertSame( 2, $small, 'N=2 時巢狀深度應為常數 2' );
		$this->assertSame(
			$small,
			$large,
			'🔴 巢狀深度不得隨驗章失敗品項數成長（修正前 N=2 → 2、N=8 → 8）'
		);
	}

	/**
	 * 【第 7 輪 MAJOR 連帶】重入修正不得改變混合購物車的結果
	 *
	 * 旗標讓巢狀的那次 calculate_totals 跳過套價，因此必須確認：
	 * 合法品項仍在、價格正確、每筆失敗品項只產生一則 notice（不重複）。
	 *
	 * @test
	 * @group edge
	 */
	public function test_reentrancy_guard_preserves_mixed_cart_correctness(): void {
		\WC()->cart->empty_cart();
		\wc_clear_notices();

		$bad = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$product = $this->createSimpleProduct( [ 'regular_price' => '' ] );
			$shop_id = $this->create_shop_with_item(
				(int) $product->get_id(),
				new PriceOverride( '500', '450', null )
			);
			$bad[] = $this->add_to_cart_via_request( (int) $product->get_id(), $shop_id );
		}

		$good = [];
		for ( $i = 0; $i < 2; $i++ ) {
			$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
			$shop_id = $this->create_shop_with_item(
				(int) $product->get_id(),
				new PriceOverride( null, '888', null )
			);
			$good[] = $this->add_to_cart_via_request( (int) $product->get_id(), $shop_id );
		}

		$this->assertCount( 5, \WC()->cart->get_cart(), '前置：5 筆品項' );

		foreach ( $bad as $key ) {
			\WC()->cart->cart_contents[ $key ]['_profit_signature'] = 'v3.' . str_repeat( 'a', 64 );
		}

		\wc_clear_notices();
		\WC()->cart->calculate_totals();

		$this->assertCount( 2, \WC()->cart->get_cart(), '無定價且驗章失敗的品項應被移除，合法品項留下' );

		foreach ( $good as $key ) {
			$this->assertArrayHasKey( $key, \WC()->cart->cart_contents, '🔴 合法品項不得被連帶移除' );
			$this->assertSame(
				'888.00',
				(string) \WC()->cart->cart_contents[ $key ]['data']->get_price( 'edit' ),
				'🔴 合法品項的覆寫價不得因巢狀計價被漏套'
			);
		}

		$this->assertCount(
			3,
			\wc_get_notices( 'error' ),
			'每筆被移除的品項只應產生一則 notice（重入會讓同一則重複寫入）'
		);
		$this->assertSame( 1776.0, (float) \WC()->cart->get_cart_contents_total(), '總額 = 888 × 2' );
	}

	// ====================================================================
	//  Helper：建立 shop / 加入購物車
	// ====================================================================

	/**
	 * 建立「只有 `_price`、regular/sale 皆空」的商品（S-4 fixture）
	 *
	 * 🔴 **不可**用 `set_regular_price('') + set_price('500') + save()`：
	 *    WC_Product_Data_Store_CPT::handle_updated_props()
	 *    （includes/data-stores/class-wc-product-data-store-cpt.php:825-833）只要
	 *    regular_price / sale_price 進了 updated_props，就會把 `_price` 覆寫成
	 *    `sale ?: regular` ⇒ 存完 `_price` 也是空字串，測不到目標狀態。
	 *
	 * 因此改為「先正常存好，再直接改 meta」——這也正是真實站台上這類商品的產生方式
	 * （匯入腳本 / 舊資料 / 部分商品型別）。WooCommerce 自己在同檔 :818-823 的註解明寫
	 * 「`_price` can also be set as a temporary active price for a product」，
	 * 證明這是**合法狀態、不是資料損毀**。
	 *
	 * @param string $price `_price` 的值
	 */
	private function create_price_only_product( string $price = '500' ): \WC_Product {
		$product = $this->createSimpleProduct( [ 'regular_price' => $price ] );
		$id      = (int) $product->get_id();

		\update_post_meta( $id, '_regular_price', '' );
		\update_post_meta( $id, '_sale_price', '' );
		\update_post_meta( $id, '_price', $price );

		\wc_delete_product_transients( $id );
		\clean_post_cache( $id );

		$fresh = \wc_get_product( $id );
		$this->assertInstanceOf( \WC_Product::class, $fresh );
		$this->assertSame( $price, (string) $fresh->get_price( 'edit' ), 'fixture：_price 必須有值' );
		$this->assertSame( '', (string) $fresh->get_regular_price( 'edit' ), 'fixture：regular 必須為空' );
		$this->assertTrue( $fresh->is_purchasable(), 'fixture：這是一個完全可正常販售的商品' );

		return $fresh;
	}

	/**
	 * 模擬**手工 POST**加購指定變體（走 $_POST，四道閘門完整跑一次）
	 *
	 * @param int $product_id   父商品 ID
	 * @param int $variation_id 變體 ID
	 * @param int $shop_id      賣場 ID
	 *
	 * @return string cart_item_key（失敗為空字串）
	 */
	private function add_variation_to_cart_via_request( int $product_id, int $variation_id, int $shop_id ): string {
		$original                = $_POST['profit_shop_id'] ?? null;
		$_POST['profit_shop_id'] = (string) $shop_id;

		try {
			$key = \WC()->cart->add_to_cart( $product_id, 1, $variation_id );
		} finally {
			if ( null === $original ) {
				unset( $_POST['profit_shop_id'] );
			} else {
				$_POST['profit_shop_id'] = $original;
			}
		}

		return is_string( $key ) ? $key : '';
	}

	/**
	 * 建立含單一商品的 ProfitShop
	 *
	 * @param int                  $product_id 商品 ID
	 * @param PriceOverride        $override   價格覆寫
	 * @param array<string, mixed> $settings   賣場設定（排程 startTime / endTime 等；預設無排程）
	 *
	 * @return int 賣場 ID
	 */
	private function create_shop_with_item( int $product_id, PriceOverride $override, array $settings = [] ): int {
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
			],
			settings: $settings
		);

		return $this->repo->save( $shop );
	}

	/**
	 * 模擬**手工 POST**（`$_POST['profit_shop_id']`）加購
	 *
	 * 與 add_to_cart_via_shop() 的差異：後者直接把 profit_shop_id 塞進
	 * $cart_item_data，會觸發 AddToCartHook 開頭的 short-circuit、**跳過全部閘門**；
	 * 本 helper 走 $_POST，是攻擊者實際能操作的那條路徑，四道閘門會完整跑一次。
	 *
	 * @param int $product_id 商品 ID
	 * @param int $shop_id    賣場 ID
	 *
	 * @return string cart_item_key（失敗為空字串）
	 */
	private function add_to_cart_via_request( int $product_id, int $shop_id ): string {
		$original      = $_POST['profit_shop_id'] ?? null;
		$_POST['profit_shop_id'] = (string) $shop_id;

		try {
			$key = \WC()->cart->add_to_cart( $product_id, 1 );
		} finally {
			if ( null === $original ) {
				unset( $_POST['profit_shop_id'] );
			} else {
				$_POST['profit_shop_id'] = $original;
			}
		}

		return is_string( $key ) ? $key : '';
	}

	/**
	 * 組出一筆「已簽章」的 cart item session 陣列（不寫進任何地方，直接給呼叫端用）
	 *
	 * 新設計不再自己讀購物車來源——$values 由 WooCommerce 在
	 * includes/class-wc-cart-session.php:183 逐項傳給
	 * woocommerce_cart_item_is_purchasable，所以測試也直接傳同樣形狀的陣列。
	 *
	 * @param int         $product_id     商品 ID
	 * @param int         $variation_id   變體 ID（0 表示非變體）
	 * @param int         $shop_id        賣場 ID
	 * @param string      $price          已 normalize 的實收價（**簽章依據**）
	 * @param string      $regular        已 normalize 的覆寫原價
	 * @param string      $sale           已 normalize 的覆寫特價
	 * @param string|null $tampered_price 若給值，meta 寫這個值但簽章仍依 $price 產生（模擬竄改）
	 *
	 * @return array<string, mixed>
	 */
	private function make_signed_cart_values(
		int $product_id,
		int $variation_id,
		int $shop_id,
		string $price,
		string $regular,
		string $sale,
		?string $tampered_price = null
	): array {
		// v3 起簽章綁定商品身分：兩個 id 必須與下面陣列裡的欄位一致，
		// 否則 verify_item() 的交叉比對會直接判失敗（那正是 S-2 要鎖的行為）。
		$signature = $this->signature_service->sign(
			$shop_id,
			$this->partner_term_id,
			$price,
			$regular,
			$sale,
			$product_id,
			$variation_id
		);

		return [
			'product_id'                     => $product_id,
			'variation_id'                   => $variation_id,
			'quantity'                       => 1,
			'_profit_shop_id'                => $shop_id,
			'_profit_partner_term_id'        => $this->partner_term_id,
			'_profit_price_override'         => $tampered_price ?? $price,
			'_profit_regular_price_override' => $regular,
			'_profit_sale_price_override'    => $sale,
			'_profit_signature'              => $signature,
		];
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

	/**
	 * 建立一筆「改版前寫入」的 v1 cart_item
	 *
	 * 刻意**不**經過 add_cart_item_data（那會寫出 v2 meta 並在加購當下就把
	 * 覆寫 regular/sale 套進 product 物件，讓「v1 不碰 regular」的斷言失去意義）。
	 * 改為先加入不帶 profit_shop_id 的乾淨 cart_item，再手動注入 4 筆 v1 meta——
	 * 這正是「舊 session 被 restore_from_session 補回來」的實際樣貌。
	 *
	 * @param int    $product_id 商品 ID
	 * @param int    $shop_id    賣場 ID
	 * @param string $price      已 normalize 的價格字串（例如 '888.00'）
	 *
	 * @return string cart_item_key
	 */
	private function add_legacy_v1_cart_item( int $product_id, int $shop_id, string $price ): string {
		$key = \WC()->cart->add_to_cart( $product_id, 1 );
		$key = is_string( $key ) ? $key : '';
		$this->assertNotEmpty( $key, '測試前置：商品應可加入購物車' );

		\WC()->cart->cart_contents[ $key ]['_profit_shop_id']         = $shop_id;
		\WC()->cart->cart_contents[ $key ]['_profit_partner_term_id'] = $this->partner_term_id;
		\WC()->cart->cart_contents[ $key ]['_profit_price_override']  = $price;
		\WC()->cart->cart_contents[ $key ]['_profit_signature']       = $this->make_legacy_v1_signature(
			$shop_id,
			$this->partner_term_id,
			$price
		);

		return $key;
	}

	/**
	 * 建立含「單一變體覆寫」的 ProfitShop
	 *
	 * @param int           $product_id   父商品 ID
	 * @param int           $variation_id 變體 ID
	 * @param PriceOverride $override     變體層覆寫
	 *
	 * @return int 賣場 ID
	 */
	private function create_shop_with_variation_override( int $product_id, int $variation_id, PriceOverride $override ): int {
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
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$variation_id => new VariationOverride( $override, new InflatedCount( 0 ) ),
					]
				),
			]
		);

		return $this->repo->save( $shop );
	}

	/**
	 * 建立「商品層有覆寫 + 指定變體帶一個條目」的賣場（第 7 輪 fixture）
	 *
	 * `$variation_override` 傳 null（預設）時建出**全 null 空條目**——那正是
	 * `ItemsEditor.tsx` 的 `emptyVariationOverride()` 在站長只設定「灌水購買人數」時
	 * 留下的形狀。`inflated_count` 刻意給 7（非 0），證明這是有意義的正常設定而不是垃圾資料。
	 *
	 * @param int                $product_id         父商品 ID
	 * @param int                $variation_id       變體 ID
	 * @param PriceOverride      $parent_override    商品層覆寫
	 * @param PriceOverride|null $variation_override 變體層覆寫；null = 全 null 空條目
	 *
	 * @return int 賣場 ID
	 */
	private function create_shop_with_empty_variation_entry(
		int $product_id,
		int $variation_id,
		PriceOverride $parent_override,
		?PriceOverride $variation_override = null
	): int {
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
					override: $parent_override,
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$variation_id => new VariationOverride(
							$variation_override ?? new PriceOverride( null, null, null ),
							new InflatedCount( 7 )
						),
					]
				),
			]
		);

		return $this->repo->save( $shop );
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
	 * 手工組出 v1 簽章（公式必須與 Phase 3-D 的 production 實作完全一致）
	 *
	 * 'cart-signature:v1|{shop_id}|{partner_term_id}|{price}'
	 *
	 * 用 WpSaltProvider 取 salt，與 hook 內部的 CartPriceSignatureService 同源。
	 *
	 * @param int    $shop_id         賣場 ID
	 * @param int    $partner_term_id 夥伴 term ID
	 * @param string $price           已 normalize 的價格字串
	 */
	private function make_legacy_v1_signature( int $shop_id, int $partner_term_id, string $price ): string {
		$payload = 'cart-signature:v1|' . $shop_id . '|' . $partner_term_id . '|' . $price;
		$salt    = ( new WpSaltProvider() )->get( 'auth' );

		return 'v1.' . hash_hmac( 'sha256', $payload, $salt );
	}
}
