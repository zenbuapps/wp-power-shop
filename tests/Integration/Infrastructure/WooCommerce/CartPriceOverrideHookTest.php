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

		// signature 必須通過驗證（v2：regular / sale 也納入 payload）
		$valid = $this->signature_service->verify(
			$shop_id,
			$this->partner_term_id,
			'888.00',
			(string) $cart_item['_profit_signature'],
			(string) $cart_item['_profit_regular_price_override'],
			(string) $cart_item['_profit_sale_price_override']
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
