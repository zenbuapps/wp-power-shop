<?php
/**
 * 結算查詢的 HPOS（custom orders table）路徑整合測試
 *
 * 對應規格：specs/features/profit-shop/query-settlements.feature
 *          specs/2026-05-06-profit-shop-design.md §2.5、§6.6
 *
 * 對應實作（**六個 public 入口、共 11 段 SQL 各有一條 HPOS 分支**）：
 *   inc/classes/.../Persistence/WpSettlementSummaryProvider.php   summary / trend / find_by_partner
 *   inc/classes/.../Persistence/WpAdminSettlementQuery.php        find_settlements / count_settlements /
 *                                                                summarize_settlements / find_order_ids /
 *                                                                count_order_ids
 *   inc/classes/.../Persistence/OrderItemSettlementRepository.php find_by_partner / find_by_shop
 *
 * ═══ 🔴 這個檔案存在的理由：HPOS 分支長期是零驗證（TD-88）═══
 *
 * 每一段 SQL 都長這樣：
 *
 *   if ( $is_hpos ) { ... INNER JOIN {prefix}wc_orders o ON o.id = oi.order_id ... o.type / o.status }
 *   else            { ... INNER JOIN {posts} p    ON p.ID = oi.order_id ... p.post_type / p.post_status }
 *
 * 而在此之前**兩邊都沒有人跑過 HPOS 那一半**：
 *   - wp-env 的 `afterStart` 顯式把 `woocommerce_custom_orders_table_enabled` 設為 `no`
 *   - 真實站台（2026-08-06 實測）同樣是 `no`
 *   - 既有 `WpSettlementSummaryProviderRealAggregationTest` 的兩條 HPOS 測試互斥 skip，
 *     要靠人記得「翻 option → 重跑第二輪 → 記得還原」，而**沒有任何 CI matrix 會做這件事**
 *
 * ⇒ HPOS 分支只要打錯一個欄位名（`o.post_type` / `p.type` 之類），
 *   在 legacy 站台上**永遠不會有人發現**；等到站長哪天按下 WooCommerce 的
 *   「啟用高效能訂單儲存」，全站分潤報表會一次歸零，而且沒有任何錯誤訊息。
 *
 * ═══ 🔴 為什麼一定要關掉 data sync（本檔最關鍵的一行）═══
 *
 * 光是把 `woocommerce_custom_orders_table_enabled` 設成 `yes` **不足以測出分支差異**。
 * 實測（WooCommerce 10.2 / wp-env）：
 *
 *   sync=yes → posts 列 post_type=shop_order            wc_orders 列 type=shop_order
 *   sync=no  → posts 列 post_type=shop_order_placehold  wc_orders 列 type=shop_order
 *
 * sync 開著時**兩張表都有一筆合格的資料列**，legacy SQL 一樣撈得到 ⇒ 把 HPOS 分支整段
 * 刪掉、強制走 legacy，測試照樣全綠 ⇒ 那會是一個永遠不會紅的測試。
 *
 * 關掉 sync 之後，posts 那列退化成 `shop_order_placehold` / `draft` 的佔位列，
 * 撞不上 legacy SQL 的 `p.post_type = 'shop_order'`（Phase 17 F-3 補上的訂單型別過濾）
 * ⇒ **走錯分支就是 0 筆**，紅燈形狀最乾淨。`assert_hpos_is_really_active()` 每條測試
 * 都先跑一次，把這個前提本身也變成斷言。
 *
 * ⚠️ sync=no 是 WooCommerce 的**預設值**（實測 option 不存在 ⇒ `false`），
 *    不是本測試為了製造紅燈而捏造的異常設定。
 *
 * ═══ 與既有 `WpSettlementSummaryProviderRealAggregationTest` 的分工 ═══
 *
 * 那一檔驗證的是「聚合算得對不對」（金額分桶、日期過濾、IDOR 隔離……），跑在 legacy 路徑；
 * 本檔驗證的是「**換到 HPOS 之後同一批查詢還撈不撈得到東西**」。兩者刻意不合併：
 * 那一檔的 fixture 龐大且 legacy 專屬，硬塞 HPOS 只會讓兩種路徑的失敗互相遮蔽。
 *
 * @group profit_shop
 * @group infrastructure
 * @group persistence
 * @group settlement
 * @group hpos_compatible
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Persistence;

use Automattic\WooCommerce\Utilities\OrderUtil;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpAdminSettlementQuery;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpSettlementSummaryProvider;
use Tests\Integration\TestCase;

/**
 * HPOS 啟用狀態下的結算查詢整合測試
 */
final class SettlementQueryHposPathTest extends TestCase {

	/**
	 * HPOS 開關的 option key
	 */
	private const OPT_HPOS = 'woocommerce_custom_orders_table_enabled';

	/**
	 * HPOS ↔ posts 雙寫同步的 option key
	 */
	private const OPT_SYNC = 'woocommerce_custom_orders_table_data_sync_enabled';

	/**
	 * 測試用的分潤夥伴 term id（刻意用高位固定值，與其他測試的 fixture 不相交）
	 */
	private const PARTNER_ID = 771001;

	/**
	 * 另一個夥伴（用來證明隔離在 HPOS 路徑下同樣成立）
	 */
	private const OTHER_PARTNER_ID = 771002;

	/**
	 * 測試用的賣場 id
	 */
	private const SHOP_ID = 772001;

	/**
	 * 原始 option 值（tear_down 還原用）
	 *
	 * @var mixed
	 */
	private mixed $orig_hpos = null;

	/**
	 * 原始 sync option 值
	 *
	 * @var mixed
	 */
	private mixed $orig_sync = null;

	/**
	 * 建立出來的訂單 id
	 *
	 * @var int[]
	 */
	private array $order_ids = [];

	/**
	 * label => order_item_id
	 *
	 * @var array<string, int>
	 */
	private array $item_ids = [];

	/**
	 * label => order_id
	 *
	 * @var array<string, int>
	 */
	private array $order_by_label = [];

	private WpSettlementSummaryProvider $provider;
	private WpAdminSettlementQuery $admin_query;
	private OrderItemSettlementRepository $repo;

	/**
	 * setUp：先把環境切到 HPOS，再建 fixture
	 *
	 * 順序不可對調——`wc_create_order()` 走哪一個 data store 是在**建立當下**決定的，
	 * 先建訂單再翻 option 會得到一批只存在於 posts 的訂單，HPOS SQL 撈不到，
	 * 測試會以「找不到資料」的形式紅燈，看起來像 SQL 寫錯，實際上是 fixture 順序錯。
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( OrderUtil::class ) ) {
			$this->markTestSkipped( 'OrderUtil 不存在；需要 WooCommerce 7.1+' );
		}

		$this->orig_hpos = \get_option( self::OPT_HPOS );
		$this->orig_sync = \get_option( self::OPT_SYNC );

		\update_option( self::OPT_HPOS, 'yes' );
		\update_option( self::OPT_SYNC, 'no' );

		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped(
				'翻了 option 但 OrderUtil 仍回報 HPOS 未啟用——'
				. '可能是 wc_orders 資料表不存在，或 WooCommerce 版本的判斷邏輯改了。'
			);
		}

		$this->provider    = WpSettlementSummaryProvider::instance();
		$this->admin_query = WpAdminSettlementQuery::instance();
		$this->repo        = OrderItemSettlementRepository::instance();

		$this->build_fixture();
	}

	/**
	 * tearDown：**先刪訂單、再還原 option**
	 *
	 * 反過來的話 `wc_get_order()` 會用 legacy data store 去找一筆只存在於 wc_orders 的訂單，
	 * 刪不掉 ⇒ 資料殘留 ⇒ 污染同一輪後續所有結算測試的聚合數字。
	 */
	public function tear_down(): void {
		foreach ( $this->order_ids as $oid ) {
			$order = \wc_get_order( $oid );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		$this->order_ids = [];
		$this->item_ids  = [];

		\update_option( self::OPT_HPOS, false === $this->orig_hpos ? 'no' : $this->orig_hpos );
		\update_option( self::OPT_SYNC, false === $this->orig_sync ? 'no' : $this->orig_sync );

		parent::tear_down();
	}

	// ====================================================================
	//  A. 前提本身就是斷言
	// ====================================================================

	/**
	 * 🔴 先證明「這一輪真的在 HPOS 上，而且 legacy SQL 撈不到」
	 *
	 * 這條是本檔其他所有測試的地基。它同時鎖住三件事：
	 *   1. option 翻了之後 `OrderUtil` 真的回報啟用（不是我們自以為）
	 *   2. 訂單真的落在 `wc_orders`（不是只翻了旗標、資料還在 posts）
	 *   3. posts 那一列是 `shop_order_placehold` ⇒ **legacy SQL 的型別過濾撈不到它**
	 *
	 * 第 3 點是「走錯分支就會紅」的唯一保證。少了它，本檔會退化成
	 * 「在 sync 開著的環境下兩條分支都能過」的假綠燈。
	 *
	 * @test
	 * @group smoke
	 */
	public function test_fixture_is_really_on_hpos_and_invisible_to_legacy_sql(): void {
		global $wpdb;

		$this->assert_hpos_is_really_active();

		$order_id = $this->order_ids[0];

		$hpos_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT type, status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ),
			ARRAY_A
		);
		$this->assertIsArray( $hpos_row, '訂單必須存在於 wc_orders' );
		$this->assertSame( 'shop_order', $hpos_row['type'] );

		$post_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT post_type, post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ),
			ARRAY_A
		);

		/*
		 * posts 那列可能不存在，也可能是 `shop_order_placehold` 佔位列——
		 * 兩種都符合「legacy SQL 撈不到」。唯一不可接受的是 `shop_order`，
		 * 那代表 data sync 沒關成功，本檔全部測試會失去鑑別力。
		 */
		if ( is_array( $post_row ) ) {
			$this->assertNotSame(
				OrderItemSettlementRepository::ORDER_POST_TYPE,
				$post_row['post_type'],
				'data sync 未關閉：posts 仍有一筆合格的 shop_order ⇒ legacy SQL 也撈得到 ⇒ 本檔失去鑑別力'
			);
		}
	}

	// ====================================================================
	//  B. WpSettlementSummaryProvider（夥伴 portal 的三個畫面）
	// ====================================================================

	/**
	 * KPI 聚合在 HPOS 下必須算出與 legacy 相同的數字
	 *
	 * fixture：pending 100 + paid 250 + refunded 30，另有一筆 cancelled 訂單（不得計入）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_summary_for_partner_works_on_hpos(): void {
		$this->assert_hpos_is_really_active();

		$kpi = $this->provider->summary_for_partner( self::PARTNER_ID, new FilterCriteria() );

		$this->assertSame( '100.00', $kpi['profit_pending'], 'HPOS 路徑的待撥款聚合' );
		$this->assertSame( '250.00', $kpi['profit_paid'], 'HPOS 路徑的已撥款聚合' );
		$this->assertSame( '30.00', $kpi['profit_refunded'], 'HPOS 路徑的已退款聚合' );

		// total_sales = 500 + 1000 + 300（cancelled 那筆的 900 不得計入）
		$this->assertSame( '1800.00', $kpi['total_sales'], 'cancelled 訂單必須被 EXCLUDED_ORDER_STATUSES 擋掉' );
	}

	/**
	 * 趨勢查詢在 HPOS 下必須撈得到 bucket
	 *
	 * @test
	 * @group happy
	 */
	public function test_trend_for_partner_works_on_hpos(): void {
		$this->assert_hpos_is_really_active();

		$trend = $this->provider->trend_for_partner( self::PARTNER_ID, new FilterCriteria(), 'day' );

		$this->assertNotSame( [], $trend, 'HPOS 路徑的趨勢查詢不得回空（回空代表 JOIN 撈不到訂單）' );

		$total = '0.00';
		foreach ( $trend as $bucket ) {
			$total = bcadd( $total, (string) $bucket['profit'], 2 );
		}
		$this->assertSame( '380.00', $total, '趨勢各 bucket 加總應等於 pending + paid + refunded' );
	}

	/**
	 * 明細列表在 HPOS 下必須撈得到，且只回該夥伴的
	 *
	 * @test
	 * @group security
	 */
	public function test_find_by_partner_works_and_stays_isolated_on_hpos(): void {
		$this->assert_hpos_is_really_active();

		$records = $this->provider->find_by_partner( self::PARTNER_ID, new FilterCriteria() );

		$this->assertCount( 3, $records, 'HPOS 路徑應撈到 3 筆有效結算（cancelled 訂單那筆不算）' );

		// `WpSettlementSummaryProvider::find_by_partner()` 回的是**關聯陣列**，不是
		// `SettlementRecord` 物件（那是 `OrderItemSettlementRepository` 的形狀）。
		foreach ( $records as $record ) {
			$this->assertSame(
				self::PARTNER_ID,
				$record['partner_term_id'],
				'HPOS 路徑同樣不得回傳其他夥伴的結算紀錄'
			);
		}

		$other = $this->provider->find_by_partner( self::OTHER_PARTNER_ID, new FilterCriteria() );
		$this->assertCount( 1, $other, '另一位夥伴應只看到自己那一筆' );
	}

	// ====================================================================
	//  C. WpAdminSettlementQuery（站長端結算頁的五個入口）
	// ====================================================================

	/**
	 * 後台結算列表在 HPOS 下必須撈得到
	 *
	 * @test
	 * @group happy
	 */
	public function test_admin_find_settlements_works_on_hpos(): void {
		$this->assert_hpos_is_really_active();

		$filter = new SettlementFilterInput( partner_term_ids: [ self::PARTNER_ID ] );
		$rows   = $this->admin_query->find_settlements( $filter );

		$this->assertCount( 3, $rows, '後台列表的 HPOS 分支撈不到資料的話這裡會是 0' );
		$this->assertSame( 3, $this->admin_query->count_settlements( $filter ), 'count 與 list 必須一致' );
	}

	/**
	 * 後台摘要卡在 HPOS 下必須算得出金額
	 *
	 * 摘要與列表是**兩段獨立的 SQL**，各有自己的 HPOS 分支——
	 * 只鎖列表等於留了一扇門，而站長最先看到的正是這四張摘要卡。
	 *
	 * @test
	 * @group happy
	 */
	public function test_admin_summarize_settlements_works_on_hpos(): void {
		$this->assert_hpos_is_really_active();

		$summary = $this->admin_query->summarize_settlements(
			new SettlementFilterInput( partner_term_ids: [ self::PARTNER_ID ] )
		);

		$this->assertSame( '100.00', $summary['pending_amount'] );
		$this->assertSame( 1, $summary['pending_count'] );
		$this->assertSame( '250.00', $summary['paid_amount'] );
		$this->assertSame( 1, $summary['paid_count'] );
		$this->assertSame( '30.00', $summary['refunded_amount'] );
		$this->assertSame( 1, $summary['refunded_count'] );
	}

	/**
	 * 訂單列表的「分潤賣場」篩選（order-ids）在 HPOS 下必須撈得到
	 *
	 * 又是一段獨立 SQL。撈不到的話站長選了賣場會看到「這個賣場沒有任何訂單」，
	 * 而畫面上不會有任何錯誤——只會像是真的沒有訂單。
	 *
	 * @test
	 * @group happy
	 */
	public function test_admin_find_order_ids_works_on_hpos(): void {
		$this->assert_hpos_is_really_active();

		$filter = new SettlementFilterInput( shop_ids: [ self::SHOP_ID ] );

		$ids = array_map( 'intval', $this->admin_query->find_order_ids( $filter, 200 ) );

		$this->assertNotSame( [], $ids, 'HPOS 路徑的 order-ids 查詢回空 ⇒ 訂單列表篩選會整個失效' );

		// 這個賣場底下 5 張訂單，其中 cancelled 那張必須被 `o.status NOT IN (...)` 擋掉。
		// 只斷言「有撈到東西」不夠——那樣就算 HPOS 分支漏掉狀態過濾也一樣綠。
		$this->assertCount( 4, $ids );
		$this->assertNotContains(
			$this->order_by_label['cancelled'],
			$ids,
			'cancelled 訂單不得出現在可篩選的訂單清單內（HPOS 分支的 o.status 過濾）'
		);
		$this->assertSame( 4, $this->admin_query->count_order_ids( $filter ), 'count 與 list 必須一致' );

		foreach ( $ids as $id ) {
			$this->assertContains( $id, $this->order_ids );
		}
	}

	// ====================================================================
	//  D. OrderItemSettlementRepository（Domain Repository 介面實作）
	// ====================================================================

	/**
	 * Repository 的 find_by_partner / find_by_shop 在 HPOS 下必須撈得到
	 *
	 * 這兩個方法共用 `query_order_item_ids()`，是 Phase 17 F-3 修過的兩段 SQL 之一。
	 *
	 * @test
	 * @group happy
	 */
	public function test_repository_queries_work_on_hpos(): void {
		$this->assert_hpos_is_really_active();

		$by_partner = $this->repo->find_by_partner( self::PARTNER_ID, new FilterCriteria() );
		$this->assertCount( 3, $by_partner );

		$by_shop = $this->repo->find_by_shop( self::SHOP_ID, new FilterCriteria() );
		$this->assertCount( 4, $by_shop, '同一個賣場含另一位夥伴那筆，共 4 筆有效結算' );
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 每條測試開頭都要跑：確認這一輪真的在 HPOS 上
	 *
	 * 若哪天有人把 `set_up()` 的翻 option 拿掉、或 WooCommerce 改了判斷邏輯，
	 * 本檔會以「HPOS 未啟用」明確紅燈，而**不是**安靜地退化成又一輪 legacy 測試。
	 */
	private function assert_hpos_is_really_active(): void {
		$this->assertTrue(
			OrderUtil::custom_orders_table_usage_is_enabled(),
			'本檔的所有斷言都建立在「這一輪跑 HPOS」之上；未啟用時測到的是 legacy 分支'
		);
	}

	/**
	 * 建立 fixture
	 *
	 * | 標籤       | 夥伴          | 訂單狀態      | 結算狀態  | 實收   | 分潤   |
	 * |-----------|--------------|--------------|----------|-------|-------|
	 * | pending   | PARTNER      | wc-completed | pending  | 500   | 100   |
	 * | paid      | PARTNER      | wc-completed | paid     | 1000  | 250   |
	 * | refunded  | PARTNER      | wc-refunded  | refunded | 300   | 30    |
	 * | cancelled | PARTNER      | wc-cancelled | pending  | 900   | 180   | ← 不得計入
	 * | other     | OTHER        | wc-completed | pending  | 400   | 80    |
	 *
	 * `cancelled` 那筆不是湊數：它讓「HPOS 分支的 `o.status NOT IN (...)`」也被覆蓋到。
	 * 少了它，就算 HPOS 分支漏掉狀態過濾，聚合數字仍然全對。
	 */
	private function build_fixture(): void {
		$this->seed( 'pending', self::PARTNER_ID, 'wc-completed', SettlementRecord::STATUS_PENDING, '500.00', '100.00' );
		$this->seed( 'paid', self::PARTNER_ID, 'wc-completed', SettlementRecord::STATUS_PAID, '1000.00', '250.00' );
		$this->seed( 'refunded', self::PARTNER_ID, 'wc-refunded', SettlementRecord::STATUS_REFUNDED, '300.00', '30.00' );
		$this->seed( 'cancelled', self::PARTNER_ID, 'wc-cancelled', SettlementRecord::STATUS_PENDING, '900.00', '180.00' );
		$this->seed( 'other', self::OTHER_PARTNER_ID, 'wc-completed', SettlementRecord::STATUS_PENDING, '400.00', '80.00' );
	}

	/**
	 * 建一張訂單 + 一筆帶結算 meta 的 line item
	 *
	 * @param string $label             fixture 標籤
	 * @param int    $partner_term_id   夥伴 term id
	 * @param string $order_status      WC 訂單狀態
	 * @param string $settlement_status 結算狀態
	 * @param string $actual_price      實收單價
	 * @param string $profit_amount     應分潤金額
	 */
	private function seed(
		string $label,
		int $partner_term_id,
		string $order_status,
		string $settlement_status,
		string $actual_price,
		string $profit_amount
	): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => "hpos-fixture-{$label}",
				'regular_price' => $actual_price,
			]
		);

		$order   = new \WC_Order();
		$item_id = $order->add_product( $product, 1 );
		$this->assertGreaterThan( 0, $item_id, "建立 line item ({$label}) 失敗" );

		$order->set_date_created( '2026-06-10 09:00:00' );
		$order->set_status( $order_status );
		$order->save();

		$this->repo->save(
			new SettlementRecord(
				order_item_id: (int) $item_id,
				shop_id: self::SHOP_ID,
				partner_term_id: $partner_term_id,
				rate: new ProfitRate( 20 ),
				actual_price: $actual_price,
				profit_amount: $profit_amount,
				status: $settlement_status,
			)
		);

		$this->order_ids[]              = (int) $order->get_id();
		$this->order_by_label[ $label ] = (int) $order->get_id();
		$this->item_ids[ $label ]       = (int) $item_id;
	}
}
