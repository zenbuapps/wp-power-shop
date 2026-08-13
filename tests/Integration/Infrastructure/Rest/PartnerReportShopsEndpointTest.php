<?php
/**
 * `GET /partner-reports/shops`（報表賣場篩選器的選項來源）端到端 IT
 *
 * 對應實作：`V2Api::get_partner_reports_shops_callback()`
 *          `ListPartnerShopsUseCase`
 *          `WpSettlementSummaryProvider::shops_for_partner()`
 * 對應契約：`SettlementSummaryProviderInterface::shops_for_partner()` 的 docblock
 *
 * ═══ 這個 endpoint 的核心規則：清單是**兩個來源的聯集** ═══
 *
 *   A. 目前指派給該夥伴、且 `status = publish` 的 powershop CPT
 *      → 涵蓋「已指派但還沒出單」的新賣場
 *   B. 結算紀錄（order itemmeta）裡出現過的 shop_id
 *      → 涵蓋「賣場已刪除 / 已改派給別人」但歷史業績仍屬於他的情況
 *
 * 兩者互不包含。只取其一都會讓夥伴少看到自己的賣場，而他**無從分辨**
 * 「那個賣場這期間沒業績」與「系統漏了它」——後者才是使用者實際回報的困惑。
 *
 * 因此本檔的每一條都刻意做成「單一來源的實作會漏掉」的形狀：
 * 只有 A 的賣場（零業績）與只有 B 的賣場（已刪除）**必須同時出現**。
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
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpAdminSettlementQuery;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use Tests\Integration\TestCase;

/**
 * 夥伴報表賣場清單 endpoint 的端到端 IT
 */
final class PartnerReportShopsEndpointTest extends TestCase {

	private const PASSWORD_A = 'shops-alice-2026';
	private const PASSWORD_B = 'shops-bob-2026';

	/**
	 * powershop meta key（與 CptProfitShopRepository 的 private const 對齊）
	 *
	 * ⚠️ 對面是 private const，這裡只能複製字面值。它一旦改名，本檔的 fixture
	 * 會建出一批「沒有夥伴歸屬」的賣場 ⇒ 來源 A 那幾條會轉紅（而不是靜默通過），
	 * 因為它們斷言的是「這個賣場**必須**出現在清單裡」。
	 */
	private const META_PARTNER = '_profit_partner_term_id';

	/**
	 * 只存在於結算紀錄、沒有對應 post 的賣場 id（來源 B 專屬）
	 */
	private const DELETED_SHOP_ID = 762001;

	private int $partner_a_id = 0;
	private int $partner_b_id = 0;
	private string $token_a   = '';

	/**
	 * fixture 建立的賣場 post id
	 *
	 * @var array<string, int>
	 */
	private array $shop_ids = [];

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
		$this->ensure_powershop_cpt_exists();

		$this->partner_a_id = $this->create_partner( 'shops-alice', self::PASSWORD_A );
		$this->partner_b_id = $this->create_partner( 'shops-bob', self::PASSWORD_B );

		// 來源 A 專屬：已指派、已發佈、但零業績
		$this->shop_ids['assigned_no_sales'] = $this->create_shop( '甲賣場（零業績）', 'publish', $this->partner_a_id );

		// 來源 A 的排除項：草稿賣場對夥伴而言還不存在
		$this->shop_ids['assigned_draft'] = $this->create_shop( '乙賣場（草稿）', 'draft', $this->partner_a_id );

		// A ∩ B：既已指派、也有業績 —— 必須只出現一次
		$this->shop_ids['assigned_with_sales'] = $this->create_shop( '丙賣場（有業績）', 'publish', $this->partner_a_id );

		// 別人的賣場：已指派給 B、且 B 有業績
		$this->shop_ids['other_partner'] = $this->create_shop( '丁賣場（別人的）', 'publish', $this->partner_b_id );

		$this->seed_settlement( $this->partner_a_id, $this->shop_ids['assigned_with_sales'], 'wc-completed' );

		// 來源 B 專屬：賣場 post 不存在（已刪除），但歷史業績還在
		$this->seed_settlement( $this->partner_a_id, self::DELETED_SHOP_ID, 'wc-completed' );

		$this->seed_settlement( $this->partner_b_id, $this->shop_ids['other_partner'], 'wc-completed' );

		$this->token_a = $this->login( 'shops-alice', self::PASSWORD_A );
		$this->assertNotSame( '', $this->token_a, '前置：Alice 必須登入成功' );
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

		foreach ( $this->shop_ids as $pid ) {
			if ( $pid > 0 ) {
				\wp_delete_post( $pid, true );
			}
		}
		$this->shop_ids = [];

		foreach ( [ $this->partner_a_id, $this->partner_b_id ] as $tid ) {
			if ( $tid > 0 ) {
				\wp_delete_term( $tid, 'profit_partner' );
			}
		}

		parent::tear_down();
	}

	// ====================================================================
	//  A. 兩個來源的聯集
	// ====================================================================

	/**
	 * 🔴 只有來源 A 才看得到的賣場（零業績）必須出現
	 *
	 * 只用結算紀錄（來源 B）實作的話，這個賣場會消失——夥伴名下兩個賣場只顯示一個，
	 * 而且他無從分辨「這期間沒業績」與「系統漏了它」。
	 *
	 * @test
	 * @group happy
	 */
	public function test_assigned_shop_without_any_sales_is_listed(): void {
		$shops = $this->get_shops();

		$this->assertArrayHasKey(
			$this->shop_ids['assigned_no_sales'],
			$shops,
			'已指派但零業績的賣場必須列得出來（只查結算紀錄的實作會漏掉它）'
		);
		$this->assertSame( '甲賣場（零業績）', $shops[ $this->shop_ids['assigned_no_sales'] ] );
	}

	/**
	 * 🔴 只有來源 B 才看得到的賣場（已刪除、業績還在）必須出現，且標示為已刪除
	 *
	 * 只查 powershop CPT（來源 A）實作的話，這個賣場會消失——但 KPI 的總計仍然包含它，
	 * 於是「逐賣場相加 ≠ 總計」，夥伴會直接發現數字對不起來。
	 *
	 * @test
	 * @group happy
	 */
	public function test_deleted_shop_with_history_is_listed_as_deleted(): void {
		$shops = $this->get_shops();

		$this->assertArrayHasKey(
			self::DELETED_SHOP_ID,
			$shops,
			'已刪除但仍有業績的賣場必須列得出來（只查 CPT 的實作會漏掉它，總計卻仍含它）'
		);
		$this->assertSame(
			WpAdminSettlementQuery::DELETED_SHOP_LABEL,
			$shops[ self::DELETED_SHOP_ID ],
			'查不到的賣場必須給明確的佔位文字，不得是空字串（前端會顯示成一個沒有名字的選項）'
		);
	}

	/**
	 * 同時屬於兩個來源的賣場只能出現一次
	 *
	 * 聯集若寫成單純的 array_merge 而漏了 array_unique，下拉選單會出現重複選項。
	 *
	 * @test
	 * @group edge
	 */
	public function test_shop_in_both_sources_appears_exactly_once(): void {
		$response = $this->request_shops();
		$rows     = (array) $response['shops'];

		$occurrences = array_filter(
			$rows,
			fn( array $row ): bool => (int) $row['shop_id'] === $this->shop_ids['assigned_with_sales']
		);

		$this->assertCount( 1, $occurrences, '既已指派、又有業績的賣場只能出現一次' );

		$row = array_values( $occurrences )[0];
		$this->assertSame( '丙賣場（有業績）', $row['shop_name'], '有對應 post 時必須回真實標題，不是佔位文字' );
	}

	// ====================================================================
	//  B. 排除項
	// ====================================================================

	/**
	 * 草稿賣場不得出現（對夥伴而言還不存在，列出來會讓人以為已上線）
	 *
	 * @test
	 * @group edge
	 */
	public function test_draft_shop_is_not_listed(): void {
		$this->assertArrayNotHasKey(
			$this->shop_ids['assigned_draft'],
			$this->get_shops(),
			'草稿賣場沒有可用的前台網址，不該出現在夥伴的篩選器裡'
		);
	}

	/**
	 * 🔴 別人的賣場不得出現——否則「別人有哪些賣場」變成可列舉
	 *
	 * @test
	 * @group security
	 */
	public function test_other_partners_shop_is_not_listed(): void {
		$shops = $this->get_shops();

		$this->assertArrayNotHasKey(
			$this->shop_ids['other_partner'],
			$shops,
			'夥伴 B 的賣場（已指派給 B、且 B 有業績）不得出現在 A 的清單裡'
		);
		$this->assertStringNotContainsString(
			'丁賣場',
			(string) \wp_json_encode( $shops ),
			'連賣場名稱都不得外洩'
		);
	}

	/**
	 * 只出現在被取消訂單裡的賣場不得進入清單
	 *
	 * 來源 B 那一半必須沿用與 KPI 相同的訂單狀態排除規則，否則一張被取消的訂單
	 * 就足以讓某個賣場永久留在選單裡，而選它只會得到 0——比看不到更令人困惑。
	 *
	 * @test
	 * @group edge
	 */
	public function test_shop_seen_only_in_cancelled_orders_is_not_listed(): void {
		$cancelled_only_shop = 762999;
		$this->seed_settlement( $this->partner_a_id, $cancelled_only_shop, 'wc-cancelled' );

		$this->assertArrayNotHasKey(
			$cancelled_only_shop,
			$this->get_shops(),
			'wc-cancelled 訂單在 KPI 已被排除，賣場清單必須沿用同一套規則'
		);
	}

	// ====================================================================
	//  C. 授權
	// ====================================================================

	/**
	 * 沒帶 token 一律 401（清單本身就是「他有哪些賣場」這個資訊）
	 *
	 * @test
	 * @group security
	 */
	public function test_shops_endpoint_requires_a_partner_token(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-shop/partner-reports/shops' );
		$response = \rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * 🔴 `?partner_term_id={別人}` 不得改變查詢對象
	 *
	 * 與 kpi / trend / settlements 同一條紀律：partner_term_id 鎖死於 token。
	 *
	 * @test
	 * @group security
	 */
	public function test_partner_term_id_in_query_string_is_ignored(): void {
		$shops = $this->get_shops( [ 'partner_term_id' => (string) $this->partner_b_id ] );

		$this->assertArrayHasKey(
			$this->shop_ids['assigned_no_sales'],
			$shops,
			'回的仍必須是 A 自己的清單'
		);
		$this->assertArrayNotHasKey(
			$this->shop_ids['other_partner'],
			$shops,
			'query string 不得把查詢對象換成夥伴 B'
		);
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 讀取賣場清單並轉成 `shop_id => shop_name` 對照表
	 *
	 * @param array<string, string> $query 額外 query string
	 *
	 * @return array<int, string>
	 */
	private function get_shops( array $query = [] ): array {
		$rows = (array) $this->request_shops( $query )['shops'];

		$map = [];
		foreach ( $rows as $row ) {
			$map[ (int) $row['shop_id'] ] = (string) $row['shop_name'];
		}

		return $map;
	}

	/**
	 * 發出請求並斷言 200 + 形狀正確
	 *
	 * @param array<string, string> $query 額外 query string
	 *
	 * @return array<string, mixed>
	 */
	private function request_shops( array $query = [] ): array {
		$request = new \WP_REST_Request( 'GET', '/power-shop/partner-reports/shops' );
		$request->set_header( 'X-Partner-Token', $this->token_a );
		if ( [] !== $query ) {
			$request->set_query_params( $query );
		}

		$response = \rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'shops 應回 200' );

		$body = (array) $response->get_data();
		$this->assertArrayHasKey( 'shops', $body, '回應必須是裸 payload `{ shops: [...] }`（partner endpoint 慣例）' );

		return $body;
	}

	/**
	 * 建立一個 powershop 賣場並指派夥伴
	 *
	 * @param string $title           賣場標題
	 * @param string $status          post_status
	 * @param int    $partner_term_id 指派的夥伴 term id
	 *
	 * @return int post id
	 */
	private function create_shop( string $title, string $status, int $partner_term_id ): int {
		$post_id = (int) \wp_insert_post(
			[
				'post_type'   => CptRegistrar::POST_TYPE,
				'post_title'  => $title,
				'post_status' => $status,
			]
		);
		$this->assertGreaterThan( 0, $post_id, "建立賣場「{$title}」失敗" );

		\update_post_meta( $post_id, self::META_PARTNER, $partner_term_id );

		return $post_id;
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
				name: "shops-{$slug}",
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
	 * 建一張訂單 + 一筆帶結算 meta 的 line item
	 *
	 * @param int    $partner_term_id 夥伴 term id
	 * @param int    $shop_id         賣場 id（凍結於 order itemmeta）
	 * @param string $order_status    WC 訂單狀態
	 */
	private function seed_settlement( int $partner_term_id, int $shop_id, string $order_status ): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => 'partner-shops-it-' . uniqid(),
				'regular_price' => '500.00',
			]
		);

		$order   = new \WC_Order();
		$item_id = $order->add_product( $product, 1 );
		$this->assertGreaterThan( 0, $item_id, '建立 line item 失敗' );

		$order->set_date_created( '2026-06-15 10:00:00' );
		$order->set_status( $order_status );
		$order->save();

		$this->repo->save(
			new SettlementRecord(
				order_item_id: (int) $item_id,
				shop_id: $shop_id,
				partner_term_id: $partner_term_id,
				rate: new ProfitRate( 15 ),
				actual_price: '500.00',
				profit_amount: '75.00',
				status: SettlementRecord::STATUS_PENDING,
			)
		);

		$this->order_ids[] = (int) $order->get_id();
	}

	/**
	 * 確保 powershop CPT 已註冊（測試環境未必跑過 ProfitShop\Loader 的 init）
	 */
	private function ensure_powershop_cpt_exists(): void {
		if ( ! \post_type_exists( CptRegistrar::POST_TYPE ) ) {
			\register_post_type( CptRegistrar::POST_TYPE, [ 'public' => false ] );
		}
	}
}
