<?php
/**
 * OrderItemSettlementRepository 整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5、§5、§6.6（HPOS 相容）
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/Persistence/OrderItemSettlementRepository.php
 *
 * 重點：
 * - SettlementRecord 透過 wc_order_itemmeta 持久化
 * - 由 wc_*_order_item_meta API 處理，HPOS 開/關都能跑
 * - 不測試 _override_* 三欄位（Phase 4 OrderHooks 職責，spec 已標 write-only by 上游）
 *
 * @group profit_shop
 * @group infrastructure
 * @group persistence
 * @group repository
 * @group hpos_compatible
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Persistence;

use Automattic\WooCommerce\Utilities\OrderUtil;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use Tests\Integration\TestCase;

/**
 * 透過真實 WC 訂單 + order_itemmeta 驗證 SettlementRecord 存取行為
 */
final class OrderItemSettlementRepositoryTest extends TestCase {

	private OrderItemSettlementRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = OrderItemSettlementRepository::instance();
	}

	// ========== Happy ==========

	/**
	 * save() 必須將 SettlementRecord 各欄位寫入 wc_order_itemmeta
	 *
	 * @test
	 * @group happy
	 */
	public function test_save_writes_order_item_meta(): void {
		$item_id = $this->create_line_item();

		$record = new SettlementRecord(
			order_item_id: $item_id,
			shop_id: 501,
			partner_term_id: 7,
			rate: new ProfitRate( 20 ),
			actual_price: '888.00',
			profit_amount: '177.60',
			status: SettlementRecord::STATUS_PENDING,
			settled_at: null,
			settled_by: null,
			is_refund_after_paid: false
		);

		$this->repo->save( $record );

		$this->assertOrderItemMetaEquals( $item_id, '_profit_shop_id', '501' );
		$this->assertOrderItemMetaEquals( $item_id, '_profit_partner_term_id', '7' );
		$this->assertOrderItemMetaEquals( $item_id, '_profit_rate', '20' );
		$this->assertOrderItemMetaEquals( $item_id, '_actual_price', '888.00' );
		$this->assertOrderItemMetaEquals( $item_id, '_profit_amount', '177.60' );
		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', SettlementRecord::STATUS_PENDING );
	}

	/**
	 * find_by_order_item() 必須能從 itemmeta 重建 SettlementRecord
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_by_order_item_returns_record(): void {
		$item_id = $this->create_line_item();
		$record  = $this->save_settlement(
			$item_id,
			[
				'shop_id'         => 501,
				'partner_term_id' => 7,
				'rate'            => 25,
				'actual_price'    => '500',
				'profit_amount'   => '125',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		$loaded = $this->repo->find_by_order_item( $item_id );

		$this->assertInstanceOf( SettlementRecord::class, $loaded );
		$this->assertSame( $item_id, $loaded->order_item_id );
		$this->assertSame( 501, $loaded->shop_id );
		$this->assertSame( 7, $loaded->partner_term_id );
		$this->assertSame( 25, $loaded->rate->value() );
		$this->assertSame( '500', $loaded->actual_price );
		$this->assertSame( '125', $loaded->profit_amount );
		$this->assertSame( SettlementRecord::STATUS_PENDING, $loaded->status );
		$this->assertNull( $loaded->settled_at );
		$this->assertNull( $loaded->settled_by );
	}

	/**
	 * 狀態從 pending → paid 後，重新 save 應同步更新 itemmeta（含 settled_at、settled_by）
	 *
	 * @test
	 * @group happy
	 */
	public function test_status_transition_pending_to_paid_updates_meta(): void {
		$item_id = $this->create_line_item();
		$record  = $this->save_settlement(
			$item_id,
			[
				'shop_id'         => 501,
				'partner_term_id' => 7,
				'rate'            => 20,
				'actual_price'    => '100',
				'profit_amount'   => '20',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		$record->mark_paid( by: 42, at: 1_700_000_000 );
		$this->repo->save( $record );

		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', SettlementRecord::STATUS_PAID );
		$this->assertOrderItemMetaEquals( $item_id, '_settled_at', '1700000000' );
		$this->assertOrderItemMetaEquals( $item_id, '_settled_by', '42' );
	}

	/**
	 * find_by_partner() 應依 partner term id 撈到符合的 SettlementRecord
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_by_partner_returns_matching_records(): void {
		$jerry_term_id = 7;
		$other_term_id = 99;

		// Jerry 兩筆
		$item_a = $this->create_line_item();
		$this->save_settlement(
			$item_a,
			[
				'shop_id'         => 501,
				'partner_term_id' => $jerry_term_id,
				'rate'            => 20,
				'actual_price'    => '300',
				'profit_amount'   => '60',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);
		$item_b = $this->create_line_item();
		$this->save_settlement(
			$item_b,
			[
				'shop_id'         => 502,
				'partner_term_id' => $jerry_term_id,
				'rate'            => 30,
				'actual_price'    => '400',
				'profit_amount'   => '120',
				'status'          => SettlementRecord::STATUS_PAID,
			]
		);

		// Other partner 一筆，必須被排除
		$item_c = $this->create_line_item();
		$this->save_settlement(
			$item_c,
			[
				'shop_id'         => 700,
				'partner_term_id' => $other_term_id,
				'rate'            => 10,
				'actual_price'    => '50',
				'profit_amount'   => '5',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		$found = $this->repo->find_by_partner( $jerry_term_id, new FilterCriteria() );

		$this->assertCount( 2, $found, 'Jerry 應有 2 筆結算紀錄' );
		foreach ( $found as $record ) {
			$this->assertSame( $jerry_term_id, $record->partner_term_id );
		}
	}

	/**
	 * 🔴 `shop_subscription` 上的 line item 不得被撈進結算查詢
	 *
	 * 訂閱商品結帳後，`_profit_*` 這組 line item meta 會**同時存在於** parent order
	 * （`shop_order`）與 `shop_subscription` 兩筆記錄上。而 `shop_subscription` 的狀態
	 * （`wc-active` / `wc-on-hold` / `wc-pending-cancel`）**完全不在**
	 * `EXCLUDED_ORDER_STATUSES` 內 ⇒ 只濾狀態不濾型別的話兩筆都會被撈出來，
	 * **夥伴的分潤金額直接翻倍**。
	 *
	 * ⚠️ 這個缺陷在 2026-08 之前不發作，只因為沒有任何程式碼會寫入這些 meta；
	 *    `OrderLineItemSettlementHook`（結帳寫入）一上線就會立刻引爆。
	 *
	 * ⚠️ 本測試涵蓋的是 **legacy（HPOS 關閉）** 那條 SQL——wp-env 的 `afterStart` 顯式把
	 *    `woocommerce_custom_orders_table_enabled` 設為 `no`。HPOS 那條分支的同款過濾
	 *    （`o.type = 'shop_order'`）目前只靠程式碼審查，未被自動化測試覆蓋。
	 *
	 * @test
	 * @group security
	 */
	public function test_find_by_partner_excludes_shop_subscription_line_items(): void {
		$partner_term_id = 4242;

		// (1) 正常訂單（shop_order）的 line item
		$order_item_id = $this->create_line_item();
		$this->save_settlement(
			$order_item_id,
			[
				'shop_id'         => 900,
				'partner_term_id' => $partner_term_id,
				'rate'            => 20,
				'actual_price'    => '500.00',
				'profit_amount'   => '100.00',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		// (2) shop_subscription 上帶著同一組 meta 的 line item（WooCommerce Subscriptions 的真實行為）
		$subscription_item_id = $this->create_subscription_line_item();
		$this->save_settlement(
			$subscription_item_id,
			[
				'shop_id'         => 900,
				'partner_term_id' => $partner_term_id,
				'rate'            => 20,
				'actual_price'    => '500.00',
				'profit_amount'   => '100.00',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		/*
		* 🔴 反假綠燈的前置檢查：證明訂閱那一筆**確實有完整可讀的結算 meta**。
		*
		* 少了這一行，本測試在「fixture 根本沒寫進資料」時也會通過（撈到 1 筆），
		* 於是 SQL 過濾即使被拿掉也永遠綠燈。加上它之後，唯一能讓 find_by_partner()
		* 只回 1 筆的原因就只剩「訂單型別過濾生效」——`wc-active` 不在
		* EXCLUDED_ORDER_STATUSES 內，狀態過濾攔不住它。
		*/
		$this->assertInstanceOf(
			SettlementRecord::class,
			$this->repo->find_by_order_item( $subscription_item_id ),
			'前置檢查：訂閱那筆 line item 的結算 meta 應該是寫得進、讀得出的'
		);

		$found = $this->repo->find_by_partner( $partner_term_id, new FilterCriteria() );

		$this->assertCount(
			1,
			$found,
			'只能撈到 shop_order 那一筆；連 shop_subscription 一起撈會讓分潤金額翻倍'
		);
		$this->assertSame( $order_item_id, $found[0]->order_item_id );
	}

	/**
	 * 同一條過濾也必須作用於 find_by_shop()
	 *
	 * 兩個 public 方法共用 `query_order_item_ids()`，但只鎖其中一個等於留了半扇門——
	 * 站長端的賣場報表會看到翻倍金額。
	 *
	 * @test
	 * @group security
	 */
	public function test_find_by_shop_excludes_shop_subscription_line_items(): void {
		$shop_id = 901;

		$order_item_id = $this->create_line_item();
		$this->save_settlement(
			$order_item_id,
			[
				'shop_id'         => $shop_id,
				'partner_term_id' => 4243,
				'rate'            => 10,
				'actual_price'    => '200.00',
				'profit_amount'   => '20.00',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		$subscription_item_id = $this->create_subscription_line_item();
		$this->save_settlement(
			$subscription_item_id,
			[
				'shop_id'         => $shop_id,
				'partner_term_id' => 4243,
				'rate'            => 10,
				'actual_price'    => '200.00',
				'profit_amount'   => '20.00',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		$found = $this->repo->find_by_shop( $shop_id, new FilterCriteria() );

		$this->assertCount( 1, $found );
		$this->assertSame( $order_item_id, $found[0]->order_item_id );
	}

	/**
	 * 確認 OrderUtil 已載入（HPOS 工具 class 存在），證明測試在 WC HPOS 相容環境執行
	 *
	 * 真正的 HPOS 開關差異交給 CI matrix（with-wc-classic / with-wc-hpos）兩種情境跑同一份測試。
	 *
	 * @test
	 * @group happy
	 */
	public function test_woocommerce_orderutil_is_available(): void {
		$this->assertTrue(
			class_exists( OrderUtil::class ),
			'WooCommerce OrderUtil class 應存在；本測試假設 wc_*_order_item_meta API 在 HPOS 開/關都能正常運作'
		);

		// 不論 HPOS 開或關，wc_get_order_item_meta 都應可取用
		$this->assertTrue(
			function_exists( 'wc_get_order_item_meta' ),
			'wc_get_order_item_meta 應在 WC 載入後可用'
		);
		$this->assertTrue(
			function_exists( 'wc_update_order_item_meta' ),
			'wc_update_order_item_meta 應在 WC 載入後可用'
		);
	}

	// ========== is_refund_after_paid 持久化（spec §5.3 / §7.9） ==========

	/**
	 * `is_refund_after_paid` 必須存得下去也讀得回來
	 *
	 * ⚠️ 在本欄位補上之前，`SettlementRecord::$is_refund_after_paid` 是一個
	 * **設得起來但存不下去**的旗標：`save()` 沒寫它、`find_by_order_item()` 讀回恆 false，
	 * 全程沒有任何錯誤訊息。讀寫必須成套 —— 任一邊漏掉就會回到那個狀態，
	 * 所以這條測試同時斷言「meta 真的落地」與「Entity 讀得回 true」兩件事。
	 *
	 * @test
	 * @group happy
	 */
	public function test_is_refund_after_paid_survives_save_and_find_round_trip(): void {
		$item_id = $this->create_line_item();

		$this->save_settlement(
			$item_id,
			[
				'shop_id'              => 1932,
				'partner_term_id'      => 5,
				'rate'                 => 20,
				'actual_price'         => '240.00',
				'profit_amount'        => '144.00',
				'status'               => SettlementRecord::STATUS_PAID,
				'settled_at'           => 1754400000,
				'settled_by'           => 1,
				'is_refund_after_paid' => true,
			]
		);

		$this->assertSame(
			'1',
			(string) \wc_get_order_item_meta( $item_id, '_profit_refund_after_paid', true ),
			'旗標必須真的寫進 order itemmeta（底線前綴讓它不會出現在顧客的訂單頁 / email）'
		);

		$found = $this->repo->find_by_order_item( $item_id );

		$this->assertInstanceOf( SettlementRecord::class, $found );
		$this->assertTrue( $found->is_refund_after_paid );
		$this->assertSame( SettlementRecord::STATUS_PAID, $found->status, '旗標不得連帶改動狀態' );
	}

	/**
	 * false 時刪除 meta（而不是寫 '0'），讀回仍是 false
	 *
	 * 絕大多數結算紀錄這個旗標都是 false，寫 '0' 等於替每一筆訂單品項憑空多一列
	 * order itemmeta；缺鍵與 '0' 的語意在讀取端完全相同。
	 *
	 * @test
	 * @group edge
	 */
	public function test_is_refund_after_paid_false_removes_the_meta_row(): void {
		$item_id = $this->create_line_item();

		$this->save_settlement(
			$item_id,
			[
				'shop_id'              => 1932,
				'partner_term_id'      => 5,
				'rate'                 => 20,
				'actual_price'         => '240.00',
				'profit_amount'        => '144.00',
				'status'               => SettlementRecord::STATUS_PAID,
				'is_refund_after_paid' => true,
			]
		);

		// 再存一次、這次旗標為 false —— 必須把先前那一列清掉，不是留著 '1'。
		$this->save_settlement(
			$item_id,
			[
				'shop_id'              => 1932,
				'partner_term_id'      => 5,
				'rate'                 => 20,
				'actual_price'         => '240.00',
				'profit_amount'        => '144.00',
				'status'               => SettlementRecord::STATUS_PAID,
				'is_refund_after_paid' => false,
			]
		);

		$this->assertSame( '', (string) \wc_get_order_item_meta( $item_id, '_profit_refund_after_paid', true ) );

		$found = $this->repo->find_by_order_item( $item_id );

		$this->assertInstanceOf( SettlementRecord::class, $found );
		$this->assertFalse( $found->is_refund_after_paid );
	}

	/**
	 * 既有資料（沒有這個 meta key）讀為 false，且其餘欄位照常讀得出來
	 *
	 * 這個欄位是後加的，2026-08 之前寫入的結算紀錄都沒有它。缺鍵一律正規化為 false，
	 * **不需要 migration**（同 Phase 9 `inflated_count` 缺鍵讀為 0 的做法）。
	 *
	 * ⚠️ 刻意一併斷言其他欄位仍正確 —— 否則「整筆記錄被丟棄所以碰巧拿到預設值 false」
	 * 這種偽綠燈會通過。
	 *
	 * @test
	 * @group edge
	 */
	public function test_legacy_record_without_refund_after_paid_meta_reads_as_false(): void {
		$item_id = $this->create_line_item();

		$this->save_settlement(
			$item_id,
			[
				'shop_id'              => 1932,
				'partner_term_id'      => 5,
				'rate'                 => 20,
				'actual_price'         => '240.00',
				'profit_amount'        => '144.00',
				'status'               => SettlementRecord::STATUS_PAID,
				'settled_at'           => 1754400000,
				'settled_by'           => 7,
				'is_refund_after_paid' => true,
			]
		);

		// 模擬「本欄位存在之前寫入的舊資料」：把 meta 整列刪掉。
		\wc_delete_order_item_meta( $item_id, '_profit_refund_after_paid' );

		$found = $this->repo->find_by_order_item( $item_id );

		$this->assertInstanceOf( SettlementRecord::class, $found );
		$this->assertFalse( $found->is_refund_after_paid, '缺鍵應正規化為 false' );

		// 防偽綠燈：其餘欄位必須完好，證明不是整筆讀取失敗才拿到預設值。
		$this->assertSame( 1932, $found->shop_id );
		$this->assertSame( 5, $found->partner_term_id );
		$this->assertSame( 20, $found->rate->value() );
		$this->assertSame( '240.00', $found->actual_price );
		$this->assertSame( '144.00', $found->profit_amount );
		$this->assertSame( SettlementRecord::STATUS_PAID, $found->status );
		$this->assertSame( 1754400000, $found->settled_at );
		$this->assertSame( 7, $found->settled_by );
	}

	/**
	 * `flag_refund_after_paid()` 只寫旗標，不得動到狀態與撥款欄位
	 *
	 * 🔴 這正是它存在的理由：`save()` 會把 `_settlement_status` / `_settled_at` /
	 * `_settled_by` 用呼叫端讀取當下的舊值寫回去，若站長此刻剛把該筆人工沖回成
	 * `refunded`，就會被推回 `paid` ⇒ 重複撥款。
	 *
	 * @test
	 * @group security
	 */
	public function test_flag_refund_after_paid_does_not_touch_status_or_settled_fields(): void {
		$item_id = $this->create_line_item();

		$this->save_settlement(
			$item_id,
			[
				'shop_id'         => 1932,
				'partner_term_id' => 5,
				'rate'            => 20,
				'actual_price'    => '240.00',
				'profit_amount'   => '144.00',
				'status'          => SettlementRecord::STATUS_PAID,
				'settled_at'      => 1754400000,
				'settled_by'      => 9,
			]
		);

		$this->repo->flag_refund_after_paid( $item_id );

		$found = $this->repo->find_by_order_item( $item_id );

		$this->assertInstanceOf( SettlementRecord::class, $found );
		$this->assertTrue( $found->is_refund_after_paid );
		$this->assertSame( SettlementRecord::STATUS_PAID, $found->status );
		$this->assertSame( 1754400000, $found->settled_at );
		$this->assertSame( 9, $found->settled_by );
		$this->assertSame( '144.00', $found->profit_amount, '旗標不得連帶改動金額' );
	}

	/**
	 * `update_profit_amount()` 只改金額，其餘欄位一律不動
	 *
	 * @test
	 * @group security
	 */
	public function test_update_profit_amount_only_changes_the_amount(): void {
		$item_id = $this->create_line_item();

		$this->save_settlement(
			$item_id,
			[
				'shop_id'         => 1932,
				'partner_term_id' => 5,
				'rate'            => 20,
				'actual_price'    => '240.00',
				'profit_amount'   => '144.00',
				'status'          => SettlementRecord::STATUS_PAID,
				'settled_at'      => 1754400000,
				'settled_by'      => 9,
			]
		);

		$this->repo->update_profit_amount( $item_id, '96.00' );

		$found = $this->repo->find_by_order_item( $item_id );

		$this->assertInstanceOf( SettlementRecord::class, $found );
		$this->assertSame( '96.00', $found->profit_amount );
		$this->assertSame( SettlementRecord::STATUS_PAID, $found->status );
		$this->assertSame( '240.00', $found->actual_price, '凍結的實際單價不得被改動' );
		$this->assertSame( 20, $found->rate->value(), '凍結的比例不得被改動' );
		$this->assertSame( 1754400000, $found->settled_at );
		$this->assertSame( 9, $found->settled_by );
	}

	// ========== Helpers ==========

	/**
	 * 建立一筆真實的 WC line item（含 product），回傳 order_item_id
	 *
	 * 過程：建立 simple product → 建立 WC_Order → 透過 add_product() 新增 line item
	 */
	private function create_line_item(): int {
		$product = $this->createSimpleProduct(
			[
				'name'          => '結算測試商品',
				'regular_price' => '888',
			]
		);

		$order   = new \WC_Order();
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		$this->assertGreaterThan( 0, $item_id, '建立 WC line item 失敗' );

		return (int) $item_id;
	}

	/**
	 * 建立一筆掛在 `shop_subscription` 底下的 line item，回傳 order_item_id
	 *
	 * 站台沒有安裝 WooCommerce Subscriptions（`.wp-env.json` 的 plugins 清單不含它），
	 * 所以 `shop_subscription` post type 與 `wc-active` post status 都沒有被註冊。
	 * 但受測的是**直接讀 DB 的 SQL**，真正重要的是 `wp_posts` 那一列的
	 * `post_type` / `post_status` 欄位值——因此建立後用 `$wpdb->update()` 直接把兩個欄位
	 * 寫成訂閱的樣子，避開 `wp_insert_post()` 對未註冊型別 / 狀態可能做的正規化。
	 *
	 * `wc-active` 刻意選用：它**不在** `EXCLUDED_ORDER_STATUSES` 內，
	 * 所以只靠狀態過濾一定攔不住——這正是本測試要鎖的缺口。
	 *
	 * @return int order_item_id
	 */
	private function create_subscription_line_item(): int {
		global $wpdb;

		$subscription_id = \wp_insert_post(
			[
				'post_title'  => '訂閱測試',
				'post_status' => 'draft',
				'post_type'   => 'post',
			]
		);

		$this->assertIsInt( $subscription_id );
		$this->assertGreaterThan( 0, $subscription_id, '建立訂閱 post 失敗' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-active',
			],
			[ 'ID' => $subscription_id ]
		);
		\clean_post_cache( $subscription_id );

		// 確認 DB 那一列真的長成訂閱的樣子——受測的 SQL 讀的就是這兩個欄位
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT post_type, post_status FROM {$wpdb->posts} WHERE ID = %d", $subscription_id ),
			ARRAY_A
		);

		$this->assertSame( 'shop_subscription', $row['post_type'] ?? '' );
		$this->assertSame( 'wc-active', $row['post_status'] ?? '' );
		$this->assertNotContains(
			'wc-active',
			[ 'trash', 'wc-trash', 'wc-failed', 'wc-cancelled', 'failed', 'cancelled', 'auto-draft' ],
			'前提：wc-active 不在 EXCLUDED_ORDER_STATUSES 內，所以狀態過濾攔不住訂閱'
		);

		$item_id = \wc_add_order_item(
			$subscription_id,
			[
				'order_item_name' => '訂閱測試品項',
				'order_item_type' => 'line_item',
			]
		);

		$this->assertGreaterThan( 0, (int) $item_id, '建立訂閱 line item 失敗' );

		return (int) $item_id;
	}

	/**
	 * 直接透過 Repository save 一筆 SettlementRecord，回傳該 Entity（方便鏈式 mark_paid 等）
	 *
	 * @param int                   $item_id order_item_id
	 * @param array<string, mixed>  $args    SettlementRecord 欄位
	 */
	private function save_settlement( int $item_id, array $args ): SettlementRecord {
		$record = new SettlementRecord(
			order_item_id: $item_id,
			shop_id: (int) ( $args['shop_id'] ?? 0 ),
			partner_term_id: (int) ( $args['partner_term_id'] ?? 0 ),
			rate: new ProfitRate( (int) ( $args['rate'] ?? 0 ) ),
			actual_price: (string) ( $args['actual_price'] ?? '0' ),
			profit_amount: (string) ( $args['profit_amount'] ?? '0' ),
			status: (string) ( $args['status'] ?? SettlementRecord::STATUS_PENDING ),
			settled_at: $args['settled_at'] ?? null,
			settled_by: $args['settled_by'] ?? null,
			is_refund_after_paid: (bool) ( $args['is_refund_after_paid'] ?? false )
		);
		$this->repo->save( $record );
		return $record;
	}
}
