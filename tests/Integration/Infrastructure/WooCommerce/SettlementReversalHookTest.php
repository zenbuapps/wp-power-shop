<?php
/**
 * SettlementReversalHook 整合測試（退款 / 取消訂單時回沖分潤結算）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.3、§5.7、§7.9
 *          specs/features/profit-shop/reverse-settlement-on-refund.feature
 *
 * 對應實作：
 *   inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/SettlementReversalHook.php
 *   inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/RefundAfterPaidNotice.php
 *
 * ═══ 這條鏈路為什麼重要 ═══
 *
 * 少了回沖，站長會對一筆**已經把錢退還給顧客**的交易照樣付出分潤，而結算頁上完全
 * 看不出異常——那筆紀錄看起來就是一筆正常的待撥款，**沒有任何錯誤訊息**。
 *
 * ═══ 三條分岔規則，差異點才是重點 ═══
 *
 * | 情境 | pending | paid |
 * |---|---|---|
 * | 全額退款 | → `refunded` | **不動**，寫稽核 + 後台待辦 |
 * | 部分退款 | **重算 `_profit_amount`**，維持 `pending` | **不動金額**，寫稽核 + 後台待辦 |
 * | 訂單取消 | → `cancelled` | **不動** |
 *
 * `paid` 一律不自動改是設計 §7.9 的明文要求：錢已經匯出去了，要不要向夥伴追討是
 * 業務決策，不是程式能決定的事。若哪天有人「順手」把 paid 也自動翻掉，
 * `test_full_refund_on_paid_record_keeps_paid_*` 會立刻紅燈——**不要**改測試讓它變綠。
 *
 * ⚠️ 行號一律以 **WooCommerce 10.1.4**（本機站台）為準；wp-env 容器裝的是另一個版本，
 *    行號會位移。本檔所有斷言只依賴行為，不依賴行號。
 *
 * @group profit_shop
 * @group infrastructure
 * @group woocommerce
 * @group settlement
 */

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerSettledLogRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\RefundAfterPaidNotice;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\SettlementReversalHook;
use Tests\Integration\TestCase;

/**
 * 退款 / 取消回沖的整合測試
 */
final class SettlementReversalHookTest extends TestCase {

	/**
	 * 結算 Repository
	 *
	 * @var OrderItemSettlementRepository
	 */
	private OrderItemSettlementRepository $settlements;

	/**
	 * 分潤夥伴 term id
	 *
	 * @var int
	 */
	private int $partner_term_id = 0;

	/**
	 * 假賣場 ID（本檔不需要真的賣場 CPT——回沖只讀 order itemmeta 的凍結快照）
	 *
	 * @var int
	 */
	private int $shop_id = 424242;

	/**
	 * setUp
	 */
	public function set_up(): void {
		parent::set_up();

		$this->settlements = OrderItemSettlementRepository::instance();

		$term                  = \wp_insert_term( '回沖測試夥伴 ' . uniqid(), 'profit_partner' );
		$this->partner_term_id = is_array( $term ) ? (int) $term['term_id'] : 0;

		RefundAfterPaidNotice::instance()->clear();
	}

	/**
	 * tearDown
	 */
	public function tear_down(): void {
		RefundAfterPaidNotice::instance()->clear();

		if ( $this->partner_term_id > 0 ) {
			\wp_delete_term( $this->partner_term_id, 'profit_partner' );
		}

		parent::tear_down();
	}

	// ====================================================================
	//  A. 註冊（Loader 接線）
	// ====================================================================

	/**
	 * Loader 必須把兩個 callback 掛上
	 *
	 * 🔴 順帶鎖住「**不可**加 `is_admin()` guard」：退款與取消必然發生在 wp-admin，
	 * 照抄 cart hook 的守門會讓整個功能不執行且沒有任何錯誤訊息。這裡刻意在
	 * `set_current_screen( 'shop_order' )` 的 admin context 下驗證掛載仍然成立。
	 *
	 * @group smoke
	 */
	public function test_hooks_are_registered_by_loader(): void {
		\set_current_screen( 'shop_order' );

		$hook = SettlementReversalHook::instance();

		$this->assertSame(
			10,
			\has_action( 'woocommerce_order_refunded', [ $hook, 'on_order_refunded' ] ),
			'退款回沖應掛在 woocommerce_order_refunded priority 10'
		);
		$this->assertSame(
			10,
			\has_action( 'woocommerce_order_status_cancelled', [ $hook, 'on_order_cancelled' ] ),
			'取消回沖應掛在 woocommerce_order_status_cancelled priority 10'
		);

		\set_current_screen( 'front' );
	}

	// ====================================================================
	//  B. 全額退款
	// ====================================================================

	/**
	 * 全額退款把 pending 轉為 refunded
	 *
	 * @group happy
	 */
	public function test_full_refund_flips_pending_to_refunded(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PENDING );

		$this->refund( $order, $item_id, 3, '720.00' );

		$this->assertSame( SettlementRecord::STATUS_REFUNDED, $this->status( $item_id ) );
	}

	/**
	 * 🔴 已撥款的紀錄遇到退款時狀態維持 paid（設計 §7.9）
	 *
	 * @group security
	 */
	public function test_full_refund_on_paid_record_keeps_paid_and_writes_audit_log(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PAID );

		$this->refund( $order, $item_id, 3, '720.00' );

		$this->assertSame( SettlementRecord::STATUS_PAID, $this->status( $item_id ), '已撥款不得被自動改寫' );
		$this->assertSame( '144.00', $this->meta( $item_id, '_profit_amount' ), '已撥款的金額不得被自動改寫' );

		$entries = PartnerSettledLogRepository::instance()->read( $this->partner_term_id );
		$this->assertNotEmpty( $entries, '應寫入一筆稽核紀錄' );

		$last = end( $entries );
		$this->assertSame( 'refund_after_paid', $last['type'] ?? '' );
		$this->assertSame( '144.00', $last['total'] ?? '' );
		$this->assertSame( 1, $last['item_count'] ?? 0 );
	}

	/**
	 * 🔴 已撥款後退款必須在**該筆品項上**留下持久化旗標
	 *
	 * 這種品項的 `_settlement_status` 仍是 `paid`（我方刻意不自動改）。少了這個旗標，
	 * 結算頁**完全無從分辨**它與一筆正常已撥款品項的差異——站長只能靠 `error_log` 去翻，
	 * 而那是他不會主動看的地方。稽核紀錄與後台待辦都不在「這一筆」上，取代不了它。
	 *
	 * @group security
	 */
	public function test_full_refund_on_paid_record_persists_refund_after_paid_flag(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PAID );

		$this->assertSame( '', $this->meta( $item_id, '_profit_refund_after_paid' ), '前提：一開始沒有這個旗標' );

		$this->refund( $order, $item_id, 3, '720.00' );

		$this->assertSame( '1', $this->meta( $item_id, '_profit_refund_after_paid' ) );

		$record = $this->settlements->find_by_order_item( $item_id );
		$this->assertInstanceOf( SettlementRecord::class, $record );
		$this->assertTrue( $record->is_refund_after_paid, 'Repository 必須讀得回這個旗標' );
		$this->assertSame( SettlementRecord::STATUS_PAID, $record->status, '旗標不得連帶改動狀態' );
		$this->assertSame( 1754400000, $record->settled_at, '撥款時間不得被清掉' );
	}

	/**
	 * 尚未撥款的紀錄被退款時**不得**留下「已撥款後退款」旗標
	 *
	 * 反向鎖：旗標只該出現在真的已經把錢匯出去之後才退款的情況。
	 *
	 * @group security
	 */
	public function test_full_refund_on_pending_record_does_not_set_refund_after_paid_flag(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PENDING );

		$this->refund( $order, $item_id, 3, '720.00' );

		$this->assertSame( SettlementRecord::STATUS_REFUNDED, $this->status( $item_id ) );
		$this->assertSame( '', $this->meta( $item_id, '_profit_refund_after_paid' ) );
	}

	/**
	 * 已撥款後退款要進後台待辦佇列（站長看得到才有意義）
	 *
	 * @group happy
	 */
	public function test_full_refund_on_paid_record_queues_admin_notice(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PAID );

		$this->refund( $order, $item_id, 3, '720.00' );

		$queued = RefundAfterPaidNotice::instance()->all();
		$this->assertCount( 1, $queued );
		$this->assertSame( $item_id, (int) $queued[0]['order_item_id'] );
		$this->assertSame( (int) $order->get_id(), (int) $queued[0]['order_id'] );
		$this->assertSame( $this->partner_term_id, (int) $queued[0]['partner_term_id'] );
		$this->assertSame( '144.00', (string) $queued[0]['amount'] );
	}

	// ====================================================================
	//  C. 部分退款（決策 D-3）
	// ====================================================================

	/**
	 * 退掉三件中的一件時分潤金額按剩餘數量重算，狀態維持 pending
	 *
	 * 240.00 × 2 件 × 20% = 96.00
	 *
	 * @group edge
	 */
	public function test_partial_refund_recalculates_profit_amount_and_keeps_pending(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PENDING );

		$this->refund( $order, $item_id, 1, '240.00' );

		$this->assertSame( '96.00', $this->meta( $item_id, '_profit_amount' ) );
		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->status( $item_id ) );
	}

	/**
	 * 部分退款不得改動凍結的實際單價與比例
	 *
	 * 它們是交易當下的事實——退掉幾件不會改變當初的成交單價。
	 *
	 * @group edge
	 */
	public function test_partial_refund_does_not_change_frozen_price_and_rate(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PENDING );

		$this->refund( $order, $item_id, 1, '240.00' );

		$this->assertSame( '240.00', $this->meta( $item_id, '_actual_price' ) );
		$this->assertSame( '20', $this->meta( $item_id, '_profit_rate' ) );
	}

	/**
	 * 已撥款的紀錄遇到部分退款時金額不變，但仍要留下稽核紀錄
	 *
	 * @group edge
	 */
	public function test_partial_refund_on_paid_record_does_not_change_amount(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PAID );

		$this->refund( $order, $item_id, 1, '240.00' );

		$this->assertSame( '144.00', $this->meta( $item_id, '_profit_amount' ) );
		$this->assertSame( SettlementRecord::STATUS_PAID, $this->status( $item_id ) );
		$this->assertNotEmpty( PartnerSettledLogRepository::instance()->read( $this->partner_term_id ) );
	}

	/**
	 * 🔴 累計退完全部數量時視為全額退款（跨多張退款單累加）
	 *
	 * 判定用的是 `get_qty_refunded_for_item()`，它會把該品項在**所有**退款單上的
	 * 數量加總。改成「只看這一張退款單」會讓「分兩次各退一半」永遠停在部分退款，
	 * 站長最後仍要為一筆全退的交易付分潤。
	 *
	 * @group edge
	 */
	public function test_accumulated_partial_refunds_reaching_full_quantity_become_full_refund(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PENDING );

		$this->refund( $order, $item_id, 1, '240.00' );
		$this->assertSame( '96.00', $this->meta( $item_id, '_profit_amount' ), '第一次退 1 件：剩 2 件' );
		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->status( $item_id ) );

		$this->refund( $order, $item_id, 2, '480.00' );

		$this->assertSame( SettlementRecord::STATUS_REFUNDED, $this->status( $item_id ), '累計 3 件 = 全退' );
	}

	/**
	 * ⚠️ 已知邊界：只退金額、不退數量的退款單不會觸發回沖
	 *
	 * 這是「累計判定以數量為準」的直接後果（見實作 class docblock）。本測試把它
	 * **鎖成明示的現況**，日後若改判準會立刻紅燈，讓人有機會重新評估而不是意外變動。
	 *
	 * @group edge
	 */
	public function test_amount_only_refund_without_quantity_does_not_reverse(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PENDING );

		$this->refund( $order, $item_id, 0, '100.00' );

		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->status( $item_id ) );
		$this->assertSame( '144.00', $this->meta( $item_id, '_profit_amount' ) );
	}

	// ====================================================================
	//  D. 訂單取消
	// ====================================================================

	/**
	 * 訂單取消把 pending 轉為 cancelled
	 *
	 * @group happy
	 */
	public function test_order_cancelled_flips_pending_to_cancelled(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PENDING );

		$order->set_status( 'cancelled' );
		$order->save();

		$this->assertSame( SettlementRecord::STATUS_CANCELLED, $this->status( $item_id ) );
	}

	/**
	 * 訂單取消不得影響已撥款的紀錄
	 *
	 * @group security
	 */
	public function test_order_cancelled_does_not_touch_paid_record(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PAID );

		$order->set_status( 'cancelled' );
		$order->save();

		$this->assertSame( SettlementRecord::STATUS_PAID, $this->status( $item_id ) );
	}

	/**
	 * 已終結的狀態不得再被取消動作改寫
	 *
	 * @group edge
	 */
	public function test_terminal_status_is_not_rewritten_by_cancel(): void {
		foreach ( [ SettlementRecord::STATUS_REFUNDED, SettlementRecord::STATUS_CANCELLED ] as $status ) {
			[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', $status );

			$order->set_status( 'cancelled' );
			$order->save();

			$this->assertSame( $status, $this->status( $item_id ), "{$status} 不應被改寫" );
		}
	}

	// ====================================================================
	//  E. 隔離 / 逐筆處理
	// ====================================================================

	/**
	 * 沒有分潤 meta 的訂單完全不受影響
	 *
	 * @group smoke
	 */
	public function test_refund_on_order_without_profit_meta_is_noop(): void {
		[ $order, $item_id ] = $this->make_order( 2, '100.00' );

		$this->refund( $order, $item_id, 2, '200.00' );

		$this->assertSame( '', $this->meta( $item_id, '_settlement_status' ) );
		$this->assertSame( '', $this->meta( $item_id, '_profit_amount' ) );
		$this->assertSame( [], PartnerSettledLogRepository::instance()->read( $this->partner_term_id ) );
		$this->assertSame( [], RefundAfterPaidNotice::instance()->all() );
	}

	/**
	 * 同一張訂單只退其中一個品項時，另一個品項完全不受影響
	 *
	 * @group edge
	 */
	public function test_multiple_line_items_are_reversed_independently(): void {
		$product_a = $this->createSimpleProduct( [ 'regular_price' => '300' ] );
		$product_b = $this->createSimpleProduct( [ 'regular_price' => '300' ] );

		$order = new \WC_Order();
		$item_a = $this->attach_item( $order, $product_a, 3, '240.00' );
		$item_b = $this->attach_item( $order, $product_b, 1, '300.00' );
		$order->set_total( '1020.00' );
		$order->set_status( 'processing' );
		$order->save();

		$id_a = (int) $item_a->get_id();
		$id_b = (int) $item_b->get_id();

		$this->seed_settlement( $id_a, '240.00', '144.00', SettlementRecord::STATUS_PENDING );
		$this->seed_settlement( $id_b, '300.00', '60.00', SettlementRecord::STATUS_PENDING );

		$this->refund( $order, $id_a, 3, '720.00' );

		$this->assertSame( SettlementRecord::STATUS_REFUNDED, $this->status( $id_a ) );
		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->status( $id_b ), '未被退款的品項不受影響' );
		$this->assertSame( '60.00', $this->meta( $id_b, '_profit_amount' ) );
	}

	// ====================================================================
	//  F. 訂單型別過濾（F-3 連帶）
	// ====================================================================

	/**
	 * 🔴 非 `shop_order` 型別的訂單一律不回沖
	 *
	 * 訂閱商品結帳後，同一組 `_profit_*` 會**同時存在於** parent order 與
	 * `shop_subscription` 上。少了型別過濾，同一筆分潤會被回沖兩次。
	 *
	 * ⚠️ wp-env 未安裝 WooCommerce Subscriptions，無法建立真的 `WC_Subscription`。
	 *    本測試改用 WooCommerce 自己的 `woocommerce_order_class` filter
	 *    （includes/class-wc-order-factory.php:286）把 `wc_get_order()` 回傳的物件換成
	 *    一個 `get_type()` 回 `'shop_subscription'` 的子類——**實際走到的就是實作裡那一行
	 *    型別比對**，不是繞過它的假綠燈。
	 *
	 * @group security
	 */
	public function test_non_shop_order_type_is_ignored(): void {
		[ $order, $item_id ] = $this->make_order_with_settlement( 3, '240.00', '144.00', SettlementRecord::STATUS_PENDING );

		$swap = static fn(): string => FakeSubscriptionOrder::class;
		\add_filter( 'woocommerce_order_class', $swap, 99 );

		try {
			// 直接呼叫 callback：wc_create_refund() 內部也會 wc_get_order()，
			// 換掉 class 會干擾退款單本身的建立，測不到我們要測的那一行。
			SettlementReversalHook::instance()->on_order_refunded( $order->get_id(), 0 );
			SettlementReversalHook::instance()->on_order_cancelled( $order->get_id(), null );
		} finally {
			\remove_filter( 'woocommerce_order_class', $swap, 99 );
		}

		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->status( $item_id ), '型別不符時不得改動任何狀態' );
		$this->assertSame( [], RefundAfterPaidNotice::instance()->all() );
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 建立一張帶單一品項的訂單（無分潤 meta）
	 *
	 * @param int    $qty        數量
	 * @param string $unit_price 單價
	 *
	 * @return array{0: \WC_Order, 1: int} 訂單與品項 ID
	 */
	private function make_order( int $qty, string $unit_price ): array {
		$product = $this->createSimpleProduct( [ 'regular_price' => '300' ] );

		$order = new \WC_Order();
		$item  = $this->attach_item( $order, $product, $qty, $unit_price );
		$order->set_total( bcmul( $unit_price, (string) $qty, 2 ) );
		$order->set_status( 'processing' );
		$order->save();

		return [ $order, (int) $item->get_id() ];
	}

	/**
	 * 建立訂單並寫入分潤結算快照
	 *
	 * @param int    $qty        數量
	 * @param string $unit_price 凍結的實際單價
	 * @param string $amount     凍結的分潤金額
	 * @param string $status     結算狀態
	 *
	 * @return array{0: \WC_Order, 1: int}
	 */
	private function make_order_with_settlement( int $qty, string $unit_price, string $amount, string $status ): array {
		[ $order, $item_id ] = $this->make_order( $qty, $unit_price );

		$this->seed_settlement( $item_id, $unit_price, $amount, $status );

		return [ $order, $item_id ];
	}

	/**
	 * 把商品掛成訂單品項
	 *
	 * @param \WC_Order   $order      訂單
	 * @param \WC_Product $product    商品
	 * @param int         $qty        數量
	 * @param string      $unit_price 單價
	 *
	 * @return \WC_Order_Item_Product
	 */
	private function attach_item( \WC_Order $order, \WC_Product $product, int $qty, string $unit_price ): \WC_Order_Item_Product {
		$line = bcmul( $unit_price, (string) $qty, 2 );

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $qty );
		$item->set_subtotal( $line );
		$item->set_total( $line );

		$order->add_item( $item );

		return $item;
	}

	/**
	 * 寫入結算快照
	 *
	 * @param int    $item_id 訂單品項 ID
	 * @param string $actual  實際單價
	 * @param string $amount  分潤金額
	 * @param string $status  結算狀態
	 *
	 * @return void
	 */
	private function seed_settlement( int $item_id, string $actual, string $amount, string $status ): void {
		$is_paid = SettlementRecord::STATUS_PAID === $status;

		$this->settlements->save(
			new SettlementRecord(
				order_item_id: $item_id,
				shop_id: $this->shop_id,
				partner_term_id: $this->partner_term_id,
				rate: new ProfitRate( 20 ),
				actual_price: $actual,
				profit_amount: $amount,
				status: $status,
				settled_at: $is_paid ? 1754400000 : null,
				settled_by: $is_paid ? 1 : null
			)
		);
	}

	/**
	 * 建立退款單（會觸發 woocommerce_order_refunded）
	 *
	 * @param \WC_Order $order   訂單
	 * @param int       $item_id 訂單品項 ID
	 * @param int       $qty     退款數量
	 * @param string    $total   退款金額
	 *
	 * @return void
	 */
	private function refund( \WC_Order $order, int $item_id, int $qty, string $total ): void {
		$refund = \wc_create_refund(
			[
				'order_id'      => (int) $order->get_id(),
				'amount'        => $total,
				'restock_items' => false,
				'line_items'    => [
					$item_id => [
						'qty'          => $qty,
						'refund_total' => $total,
					],
				],
			]
		);

		$this->assertNotWPError( $refund, '退款單應建立成功' );
	}

	/**
	 * 讀取結算狀態
	 *
	 * @param int $item_id 訂單品項 ID
	 *
	 * @return string
	 */
	private function status( int $item_id ): string {
		return $this->meta( $item_id, '_settlement_status' );
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
		\wp_cache_delete( $item_id, 'order_item_meta' );

		return (string) \wc_get_order_item_meta( $item_id, $meta_key, true );
	}
}

/**
 * 測試用的假訂閱物件：只覆寫 `get_type()`
 *
 * wp-env 未安裝 WooCommerce Subscriptions，但實作裡的型別守門必須被真的走一次。
 * 透過 `woocommerce_order_class` filter 讓 `wc_get_order()` 回傳這個類別，
 * 就能在不裝 WCS 的情況下驗證「非 shop_order 一律不回沖」。
 */
class FakeSubscriptionOrder extends \WC_Order {

	/**
	 * 內部型別
	 *
	 * @return string
	 */
	public function get_type() {
		return 'shop_subscription';
	}
}
