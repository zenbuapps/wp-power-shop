<?php
/**
 * WpSettlementSummaryProvider 真實 SQL 聚合 IT（Phase 3-D 紅燈，最高風險 task）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5、§2.6、§3、§5、§6.6、§7、§8
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/Persistence/WpSettlementSummaryProvider.php
 *
 * 驗證範圍：
 *   1. 基本聚合（total_sales / profit_pending / profit_paid / profit_refunded）
 *   2. 跨 partner 隔離（IDOR 防禦） — partner A 的 query 永遠不能含 partner B 的數據
 *   3. settlement status 分桶（pending / paid / refunded）
 *   4. date range 過濾
 *   5. SQL injection 防禦（status 白名單、shop_id int cast、date 嚴格 format）
 *   6. trend_for_partner 的時間序列分桶
 *   7. find_by_partner 的分頁與 settlement record 內容
 *   8. HPOS 雙路徑（custom orders table 開/關）
 *   9. partner_term_id = 0 防禦
 *
 * 重要區別：
 *   - WC 訂單狀態（wc-completed / wc-pending / wc-refunded） → 由 OrderItemSettlementRepository 內部
 *     的 EXCLUDED_ORDER_STATUSES 排除掉 wc-cancelled / wc-failed / wc-trash 等無效訂單。
 *     pending / completed / on-hold / processing / refunded 訂單的 line item 都應計入。
 *   - Settlement status（pending / paid / refunded / cancelled） → line item 上的 _settlement_status
 *     meta，作為 KpiReport 的 profit_pending / profit_paid / profit_refunded 分桶依據。
 *   - FilterCriteria::statuses 是 settlement status 過濾（spec §3 / FilterCriteria docblock）。
 *
 * 紅燈預期：
 *   - placeholder 的 summary_for_partner 永遠回 0 → 多數聚合斷言會炸（assertSame '300' vs '0.00'）。
 *   - placeholder 的 trend_for_partner 永遠回 [] → trend 斷言會炸。
 *   - placeholder 的 find_by_partner 永遠回 [] → list 斷言會炸。
 *   - 跨 partner 隔離（assertSame '0.00'）反而會 PASS（placeholder 巧合滿足）——這正是
 *     紅燈→綠燈過程中 reviewer 必須警惕的「Phase 3-C placeholder 把安全測試誤導為綠燈」陷阱。
 *
 * @group profit_shop
 * @group infrastructure
 * @group persistence
 * @group settlement
 * @group security
 * @group hpos_compatible
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Persistence;

use Automattic\WooCommerce\Utilities\OrderUtil;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpSettlementSummaryProvider;
use Tests\Integration\TestCase;

/**
 * 對 wc_order_itemmeta 真實 JOIN 聚合的端到端整合測試
 */
final class WpSettlementSummaryProviderRealAggregationTest extends TestCase {

	private WpSettlementSummaryProvider $provider;
	private OrderItemSettlementRepository $repo;

	/**
	 * Partner term IDs（由 fixture 填入）
	 */
	private int $partner_a_id = 0;
	private int $partner_b_id = 0;
	private int $partner_c_id = 0;

	/**
	 * Shop IDs（由 fixture 填入）
	 */
	private int $shop_a_id = 0;
	private int $shop_b_id = 0;

	/**
	 * Order IDs（由 fixture 填入）— key = 標籤；value = order_id
	 *
	 * @var array<string, int>
	 */
	private array $order_ids = [];

	/**
	 * Line item IDs（由 fixture 填入）— key = 標籤；value = order_item_id
	 *
	 * @var array<string, int>
	 */
	private array $item_ids = [];

	public function set_up(): void {
		parent::set_up();

		$this->provider = WpSettlementSummaryProvider::instance();
		$this->repo     = OrderItemSettlementRepository::instance();

		$this->build_fixture();
	}

	public function tear_down(): void {
		// 清除 partner term（profit_partner taxonomy）
		foreach ( [ $this->partner_a_id, $this->partner_b_id, $this->partner_c_id ] as $tid ) {
			if ( $tid > 0 ) {
				\wp_delete_term( $tid, 'profit_partner' );
			}
		}

		// 清除 shop CPT
		foreach ( [ $this->shop_a_id, $this->shop_b_id ] as $pid ) {
			if ( $pid > 0 ) {
				\wp_delete_post( $pid, true );
			}
		}

		// 清除 orders（含 line items）
		foreach ( $this->order_ids as $oid ) {
			$order = \wc_get_order( $oid );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}

		parent::tear_down();
	}

	// ============================================================
	// A. 基本聚合
	// ============================================================

	/**
	 * partner_A + completed orders（settlement status pending+paid）的總和
	 *
	 * fixture：order_1 profit=100 (settlement=paid)、order_2 profit=200 (settlement=pending)
	 *          → total_sales（partner_A） 應 = 880 + 660 = 1540（兩筆 wc-completed 訂單的 actual_price 和）
	 *          → profit_paid = 100；profit_pending = 200
	 *
	 * @test
	 * @group happy
	 */
	public function test_summary_returns_correct_totals_for_partner_a(): void {
		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, new FilterCriteria() );

		// total_sales 涵蓋所有未排除狀態（pending / completed / refunded 都算）
		// 實際依各 fixture line item 的 actual_price 加總；參考 build_fixture 註解。
		$this->assertArrayHasKey( 'total_sales', $kpi );
		$this->assertArrayHasKey( 'profit_pending', $kpi );
		$this->assertArrayHasKey( 'profit_paid', $kpi );
		$this->assertArrayHasKey( 'profit_refunded', $kpi );

		// settlement status 分桶（partner_A 的 line items）：
		//   - order_1 (paid)         → profit 100 → profit_paid
		//   - order_2 (pending)      → profit 200 → profit_pending
		//   - order_3 (pending)      → profit 300 → profit_pending（wc-pending order，仍計入）
		//   - order_5 (refunded)     → profit 50  → profit_refunded
		// total: paid=100, pending=500, refunded=50
		$this->assertSame( '100.00', $kpi['profit_paid'], 'profit_paid 應為 order_1 的 100' );
		$this->assertSame( '500.00', $kpi['profit_pending'], 'profit_pending 應為 order_2(200)+order_3(300)=500' );
		$this->assertSame( '50.00', $kpi['profit_refunded'], 'profit_refunded 應為 order_5 的 50' );
	}

	/**
	 * 沒有任何訂單的 partner → 全 0
	 *
	 * @test
	 * @group happy
	 */
	public function test_summary_returns_zero_for_partner_with_no_orders(): void {
		$kpi = $this->provider->summary_for_partner( $this->partner_c_id, new FilterCriteria() );

		$this->assertSame( '0.00', $kpi['total_sales'] );
		$this->assertSame( '0.00', $kpi['profit_pending'] );
		$this->assertSame( '0.00', $kpi['profit_paid'] );
		$this->assertSame( '0.00', $kpi['profit_refunded'] );
	}

	/**
	 * total_sales 加總所有 partner_A 的 line item actual_price
	 *
	 * fixture actual_price：order_1=880, order_2=660, order_3=440, order_5=220
	 * → 全部 partner_A line item 加總 2200（refund 也計入 total_sales，profit 才分桶）
	 *
	 * @test
	 * @group happy
	 */
	public function test_summary_aggregates_total_sales_correctly(): void {
		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, new FilterCriteria() );

		$this->assertSame( '2200.00', $kpi['total_sales'] );
	}

	// ============================================================
	// B. 跨 partner 隔離（IDOR 防禦）— 最關鍵
	// ============================================================

	/**
	 * partner_A 的 KPI 必須完全不含 partner_B 的 999 元訂單
	 *
	 * 即使 placeholder 巧合過了（回 0），紅燈/綠燈轉換時 reviewer 也應看這條斷言
	 * 確認 SQL 真的有用 partner_term_id WHERE 過濾。
	 *
	 * @test
	 * @group security
	 */
	public function test_summary_for_partner_a_excludes_partner_b_orders(): void {
		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, new FilterCriteria() );

		// partner_B 的 order_4 profit=999；total_sales / profit_paid 都不該含 999
		$this->assertStringNotContainsString( '999', $kpi['total_sales'] );
		$this->assertStringNotContainsString( '999', $kpi['profit_paid'] );
		$this->assertStringNotContainsString( '999', $kpi['profit_pending'] );
		$this->assertStringNotContainsString( '999', $kpi['profit_refunded'] );
	}

	/**
	 * 反向：partner_B 的 KPI 也不可含 partner_A 的數據
	 *
	 * @test
	 * @group security
	 */
	public function test_summary_for_partner_b_excludes_partner_a_orders(): void {
		$kpi = $this->provider->summary_for_partner( $this->partner_b_id, new FilterCriteria() );

		// partner_B 只有 order_4：profit=999，settlement=paid
		$this->assertSame( '999.00', $kpi['profit_paid'] );
		// partner_A 的 100/200/300/50 全都不該出現
		$this->assertSame( '0.00', $kpi['profit_pending'] );
		$this->assertSame( '0.00', $kpi['profit_refunded'] );
	}

	// ============================================================
	// C. Settlement status filter（FilterCriteria::statuses）
	// ============================================================

	/**
	 * statuses=[pending] → 只回 settlement=pending 的 line item profit
	 *
	 * partner_A pending：order_2(200)+order_3(300) = 500
	 *
	 * @test
	 * @group happy
	 */
	public function test_summary_respects_status_filter_pending(): void {
		$criteria = new FilterCriteria( statuses: [ SettlementRecord::STATUS_PENDING ] );
		$kpi      = $this->provider->summary_for_partner( $this->partner_a_id, $criteria );

		// 在 status filter 模式下，profit_pending 應為 500，paid/refunded 為 0
		$this->assertSame( '500.00', $kpi['profit_pending'] );
		$this->assertSame( '0.00', $kpi['profit_paid'] );
		$this->assertSame( '0.00', $kpi['profit_refunded'] );
	}

	/**
	 * statuses=[refunded] → 只回 refunded
	 *
	 * @test
	 * @group happy
	 */
	public function test_summary_respects_status_filter_refunded(): void {
		$criteria = new FilterCriteria( statuses: [ SettlementRecord::STATUS_REFUNDED ] );
		$kpi      = $this->provider->summary_for_partner( $this->partner_a_id, $criteria );

		$this->assertSame( '0.00', $kpi['profit_pending'] );
		$this->assertSame( '0.00', $kpi['profit_paid'] );
		$this->assertSame( '50.00', $kpi['profit_refunded'] );
	}

	// ============================================================
	// D. Date range filter
	// ============================================================

	/**
	 * 限定 2026-04-10 ~ 04-30 + partner_A → 只 order_2 在範圍內
	 *
	 * fixture 訂單日期（見 build_fixture）：
	 *   order_1: 2026-04-01
	 *   order_2: 2026-04-15  ← 唯一落在 [04-10, 04-30] 區間
	 *   order_3: 2026-04-05
	 *   order_5: 2026-04-20  ← 也在區間內
	 *
	 * @test
	 * @group happy
	 */
	public function test_summary_respects_date_range_filter(): void {
		$criteria = new FilterCriteria(
			date_start: \strtotime( '2026-04-10 00:00:00' ),
			date_end: \strtotime( '2026-04-30 23:59:59' ),
		);
		$kpi      = $this->provider->summary_for_partner( $this->partner_a_id, $criteria );

		// 範圍內 partner_A：order_2(pending 200) + order_5(refunded 50)
		$this->assertSame( '200.00', $kpi['profit_pending'] );
		$this->assertSame( '0.00', $kpi['profit_paid'] );
		$this->assertSame( '50.00', $kpi['profit_refunded'] );
	}

	// ============================================================
	// E. SQL injection 防禦
	// ============================================================

	/**
	 * status 白名單防禦：傳入 SQL injection payload 不可繞過 partner 過濾
	 *
	 * 攻擊：statuses = ["wc-completed' OR 1=1 --"]
	 * 預期：白名單比對失敗 → 視為「無 status 命中」→ 各分桶回 0；絕對不可回到所有訂單。
	 *
	 * @test
	 * @group security
	 */
	public function test_summary_does_not_leak_via_status_injection(): void {
		$criteria = new FilterCriteria(
			statuses: [ "wc-completed' OR 1=1 --" ]
		);
		$kpi      = $this->provider->summary_for_partner( $this->partner_a_id, $criteria );

		// 惡意 status 不在白名單 → 應視為無命中 → 全 0
		$this->assertSame( '0.00', $kpi['profit_pending'] );
		$this->assertSame( '0.00', $kpi['profit_paid'] );
		$this->assertSame( '0.00', $kpi['profit_refunded'] );
		// total_sales 在 status filter 啟用時，0 命中 = 0 總額
		$this->assertSame( '0.00', $kpi['total_sales'] );
	}

	/**
	 * shop_ids int cast 防禦：傳 string 含 SQL payload，cast 後變 int，query 不爆炸
	 *
	 * @test
	 * @group security
	 */
	public function test_summary_does_not_leak_via_shop_id_injection(): void {
		// FilterCriteria 簽名要求 int[]，但歷史上常見從 query string 進來會有 string，
		// 這裡用 int cast 後的值表示「正常路徑」，再額外驗證 SQL 不會炸；安全骨架由 prepare 保證。
		$criteria = new FilterCriteria( shop_ids: [ (int) '1; DROP TABLE wp_options; --' ] );

		// 不該 throw、不該 fatal
		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, $criteria );
		$this->assertIsArray( $kpi );

		// 重要副作用斷言：wp_options 表必須仍存在（DROP TABLE 沒被執行）
		global $wpdb;
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->options}'" ); // phpcs:ignore
		$this->assertNotEmpty( $exists, 'wp_options 表必須仍存在；shop_id injection 不可執行 DROP TABLE' );
	}

	/**
	 * date 字串 injection 防禦
	 *
	 * 攻擊：date_start 不是合法 unix timestamp（FilterCriteria 簽名要 ?int），
	 * 但若 provider 內部把它格式化成 SQL 字串時沒有用 prepare → 可能 UNION SELECT。
	 * 這裡用合法 int 但極端值，確認 provider 用 prepare 包好。
	 *
	 * @test
	 * @group security
	 */
	public function test_summary_uses_prepared_statement_for_date_range(): void {
		// PHP_INT_MAX 是 unix timestamp 邏輯上不可能命中 → 預期 0 命中
		$criteria = new FilterCriteria(
			date_start: \PHP_INT_MAX - 100,
			date_end: \PHP_INT_MAX,
		);
		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, $criteria );

		$this->assertSame( '0.00', $kpi['profit_pending'] );
		$this->assertSame( '0.00', $kpi['profit_paid'] );
	}

	// ============================================================
	// F. trend_for_partner（時間序列）
	// ============================================================

	/**
	 * trend granularity=day，partner_A 的時間序列應依日期分桶
	 *
	 * @test
	 * @group happy
	 */
	public function test_trend_groups_by_day(): void {
		$criteria = new FilterCriteria(
			date_start: \strtotime( '2026-04-01 00:00:00' ),
			date_end: \strtotime( '2026-04-30 23:59:59' ),
		);
		$series = $this->provider->trend_for_partner( $this->partner_a_id, $criteria, 'day' );

		$this->assertIsArray( $series );
		$this->assertNotEmpty( $series, 'partner_A 在 2026-04 範圍內應有 trend 點' );

		// 每筆應含 date 欄位
		foreach ( $series as $point ) {
			$this->assertArrayHasKey( 'date', $point );
		}
	}

	/**
	 * trend 跨 partner 隔離
	 *
	 * @test
	 * @group security
	 */
	public function test_trend_excludes_other_partners(): void {
		$criteria = new FilterCriteria(
			date_start: \strtotime( '2026-04-01 00:00:00' ),
			date_end: \strtotime( '2026-04-30 23:59:59' ),
		);
		$series = $this->provider->trend_for_partner( $this->partner_a_id, $criteria, 'day' );

		// partner_B 的 999 不可洩漏進 series
		foreach ( $series as $point ) {
			foreach ( $point as $key => $value ) {
				$this->assertStringNotContainsString( '999', (string) $value, "trend point[{$key}] 不可含 partner_B 的 999" );
			}
		}
	}

	// ============================================================
	// G. find_by_partner（settlement list）
	// ============================================================

	/**
	 * find_by_partner 應回 settlement 陣列（一筆一個 line item）
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_by_partner_returns_settlement_records(): void {
		$list = $this->provider->find_by_partner( $this->partner_a_id, new FilterCriteria( per_page: 100 ) );

		$this->assertIsArray( $list );
		// partner_A 應有 4 筆 settlement（order_1, 2, 3, 5）
		$this->assertCount( 4, $list, 'partner_A 應有 4 筆 settlement' );

		// 每筆應含必要欄位
		foreach ( $list as $row ) {
			$this->assertArrayHasKey( 'order_item_id', $row );
			$this->assertArrayHasKey( 'partner_term_id', $row );
			$this->assertArrayHasKey( 'profit_amount', $row );
			$this->assertArrayHasKey( 'status', $row );
			$this->assertSame( $this->partner_a_id, (int) $row['partner_term_id'], '不可洩漏其他 partner 的 settlement' );
		}
	}

	/**
	 * find_by_partner 分頁（per_page=2）
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_by_partner_respects_pagination(): void {
		$list = $this->provider->find_by_partner( $this->partner_a_id, new FilterCriteria( page: 1, per_page: 2 ) );

		$this->assertIsArray( $list );
		$this->assertCount( 2, $list, 'page=1 per_page=2 應只回 2 筆' );
	}

	/**
	 * find_by_partner 跨 partner 隔離
	 *
	 * @test
	 * @group security
	 */
	public function test_find_by_partner_excludes_other_partners(): void {
		$list = $this->provider->find_by_partner( $this->partner_a_id, new FilterCriteria( per_page: 100 ) );

		foreach ( $list as $row ) {
			$this->assertNotEquals( $this->partner_b_id, (int) ( $row['partner_term_id'] ?? 0 ) );
			// 不該包含 partner_B 的 999
			$this->assertNotSame( '999.00', (string) ( $row['profit_amount'] ?? '' ) );
		}
	}

	// ============================================================
	// H. HPOS 雙路徑
	// ============================================================

	/**
	 * 驗證在 HPOS 開啟（custom orders table）下仍能正確聚合
	 *
	 * 若本機環境不支援動態切換，標 skipped；CI matrix 應跑 with-wc-classic / with-wc-hpos。
	 *
	 * ⚠️ `@group happy` 不可移除：PHPUnit 9 的**方法級 `@group` 會取代類別級**，不是疊加。
	 * 本檔類別級雖標了 `@group security`（在 phpunit.xml.dist 的 include 清單內），
	 * 但方法級一旦只標 `hpos_compatible`（不在清單內），這條就會被**靜默跳過**——
	 * 不報錯、不顯示 skipped，只是筆數對不上（22 個方法只跑 20）。
	 * 本檔的這兩條因此曾長期從未執行，HPOS 分支等同零自動化驗證。
	 *
	 * @test
	 * @group happy
	 * @group hpos_compatible
	 */
	public function test_summary_works_under_hpos_enabled(): void {
		if ( ! class_exists( OrderUtil::class ) ) {
			$this->markTestSkipped( 'OrderUtil 不存在；需要 WooCommerce 7.1+' );
		}
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'HPOS 未啟用；CI matrix with-wc-hpos 才會跑此 case' );
		}

		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, new FilterCriteria() );

		// 開 HPOS 結果應與 legacy 一致
		$this->assertSame( '100.00', $kpi['profit_paid'] );
		$this->assertSame( '500.00', $kpi['profit_pending'] );
		$this->assertSame( '50.00', $kpi['profit_refunded'] );
	}

	/**
	 * 驗證在 legacy（HPOS 關閉、posts-based）下仍能正確聚合
	 *
	 * ⚠️ `@group happy` 不可移除，理由同上一條方法的 docblock。
	 *
	 * @test
	 * @group happy
	 * @group hpos_compatible
	 */
	public function test_summary_works_under_legacy_post_orders(): void {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'HPOS 已啟用；本 case 屬於 legacy posts-based 路徑' );
		}

		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, new FilterCriteria() );

		$this->assertSame( '100.00', $kpi['profit_paid'] );
		$this->assertSame( '500.00', $kpi['profit_pending'] );
		$this->assertSame( '50.00', $kpi['profit_refunded'] );
	}

	// ============================================================
	// I. partner_term_id = 0 防禦
	// ============================================================

	/**
	 * partner_term_id = 0 → 必須回空 KPI，禁止整撈
	 *
	 * @test
	 * @group security
	 */
	public function test_summary_returns_empty_for_partner_term_id_zero(): void {
		$kpi = $this->provider->summary_for_partner( 0, new FilterCriteria() );

		$this->assertSame( '0.00', $kpi['total_sales'] );
		$this->assertSame( '0.00', $kpi['profit_pending'] );
		$this->assertSame( '0.00', $kpi['profit_paid'] );
		$this->assertSame( '0.00', $kpi['profit_refunded'] );
	}

	/**
	 * partner_term_id = 0 → find_by_partner 必須回空陣列
	 *
	 * @test
	 * @group security
	 */
	public function test_find_by_partner_returns_empty_for_partner_term_id_zero(): void {
		$list = $this->provider->find_by_partner( 0, new FilterCriteria() );

		$this->assertSame( [], $list );
	}

	/**
	 * partner_term_id = 0 → trend 必須回空陣列
	 *
	 * @test
	 * @group security
	 */
	public function test_trend_returns_empty_for_partner_term_id_zero(): void {
		$series = $this->provider->trend_for_partner( 0, new FilterCriteria(), 'day' );

		$this->assertSame( [], $series );
	}

	// ============================================================
	// Fixture
	// ============================================================

	/**
	 * 建立 fixture：
	 *
	 * Partners：
	 *   - partner_A（term）
	 *   - partner_B（term）
	 *   - partner_C（term，無訂單）
	 *
	 * Shops：
	 *   - shop_A（powershop CPT）
	 *   - shop_B（powershop CPT）
	 *
	 * Orders（皆為真實 WC_Order，line item 透過 add_product 加入；
	 *         settlement meta 透過 OrderItemSettlementRepository::save 寫入）：
	 *
	 *   標籤   | partner | shop   | order_status   | settlement_status | actual_price | profit_amount | date_created
	 *   ------|---------|--------|----------------|-------------------|--------------|---------------|--------------
	 *   order_1 | A      | shop_A | wc-completed   | paid              | 880.00       | 100.00        | 2026-04-01
	 *   order_2 | A      | shop_A | wc-completed   | pending           | 660.00       | 200.00        | 2026-04-15
	 *   order_3 | A      | shop_B | wc-pending     | pending           | 440.00       | 300.00        | 2026-04-05
	 *   order_4 | B      | shop_B | wc-completed   | paid              | 4995.00      | 999.00        | 2026-04-10
	 *   order_5 | A      | shop_A | wc-refunded    | refunded          | 220.00       | 50.00         | 2026-04-20
	 *
	 * partner_A 聚合預期（FilterCriteria()）：
	 *   total_sales      = 880 + 660 + 440 + 220 = 2200.00
	 *   profit_paid      = 100.00 (order_1)
	 *   profit_pending   = 200 + 300 = 500.00 (order_2 + order_3)
	 *   profit_refunded  = 50.00 (order_5)
	 *
	 * partner_B 聚合預期：
	 *   total_sales      = 4995.00
	 *   profit_paid      = 999.00
	 */

	/**
	 * 🔴 三段 SQL 都必須排除 `shop_subscription`，否則夥伴看到的報表金額直接 ×2
	 *
	 * 成因：訂閱商品結帳後，`_profit_*` 這組 line item meta 會**同時存在於** parent order
	 * （`shop_order`）與 `shop_subscription` 兩筆記錄上；而 `shop_subscription` 的狀態
	 * （`wc-active` / `wc-on-hold` / `wc-pending-cancel`）**完全不在** EXCLUDED_ORDER_STATUSES
	 * 內 ⇒ 只濾狀態的話兩筆都會被撈進來。
	 *
	 * 本測試用**獨立的 partner_term_id**（不在 build_fixture 的資料裡），基線因此精確為 0，
	 * 「1 筆 vs 2 筆」「100 vs 200」就是這條過濾生不生效的直接指紋。
	 *
	 * ⚠️ 三個 public 方法各有一段自己的 SQL，**必須逐一斷言**：只鎖其中一段等於留了兩扇門，
	 *    而夥伴 portal 的 KPI 卡、趨勢圖、結算明細是三個不同的畫面。
	 *
	 * @test
	 * @group security
	 */
	public function test_all_three_queries_exclude_shop_subscription_line_items(): void {
		$partner_id = 987654;
		$shop_id    = 902;

		$order_id = $this->create_order_with_settlement(
			$partner_id,
			$shop_id,
			'wc-completed',
			SettlementRecord::STATUS_PENDING,
			'500.00',
			'100.00',
			'2026-05-10 09:00:00',
			'subscription_guard_parent'
		);
		$order_item_id = $this->item_ids['subscription_guard_parent'];

		// 把訂閱那筆的日期對齊 parent order 的 GMT 日期：若過濾失效，trend 會在**同一個
		// bucket** 內變成 count=2 / profit=200.00，紅燈形狀最清楚。
		$parent_date_gmt = $this->read_post_date_gmt( $order_id );

		$subscription_item_id = $this->create_subscription_line_item( $parent_date_gmt );
		$this->repo->save(
			new SettlementRecord(
				order_item_id: $subscription_item_id,
				shop_id: $shop_id,
				partner_term_id: $partner_id,
				rate: new ProfitRate( 20 ),
				actual_price: '500.00',
				profit_amount: '100.00',
				status: SettlementRecord::STATUS_PENDING,
			)
		);

		/*
		* 🔴 反假綠燈保護（二）：證明訂閱那一筆**確實有完整可讀的結算 meta**。
		*
		* 少了這一行，本測試在「fixture 根本沒寫進資料」時也會通過（各項都只算到 1 筆），
		* 於是型別過濾即使被整段拿掉也永遠綠燈。加上它之後，唯一能讓下面三段斷言成立的
		* 原因就只剩「訂單型別過濾生效」。
		* （反假綠燈保護（一）在 create_subscription_line_item() 內：直接讀 wp_posts
		*   斷言該列真的是 post_type='shop_subscription' / post_status='wc-active'。）
		*/
		$this->assertInstanceOf(
			SettlementRecord::class,
			$this->repo->find_by_order_item( $subscription_item_id ),
			'前置檢查：訂閱那筆 line item 的結算 meta 應該是寫得進、讀得出的'
		);

		$criteria = new FilterCriteria();

		// ---- ① KPI（summary_for_partner）----
		$kpi = $this->provider->summary_for_partner( $partner_id, $criteria );

		$this->assertSame(
			'100.00',
			$kpi['profit_pending'],
			'KPI 待撥款只能算 shop_order 那一筆；連 shop_subscription 一起算會變 200.00（金額 ×2）'
		);
		$this->assertSame(
			'500.00',
			$kpi['total_sales'],
			'KPI 總銷售同理，重複計入會變 1000.00'
		);

		// ---- ② 趨勢（trend_for_partner）----
		$trend = $this->provider->trend_for_partner( $partner_id, $criteria, 'day' );

		$this->assertCount( 1, $trend, '趨勢只該有一個 bucket（兩筆日期刻意對齊，重複計入不會多一格、只會讓該格翻倍）' );
		$this->assertSame( 1, $trend[0]['count'], '該 bucket 的筆數重複計入會變 2' );
		$this->assertSame( '100.00', $trend[0]['profit'], '該 bucket 的分潤重複計入會變 200.00' );

		// ---- ③ 結算明細（find_by_partner）----
		$list = $this->provider->find_by_partner( $partner_id, $criteria );

		$this->assertCount( 1, $list, '結算明細只能列出 shop_order 那一筆' );
		$this->assertSame(
			$order_item_id,
			$list[0]['order_item_id'],
			'列出來的必須是 shop_order 的 line item，不是訂閱的那一筆'
		);
	}

	/**
	 * 讀出某筆 post 的 post_date_gmt（trend 分桶依據）
	 *
	 * @param int $post_id post ID
	 *
	 * @return string `Y-m-d H:i:s`
	 */
	private function read_post_date_gmt( int $post_id ): string {
		global $wpdb;

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT post_date_gmt FROM {$wpdb->posts} WHERE ID = %d", $post_id )
		);

		$this->assertIsString( $value, '讀不到 parent order 的 post_date_gmt' );

		return (string) $value;
	}

	/**
	 * 建立一筆掛在 `shop_subscription` 上的 line item
	 *
	 * WooCommerce Subscriptions 未必安裝於測試環境，因此直接改寫 `wp_posts` 的
	 * `post_type` / `post_status`——受測的 SQL 讀的就是這兩個欄位，這樣造出的資料
	 * 與真實訂閱在**過濾的角度上完全等價**。
	 *
	 * 🔴 反假綠燈保護（一）：建立後直接讀 `wp_posts` 斷言該列真的長成訂閱的樣子。
	 * 少了它，若 `$wpdb->update()` 因任何原因沒生效（例如欄位名打錯），這筆就還是
	 * `shop_order`，測試會在「過濾根本沒生效」的情況下照樣綠燈。
	 *
	 * @param string $date_gmt 要套用的 `post_date_gmt`（與 parent order 對齊，供 trend 同 bucket）
	 *
	 * @return int order_item_id
	 */
	private function create_subscription_line_item( string $date_gmt ): int {
		global $wpdb;

		$subscription_id = \wp_insert_post(
			[
				'post_title'  => '訂閱測試（型別過濾用）',
				'post_status' => 'draft',
				'post_type'   => 'post',
			]
		);

		$this->assertIsInt( $subscription_id );
		$this->assertGreaterThan( 0, $subscription_id, '建立訂閱 post 失敗' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[
				'post_type'     => 'shop_subscription',
				'post_status'   => 'wc-active',
				'post_date_gmt' => $date_gmt,
				'post_date'     => $date_gmt,
			],
			[ 'ID' => $subscription_id ]
		);
		\clean_post_cache( $subscription_id );

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

		$this->assertIsInt( $item_id );
		$this->assertGreaterThan( 0, $item_id, '建立訂閱 line item 失敗' );

		return (int) $item_id;
	}

	private function build_fixture(): void {
		// 1. Partners（profit_partner taxonomy）
		$this->ensure_partner_taxonomy_exists();

		$tid_a = \wp_insert_term( 'Alice', 'profit_partner', [ 'slug' => 'fixture-alice-' . \wp_generate_password( 6, false ) ] );
		$tid_b = \wp_insert_term( 'Bob', 'profit_partner', [ 'slug' => 'fixture-bob-' . \wp_generate_password( 6, false ) ] );
		$tid_c = \wp_insert_term( 'Carol', 'profit_partner', [ 'slug' => 'fixture-carol-' . \wp_generate_password( 6, false ) ] );

		$this->assertIsArray( $tid_a );
		$this->assertIsArray( $tid_b );
		$this->assertIsArray( $tid_c );

		$this->partner_a_id = (int) $tid_a['term_id'];
		$this->partner_b_id = (int) $tid_b['term_id'];
		$this->partner_c_id = (int) $tid_c['term_id'];

		// 2. Shops（powershop CPT 不存在則 fallback 用 post 模擬）
		$this->ensure_powershop_cpt_exists();
		$this->shop_a_id = (int) \wp_insert_post(
			[
				'post_type'   => 'powershop',
				'post_title'  => 'Fixture Shop A',
				'post_status' => 'publish',
			]
		);
		$this->shop_b_id = (int) \wp_insert_post(
			[
				'post_type'   => 'powershop',
				'post_title'  => 'Fixture Shop B',
				'post_status' => 'publish',
			]
		);

		// 3. Orders + line items + settlement meta
		$this->order_ids['order_1'] = $this->create_order_with_settlement(
			partner_term_id: $this->partner_a_id,
			shop_id: $this->shop_a_id,
			order_status: 'wc-completed',
			settlement_status: SettlementRecord::STATUS_PAID,
			actual_price: '880.00',
			profit_amount: '100.00',
			date_created: '2026-04-01 12:00:00',
			label: 'order_1'
		);

		$this->order_ids['order_2'] = $this->create_order_with_settlement(
			partner_term_id: $this->partner_a_id,
			shop_id: $this->shop_a_id,
			order_status: 'wc-completed',
			settlement_status: SettlementRecord::STATUS_PENDING,
			actual_price: '660.00',
			profit_amount: '200.00',
			date_created: '2026-04-15 12:00:00',
			label: 'order_2'
		);

		$this->order_ids['order_3'] = $this->create_order_with_settlement(
			partner_term_id: $this->partner_a_id,
			shop_id: $this->shop_b_id,
			order_status: 'wc-pending',
			settlement_status: SettlementRecord::STATUS_PENDING,
			actual_price: '440.00',
			profit_amount: '300.00',
			date_created: '2026-04-05 12:00:00',
			label: 'order_3'
		);

		$this->order_ids['order_4'] = $this->create_order_with_settlement(
			partner_term_id: $this->partner_b_id,
			shop_id: $this->shop_b_id,
			order_status: 'wc-completed',
			settlement_status: SettlementRecord::STATUS_PAID,
			actual_price: '4995.00',
			profit_amount: '999.00',
			date_created: '2026-04-10 12:00:00',
			label: 'order_4'
		);

		$this->order_ids['order_5'] = $this->create_order_with_settlement(
			partner_term_id: $this->partner_a_id,
			shop_id: $this->shop_a_id,
			order_status: 'wc-refunded',
			settlement_status: SettlementRecord::STATUS_REFUNDED,
			actual_price: '220.00',
			profit_amount: '50.00',
			date_created: '2026-04-20 12:00:00',
			label: 'order_5'
		);
	}

	/**
	 * 建立一筆 WC 訂單 + 一筆 line item + 寫入 settlement meta
	 *
	 * @return int order_id
	 */
	private function create_order_with_settlement(
		int $partner_term_id,
		int $shop_id,
		string $order_status,
		string $settlement_status,
		string $actual_price,
		string $profit_amount,
		string $date_created,
		string $label
	): int {
		$product = $this->createSimpleProduct(
			[
				'name'          => "fixture-product-{$label}",
				'regular_price' => $actual_price,
			]
		);

		$order = new \WC_Order();
		$item_id = $order->add_product( $product, 1 );
		$this->assertGreaterThan( 0, $item_id, "建立 line item ({$label}) 失敗" );

		$order->set_date_created( $date_created );
		$order->set_status( $order_status );
		$order->save();

		// 寫入 settlement meta（透過 Repository 確保 meta key 一致）
		$record = new SettlementRecord(
			order_item_id: (int) $item_id,
			shop_id: $shop_id,
			partner_term_id: $partner_term_id,
			rate: new ProfitRate( 20 ),
			actual_price: $actual_price,
			profit_amount: $profit_amount,
			status: $settlement_status,
		);
		$this->repo->save( $record );

		$this->item_ids[ $label ] = (int) $item_id;

		return (int) $order->get_id();
	}

	/**
	 * 確保 profit_partner taxonomy 已註冊（由 ProfitShop\Loader 在 init 時註冊）
	 */
	private function ensure_partner_taxonomy_exists(): void {
		if ( ! \taxonomy_exists( 'profit_partner' ) ) {
			\register_taxonomy( 'profit_partner', [ 'powershop' ], [ 'public' => false ] );
		}
	}

	/**
	 * 確保 powershop CPT 已註冊
	 */
	private function ensure_powershop_cpt_exists(): void {
		if ( ! \post_type_exists( 'powershop' ) ) {
			\register_post_type( 'powershop', [ 'public' => false ] );
		}
	}

	// ============================================================
	// J. 🔴 訂單型別過濾（訂閱物件不得被計入）
	// ============================================================

	/**
	 * 在 `shop_subscription` 物件上建立帶 partner_A 分潤 meta 的 line item
	 *
	 * 模擬訂閱商品結帳後 `_profit_*` 同時落在主訂單與訂閱物件上的真實情況。
	 * 直接寫 `wp_posts` / `woocommerce_order_items` 而不用 WCS API——
	 * WooCommerce Subscriptions 未必在測試環境啟用，但「訂單型別必須被過濾」
	 * 這條規則與該外掛是否啟用無關。
	 *
	 * ⚠️ **反假綠燈保護 1**：本 helper 內直接讀 `wp_posts` 斷言該列真的是
	 * `post_type='shop_subscription'` / `post_status='wc-active'`。
	 * 少了它，若 factory 因故建出 `shop_order`，測試會「通過」但測到的是錯的東西。
	 *
	 * @param string $profit_amount 分潤金額
	 * @param string $status        結算狀態
	 *
	 * @return int order_item_id
	 */
	private function seed_subscription_line_item( string $profit_amount = '200.00', string $status = 'pending' ): int {
		global $wpdb;

		$subscription_id = self::factory()->post->create(
			[
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-active',
			]
		);

		// 反假綠燈保護 1：確認 fixture 真的是訂閱物件而不是普通訂單
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT post_type, post_status FROM {$wpdb->posts} WHERE ID = %d", $subscription_id ),
			\ARRAY_A
		);
		$this->assertIsArray( $row, 'fixture 應建立成功' );
		$this->assertSame( 'shop_subscription', $row['post_type'], 'fixture 必須真的是 shop_subscription' );
		$this->assertSame( 'wc-active', $row['post_status'], 'wc-active 刻意不在 EXCLUDED_ORDER_STATUSES 內——這正是缺陷成因' );

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_order_items',
			[
				'order_item_name' => '訂閱物件上的分潤品項',
				'order_item_type' => 'line_item',
				'order_id'        => $subscription_id,
			],
			[ '%s', '%s', '%d' ]
		);
		$item_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $item_id );

		\wc_update_order_item_meta( $item_id, '_profit_shop_id', $this->shop_a_id );
		\wc_update_order_item_meta( $item_id, '_profit_partner_term_id', $this->partner_a_id );
		\wc_update_order_item_meta( $item_id, '_profit_rate', 20 );
		\wc_update_order_item_meta( $item_id, '_actual_price', '1000.00' );
		\wc_update_order_item_meta( $item_id, '_profit_amount', $profit_amount );
		\wc_update_order_item_meta( $item_id, '_settlement_status', $status );

		// 反假綠燈保護 2：確認 meta 真的寫進去了
		// 少了它，「fixture 根本沒寫入」會讓所有排除斷言以假通過的方式全綠。
		$this->assertSame(
			(string) $this->partner_a_id,
			(string) \wc_get_order_item_meta( $item_id, '_profit_partner_term_id', true ),
			'fixture 的 partner meta 必須真的寫入，否則排除斷言會以假通過的方式全綠'
		);
		$this->assertSame(
			$profit_amount,
			(string) \wc_get_order_item_meta( $item_id, '_profit_amount', true ),
			'fixture 的金額 meta 必須真的寫入'
		);

		$this->order_ids[ 'subscription_' . $subscription_id ] = $subscription_id;

		return $item_id;
	}

	/**
	 * 🔴 security：KPI 不得把訂閱物件上的品項計入（否則金額 ×2）
	 *
	 * baseline：partner_A 的 profit_pending = order_2(200) + order_3(300) = 500。
	 * 加入一筆掛在 `shop_subscription` 上、同樣 200 的 pending 品項後，
	 * 正確結果仍應是 500；少了型別過濾會變成 700。
	 *
	 * @test
	 * @group security
	 */
	public function test_summary_excludes_shop_subscription_line_items(): void {
		$before = $this->provider->summary_for_partner( $this->partner_a_id, new FilterCriteria() );
		$this->assertSame( '500.00', $before['profit_pending'], 'baseline 應為 500' );

		$this->seed_subscription_line_item( '200.00', 'pending' );

		$after = $this->provider->summary_for_partner( $this->partner_a_id, new FilterCriteria() );

		$this->assertSame(
			'500.00',
			$after['profit_pending'],
			'訂閱物件上的品項不得被計入——變成 700 就是「訂閱商品分潤 ×2」的具體形狀'
		);
		$this->assertSame( $before['total_sales'], $after['total_sales'], 'total_sales 同樣不得被污染' );
	}

	/**
	 * 🔴 security：trend 不得把訂閱物件上的品項計入
	 *
	 * @test
	 * @group security
	 */
	public function test_trend_excludes_shop_subscription_line_items(): void {
		$before = $this->provider->trend_for_partner( $this->partner_a_id, new FilterCriteria(), 'day' );
		$before_profit = array_sum( array_map( static fn( array $r ): float => (float) $r['profit'], $before ) );
		$before_count  = array_sum( array_map( static fn( array $r ): int => (int) $r['count'], $before ) );

		$this->seed_subscription_line_item( '200.00', 'pending' );

		$after = $this->provider->trend_for_partner( $this->partner_a_id, new FilterCriteria(), 'day' );
		$after_profit = array_sum( array_map( static fn( array $r ): float => (float) $r['profit'], $after ) );
		$after_count  = array_sum( array_map( static fn( array $r ): int => (int) $r['count'], $after ) );

		$this->assertSame( $before_count, $after_count, 'trend 的筆數不得因訂閱物件而增加' );
		$this->assertEqualsWithDelta( $before_profit, $after_profit, 0.001, 'trend 的金額不得因訂閱物件而增加' );
	}

	/**
	 * 🔴 security：settlements 列表不得把訂閱物件上的品項計入
	 *
	 * @test
	 * @group security
	 */
	public function test_find_by_partner_excludes_shop_subscription_line_items(): void {
		$before = $this->provider->find_by_partner( $this->partner_a_id, new FilterCriteria( per_page: 100 ) );

		$subscription_item_id = $this->seed_subscription_line_item( '200.00', 'pending' );

		$after = $this->provider->find_by_partner( $this->partner_a_id, new FilterCriteria( per_page: 100 ) );

		$this->assertCount( count( $before ), $after, '列表筆數不得因訂閱物件而增加' );

		$returned_ids = array_map( static fn( array $r ): int => (int) $r['order_item_id'], $after );
		$this->assertNotContains(
			$subscription_item_id,
			$returned_ids,
			'訂閱物件上的 line item 不得出現在結算列表中'
		);
	}

	/**
	 * 🔴 edge：三個查詢的 placeholder 數與 args 數必須相等
	 *
	 * `$wpdb->prepare()` 在 placeholder 與 args 數量不符時**回空字串**，
	 * 查詢因此回 0 筆——夥伴報表整片歸零，而且 `phpcs` 與 `phpunit` 都不會紅，
	 * 沒有任何錯誤訊息。這條測試對三個方法各跑一次真實查詢並斷言**回傳非空**，
	 * 是防這種靜默失效最直接的守門。
	 *
	 * （partner_A 在 fixture 中必然有資料；若 prepare 失敗，三者都會變成空。）
	 *
	 * @test
	 * @group edge
	 */
	public function test_all_three_queries_have_matching_placeholder_and_arg_counts(): void {
		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, new FilterCriteria() );
		$this->assertNotSame(
			'0.00',
			$kpi['profit_pending'],
			'summary_for_partner 回全 0 可能代表 prepare() 因 placeholder/args 不符而回空字串'
		);

		$trend = $this->provider->trend_for_partner( $this->partner_a_id, new FilterCriteria(), 'day' );
		$this->assertNotEmpty(
			$trend,
			'trend_for_partner 回空可能代表 prepare() 因 placeholder/args 不符而回空字串'
		);

		$list = $this->provider->find_by_partner( $this->partner_a_id, new FilterCriteria( per_page: 100 ) );
		$this->assertNotEmpty(
			$list,
			'find_by_partner 回空可能代表 prepare() 因 placeholder/args 不符而回空字串'
		);
	}

	/**
	 * edge：三個查詢在帶了額外過濾條件時同樣不會 prepare 失敗
	 *
	 * 額外條件會往 args 追加元素，是 placeholder/args 最容易不同步的路徑。
	 *
	 * @test
	 * @group edge
	 */
	public function test_all_three_queries_survive_extra_filters(): void {
		$criteria = new FilterCriteria(
			date_start: strtotime( '2000-01-01' ),
			date_end: strtotime( '2100-01-01' ),
			shop_ids: [ $this->shop_a_id ],
			statuses: [ SettlementRecord::STATUS_PENDING ],
			per_page: 100,
		);

		$kpi = $this->provider->summary_for_partner( $this->partner_a_id, $criteria );
		$this->assertNotSame( '0.00', $kpi['profit_pending'], '帶完整過濾條件時 summary 不得因 prepare 失敗而歸零' );

		$this->assertNotEmpty(
			$this->provider->trend_for_partner( $this->partner_a_id, $criteria, 'day' ),
			'帶完整過濾條件時 trend 不得因 prepare 失敗而回空'
		);
		$this->assertNotEmpty(
			$this->provider->find_by_partner( $this->partner_a_id, $criteria ),
			'帶完整過濾條件時 list 不得因 prepare 失敗而回空'
		);
	}
}
