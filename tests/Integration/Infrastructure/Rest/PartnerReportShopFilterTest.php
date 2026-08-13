<?php
/**
 * 夥伴報表 `?shop_ids=` 賣場篩選的端到端 IT（D1 fail-closed 回歸鎖）
 *
 * 對應實作：`V2Api::build_filter_criteria_from_request()`
 *          `WpSettlementSummaryProvider::build_extra_where()`
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8
 *
 * ═══ 🔴 這個檔案存在的理由：`shop_ids` 的三態語意沒有任何端到端覆蓋 ═══
 *
 * `build_filter_criteria_from_request()` 是 **private static**，三態語意只能從
 * 三個 partner-reports endpoint 端到端觀察：
 *
 * | query string | `$shop_ids` | 夥伴看到什麼 |
 * |---|---|---|
 * | 完全沒帶 `shop_ids` | `[]` | 全部賣場合計（**正確**，這是預設檢視） |
 * | 帶了且至少一個合法 | `[1932, ...]` | 只算這些賣場 |
 * | 帶了但清洗後一個都不剩 | `[哨符]` | 誠實回 0 |
 *
 * 🔴 **第一態與第三態都是「空輸入」，語意卻完全相反**：前者是「使用者沒有要求篩選」，
 * 後者是「使用者要求了篩選、但要求的內容無效」。兩者若共用空陣列，第三態會**靜默退化**
 * 成第一態——夥伴以為自己在看單一賣場，畫面上其實是全部賣場的合計，**數字偏大、
 * 而且沒有任何錯誤訊息**。
 *
 * 這不是假設性的攻擊情境：`?shop_ids=1932` 這種連結被通訊軟體 / 郵件用戶端在
 * `=` 之後截斷，就會變成 `?shop_ids=`，正好落在第三態。
 *
 * ═══ 為何斷言全部釘死精確金額 ═══
 *
 * fixture 刻意讓「單一賣場」與「全部賣場」是**不同的非零數字**（111.00 vs 333.00）。
 * 只斷言「不是 0」或「有 key」的話，第三態退化成第一態時仍會通過——那正是
 * `WpSettlementSummaryProviderRealAggregationTest` 曾經踩過的假綠燈形狀。
 *
 * @group profit_shop
 * @group rest
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use Tests\Integration\TestCase;

/**
 * `?shop_ids=` 三態語意的端到端 IT
 */
final class PartnerReportShopFilterTest extends TestCase {

	private const PASSWORD_A = 'shopfilter-alice-2026';
	private const PASSWORD_B = 'shopfilter-bob-2026';

	/**
	 * 夥伴 A 的兩個賣場（shop_id 只是 order itemmeta 的凍結值，不需要真的有對應 post）
	 */
	private const SHOP_ONE = 771001;
	private const SHOP_TWO = 771002;

	/**
	 * 夥伴 B 的賣場——A 拿這個 id 去篩必須得到 0，而不是「不過濾」
	 */
	private const SHOP_OF_OTHER_PARTNER = 771003;

	/**
	 * A 在兩個賣場的分潤金額（刻意互不相同、相加後也不與任一者相同）
	 */
	private const PROFIT_SHOP_ONE = '111.00';
	private const PROFIT_SHOP_TWO = '222.00';
	private const PROFIT_BOTH     = '333.00';

	/**
	 * A 的實收金額（total_sales 的來源）
	 */
	private const SALES_SHOP_ONE = '1000.00';
	private const SALES_SHOP_TWO = '2000.00';
	private const SALES_BOTH     = '3000.00';

	/**
	 * 🔴 只屬於夥伴 B 的金絲雀金額：出現在 A 的任何回應裡 = 跨夥伴外洩
	 */
	private const CANARY_AMOUNT = '86420.99';

	/**
	 * 不對應任何結算紀錄的合法正整數 shop id（用於 cap / dedupe 測試）
	 *
	 * 必須是**正整數**，才不會被 `build_filter_criteria_from_request()` 的
	 * `$id > 0` 過濾掉——那樣就測不到 cap 與 dedupe 的順序了。
	 */
	private const JUNK_SHOP_ID = 779001;

	private int $partner_a_id = 0;
	private int $partner_b_id = 0;
	private string $token_a   = '';

	/**
	 * 建立出來的訂單 id（tear_down 清理用）
	 *
	 * @var int[]
	 */
	private array $order_ids = [];

	private OrderItemSettlementRepository $repo;

	/**
	 * setUp
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repo = OrderItemSettlementRepository::instance();

		$this->partner_a_id = $this->create_partner( 'shopfilter-alice', self::PASSWORD_A );
		$this->partner_b_id = $this->create_partner( 'shopfilter-bob', self::PASSWORD_B );

		$this->seed_settlement( $this->partner_a_id, self::SHOP_ONE, self::SALES_SHOP_ONE, self::PROFIT_SHOP_ONE );
		$this->seed_settlement( $this->partner_a_id, self::SHOP_TWO, self::SALES_SHOP_TWO, self::PROFIT_SHOP_TWO );
		$this->seed_settlement( $this->partner_b_id, self::SHOP_OF_OTHER_PARTNER, '5000.00', self::CANARY_AMOUNT );

		$this->token_a = $this->login( 'shopfilter-alice', self::PASSWORD_A );
		$this->assertNotSame( '', $this->token_a, '前置：Alice 必須登入成功，否則以下每條都會是 401 而非真的驗到東西' );
	}

	/**
	 * tearDown
	 */
	public function tear_down(): void {
		foreach ( $this->order_ids as $oid ) {
			$order = \wc_get_order( $oid );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		$this->order_ids = [];

		foreach ( [ $this->partner_a_id, $this->partner_b_id ] as $tid ) {
			if ( $tid > 0 ) {
				\wp_delete_term( $tid, 'profit_partner' );
			}
		}

		parent::tear_down();
	}

	// ====================================================================
	//  A. 三態語意
	// ====================================================================

	/**
	 * 第一態：完全沒帶 `shop_ids` → 全部賣場合計（預設檢視，**不是**缺陷）
	 *
	 * 這條同時是第三態那條的基準線：兩者都是「空輸入」，
	 * 必須得到**不同**的答案。
	 *
	 * @test
	 * @group happy
	 */
	public function test_omitting_shop_ids_returns_every_shop_combined(): void {
		$kpi = $this->get_report( 'kpi' );

		$this->assertSame( self::PROFIT_BOTH, $kpi['profit_pending'], '沒指定賣場 = 全部賣場合計' );
		$this->assertSame( self::SALES_BOTH, $kpi['total_sales'] );

		$this->assert_no_canary( $kpi, 'KPI（未指定賣場）' );
	}

	/**
	 * 🔴 第三態（D1 核心）：帶了 `shop_ids` 但清洗後一個都不剩 → 必須誠實回 0
	 *
	 * 情境不需要攻擊者：`?shop_ids=1932` 這種連結被通訊軟體在 `=` 後截斷即成立。
	 *
	 * 本條把第一態的答案一起讀出來比對，是刻意的——只斷言 `0.00` 的話，
	 * 「整個 endpoint 壞掉、什麼都回 0」也會通過；同時要求
	 * 「第一態非零」與「第三態為零」才能證明**兩態確實被分開處理**。
	 *
	 * @test
	 * @group security
	 */
	public function test_empty_shop_ids_fails_closed_instead_of_returning_everything(): void {
		$unfiltered = $this->get_report( 'kpi' );
		$this->assertSame(
			self::PROFIT_BOTH,
			$unfiltered['profit_pending'],
			'前置：未過濾時必須是非零的全量，否則下面的 0.00 可能來自別的原因'
		);

		$truncated = $this->get_report( 'kpi', [ 'shop_ids' => '' ] );

		$this->assertSame(
			'0.00',
			$truncated['profit_pending'],
			'指定了賣場卻一個都解析不出來時必須回 0；回 ' . self::PROFIT_BOTH
			. ' 代表退化成「不過濾」，夥伴會以為自己在看單一賣場'
		);
		$this->assertSame( '0.00', $truncated['total_sales'] );
		$this->assertSame( '0.00', $truncated['profit_paid'] );
		$this->assertSame( '0.00', $truncated['profit_refunded'] );

		$this->assertNotSame(
			$unfiltered,
			$truncated,
			'「沒帶 shop_ids」與「帶了但全是垃圾」是相反語意，兩者的回應不得相同'
		);
	}

	/**
	 * 第二態：帶了合法賣場 → 只算那個賣場
	 *
	 * 兩個方向都測，否則「條件寫死成永遠命中第一個賣場」也會通過。
	 *
	 * @test
	 * @group happy
	 */
	public function test_shop_ids_scopes_the_report_to_that_shop(): void {
		$one = $this->get_report( 'kpi', [ 'shop_ids' => (string) self::SHOP_ONE ] );
		$this->assertSame( self::PROFIT_SHOP_ONE, $one['profit_pending'] );
		$this->assertSame( self::SALES_SHOP_ONE, $one['total_sales'] );

		$two = $this->get_report( 'kpi', [ 'shop_ids' => (string) self::SHOP_TWO ] );
		$this->assertSame( self::PROFIT_SHOP_TWO, $two['profit_pending'] );
		$this->assertSame( self::SALES_SHOP_TWO, $two['total_sales'] );

		$both = $this->get_report(
			'kpi',
			[ 'shop_ids' => self::SHOP_ONE . ',' . self::SHOP_TWO ]
		);
		$this->assertSame( self::PROFIT_BOTH, $both['profit_pending'], '兩個都指定 = 兩者相加' );
		$this->assertSame( self::SALES_BOTH, $both['total_sales'] );
	}

	/**
	 * 🔴 各種「解析不出任何合法 id」的輸入，全部必須 fail-closed
	 *
	 * 逐一列舉而非只測空字串：`0` 與 `-1` 是最容易被漏掉的一組——
	 * 它們「看起來是數字」，卻會被 `$id > 0` 過濾掉，最終與空字串同一個下場。
	 *
	 * @test
	 * @group security
	 */
	public function test_all_unparsable_shop_ids_fail_closed(): void {
		$cases = [
			'連結被截斷（空字串）' => '',
			'只有逗號'             => ',',
			'非數字'               => 'abc',
			'零'                   => '0',
			'負數'                 => '-1',
			'多個垃圾值'           => 'abc,0,-1,',
		];

		foreach ( $cases as $label => $raw ) {
			$kpi = $this->get_report( 'kpi', [ 'shop_ids' => $raw ] );

			$this->assertSame(
				'0.00',
				$kpi['profit_pending'],
				"{$label}（shop_ids='{$raw}'）必須 fail-closed 回 0；回 " . self::PROFIT_BOTH . ' 代表退化成不過濾'
			);
			$this->assertSame( '0.00', $kpi['total_sales'], "{$label}：total_sales 同樣必須為 0" );
		}
	}

	/**
	 * 三個 endpoint 都要 fail-closed，不是只有 KPI
	 *
	 * KPI / 趨勢 / 明細是夥伴報表上三個不同的畫面，各自吃一段獨立的 SQL。
	 * 只鎖 KPI 等於留了兩扇門。
	 *
	 * @test
	 * @group security
	 */
	public function test_empty_shop_ids_fails_closed_on_all_three_endpoints(): void {
		// 前置：不帶 shop_ids 時三者都必須有東西，否則下面的「空」證明不了任何事。
		$this->assertNotSame( '0.00', $this->get_report( 'kpi' )['profit_pending'] );
		$this->assertNotSame( [], (array) $this->get_report( 'trend' )['series'] );
		$this->assertNotSame( [], (array) $this->get_report( 'settlements' )['items'] );

		$kpi = $this->get_report( 'kpi', [ 'shop_ids' => '' ] );
		$this->assertSame( '0.00', $kpi['profit_pending'] );

		$trend = $this->get_report( 'trend', [ 'shop_ids' => '' ] );
		$this->assertSame( [], (array) $trend['series'], '趨勢必須是空的，不得畫出全部賣場的線' );

		$settlements = $this->get_report( 'settlements', [ 'shop_ids' => '' ] );
		$this->assertSame( [], (array) $settlements['items'], '明細必須是空的，不得列出全部賣場的紀錄' );
	}

	// ====================================================================
	//  B. 跨夥伴
	// ====================================================================

	/**
	 * 🔴 拿別的夥伴的 shop_id 來篩，必須得到 0（而不是「不過濾」或別人的數字）
	 *
	 * 這條同時是 IDOR 的第二道防線：即使賣場清單被竄改、塞進別人的 shop id，
	 * 主查詢仍鎖在自己的 partner_term_id 上 ⇒ 交集為空。
	 *
	 * @test
	 * @group security
	 */
	public function test_other_partners_shop_id_yields_zero_not_everything(): void {
		$kpi = $this->get_report( 'kpi', [ 'shop_ids' => (string) self::SHOP_OF_OTHER_PARTNER ] );

		$this->assertSame(
			'0.00',
			$kpi['profit_pending'],
			'別人的賣場對我而言沒有任何紀錄；出現 ' . self::CANARY_AMOUNT . ' 就是跨夥伴外洩，'
			. '出現 ' . self::PROFIT_BOTH . ' 則是退化成不過濾'
		);
		$this->assertSame( '0.00', $kpi['profit_paid'] );
		$this->assertSame( '0.00', $kpi['total_sales'] );

		$this->assert_no_canary( $kpi, 'KPI（用別人的 shop_id 篩）' );

		$settlements = $this->get_report( 'settlements', [ 'shop_ids' => (string) self::SHOP_OF_OTHER_PARTNER ] );
		$this->assertSame( [], (array) $settlements['items'] );
		$this->assert_no_canary( $settlements, '明細（用別人的 shop_id 篩）' );
	}

	// ====================================================================
	//  C. 去重與上限
	// ====================================================================

	/**
	 * 重複的 shop_id 與只送一次，結果必須完全相同
	 *
	 * @test
	 * @group edge
	 */
	public function test_duplicate_shop_ids_behave_like_a_single_one(): void {
		$single = $this->get_report( 'kpi', [ 'shop_ids' => (string) self::SHOP_ONE ] );
		$duped  = $this->get_report(
			'kpi',
			[ 'shop_ids' => self::SHOP_ONE . ',' . self::SHOP_ONE . ',' . self::SHOP_ONE ]
		);

		$this->assertSame( $single, $duped, '重複值不得改變任何 KPI 欄位' );
		$this->assertSame( self::PROFIT_SHOP_ONE, $duped['profit_pending'], '釘住數值，避免兩邊同時壞掉的假綠燈' );
	}

	/**
	 * 只要有**任何一個**合法 id，就不算第三態——不得因為混了垃圾值而整個 fail-closed
	 *
	 * 反過來說也重要：不得因為混了垃圾值就把整串當成合法而放行全部。
	 *
	 * @test
	 * @group edge
	 */
	public function test_partially_valid_shop_ids_keep_only_the_valid_ones(): void {
		$kpi = $this->get_report( 'kpi', [ 'shop_ids' => self::SHOP_ONE . ',abc,0,-1,' ] );

		$this->assertSame(
			self::PROFIT_SHOP_ONE,
			$kpi['profit_pending'],
			'合法的那個 id 仍要生效（0.00 代表被誤判成第三態）；'
			. self::PROFIT_BOTH . ' 代表整串被當成無效而退化成不過濾'
		);
		$this->assertSame( self::SALES_SHOP_ONE, $kpi['total_sales'] );
	}

	/**
	 * 🔴 去重必須發生在套用數量上限**之前**
	 *
	 * 這一對測試（本條 + 下一條）是「順序」的直接證明，單獨任一條都證不出來：
	 *
	 *   本條：100 個**重複**的垃圾 id + 1 個合法 id
	 *         → 先去重 ⇒ 只剩 2 個 ⇒ 上限 100 綽綽有餘 ⇒ 合法 id 存活 ⇒ 回 111.00
	 *         → 先截斷 ⇒ 前 100 個全是垃圾 ⇒ 合法 id 被擠掉 ⇒ 回 0.00 ❌
	 *
	 *   下一條：100 個**相異**的垃圾 id + 1 個合法 id
	 *         → 不論順序，去重後仍有 101 個 ⇒ 合法 id 落在上限外 ⇒ 回 0.00
	 *
	 * ⇒ 兩條同時成立，才能斷定「去重在前、截斷在後」。
	 *
	 * 這件事的實際後果：`?shop_ids=5,5,5,...`（前端重複送同一個賣場，
	 * 例如多選元件沒去重）會把額度吃光，把使用者真正想看的賣場**靜默擠掉**，
	 * 畫面上顯示著那個賣場、數字卻是 0。
	 *
	 * @test
	 * @group edge
	 */
	public function test_deduplication_happens_before_the_cap(): void {
		$repeated = implode( ',', array_fill( 0, 100, (string) self::JUNK_SHOP_ID ) );

		$kpi = $this->get_report( 'kpi', [ 'shop_ids' => $repeated . ',' . self::SHOP_ONE ] );

		$this->assertSame(
			self::PROFIT_SHOP_ONE,
			$kpi['profit_pending'],
			'去重若排在截斷之後，100 個重複值會把額度吃光、把第 101 個合法賣場擠出上限 ⇒ 這裡會是 0.00'
		);
	}

	/**
	 * 超過數量上限的 id 被丟棄——方向是**收斂**（回傳更少），不是放行
	 *
	 * 上限本身是刻意的（每個 id 都會展開成一個 `%d` placeholder 併進
	 * EXISTS 的 `IN (...)`）。這條鎖的是「被截斷時仍然只回更少的資料」，
	 * 防止有人為了修掉截斷而改成「超過上限就不過濾」——那會是最糟的方向。
	 *
	 * @test
	 * @group edge
	 */
	public function test_ids_beyond_the_cap_are_dropped_towards_fewer_results(): void {
		$distinct = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$distinct[] = (string) ( self::JUNK_SHOP_ID + $i );
		}

		$kpi = $this->get_report(
			'kpi',
			[ 'shop_ids' => implode( ',', $distinct ) . ',' . self::SHOP_ONE ]
		);

		$this->assertSame(
			'0.00',
			$kpi['profit_pending'],
			'第 101 個 id 應被上限丟棄，只剩 100 個查無資料的賣場；'
			. self::PROFIT_BOTH . ' 代表超過上限時整段過濾被放掉'
		);
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 掃描回應，確認不含屬於夥伴 B 的專屬金額
	 *
	 * @param array<string, mixed> $body  response body
	 * @param string               $label 出錯時的情境描述
	 */
	private function assert_no_canary( array $body, string $label ): void {
		$this->assertStringNotContainsString(
			self::CANARY_AMOUNT,
			(string) \wp_json_encode( $body ),
			"{$label} 的回應含有夥伴 B 的專屬金額 " . self::CANARY_AMOUNT . '（跨夥伴資料外洩）'
		);
	}

	/**
	 * 建立 partner（含密碼）
	 *
	 * @param string $slug     partner slug
	 * @param string $password 明文密碼
	 *
	 * @return int term id
	 */
	private function create_partner( string $slug, string $password ): int {
		return PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: "shopfilter-{$slug}",
				slug: new PartnerSlug( $slug ),
				contact_email: null
			),
			$password
		);
	}

	/**
	 * 走真實 endpoint 登入，回傳 token（失敗回空字串）
	 *
	 * @param string $slug     partner slug
	 * @param string $password 明文密碼
	 *
	 * @return string
	 */
	private function login( string $slug, string $password ): string {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( [ 'slug' => $slug, 'password' => $password ] ) );

		$body = (array) \rest_do_request( $request )->get_data();

		return (string) ( $body['token'] ?? '' );
	}

	/**
	 * 讀報表並回傳 body（斷言 200）
	 *
	 * @param string                $endpoint kpi / trend / settlements
	 * @param array<string, string> $query    額外 query string
	 *
	 * @return array<string, mixed>
	 */
	private function get_report( string $endpoint, array $query = [] ): array {
		$request = new \WP_REST_Request( 'GET', "/power-shop/partner-reports/{$endpoint}" );
		$request->set_header( 'X-Partner-Token', $this->token_a );
		if ( [] !== $query ) {
			$request->set_query_params( $query );
		}

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), "{$endpoint} 應回 200" );

		return (array) $response->get_data();
	}

	/**
	 * 建一張訂單 + 一筆帶結算 meta 的 line item（狀態一律 pending）
	 *
	 * @param int    $partner_term_id 夥伴 term id
	 * @param int    $shop_id         賣場 id（凍結於 order itemmeta）
	 * @param string $actual_price    實收單價
	 * @param string $profit_amount   應分潤金額
	 */
	private function seed_settlement(
		int $partner_term_id,
		int $shop_id,
		string $actual_price,
		string $profit_amount
	): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => 'shop-filter-it-' . uniqid(),
				'regular_price' => $actual_price,
			]
		);

		$order   = new \WC_Order();
		$item_id = $order->add_product( $product, 1 );
		$this->assertGreaterThan( 0, $item_id, '建立 line item 失敗' );

		$order->set_date_created( '2026-06-15 10:00:00' );
		$order->set_status( 'wc-completed' );
		$order->save();

		$this->repo->save(
			new SettlementRecord(
				order_item_id: (int) $item_id,
				shop_id: $shop_id,
				partner_term_id: $partner_term_id,
				rate: new ProfitRate( 15 ),
				actual_price: $actual_price,
				profit_amount: $profit_amount,
				status: SettlementRecord::STATUS_PENDING,
			)
		);

		$this->order_ids[] = (int) $order->get_id();
	}
}
