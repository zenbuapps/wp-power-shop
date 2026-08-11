<?php
/**
 * RenewalProfitHook 整合測試（訂閱續訂單帶入分潤結算資訊）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.4、§5.7
 *          specs/features/profit-shop/carry-profit-to-renewal-order.feature
 *
 * 對應實作：
 *   inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/RenewalProfitHook.php
 *
 * ═══ 🔴 本 hook 的職責是「重置」，不是「複製」═══
 *
 * WooCommerce Subscriptions 在 `wcs_copy_order_item()`
 * （subscriptions-core/includes/wcs-order-functions.php:758-764）把母訂單品項的 meta
 * **整組複製**到續訂單，只排除 `_reduced_stock`。也就是說續訂單一出生就可能帶著
 * `_settlement_status='paid'` 與上一期的 `_settled_at` / `_settled_by`
 * ⇒ 站長會看到一筆「已撥款」的新分潤，**永遠不會撥款給夥伴**，且沒有任何錯誤訊息。
 *
 * ⚠️ **wp-env 沒有安裝 WooCommerce Subscriptions**（見 `.wp-env.json` 的 plugins 清單）。
 *    因此本檔採兩段式驗證，避免退化成 TD-64 那種「恆 skip 的假綠燈」：
 *
 *    1. **不需要 WCS 的部分（本檔絕大多數測試）**：手工造出一張「已經被 WCS 複製過
 *       meta」的續訂單（用 `OrderItemSettlementRepository::save()` 寫入與 `wcs_copy_order_item()`
 *       完全相同的 11 個 meta），再**直接呼叫** `carry_profit()`。凍結 / 動態 / 重算 /
 *       強制重置四類行為全部真的跑過。
 *    2. **需要 WCS 的部分（僅 1 條）**：真的走 `wcs_create_renewal_order()`，
 *       在 wp-env 會 `markTestSkipped`，**只有真實站台驗得到**——交付紀錄必須據實說明。
 *
 * ⚠️ 行號以本機站台的 WooCommerce Subscriptions **6.1.0**（subscriptions-core）為準。
 *
 * @group profit_shop
 * @group infrastructure
 * @group woocommerce
 * @group settlement
 */

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\ProfitCalculator;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\MoneyFormatter;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\RenewalProfitHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\WcDecimalRounding;
use Tests\Integration\TestCase;

/**
 * 訂閱續訂單分潤重置的整合測試
 */
final class RenewalProfitHookTest extends TestCase {

	/**
	 * 賣場 Repository
	 *
	 * @var CptProfitShopRepository
	 */
	private CptProfitShopRepository $shops;

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
	 * setUp
	 */
	public function set_up(): void {
		parent::set_up();

		$this->shops       = CptProfitShopRepository::instance();
		$this->settlements = OrderItemSettlementRepository::instance();

		$term                  = \wp_insert_term( '續訂測試夥伴 ' . uniqid(), 'profit_partner' );
		$this->partner_term_id = is_array( $term ) ? (int) $term['term_id'] : 0;
	}

	/**
	 * tearDown
	 */
	public function tear_down(): void {
		if ( $this->partner_term_id > 0 ) {
			\wp_delete_term( $this->partner_term_id, 'profit_partner' );
		}

		parent::tear_down();
	}

	// ====================================================================
	//  A. 環境相依：WCS 未安裝時整段不註冊
	// ====================================================================

	/**
	 * 掛載與否必須完全跟隨 WooCommerce Subscriptions 是否可用
	 *
	 * 兩條分支都是真的斷言（不是「沒裝就跳過」）：
	 *   - 有裝 ⇒ Loader 建立的實例必須已掛上 filter
	 *   - 沒裝 ⇒ 就算有人 `new` 出一個實例，也**不得**掛上 filter
	 *
	 * 無條件註冊會在未安裝 WCS 的站台引用不存在的函式 ⇒ fatal ⇒ 整個外掛掛掉。
	 *
	 * @group smoke
	 */
	public function test_filter_registration_matches_subscriptions_availability(): void {
		if ( RenewalProfitHook::is_supported() ) {
			$this->assertNotFalse(
				\has_filter( 'wcs_renewal_order_created', [ RenewalProfitHook::instance(), 'carry_profit' ] ),
				'已安裝 WooCommerce Subscriptions 時 Loader 應註冊 carry_profit'
			);
			return;
		}

		$probe = $this->make_hook();

		$this->assertFalse(
			\has_filter( 'wcs_renewal_order_created', [ $probe, 'carry_profit' ] ),
			'未安裝 WooCommerce Subscriptions 時不得註冊任何續訂 hook'
		);
	}

	// ====================================================================
	//  B. 凍結：賣場與夥伴歸屬沿用母訂單
	// ====================================================================

	/**
	 * 賣場與夥伴的歸屬沿用繼承來的值，不重新判定
	 *
	 * @group happy
	 */
	public function test_renewal_freezes_shop_id_and_partner_term_id(): void {
		$shop_id = $this->create_shop( 20 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '240.00', SettlementRecord::STATUS_PAID );

		$this->carry( $item_id );

		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( (string) $this->partner_term_id, $this->meta( $item_id, '_profit_partner_term_id' ) );
	}

	/**
	 * 賣場已下架（draft）時仍沿用歸屬，且比例照樣重讀
	 *
	 * 「還能不能被買」與「已經在收費的訂閱怎麼記帳」是兩件事。
	 *
	 * @group edge
	 */
	public function test_draft_shop_still_carries_attribution_and_rate(): void {
		$shop_id = $this->create_shop( 30, 'draft' );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '240.00', SettlementRecord::STATUS_PENDING );

		$this->carry( $item_id );

		$this->assertSame( (string) $shop_id, $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '30', $this->meta( $item_id, '_profit_rate' ), '下架不等於刪除，比例照樣重讀' );
	}

	// ====================================================================
	//  C. 動態：比例重讀賣場
	// ====================================================================

	/**
	 * 站長調高比例後續訂單採用新比例
	 *
	 * @group happy
	 */
	public function test_renewal_reads_current_rate_from_shop(): void {
		$shop_id = $this->create_shop( 30 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '200.00', SettlementRecord::STATUS_PENDING );

		$this->carry( $item_id );

		$this->assertSame( '30', $this->meta( $item_id, '_profit_rate' ) );
		$this->assertSame( '60.00', $this->meta( $item_id, '_profit_amount' ), '200 × 1 × 30% = 60.00' );
	}

	/**
	 * 賣場已被刪除時退回母訂單的凍結比例
	 *
	 * @group error
	 */
	public function test_renewal_falls_back_to_inherited_rate_when_shop_deleted(): void {
		$item_id = $this->make_renewal_item( 999999, 20, 1, '200.00', SettlementRecord::STATUS_PENDING );

		$this->carry( $item_id );

		$this->assertSame( '20', $this->meta( $item_id, '_profit_rate' ), '賣場不存在 ⇒ 用繼承來的凍結比例' );
		$this->assertSame( '40.00', $this->meta( $item_id, '_profit_amount' ) );
	}

	// ====================================================================
	//  D. 重算：實際單價與分潤金額
	// ====================================================================

	/**
	 * 續訂價與首期不同時重新計算
	 *
	 * @group happy
	 */
	public function test_renewal_recalculates_actual_price_and_profit_amount(): void {
		$shop_id = $this->create_shop( 20 );
		// 繼承來的是首期的 240.00 / 48.00；這一期 WC 算出來的續訂價是 200.00。
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '200.00', SettlementRecord::STATUS_PAID, '240.00', '48.00' );

		$this->carry( $item_id );

		$this->assertSame( '200.00', $this->meta( $item_id, '_actual_price' ) );
		$this->assertSame( '40.00', $this->meta( $item_id, '_profit_amount' ) );
	}

	/**
	 * 數量大於一時分潤金額按數量計算
	 *
	 * @group happy
	 */
	public function test_renewal_recalculates_profit_amount_for_multiple_quantity(): void {
		$shop_id = $this->create_shop( 20 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 3, '200.00', SettlementRecord::STATUS_PENDING );

		$this->carry( $item_id );

		$this->assertSame( '200.00', $this->meta( $item_id, '_actual_price' ), '單價 = 行小計 ÷ 數量' );
		$this->assertSame( '120.00', $this->meta( $item_id, '_profit_amount' ), '200 × 3 × 20% = 120.00' );
	}

	/**
	 * 比例為零時分潤金額為零
	 *
	 * @group edge
	 */
	public function test_zero_rate_yields_zero_profit_amount(): void {
		$shop_id = $this->create_shop( 0 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '200.00', SettlementRecord::STATUS_PENDING );

		$this->carry( $item_id );

		$this->assertSame( '0', $this->meta( $item_id, '_profit_rate' ) );
		$this->assertSame( '0.00', $this->meta( $item_id, '_profit_amount' ) );
	}

	/**
	 * 單價是「行小計 ÷ 數量」，除法一律 bcdiv 後 half-up 收斂
	 *
	 * 行小計 100.00 ÷ 3 件 = 33.333333… → `_actual_price` 入庫 `33.33`；
	 * 再 33.33 × 3 × 20% = 19.998 → `_profit_amount` 入庫 `20.00`。
	 *
	 * 刻意挑一個**除不盡**的組合：整除的話不論用 `/` 還是 `bcdiv` 都會得到相同結果，
	 * 那種測試無法分辨實作有沒有真的走 bcmath，也看不出入庫前有沒有收斂到貨幣小數位。
	 *
	 * @group edge
	 */
	public function test_unit_price_division_is_rounded_half_up(): void {
		$shop_id = $this->create_shop( 20 );

		$order = $this->make_order();
		$item  = $this->attach_item_with_line_subtotal( $order, 3, '100.00' );
		$order->save();

		$item_id = (int) $item->get_id();

		$this->seed_inherited( $item_id, $shop_id, 20, '240.00', '48.00', SettlementRecord::STATUS_PENDING );

		$this->carry( $item_id );

		$this->assertSame( '33.33', $this->meta( $item_id, '_actual_price' ) );
		$this->assertSame( '20.00', $this->meta( $item_id, '_profit_amount' ) );
	}

	// ====================================================================
	//  E. 安全：撥款狀態絕不可從母訂單繼承
	// ====================================================================

	/**
	 * 🔴 續訂單的結算狀態強制為 pending
	 *
	 * 這是本 hook 存在的主要理由。少了它，站長會對同一筆分潤重複撥款，
	 * 或以為已撥款而讓夥伴永遠拿不到這期的錢——兩種結果都沒有任何錯誤訊息。
	 *
	 * @group security
	 */
	public function test_renewal_resets_paid_status_to_pending(): void {
		$shop_id = $this->create_shop( 20 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '200.00', SettlementRecord::STATUS_PAID );

		$this->assertSame( 'paid', $this->meta( $item_id, '_settlement_status' ), '前提：繼承來的狀態確實是 paid' );

		$this->carry( $item_id );

		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->meta( $item_id, '_settlement_status' ) );
	}

	/**
	 * 🔴 續訂單不得帶有母訂單的撥款時間與操作者
	 *
	 * @group security
	 */
	public function test_renewal_clears_settled_at_and_settled_by(): void {
		$shop_id = $this->create_shop( 20 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '200.00', SettlementRecord::STATUS_PAID );

		$this->assertSame( '1754400000', $this->meta( $item_id, '_settled_at' ), '前提：繼承來的撥款時間確實存在' );

		$this->carry( $item_id );

		$this->assertSame( '', $this->meta( $item_id, '_settled_at' ) );
		$this->assertSame( '', $this->meta( $item_id, '_settled_by' ) );
	}

	/**
	 * 母訂單為任何狀態時續訂單一律重置為 pending
	 *
	 * @group security
	 */
	public function test_any_inherited_status_is_reset_to_pending(): void {
		$shop_id = $this->create_shop( 20 );

		$statuses = [
			SettlementRecord::STATUS_PENDING,
			SettlementRecord::STATUS_PAID,
			SettlementRecord::STATUS_REFUNDED,
			SettlementRecord::STATUS_CANCELLED,
		];

		foreach ( $statuses as $status ) {
			$item_id = $this->make_renewal_item( $shop_id, 20, 1, '200.00', $status );

			$this->carry( $item_id );

			$this->assertSame(
				SettlementRecord::STATUS_PENDING,
				$this->meta( $item_id, '_settlement_status' ),
				"繼承狀態 {$status} 應被重置為 pending"
			);
		}
	}

	// ====================================================================
	//  F. filter 契約
	// ====================================================================

	/**
	 * 🔴 掛載點是 filter，必須回傳續訂單物件
	 *
	 * 漏了 return 會讓 `wcs_create_renewal_order()` 拿到 null ⇒ **整條訂閱續訂鏈路壞掉**
	 * （顧客的訂閱停止續期），影響範圍遠大於分潤。
	 *
	 * @group smoke
	 */
	public function test_hook_returns_the_renewal_order_object(): void {
		$shop_id = $this->create_shop( 20 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '200.00', SettlementRecord::STATUS_PAID );
		$order   = \wc_get_order( $this->order_id_of( $item_id ) );

		$returned = $this->make_hook()->carry_profit( $order, null );

		$this->assertSame( $order, $returned );
	}

	/**
	 * 處理過程拋例外時仍必須回傳續訂單物件
	 *
	 * @group error
	 */
	public function test_hook_returns_order_even_when_shop_repository_throws(): void {
		$item_id = $this->make_renewal_item( 12345, 20, 1, '200.00', SettlementRecord::STATUS_PAID );
		$order   = \wc_get_order( $this->order_id_of( $item_id ) );

		$hook = new RenewalProfitHook(
			new ThrowingProfitShopRepository(),
			$this->settlements,
			new ProfitCalculator( new WcDecimalRounding() ),
			new MoneyFormatter( new WcDecimalRounding() )
		);

		$returned = $hook->carry_profit( $order, null );

		$this->assertSame( $order, $returned, '例外必須被吞掉並原封回傳續訂單' );
	}

	/**
	 * 非 WC_Order 的輸入原封回傳（filter 契約的極端情況）
	 *
	 * @group edge
	 */
	public function test_non_order_input_is_returned_untouched(): void {
		$error = new \WP_Error( 'renewal-order-error', 'boom' );

		$this->assertSame( $error, $this->make_hook()->carry_profit( $error, null ) );
	}

	// ====================================================================
	//  G. 隔離
	// ====================================================================

	/**
	 * 沒有分潤歸屬的品項完全不受影響
	 *
	 * @group smoke
	 */
	public function test_item_without_profit_meta_is_untouched(): void {
		$order   = $this->make_order();
		$item    = $this->attach_item( $order, 1, '200.00' );
		$order->save();
		$item_id = (int) $item->get_id();

		$this->make_hook()->carry_profit( $order, null );

		$this->assertSame( '', $this->meta( $item_id, '_profit_shop_id' ) );
		$this->assertSame( '', $this->meta( $item_id, '_settlement_status' ) );
		$this->assertSame( '', $this->meta( $item_id, '_profit_amount' ) );
	}

	/**
	 * 同一張續訂單內只處理帶有分潤歸屬的品項
	 *
	 * @group edge
	 */
	public function test_only_items_with_profit_meta_are_processed(): void {
		$shop_id = $this->create_shop( 20 );

		$order     = $this->make_order();
		$with_meta = $this->attach_item( $order, 1, '200.00' );
		$plain     = $this->attach_item( $order, 1, '150.00' );
		$order->save();

		$id_with  = (int) $with_meta->get_id();
		$id_plain = (int) $plain->get_id();

		$this->seed_inherited( $id_with, $shop_id, 20, '240.00', '48.00', SettlementRecord::STATUS_PAID );

		$this->make_hook()->carry_profit( $order, null );

		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->meta( $id_with, '_settlement_status' ) );
		$this->assertSame( '200.00', $this->meta( $id_with, '_actual_price' ) );

		$this->assertSame( '', $this->meta( $id_plain, '_settlement_status' ), '無分潤歸屬的品項不得被寫入' );
		$this->assertSame( '', $this->meta( $id_plain, '_profit_shop_id' ) );
	}

	// ====================================================================
	//  H. 真的走 WCS（wp-env 未安裝 ⇒ skip，見檔頭說明）
	// ====================================================================

	/**
	 * 端到端：真的用 `wcs_create_renewal_order()` 建立續訂單
	 *
	 * ⚠️ wp-env 未安裝 WooCommerce Subscriptions，本測試在 CI / 容器內恆 skip，
	 *    **只有真實站台驗得到**——交付紀錄不得因為它「沒紅燈」就宣稱已驗證。
	 *
	 * `markTestSkipped` 的條件刻意只看「函式存在與否」，不把任何行為斷言包進去，
	 * 否則會退化成 TD-64 那種假綠燈。
	 *
	 * @group happy
	 */
	public function test_real_wcs_renewal_order_resets_paid_status(): void {
		if ( ! RenewalProfitHook::is_supported() || ! function_exists( 'wcs_create_subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未安裝（wp-env 的 plugins 清單不含它）' );
		}

		$subscription = \wcs_create_subscription( [ 'billing_period' => 'month', 'billing_interval' => 1 ] );
		$this->assertNotWPError( $subscription );

		$product = $this->createSimpleProduct( [ 'regular_price' => '200' ] );
		$sub_item = new \WC_Order_Item_Product();
		$sub_item->set_product( $product );
		$sub_item->set_quantity( 1 );
		$sub_item->set_subtotal( '200.00' );
		$sub_item->set_total( '200.00' );
		$subscription->add_item( $sub_item );
		$subscription->save();

		$shop_id = $this->create_shop( 20 );
		$this->seed_inherited( (int) $sub_item->get_id(), $shop_id, 20, '240.00', '48.00', SettlementRecord::STATUS_PAID );

		$renewal = \wcs_create_renewal_order( $subscription );
		$this->assertNotWPError( $renewal );
		$this->assertInstanceOf( \WC_Order::class, $renewal, 'filter 必須回傳續訂單物件' );

		foreach ( $renewal->get_items() as $item_id => $item ) {
			$this->assertSame( SettlementRecord::STATUS_PENDING, $this->meta( (int) $item_id, '_settlement_status' ) );
			$this->assertSame( '', $this->meta( (int) $item_id, '_settled_at' ) );
			$this->assertSame( '', $this->meta( (int) $item_id, '_settled_by' ) );
		}
	}

	// ====================================================================
	//  I. filter 契約：真的走 apply_filters（不是直接呼叫 method）
	// ====================================================================

	/*
	 * ═══ 這一整段補的是什麼 ═══
	 *
	 * 上面 A～H 共 18 條全部是**直接呼叫** `carry_profit()`。那證明了「這個方法算得對」，
	 * 但沒有證明「它掛成 WordPress filter 之後還能用」——而 `wcs_renewal_order_created`
	 * 是 **filter 不是 action**，兩者的失敗模式完全不同：
	 *
	 *   action 的 callback 忘記 return  ⇒ 什麼事都不會發生
	 *   filter 的 callback 忘記 return  ⇒ 回傳 null ⇒ `wcs_create_renewal_order()` 拿到 null
	 *                                   ⇒ **整條訂閱續訂鏈路壞掉，顧客的訂閱停止續期**
	 *
	 * 影響範圍遠大於分潤本身，是本 hook 風險最高的單一失誤點。
	 *
	 * ⚠️ 這裡**刻意不定義** `wcs_get_subscriptions_for_renewal_order()` 來讓
	 *    `is_supported()` 變成 true。PHP 沒有「取消定義函式」這回事，一旦定義就會污染
	 *    同一輪後續所有測試——`test_filter_registration_matches_subscriptions_availability`
	 *    會改走「已安裝」分支，去斷言 Loader 註冊過的實例存在，而 Loader 是在 bootstrap
	 *    當下（函式還不存在）跑的，那條會以 `ArgumentCountError` 炸掉。
	 *
	 *    改為**手動註冊**（與建構子逐字相同的 `add_filter` 參數）再 `apply_filters`。
	 *    這測得到 filter 契約本身，代價是測不到「建構子會註冊」那一行——
	 *    後者已由 `test_filter_registration_matches_subscriptions_availability`
	 *    的未安裝分支反向鎖住。
	 */

	/**
	 * 🔴 掛成 filter 之後必須正常運作，且回傳同一個訂單物件
	 *
	 * @group happy
	 */
	public function test_carry_profit_works_through_the_real_filter_chain(): void {
		$shop_id = $this->create_shop( 25 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '400.00', SettlementRecord::STATUS_PAID );
		$order   = \wc_get_order( $this->order_id_of( $item_id ) );

		$this->assertInstanceOf( \WC_Order::class, $order );

		$hook = $this->make_hook();
		\add_filter( 'wcs_renewal_order_created', [ $hook, 'carry_profit' ], 20, 2 );

		try {
			$returned = \apply_filters( 'wcs_renewal_order_created', $order, null );
		} finally {
			\remove_filter( 'wcs_renewal_order_created', [ $hook, 'carry_profit' ], 20 );
		}

		$this->assertSame( $order, $returned, 'filter 必須原封不動回傳續訂單物件' );
		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->meta( $item_id, '_settlement_status' ) );
		$this->assertSame( '25', $this->meta( $item_id, '_profit_rate' ), '比例應已重讀賣場' );
		$this->assertSame( '100.00', $this->meta( $item_id, '_profit_amount' ), '400 × 1 × 25%' );
	}

	/**
	 * 🔴 filter 鏈上的下一個 callback 仍必須收到訂單物件
	 *
	 * 這條比「斷言回傳值」更接近真實失效場景：WCS 自己在
	 * `wcs_create_renewal_order()` 內部會拿 filter 的結果繼續往下用。
	 * 我們的 callback 若在某個分支漏了 `return`，下游拿到的就是 null——
	 * 而直接呼叫 method 的測試**完全看不到這件事**。
	 *
	 * @group security
	 */
	public function test_downstream_filter_callback_still_receives_the_order(): void {
		$shop_id = $this->create_shop( 10 );
		$item_id = $this->make_renewal_item( $shop_id, 10, 1, '100.00', SettlementRecord::STATUS_PAID );
		$order   = \wc_get_order( $this->order_id_of( $item_id ) );

		$seen_by_downstream = 'never-ran';

		$downstream = static function ( $value ) use ( &$seen_by_downstream ) {
			$seen_by_downstream = $value instanceof \WC_Order ? 'order' : gettype( $value );
			return $value;
		};

		$hook = $this->make_hook();
		\add_filter( 'wcs_renewal_order_created', [ $hook, 'carry_profit' ], 20, 2 );
		\add_filter( 'wcs_renewal_order_created', $downstream, 30, 1 );

		try {
			\apply_filters( 'wcs_renewal_order_created', $order, null );
		} finally {
			\remove_filter( 'wcs_renewal_order_created', [ $hook, 'carry_profit' ], 20 );
			\remove_filter( 'wcs_renewal_order_created', $downstream, 30 );
		}

		$this->assertSame(
			'order',
			$seen_by_downstream,
			'下游 callback 收到的不是 WC_Order ⇒ wcs_create_renewal_order() 會拿到壞值 ⇒ 訂閱停止續期'
		);
	}

	/**
	 * hook priority 必須是 20（晚於 WCS 自己在 10 的處理）
	 *
	 * WCS 在 `wcs_create_renewal_order()` 內部與其他 callback 都掛在預設的 10；
	 * 我們必須在它們把 meta 複製完之後才動手，否則會重置到一半又被覆蓋回去。
	 *
	 * 用 reflection 讀 private const，而不是重寫一份 `20` 在測試裡——
	 * 後者只是把同一個數字抄兩遍，改了 production 也不會紅。
	 *
	 * @group smoke
	 */
	public function test_hook_priority_is_twenty(): void {
		$priority = ( new \ReflectionClass( RenewalProfitHook::class ) )
			->getConstant( 'HOOK_PRIORITY' );

		$this->assertSame(
			20,
			$priority,
			'priority 若 <= 10 會與 WCS 自己的 meta 複製搶順序，重置結果可能被覆蓋'
		);
	}

	/**
	 * 🔴 母訂單的「已撥款後退款」旗標不得被續訂單繼承
	 *
	 * `wcs_copy_order_item()` 複製**全部** meta（只排除 `_reduced_stock`），
	 * 所以母訂單若曾發生「已撥款之後退款」，`_profit_refund_after_paid=1`
	 * 會一路複製到這一期的續訂單上。
	 *
	 * 不清掉的話，站長的結算頁會在一筆**全新、從未撥款也從未退款**的續訂分潤上
	 * 標紅「需人工沖回」，而且沒有任何辦法讓它消失——這一期根本沒有可以沖回的東西。
	 *
	 * @group edge
	 */
	public function test_inherited_refund_after_paid_flag_is_cleared(): void {
		$shop_id = $this->create_shop( 20 );
		$item_id = $this->make_renewal_item( $shop_id, 20, 1, '500.00', SettlementRecord::STATUS_PAID );

		// 模擬 WCS 把母訂單的旗標一併複製過來
		$this->settlements->flag_refund_after_paid( $item_id );
		$this->assertSame(
			'1',
			$this->meta( $item_id, '_profit_refund_after_paid' ),
			'前置檢查：旗標必須真的被寫進去，否則下面的斷言在「本來就沒有」的情況下也會過'
		);

		$this->carry( $item_id );

		$this->assertSame(
			'',
			$this->meta( $item_id, '_profit_refund_after_paid' ),
			'續訂單不得繼承母訂單的「已撥款後退款」旗標'
		);
	}

	/**
	 * 🔴 走完 filter 之後，11 個結算欄位沒有任何一個殘留上一期的值
	 *
	 * 逐欄位掃過一遍，而不是只挑三個來看。理由：`wcs_copy_order_item()` 是
	 * **無差別全複製**，任何一個日後新增的 `_profit_*` 欄位若忘了納入重置，
	 * 只挑三個看的測試不會發現。
	 *
	 * @group happy
	 */
	public function test_no_stale_field_survives_a_faithful_wcs_copy(): void {
		$shop_id = $this->create_shop( 30 );

		// 這一期：數量 2、行小計 600 ⇒ 單價 300、分潤 600 × 30% = 180
		$order = $this->make_order();
		$item  = $this->attach_item( $order, 2, '300.00' );
		$order->save();
		$item_id = (int) $item->get_id();

		// WCS 從母訂單複製過來的一整組舊值（刻意每一個都與正解不同）
		$this->seed_inherited( $item_id, $shop_id, 5, '111.11', '222.22', SettlementRecord::STATUS_PAID );
		$this->settlements->flag_refund_after_paid( $item_id );

		$hook = $this->make_hook();
		\add_filter( 'wcs_renewal_order_created', [ $hook, 'carry_profit' ], 20, 2 );

		try {
			\apply_filters( 'wcs_renewal_order_created', \wc_get_order( $this->order_id_of( $item_id ) ), null );
		} finally {
			\remove_filter( 'wcs_renewal_order_created', [ $hook, 'carry_profit' ], 20 );
		}

		$expected = [
			// 凍結
			'_profit_shop_id'           => (string) $shop_id,
			'_profit_partner_term_id'   => (string) $this->partner_term_id,
			// 動態（重讀賣場）
			'_profit_rate'              => '30',
			// 重算
			'_actual_price'             => '300.00',
			'_profit_amount'            => '180.00',
			// 強制重置
			'_settlement_status'        => SettlementRecord::STATUS_PENDING,
			'_settled_at'               => '',
			'_settled_by'               => '',
			'_profit_refund_after_paid' => '',
		];

		foreach ( $expected as $key => $value ) {
			$this->assertSame(
				$value,
				$this->meta( $item_id, $key ),
				"{$key} 殘留了上一期的值（WCS 是無差別全複製，新欄位必須一併納入重置）"
			);
		}
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 建立一個 hook 實例（production 相依，但由測試自己持有）
	 *
	 * @return RenewalProfitHook
	 */
	private function make_hook(): RenewalProfitHook {
		$rounding = new WcDecimalRounding();

		return new RenewalProfitHook(
			$this->shops,
			$this->settlements,
			new ProfitCalculator( $rounding ),
			new MoneyFormatter( $rounding )
		);
	}

	/**
	 * 建立分潤賣場
	 *
	 * @param int    $rate   分潤比例
	 * @param string $status 賣場狀態
	 *
	 * @return int 賣場 ID
	 */
	private function create_shop( int $rate, string $status = 'publish' ): int {
		return $this->shops->save(
			new ProfitShop(
				id: 0,
				title: '續訂測試賣場 ' . uniqid(),
				slug: 'renewal-shop-' . uniqid(),
				status: $status,
				mode: ShopMode::PAGE,
				partner_term_id: $this->partner_term_id,
				rate: new ProfitRate( $rate ),
				items: []
			)
		);
	}

	/**
	 * 建立一張空訂單
	 *
	 * @return \WC_Order
	 */
	private function make_order(): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( 'pending' );

		return $order;
	}

	/**
	 * 掛一個品項到訂單上
	 *
	 * @param \WC_Order $order      訂單
	 * @param int       $qty        數量
	 * @param string    $unit_price 單價（行小計 = 單價 × 數量）
	 *
	 * @return \WC_Order_Item_Product
	 */
	private function attach_item( \WC_Order $order, int $qty, string $unit_price ): \WC_Order_Item_Product {
		return $this->attach_item_with_line_subtotal( $order, $qty, bcmul( $unit_price, (string) $qty, 2 ) );
	}

	/**
	 * 掛一個品項到訂單上，直接指定行小計（供「除不盡」的精度測試使用）
	 *
	 * @param \WC_Order $order         訂單
	 * @param int       $qty           數量
	 * @param string    $line_subtotal 行小計
	 *
	 * @return \WC_Order_Item_Product
	 */
	private function attach_item_with_line_subtotal( \WC_Order $order, int $qty, string $line_subtotal ): \WC_Order_Item_Product {
		$product = $this->createSimpleProduct( [ 'regular_price' => '300' ] );

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $qty );
		$item->set_subtotal( $line_subtotal );
		$item->set_total( $line_subtotal );

		$order->add_item( $item );

		return $item;
	}

	/**
	 * 造出一張「WCS 已把 meta 複製過來」的續訂單品項
	 *
	 * @param int    $shop_id         繼承的賣場 ID
	 * @param int    $inherited_rate  繼承的分潤比例
	 * @param int    $qty             這一期的數量
	 * @param string $renewal_unit    這一期 WC 算出來的單價
	 * @param string $status          繼承的結算狀態
	 * @param string $inherited_price 繼承的 `_actual_price`（預設同 renewal_unit）
	 * @param string $inherited_amt   繼承的 `_profit_amount`
	 *
	 * @return int 續訂單品項 ID
	 */
	private function make_renewal_item(
		int $shop_id,
		int $inherited_rate,
		int $qty,
		string $renewal_unit,
		string $status,
		string $inherited_price = '',
		string $inherited_amt = '999.99'
	): int {
		$order = $this->make_order();
		$item  = $this->attach_item( $order, $qty, $renewal_unit );
		$order->save();

		$item_id = (int) $item->get_id();

		$this->seed_inherited(
			$item_id,
			$shop_id,
			$inherited_rate,
			'' === $inherited_price ? $renewal_unit : $inherited_price,
			$inherited_amt,
			$status
		);

		return $item_id;
	}

	/**
	 * 寫入「從母訂單繼承來的」結算 meta（等同 wcs_copy_order_item 的結果）
	 *
	 * @param int    $item_id 訂單品項 ID
	 * @param int    $shop_id 賣場 ID
	 * @param int    $rate    分潤比例
	 * @param string $actual  實際單價
	 * @param string $amount  分潤金額
	 * @param string $status  結算狀態
	 *
	 * @return void
	 */
	private function seed_inherited( int $item_id, int $shop_id, int $rate, string $actual, string $amount, string $status ): void {
		$is_paid = SettlementRecord::STATUS_PAID === $status;

		$this->settlements->save(
			new SettlementRecord(
				order_item_id: $item_id,
				shop_id: $shop_id,
				partner_term_id: $this->partner_term_id,
				rate: new ProfitRate( $rate ),
				actual_price: $actual,
				profit_amount: $amount,
				status: $status,
				settled_at: $is_paid ? 1754400000 : null,
				settled_by: $is_paid ? 1 : null
			)
		);
	}

	/**
	 * 對某個品項所屬的訂單執行 carry_profit()
	 *
	 * @param int $item_id 訂單品項 ID
	 *
	 * @return void
	 */
	private function carry( int $item_id ): void {
		$order = \wc_get_order( $this->order_id_of( $item_id ) );

		$this->assertInstanceOf( \WC_Order::class, $order );

		$this->make_hook()->carry_profit( $order, null );
	}

	/**
	 * 由 order item id 反查 order id
	 *
	 * @param int $item_id 訂單品項 ID
	 *
	 * @return int
	 */
	private function order_id_of( int $item_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d",
				$item_id
			)
		);
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
 * 一定會拋例外的賣場 Repository（驗證 filter 契約在例外路徑仍成立）
 */
class ThrowingProfitShopRepository implements ProfitShopRepositoryInterface {

	/**
	 * 依 ID 取得賣場
	 *
	 * @param int $id 賣場 ID
	 *
	 * @throws \RuntimeException 一律拋出
	 *
	 * @return ProfitShop|null
	 */
	public function find( int $id ): ?ProfitShop {
		throw new \RuntimeException( 'boom ' . $id );
	}

	/**
	 * 依 slug 取得賣場
	 *
	 * @param string $slug 賣場 slug
	 *
	 * @return ProfitShop|null
	 */
	public function find_by_slug( string $slug ): ?ProfitShop {
		unset( $slug );
		return null;
	}

	/**
	 * 儲存賣場
	 *
	 * @param ProfitShop $shop 賣場
	 *
	 * @return int
	 */
	public function save( ProfitShop $shop ): int {
		unset( $shop );
		return 0;
	}

	/**
	 * 刪除賣場
	 *
	 * @param int $id 賣場 ID
	 *
	 * @return void
	 */
	public function delete( int $id ): void {
		unset( $id );
	}

	/**
	 * 依夥伴取得賣場
	 *
	 * @param int $term_id 夥伴 term ID
	 *
	 * @return ProfitShop[]
	 */
	public function find_by_partner( int $term_id ): array {
		unset( $term_id );
		return [];
	}

	/**
	 * 全部賣場
	 *
	 * @return ProfitShop[]
	 */
	public function all(): array {
		return [];
	}
}
