<?php
/**
 * OrderLineItemSettlementHook 整合測試（結帳寫入分潤結算 meta）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5（order itemmeta 欄位表）、
 *          §5.2（結帳價格套用 + 寫入 order_itemmeta）、§5.7（邊緣情境）
 *
 * 對應實作：
 *   inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/OrderLineItemSettlementHook.php
 *   inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/WcDecimalRounding.php
 *   inc/classes/Domains/ProfitShop/Infrastructure/Persistence/OrderItemSettlementRepository::save_to_item()
 *
 * ═══ 這條鏈路為什麼重要 ═══
 *
 * 顧客從分潤賣場加購 → cart item 帶 _profit_* meta 與 HMAC 簽章（CartPriceOverrideHook）
 * → **本 hook** → order itemmeta 凍結快照 → 夥伴報表 / 站長撥款。
 * 少了本 hook，訂單成立後完全沒有分潤紀錄，夥伴報表永遠是空的，而且沒有任何錯誤訊息。
 *
 * ═══ 🔴 cart 與 order 的 meta key 命名不同 ═══
 *
 * | cart item meta（CartPriceOverrideHook 寫） | order itemmeta（本 hook 寫）  |
 * |---|---|
 * | `_profit_shop_id`                  | `_profit_shop_id`（同名）        |
 * | `_profit_partner_term_id`          | `_profit_partner_term_id`（同名）|
 * | （cart 沒有）                       | `_profit_rate`（讀賣場 CPT）     |
 * | `_profit_regular_price_override`   | `_override_regular_price`        |
 * | `_profit_sale_price_override`      | `_override_sale_price`           |
 * | （cart 沒有）                       | `_override_signup_fee`           |
 * | `_profit_price_override`           | `_actual_price`                  |
 * | （cart 沒有）                       | `_profit_amount`（計算）         |
 * | （cart 沒有）                       | `_settlement_status` = 'pending' |
 *
 * 寫錯對映**不會有任何錯誤訊息**，只會產生金額錯誤的結算列，因此本檔逐欄位斷言。
 *
 * ═══ 三條結帳路徑共用同一個 hook ═══
 *
 * WooCommerce 10.1.4（本機站台）：
 *   - includes/class-wc-checkout.php:571
 *     `do_action( 'woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $values, $order )`
 *   - src/StoreApi/Utilities/OrderController.php:789（Block / Store API 結帳）
 *     `wc()->checkout->create_order_line_items( $order, $cart )` ⇒ 走上面同一個方法
 *   - `grep -rn "woocommerce_checkout_create_order_line_item" src/ includes/` 只有兩處命中
 *     （:537 的 `_object` filter 與 :571 的 action）
 *
 * ⚠️ 「共用同一個方法」是 WooCommerce 目前的**實作細節**，不是它承諾的契約——
 *    所以下面兩條 happy path（傳統結帳 / Store API）**刻意各測一條**，
 *    日後 WooCommerce 若把兩條路徑拆開，會立刻紅燈而不是靜默漏寫。
 *
 * ⚠️ 行號一律以 **WooCommerce 10.1.4**（本機站台）為準；wp-env 容器裝的是另一個版本
 *    （實測 11.0.0-rc.1），行號會位移，對不上時先確認版本再懷疑註解。
 *
 * @group profit_shop
 * @group infrastructure
 * @group woocommerce
 * @group settlement
 */

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\OrderLineItemSettlementHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpSaltProvider;
use Tests\Integration\TestCase;

/**
 * 結帳寫入分潤結算 meta 的整合測試
 */
final class OrderLineItemSettlementHookTest extends TestCase {

	/**
	 * 賣場 Repository
	 *
	 * @var CptProfitShopRepository
	 */
	private CptProfitShopRepository $repo;

	/**
	 * 結算 Repository（讀回驗證用）
	 *
	 * @var OrderItemSettlementRepository
	 */
	private OrderItemSettlementRepository $settlements;

	/**
	 * 預設 partner term id
	 *
	 * @var int
	 */
	private int $partner_term_id = 0;

	/**
	 * setUp：清空 cart、建立 partner term
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repo        = CptProfitShopRepository::instance();
		$this->settlements = OrderItemSettlementRepository::instance();

		if ( null !== \WC()->cart ) {
			\WC()->cart->empty_cart();
		}

		$term                  = \wp_insert_term( '結算測試夥伴 ' . uniqid(), 'profit_partner' );
		$this->partner_term_id = is_array( $term ) ? (int) $term['term_id'] : 0;
	}

	/**
	 * tearDown：清 cart、清 partner term
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
	//  A. 註冊（Loader 接線）
	// ====================================================================

	/**
	 * Loader 必須把本 hook 掛在 woocommerce_checkout_create_order_line_item，priority 20
	 *
	 * priority 20 的理由見實作 docblock：晚於絕大多數預設 10 的擴充（讀得到它們調整後的
	 * quantity），又留下 > 20 的空間給需要讀我方分潤 meta 的擴充。
	 *
	 * @group smoke
	 */
	public function test_hook_is_registered_by_loader_at_priority_20(): void {
		$this->assertSame(
			20,
			\has_action( 'woocommerce_checkout_create_order_line_item', [ OrderLineItemSettlementHook::instance(), 'freeze_settlement' ] ),
			'Loader 應把 freeze_settlement 掛在 priority 20'
		);
	}

	// ====================================================================
	//  B. Happy path：兩條結帳路徑
	// ====================================================================

	/**
	 * 傳統結帳：11 個欄位逐一正確（含 cart → order 的命名對映）
	 *
	 * @group happy
	 */
	public function test_classic_checkout_writes_all_settlement_meta(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$this->add_to_cart( $product->get_id(), $shop_id );

		$item_id = $this->checkout_classic();

		$this->assertGreaterThan( 0, $item_id, 'line item 應已儲存並取得 ID' );

		// 同名欄位
		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( (string) $this->partner_term_id, $this->meta( $item_id, '_profit_partner_term_id' ) );

		// cart 沒有、必須從賣場 CPT 讀出來凍結的欄位
		$this->assertSame( '20', $this->meta( $item_id, '_profit_rate' ) );

		// 🔴 命名對映：cart 的 _profit_regular_price_override → order 的 _override_regular_price
		$this->assertSame( '1000.00', $this->meta( $item_id, '_override_regular_price' ) );
		$this->assertSame( '888.00', $this->meta( $item_id, '_override_sale_price' ) );
		$this->assertSame( '', $this->meta( $item_id, '_override_signup_fee' ), '非訂閱商品，signup_fee 為空字串' );

		// 🔴 命名對映：cart 的 _profit_price_override → order 的 _actual_price
		$this->assertSame( '888.00', $this->meta( $item_id, '_actual_price' ) );

		// 888.00 × 1 × 20% = 177.60
		$this->assertSame( '177.60', $this->meta( $item_id, '_profit_amount' ) );

		$this->assertSame( 'pending', $this->meta( $item_id, '_settlement_status' ) );

		// settled_at / settled_by 尚未撥款 ⇒ 不寫 meta（與 Repository::save() 的 null ⇒ delete 對齊）
		$this->assertSame( '', $this->meta( $item_id, '_settled_at' ) );
		$this->assertSame( '', $this->meta( $item_id, '_settled_by' ) );

		// Repository 讀得回一個合法的 Entity（證明寫入格式與讀取端相容）
		$record = $this->settlements->find_by_order_item( $item_id );
		$this->assertInstanceOf( SettlementRecord::class, $record );
		$this->assertSame( $shop_id, $record->shop_id );
		$this->assertSame( $this->partner_term_id, $record->partner_term_id );
		$this->assertSame( 20, $record->rate->value() );
		$this->assertSame( '888.00', $record->actual_price );
		$this->assertSame( '177.60', $record->profit_amount );
		$this->assertSame( SettlementRecord::STATUS_PENDING, $record->status );
		$this->assertNull( $record->settled_at );
		$this->assertNull( $record->settled_by );
	}

	/**
	 * Store API / Block 結帳路徑同樣寫入
	 *
	 * 刻意不與傳統結帳共用一條測試：見檔頭「三條結帳路徑」的說明。
	 *
	 * @group happy
	 */
	public function test_store_api_checkout_writes_settlement_meta(): void {
		if ( ! class_exists( \Automattic\WooCommerce\StoreApi\Utilities\OrderController::class ) ) {
			$this->markTestSkipped( 'Store API OrderController 不存在（WooCommerce 版本過舊）' );
		}

		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$this->add_to_cart( $product->get_id(), $shop_id );

		$controller = new \Automattic\WooCommerce\StoreApi\Utilities\OrderController();
		$order      = $controller->create_order_from_cart();
		$order->save();

		$item_id = $this->first_item_id( $order );

		$this->assertGreaterThan( 0, $item_id, 'Store API 應建立 line item' );
		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '20', $this->meta( $item_id, '_profit_rate' ) );
		$this->assertSame( '888.00', $this->meta( $item_id, '_actual_price' ) );
		$this->assertSame( '177.60', $this->meta( $item_id, '_profit_amount' ) );
		$this->assertSame( 'pending', $this->meta( $item_id, '_settlement_status' ) );
	}

	// ====================================================================
	//  C. 安全：沒驗過章的一律不寫
	// ====================================================================

	/**
	 * 簽章被竄改 → 完全不寫任何分潤 meta（訂單本身照常成立）
	 *
	 * 這是「偽造 cart meta 就能憑空生出分潤紀錄」的最後一道防線：
	 * 攻擊者若能繞過定價鏈路直接往 cart item 塞 _profit_* meta，
	 * 沒有本檢查就會產生一筆帶金額的合法結算列。
	 *
	 * @group security
	 */
	public function test_tampered_signature_writes_no_settlement_meta(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$key = $this->add_to_cart( $product->get_id(), $shop_id );

		// 竄改實收價（簽章沒跟著改 ⇒ 驗章必敗）
		\WC()->cart->cart_contents[ $key ]['_profit_price_override'] = '1.00';

		$item_id = $this->checkout_classic( false );

		$this->assertGreaterThan( 0, $item_id, '訂單與 line item 仍應正常建立' );
		$this->assertSame( '', $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '', $this->meta( $item_id, '_profit_amount' ) );
		$this->assertSame( '', $this->meta( $item_id, '_settlement_status' ) );
		$this->assertNull( $this->settlements->find_by_order_item( $item_id ) );
	}

	/**
	 * 簽章直接被移除 → 同樣不寫（version 判不出來就 fail-closed）
	 *
	 * @group security
	 */
	public function test_missing_signature_writes_no_settlement_meta(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$key = $this->add_to_cart( $product->get_id(), $shop_id );
		unset( \WC()->cart->cart_contents[ $key ]['_profit_signature'] );

		$item_id = $this->checkout_classic( false );

		$this->assertSame( '', $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertNull( $this->settlements->find_by_order_item( $item_id ) );
	}

	/**
	 * 一般商品（cart item 完全沒有 _profit_* meta）→ 不受任何影響
	 *
	 * @group security
	 */
	public function test_plain_cart_item_is_untouched(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );

		\WC()->cart->add_to_cart( $product->get_id(), 1 );

		$item_id = $this->checkout_classic();

		$this->assertGreaterThan( 0, $item_id );
		$this->assertSame( '', $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '', $this->meta( $item_id, '_profit_rate' ) );
		$this->assertSame( '', $this->meta( $item_id, '_settlement_status' ) );
	}

	// ====================================================================
	//  D. 金額計算
	// ====================================================================

	/**
	 * qty > 1：金額必須乘上數量
	 *
	 * 100.00 × 3 × 20% = 60.00
	 *
	 * @group edge
	 */
	public function test_profit_amount_multiplies_quantity(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '150', '100', null ), 20 );

		$this->add_to_cart( $product->get_id(), $shop_id, 3 );

		$item_id = $this->checkout_classic();

		$this->assertSame( '100.00', $this->meta( $item_id, '_actual_price' ), '_actual_price 是**單價**，不是小計' );
		$this->assertSame( '60.00', $this->meta( $item_id, '_profit_amount' ) );
	}

	/**
	 * bcmath 精度：333.33 × 3 × 7% —— 不得出現浮點尾數
	 *
	 * 精確值 999.99 × 7 / 100 = 69.9993 → 四捨五入到貨幣小數位 = 70.00
	 *
	 * @group edge
	 */
	public function test_profit_amount_uses_bcmath_without_float_artifacts(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '333.33', null, null ), 7 );

		$this->add_to_cart( $product->get_id(), $shop_id, 3 );

		$item_id = $this->checkout_classic();
		$amount  = $this->meta( $item_id, '_profit_amount' );

		$this->assertSame( '70.00', $amount );
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d{2}$/',
			$amount,
			'金額必須是乾淨的兩位小數字串，不可有 69.99930000000001 這種浮點尾數'
		);
	}

	/**
	 * 四捨五入必須是 half-up，不是無條件捨去
	 *
	 * 0.15 × 1 × 30% = 0.045 → half-up 到 2 位 = 0.05
	 * （若把 rounding 實作成 bcmath 的預設截斷行為，這裡會得到 0.04）
	 *
	 * @group edge
	 */
	public function test_profit_amount_rounds_half_up(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '0.15', null, null ), 30 );

		$this->add_to_cart( $product->get_id(), $shop_id );

		$item_id = $this->checkout_classic();

		$this->assertSame( '0.15', $this->meta( $item_id, '_actual_price' ) );
		$this->assertSame( '0.05', $this->meta( $item_id, '_profit_amount' ) );
	}

	/**
	 * rate = 0 的賣場：仍寫入歸屬，金額為 0
	 *
	 * 歸屬（shop / partner）與金額是兩件事——rate 0 是站長的合法設定，
	 * 不寫歸屬會讓那些訂單在報表上完全消失。
	 *
	 * @group edge
	 */
	public function test_zero_rate_still_records_attribution(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 0 );

		$this->add_to_cart( $product->get_id(), $shop_id );

		$item_id = $this->checkout_classic();

		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '0', $this->meta( $item_id, '_profit_rate' ) );
		$this->assertSame( '0.00', $this->meta( $item_id, '_profit_amount' ) );
	}

	// ====================================================================
	//  E. 邊緣情境
	// ====================================================================

	/**
	 * 下單當下賣場已被刪除 → **仍寫入完整歸屬**，rate 記 0、金額記 '0.00'
	 *
	 * 🔴 這裡刻意**不是**「完全不寫」：`CartPriceOverrideHook::apply_override()`（999）
	 * 不重讀賣場、只驗簽章就對 frozen 價格套價 ⇒ 顧客**確實已經付了分潤價**。
	 * 零紀錄會製造「顧客付了分潤價、系統查無此筆」——正是本 hook 要消滅的東西。
	 *
	 * rate 記 0 是保守且明顯異常的值，配合 `WC::logger` 的 error 讓結算頁標紅
	 * 「賣場已刪除，需人工設定比例」。
	 *
	 * @group edge
	 */
	public function test_deleted_shop_still_records_attribution_with_zero_rate(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$this->add_to_cart( $product->get_id(), $shop_id );

		// 加購之後、結帳之前賣場被永久刪除
		\wp_delete_post( $shop_id, true );

		$item_id = $this->checkout_classic( false );

		$this->assertGreaterThan( 0, $item_id, '訂單仍必須成立——結算記帳失敗不可擋下已付款的交易' );

		// 歸屬完整保留（顧客付了分潤價，這筆帳必須查得到）
		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( (string) $this->partner_term_id, $this->meta( $item_id, '_profit_partner_term_id' ) );
		$this->assertSame( '888.00', $this->meta( $item_id, '_actual_price' ), '實付單價照實記錄' );

		// 比例無可信來源 ⇒ 記 0，金額同步為 0
		$this->assertSame( '0', $this->meta( $item_id, '_profit_rate' ) );
		$this->assertSame( '0.00', $this->meta( $item_id, '_profit_amount' ) );
		$this->assertSame( 'pending', $this->meta( $item_id, '_settlement_status' ) );

		$record = $this->settlements->find_by_order_item( $item_id );
		$this->assertInstanceOf( SettlementRecord::class, $record );
		$this->assertSame( 0, $record->rate->value() );
	}

	/**
	 * 賣場被下架（draft）：仍寫入
	 *
	 * 交易在賣場還上架時就成立了，rate 也仍然讀得到；
	 * 此時拒寫等於讓站長「下架賣場」變成「抹掉夥伴已成交的分潤」。
	 *
	 * @group edge
	 */
	public function test_unpublished_shop_still_freezes_rate(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$this->add_to_cart( $product->get_id(), $shop_id );

		\wp_update_post(
			[
				'ID'          => $shop_id,
				'post_status' => 'draft',
			]
		);
		\wp_cache_flush();

		$item_id = $this->checkout_classic();

		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '20', $this->meta( $item_id, '_profit_rate' ) );
		$this->assertSame( '177.60', $this->meta( $item_id, '_profit_amount' ) );
	}

	/**
	 * v1 簽章（改版前的既有購物車）仍必須寫入
	 *
	 * v1 payload 只簽 price，沒有 regular / sale ⇒ 兩個 _override_* 欄位為空字串。
	 * 拒收 v1 等於讓那些**永不過期**的持久化購物車在結帳時靜默失去分潤歸屬。
	 *
	 * @group edge
	 */
	public function test_legacy_v1_signature_is_accepted(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$key = $this->add_to_cart( $product->get_id(), $shop_id );

		// 還原成 v1 形狀：只有 4 筆 meta + v1 簽章
		unset(
			\WC()->cart->cart_contents[ $key ]['_profit_regular_price_override'],
			\WC()->cart->cart_contents[ $key ]['_profit_sale_price_override']
		);
		\WC()->cart->cart_contents[ $key ]['_profit_signature'] = $this->make_legacy_signature(
			'v1',
			$shop_id,
			$this->partner_term_id,
			'888.00'
		);

		$item_id = $this->checkout_classic( false );

		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '888.00', $this->meta( $item_id, '_actual_price' ) );
		$this->assertSame( '177.60', $this->meta( $item_id, '_profit_amount' ) );
		$this->assertSame( '', $this->meta( $item_id, '_override_regular_price' ), 'v1 從未簽過 regular，必須留空' );
		$this->assertSame( '', $this->meta( $item_id, '_override_sale_price' ) );
	}

	/**
	 * v2 簽章仍必須寫入（regular / sale 有簽，商品身分沒簽）
	 *
	 * @group edge
	 */
	public function test_legacy_v2_signature_is_accepted(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$key = $this->add_to_cart( $product->get_id(), $shop_id );

		\WC()->cart->cart_contents[ $key ]['_profit_signature'] = $this->make_legacy_signature(
			'v2',
			$shop_id,
			$this->partner_term_id,
			'888.00',
			'1000.00',
			'888.00'
		);

		$item_id = $this->checkout_classic( false );

		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '1000.00', $this->meta( $item_id, '_override_regular_price' ) );
		$this->assertSame( '177.60', $this->meta( $item_id, '_profit_amount' ) );
	}

	/**
	 * v3 簽章綁定商品身分：line item 的商品與簽章不符 → 不寫
	 *
	 * 模擬「把 A 商品的整組 _profit_* meta 搬到 B 商品的 cart item 上」。
	 *
	 * @group security
	 */
	public function test_v3_signature_bound_to_wrong_product_is_rejected(): void {
		$product_a = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );
		$product_b = $this->createSimpleProduct( [ 'regular_price' => '1200' ] );

		$shop_a = $this->create_shop( $product_a->get_id(), new PriceOverride( '1000', '888', null ), 20 );
		$shop_b = $this->create_shop( $product_b->get_id(), new PriceOverride( '1000', '888', null ), 20 );

		$key_a = $this->add_to_cart( $product_a->get_id(), $shop_a );
		$key_b = $this->add_to_cart( $product_b->get_id(), $shop_b );

		// 把 A 的整組簽章 meta 搬到 B 的 cart item 上
		foreach ( [ '_profit_shop_id', '_profit_partner_term_id', '_profit_price_override', '_profit_regular_price_override', '_profit_sale_price_override', '_profit_signature' ] as $meta_key ) {
			\WC()->cart->cart_contents[ $key_b ][ $meta_key ] = \WC()->cart->cart_contents[ $key_a ][ $meta_key ];
		}

		$this->checkout_classic( false );

		$order    = $this->last_order;
		$found_b  = 0;
		$found_a  = 0;
		foreach ( $order->get_items() as $item ) {
			$item_id = (int) $item->get_id();
			if ( (int) $item->get_product_id() === $product_b->get_id() ) {
				$found_b = $item_id;
			}
			if ( (int) $item->get_product_id() === $product_a->get_id() ) {
				$found_a = $item_id;
			}
		}

		$this->assertGreaterThan( 0, $found_a );
		$this->assertGreaterThan( 0, $found_b );
		$this->assertSame( (string) $shop_a, $this->meta( $found_a, '_profit_shop_id' ), 'A 自己那筆不受影響' );
		$this->assertSame( '', $this->meta( $found_b, '_profit_shop_id' ), 'B 被搬過來的簽章必須驗不過' );
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 最近一次 checkout_classic() 建立的訂單
	 *
	 * @var \WC_Order|null
	 */
	private ?\WC_Order $last_order = null;

	/**
	 * 建立一個含單一商品的分潤賣場
	 *
	 * @param int           $product_id 商品 ID
	 * @param PriceOverride $override   價格覆寫
	 * @param int           $rate       分潤比例（0-100）
	 *
	 * @return int 賣場 ID
	 */
	/**
	 * 🔴 寫入失敗一律靜默降級，**絕不可**中斷顧客結帳
	 *
	 * 對應規格：attribute-order-line-item.feature「錯誤（降級）」
	 *
	 * 本 hook 在 `WC_Checkout::create_order_line_items()` 內執行，此刻顧客的款項
	 * 流程**已經啟動**。結算記帳失敗只是「少一筆分潤紀錄」（站長事後補得回來）；
	 * 讓例外冒出去卻會變成「顧客付不了款」——後者嚴重得多。
	 *
	 * ⚠️ 這條在 2026-08-11 之前沒有測試：原標註指向
	 * `test_tampered_signature_writes_no_settlement_meta`，那驗的是「驗章失敗不寫
	 * meta」，走的是**正常的 return 路徑**，`catch (\Throwable)` 一次都不會執行。
	 * 把整段 try/catch 拿掉也不會有任何測試轉紅。
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_exception_during_freeze_never_breaks_checkout(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '800', '600', null ), 10 );
		$key     = $this->add_to_cart( $product->get_id(), $shop_id );

		// 真實、已簽章的 cart values（由 CartPriceOverrideHook 寫入）
		$values = \WC()->cart->cart_contents[ $key ];
		$this->assertArrayHasKey( '_profit_signature', $values, '測試前置：cart values 必須已簽章' );

		// 只把 repository 換成一定會爆的版本，其餘依賴全用真的
		$exploding = new class() implements \J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface {
			public function find( int $id ): ?ProfitShop {
				throw new \RuntimeException( 'DB exploded during checkout' );
			}
			public function find_by_slug( string $slug ): ?ProfitShop {
				throw new \RuntimeException( 'DB exploded during checkout' );
			}
			public function save( ProfitShop $shop ): int {
				throw new \RuntimeException( 'DB exploded during checkout' );
			}
			public function delete( int $id ): void {
				throw new \RuntimeException( 'DB exploded during checkout' );
			}
			/** @return ProfitShop[] */
			public function find_by_partner( int $term_id ): array {
				throw new \RuntimeException( 'DB exploded during checkout' );
			}
			/** @return ProfitShop[] */
			public function all(): array {
				throw new \RuntimeException( 'DB exploded during checkout' );
			}
		};

		$hook = $this->make_hook( $exploding );

		$item = new \WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );
		$item->set_quantity( 1 );

		try {
			// 唯一的驗法就是「呼叫它，然後這一行還跑得到」——
			// 例外若冒出去，PHPUnit 會把這條標成 error 而不是 failure
			$hook->freeze_settlement( $item, $key, $values, null );

			$this->assertTrue(
				true,
				'freeze_settlement 內部拋例外時必須被吞掉，讓 WC_Checkout 繼續建單'
			);

			// 降級不等於假裝成功：不得寫入半套的分潤 meta
			$this->assertSame(
				'',
				(string) $item->get_meta( '_profit_shop_id', true ),
				'降級時不得寫入半套的分潤 meta'
			);
		} finally {
			$this->unhook( $hook );
		}
	}

	/**
	 * 🔴 後台情境同樣必須寫入分潤 meta
	 *
	 * 對應規格：attribute-order-line-item.feature「環境（後台）」
	 *
	 * 購物車那三個 hook 都有 `is_admin() && ! wp_doing_ajax()` 就不掛載的守門。
	 * 本 hook **刻意沒有**——退款一定發生在後台、站長手動建單也在後台。
	 * 照抄那道守門的症狀是「後台手動建立的訂單完全沒有分潤紀錄」，
	 * 而且沒有任何錯誤訊息。
	 *
	 * ⚠️ 原標註指向 `test_hook_is_registered_by_loader_at_priority_20`，
	 * 那條在**前台**情境驗註冊，對「後台會不會被守門擋掉」一個字都沒說。
	 *
	 * @test
	 * @group security
	 */
	public function test_hook_still_writes_meta_in_admin_context(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( $product->get_id(), new PriceOverride( '800', '600', null ), 10 );
		$key     = $this->add_to_cart( $product->get_id(), $shop_id );
		$values  = \WC()->cart->cart_contents[ $key ];

		\set_current_screen( 'shop_order' );
		$hook = null;

		try {
			$this->assertTrue( \is_admin(), '測試前置：必須真的處於後台情境' );

			// 在後台情境**當場**建構 hook——若有人加上 is_admin() 守門，
			// 這一步就不會註冊 action
			$hook = $this->make_hook( $this->repo );

			$this->assertNotFalse(
				\has_action( 'woocommerce_checkout_create_order_line_item', [ $hook, 'freeze_settlement' ] ),
				'後台情境下 hook 仍必須註冊（退款與手動建單都發生在後台）'
			);

			$item = new \WC_Order_Item_Product();
			$item->set_product_id( $product->get_id() );
			$item->set_quantity( 1 );

			$hook->freeze_settlement( $item, $key, $values, null );

			$this->assertSame(
				(string) $shop_id,
				(string) $item->get_meta( '_profit_shop_id', true ),
				'後台情境下仍必須寫入分潤 meta'
			);
			$this->assertNotSame(
				'',
				(string) $item->get_meta( '_profit_amount', true ),
				'後台情境下仍必須算出分潤金額'
			);
		} finally {
			if ( $hook ) {
				$this->unhook( $hook );
			}
			\set_current_screen( 'front' );
		}
	}

	/**
	 * 用真實依賴建一個 hook 實例，只有 repository 可替換
	 *
	 * @param \J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface $shops 賣場 repository
	 */
	private function make_hook( $shops ): OrderLineItemSettlementHook {
		$rounding = new \J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\WcDecimalRounding();

		return new OrderLineItemSettlementHook(
			$shops,
			$this->settlements,
			new \J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService( new WpSaltProvider() ),
			new \J7\PowerShop\Domains\ProfitShop\Domain\Service\ProfitCalculator( $rounding ),
			new \J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\MoneyFormatter( $rounding )
		);
	}

	/** 解除測試中額外建構的 hook，避免污染同一個 request 內的其他測試 */
	private function unhook( OrderLineItemSettlementHook $hook ): void {
		\remove_action( 'woocommerce_checkout_create_order_line_item', [ $hook, 'freeze_settlement' ], 20 );
	}

	private function create_shop( int $product_id, PriceOverride $override, int $rate ): int {
		$shop = new ProfitShop(
			id: 0,
			title: '結算測試賣場 ' . uniqid(),
			slug: 'settlement-shop-' . uniqid(),
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: $this->partner_term_id,
			rate: new ProfitRate( $rate ),
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
	 * 以賣場情境加入購物車（走 $_POST，四道閘門完整跑一次）
	 *
	 * @param int $product_id 商品 ID
	 * @param int $shop_id    賣場 ID
	 * @param int $quantity   數量
	 *
	 * @return string cart_item_key
	 */
	private function add_to_cart( int $product_id, int $shop_id, int $quantity = 1 ): string {
		$original                = $_POST['profit_shop_id'] ?? null;
		$_POST['profit_shop_id'] = (string) $shop_id;

		try {
			$key = \WC()->cart->add_to_cart( $product_id, $quantity );
		} finally {
			if ( null === $original ) {
				unset( $_POST['profit_shop_id'] );
			} else {
				$_POST['profit_shop_id'] = $original;
			}
		}

		$this->assertIsString( $key, 'add_to_cart 應成功' );
		$this->assertNotSame( '', $key );

		return $key;
	}

	/**
	 * 走傳統結帳建立訂單 line items（WC_Checkout::create_order_line_items）
	 *
	 * @param bool $calculate_totals 是否先 calculate_totals()；竄改測試必須傳 false，
	 *                               否則 CartPriceOverrideHook 會在 999 先把被竄改的
	 *                               _profit_* meta 整組清掉，測不到本 hook 的行為。
	 *
	 * @return int 第一筆 line item 的 ID
	 */
	private function checkout_classic( bool $calculate_totals = true ): int {
		if ( $calculate_totals ) {
			\WC()->cart->calculate_totals();
		} else {
			// create_order_line_items 需要 line_subtotal / line_total 等欄位，
			// 用 WC 自己的 calculate 之外的最小補齊：直接補上目前 cart_contents 缺的鍵。
			$this->fill_line_totals();
		}

		$order = new \WC_Order();
		\WC()->checkout()->create_order_line_items( $order, \WC()->cart );
		$order->save();

		$this->last_order = $order;

		return $this->first_item_id( $order );
	}

	/**
	 * 在不呼叫 calculate_totals() 的情況下補齊 create_order_line_items 需要的欄位
	 *
	 * @return void
	 */
	private function fill_line_totals(): void {
		foreach ( \WC()->cart->cart_contents as $key => $item ) {
			if ( isset( $item['line_total'] ) ) {
				continue;
			}
			$product = $item['data'] ?? null;
			$price   = $product instanceof \WC_Product ? (float) $product->get_price() : 0.0;
			$qty     = (int) ( $item['quantity'] ?? 1 );

			\WC()->cart->cart_contents[ $key ]['line_subtotal']     = $price * $qty;
			\WC()->cart->cart_contents[ $key ]['line_total']        = $price * $qty;
			\WC()->cart->cart_contents[ $key ]['line_subtotal_tax'] = 0;
			\WC()->cart->cart_contents[ $key ]['line_tax']          = 0;
			\WC()->cart->cart_contents[ $key ]['line_tax_data']     = [
				'subtotal' => [],
				'total'    => [],
			];
		}
	}

	/**
	 * 取得訂單第一筆 line item 的 ID
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return int
	 */
	private function first_item_id( \WC_Order $order ): int {
		foreach ( $order->get_items() as $item ) {
			return (int) $item->get_id();
		}
		return 0;
	}

	/**
	 * 讀 order itemmeta（找不到回空字串）
	 *
	 * @param int    $item_id  order item ID
	 * @param string $meta_key meta key
	 *
	 * @return string
	 */
	private function meta( int $item_id, string $meta_key ): string {
		return (string) \wc_get_order_item_meta( $item_id, $meta_key, true );
	}

	/**
	 * 手工組出 v1 / v2 簽章（公式必須與 CartPriceSignatureService 完全一致）
	 *
	 * v1：'cart-signature:v1|{shop}|{partner}|{price}'
	 * v2：'cart-signature:v2|{shop}|{partner}|{price}|{regular}|{sale}'
	 *
	 * @param string $version         'v1' | 'v2'
	 * @param int    $shop_id         賣場 ID
	 * @param int    $partner_term_id 夥伴 term ID
	 * @param string $price           已 normalize 的價格
	 * @param string $regular         已 normalize 的原價（v2 用）
	 * @param string $sale            已 normalize 的特價（v2 用）
	 *
	 * @return string
	 */
	private function make_legacy_signature(
		string $version,
		int $shop_id,
		int $partner_term_id,
		string $price,
		string $regular = '',
		string $sale = ''
	): string {
		$payload = 'cart-signature:' . $version . '|' . $shop_id . '|' . $partner_term_id . '|' . $price;

		if ( 'v2' === $version ) {
			$payload .= '|' . $regular . '|' . $sale;
		}

		$salt = ( new WpSaltProvider() )->get( 'auth' );

		return $version . '.' . hash_hmac( 'sha256', $payload, $salt );
	}
}
