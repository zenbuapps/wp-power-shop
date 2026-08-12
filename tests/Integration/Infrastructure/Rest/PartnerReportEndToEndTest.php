<?php
/**
 * 夥伴報表端到端整合測試（用真實密碼登入 → 看到自己的錢 → 看不到別人的錢）
 *
 * 對應規格：specs/features/profit-shop/login-partner.feature
 *          specs/features/profit-shop/view-partner-report.feature
 *          specs/2026-05-06-profit-shop-design.md §6.3、§8
 *
 * ═══ 🔴 這個檔案存在的理由：既有的隔離測試是**空轉的** ═══
 *
 * `PartnerReportsScopeTest` 建了兩個 partner、讓 A 登入、然後帶 `?partner_term_id={B}`
 * 打 settlements，接著：
 *
 *     foreach ( (array) $body['items'] as $item ) {
 *         if ( isset( $item['partner_term_id'] ) ) { ...assert... }
 *     }
 *
 * ——**它從來沒有種過任何結算資料**。兩個 partner 的 items 都是空陣列，
 * 迴圈跑零次、`if` 一次都沒進去 ⇒ 那段斷言**在任何實作下都會通過**，
 * 包含「後端直接照 query string 撈 B 的資料」這種最糟的實作。
 *
 * KPI 那條同理：它只斷言 `total_sales` 這個 **key 存在**。A 和 B 的金額都是 `0.00`，
 * 所以就算後端真的回了 B 的 KPI，斷言照樣成立。
 *
 * ⇒ 「夥伴看報表」這條動線在此之前**沒有任何一條測試真的看過一個非零的數字**。
 *
 * ═══ 本檔的做法：金額當金絲雀 ═══
 *
 * B 的分潤金額刻意設成一個**全站唯一、不可能巧合出現**的數字（`CANARY_AMOUNT`）。
 * 每一條測試都把整個 response `wp_json_encode()` 之後掃一次：
 * 只要那串數字出現在 A 的任何回應裡，就是資料外洩，不需要猜是哪個欄位漏的。
 *
 * 這比逐欄位比對強韌：日後 response 多加欄位（例如把 shop 名稱一起帶出來），
 * 逐欄位斷言不會跟著更新，金絲雀掃描會。
 *
 * ⚠️ 登入一律走**真實的 REST endpoint + 真實密碼**，不直接呼叫 `PartnerTokenStore::issue()`。
 *    後者會跳過 `PartnerAuthService` 的驗證流程，等於把「密碼對不對」這件事整段跳過。
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
 * 夥伴報表端到端整合測試
 */
final class PartnerReportEndToEndTest extends TestCase {

	/**
	 * 夥伴 A 的密碼（登入走真實 endpoint，密碼必須真的對得上）
	 */
	private const PASSWORD_A = 'alice-pw-2026';

	/**
	 * 夥伴 B 的密碼
	 */
	private const PASSWORD_B = 'bob-pw-2026';

	/**
	 * 🔴 金絲雀金額：全站唯一、不可能巧合出現的數字
	 *
	 * 只屬於夥伴 B。任何一支 A 的 response 裡出現這串字元 = 跨夥伴資料外洩。
	 */
	private const CANARY_AMOUNT = '86420.13';

	/**
	 * A 的兩筆分潤金額（刻意用非整數，避免與 fixture 的其他數字撞號）
	 */
	private const A_PENDING = '137.50';
	private const A_PAID    = '264.25';

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

		$this->partner_a_id = $this->create_partner( 'e2e-alice', self::PASSWORD_A );
		$this->partner_b_id = $this->create_partner( 'e2e-bob', self::PASSWORD_B );

		// A：一筆待撥款、一筆已撥款
		$this->seed_settlement( $this->partner_a_id, SettlementRecord::STATUS_PENDING, '900.00', self::A_PENDING );
		$this->seed_settlement( $this->partner_a_id, SettlementRecord::STATUS_PAID, '1100.00', self::A_PAID );

		// B：一筆金絲雀
		$this->seed_settlement( $this->partner_b_id, SettlementRecord::STATUS_PAID, '5000.00', self::CANARY_AMOUNT );

		$this->token_a = $this->login( 'e2e-alice', self::PASSWORD_A );
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
	//  A. 登入：真實密碼
	// ====================================================================

	/**
	 * 用正確密碼登入必須拿得到 token
	 *
	 * 這條同時是本檔其他所有測試的前置檢查：token 拿不到的話，
	 * 後面每一條都會以 401 紅燈，而不是安靜地測了個寂寞。
	 *
	 * @test
	 * @group happy
	 */
	public function test_partner_logs_in_with_real_password(): void {
		$this->assertNotSame( '', $this->token_a, '正確密碼必須換得到 token' );
	}

	/**
	 * 密碼錯誤必須 401，且不得簽發 token
	 *
	 * @test
	 * @group security
	 */
	public function test_wrong_password_is_rejected_and_issues_no_token(): void {
		$response = $this->request_login( 'e2e-alice', self::PASSWORD_A . '-wrong' );

		$this->assertSame( 401, $response->get_status() );

		$body = (array) $response->get_data();
		$this->assertArrayNotHasKey( 'token', $body, '登入失敗絕不可夾帶 token' );
	}

	/**
	 * 拿 B 的密碼登入 A 的 slug 必須失敗
	 *
	 * 密碼比對若誤用「任何一個 partner 的密碼都算數」的實作（例如比對前忘了鎖 slug），
	 * 這條會綠 ⇒ 任何夥伴都能看所有人的報表。
	 *
	 * @test
	 * @group security
	 */
	public function test_other_partners_password_cannot_log_into_this_slug(): void {
		$response = $this->request_login( 'e2e-alice', self::PASSWORD_B );

		$this->assertSame( 401, $response->get_status() );
	}

	// ====================================================================
	//  B. KPI：看得到自己的錢
	// ====================================================================

	/**
	 * 🔴 KPI 必須回**自己的非零金額**，而且不含金絲雀
	 *
	 * 既有測試只斷言 key 存在。那在「兩個 partner 都是 0」的 fixture 下，
	 * 連「後端回了另一個人的 KPI」都測不出來。
	 *
	 * @test
	 * @group happy
	 */
	public function test_kpi_returns_own_non_zero_amounts(): void {
		$body = $this->get_report( 'kpi' );

		$this->assertSame( self::A_PENDING, $body['profit_pending'], '待撥款應等於 A 自己那一筆' );
		$this->assertSame( self::A_PAID, $body['profit_paid'], '已撥款應等於 A 自己那一筆' );
		$this->assertSame( '2000.00', $body['total_sales'], '總銷售 = 900 + 1100' );

		$this->assert_no_canary( $body, 'KPI' );
	}

	/**
	 * 🔴 帶 `?partner_term_id={B}` 攻擊時，回的仍必須是 A 的數字
	 *
	 * 與既有測試的差別：那條在無資料下只能斷言 key 存在；本條有資料，
	 * 所以「後端照 query string 撈」會直接讓數字變成 B 的，紅燈明確。
	 *
	 * @test
	 * @group security
	 */
	public function test_kpi_ignores_partner_term_id_in_query_string(): void {
		$body = $this->get_report( 'kpi', [ 'partner_term_id' => (string) $this->partner_b_id ] );

		$this->assertSame( self::A_PAID, $body['profit_paid'], 'query string 不得改變查詢對象' );
		$this->assert_no_canary( $body, 'KPI（帶攻擊 query string）' );
	}

	// ====================================================================
	//  C. 結算明細：迴圈必須真的跑
	// ====================================================================

	/**
	 * 🔴 明細列表必須非空，且每一筆都屬於 A
	 *
	 * `assertNotSame( [], $items )` 這一行是本條的關鍵：
	 * 少了它，下面的 foreach 在空陣列上跑零次，整條退化成既有那個永遠會過的測試。
	 *
	 * @test
	 * @group security
	 */
	public function test_settlements_list_is_non_empty_and_all_belong_to_self(): void {
		$body  = $this->get_report( 'settlements' );
		$items = (array) $body['items'];

		$this->assertNotSame( [], $items, '明細為空時下面的逐筆斷言會空轉，整條測試失去意義' );
		$this->assertCount( 2, $items, 'A 有兩筆結算' );

		foreach ( $items as $item ) {
			$this->assertArrayHasKey(
				'partner_term_id',
				$item,
				'欄位若被改名，逐筆斷言會靜默失效——這一行讓它改名就紅'
			);
			$this->assertSame( $this->partner_a_id, (int) $item['partner_term_id'] );
		}

		$this->assert_no_canary( $body, '結算明細' );
	}

	/**
	 * 🔴 帶 `?partner_term_id={B}` 攻擊明細，同樣只能看到自己的
	 *
	 * @test
	 * @group security
	 */
	public function test_settlements_ignores_partner_term_id_in_query_string(): void {
		$body  = $this->get_report( 'settlements', [ 'partner_term_id' => (string) $this->partner_b_id ] );
		$items = (array) $body['items'];

		$this->assertNotSame( [], $items );
		$this->assertCount( 2, $items, '仍應是 A 的兩筆，不是 B 的一筆' );

		foreach ( $items as $item ) {
			$this->assertSame( $this->partner_a_id, (int) $item['partner_term_id'] );
		}

		$this->assert_no_canary( $body, '結算明細（帶攻擊 query string）' );
	}

	// ====================================================================
	//  D. 趨勢
	// ====================================================================

	/**
	 * 趨勢必須撈得到資料，且金額只含自己的
	 *
	 * @test
	 * @group happy
	 */
	public function test_trend_returns_own_data_only(): void {
		$body = $this->get_report( 'trend' );

		$series = (array) ( $body['series'] ?? $body['items'] ?? [] );
		$this->assertNotSame( [], $series, '趨勢回空代表 JOIN 撈不到訂單，夥伴會看到一張空圖' );

		$this->assert_no_canary( $body, '趨勢' );
	}

	// ====================================================================
	//  E. 登出：token 立即失效
	// ====================================================================

	/**
	 * 登出之後同一個 token 不得再讀到任何報表
	 *
	 * 只測「登出回 200」是不夠的——真正要防的是「登出了但 token 還能用」。
	 *
	 * @test
	 * @group security
	 */
	public function test_logout_revokes_token_for_reports(): void {
		// 先證明登出前是通的，否則下面的 401 可能來自別的原因
		$before = $this->request_report( 'kpi', $this->token_a );
		$this->assertSame( 200, $before->get_status(), '登出前應可正常讀取' );

		$logout = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/logout' );
		$logout->set_header( 'X-Partner-Token', $this->token_a );
		\rest_do_request( $logout );

		$after = $this->request_report( 'kpi', $this->token_a );
		$this->assertSame( 401, $after->get_status(), '登出後舊 token 必須失效' );
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 🔴 掃描整個 response，確認不含屬於別人的金額
	 *
	 * @param array<string, mixed> $body  response body
	 * @param string               $label 出錯時的情境描述
	 */
	private function assert_no_canary( array $body, string $label ): void {
		$json = (string) \wp_json_encode( $body );

		// 金額用字串比對是**刻意且正確**的：CANARY_AMOUNT 依檔頭設計是一個全站唯一、
		// 不可能巧合出現的數字，因此「這串字元出現在回應裡」等價於「B 的資料外洩了」。
		$this->assertStringNotContainsString(
			self::CANARY_AMOUNT,
			$json,
			"{$label} 的回應含有夥伴 B 的專屬金額 " . self::CANARY_AMOUNT . '（跨夥伴資料外洩）'
		);

		// 🔴 但 term id **不可以**照抄上面那種寫法——它沒有 canary 的性質。
		// partner_b_id 是 auto_increment 的普通小數字，對整個 JSON 做子字串比對時，
		// 任何「碰巧含有這串數字」的其他欄位都會誤命中：term id 237 會被
		// `"order_item_id":23773`（前綴）、`"order_id":2370`、金額 `"1237.00"` 全部打中。
		// 實測 2026-08-12：order_item_id 漲到 23773 時這條無中生有地紅了，
		// 而回應內每一筆的 partner_term_id 其實都是 A（236）。
		// ⇒ 改為結構化比對：只看 `partner_term_id` 這個欄位的**值**。
		$this->assertSame(
			[],
			$this->collect_partner_term_ids( $body['items'] ?? [], $this->partner_b_id ),
			"{$label} 的明細含有夥伴 B（term id {$this->partner_b_id}）的紀錄"
		);
	}

	/**
	 * 遞迴走訪任意巢狀結構，收集所有「值等於 $needle 的 partner_term_id 欄位」
	 *
	 * 走整棵樹而不是只掃第一層，是為了維持原本「掃描整個 response、涵蓋逐筆斷言
	 * 沒檢查到的角落」那道縱深防線——換成結構化比對之後仍然涵蓋巢狀欄位。
	 *
	 * @param mixed $data   任意結構（陣列 / 純量）
	 * @param int   $needle 不該出現的 partner term id
	 * @return array<int, string> 命中的路徑清單；空陣列代表乾淨
	 */
	private function collect_partner_term_ids( $data, int $needle, string $path = 'items' ): array {
		if ( ! \is_array( $data ) ) {
			return [];
		}

		$hits = [];

		foreach ( $data as $key => $value ) {
			$child = "{$path}.{$key}";

			if ( 'partner_term_id' === $key && (int) $value === $needle ) {
				$hits[] = $child;
				continue;
			}

			$hits = \array_merge( $hits, $this->collect_partner_term_ids( $value, $needle, $child ) );
		}

		return $hits;
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
				name: "e2e-{$slug}",
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
		$body = (array) $this->request_login( $slug, $password )->get_data();

		return (string) ( $body['token'] ?? '' );
	}

	/**
	 * 發出登入請求
	 *
	 * @param string $slug     partner slug
	 * @param string $password 明文密碼
	 *
	 * @return \WP_REST_Response
	 */
	private function request_login( string $slug, string $password ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( [ 'slug' => $slug, 'password' => $password ] ) );

		return \rest_do_request( $request );
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
		$response = $this->request_report( $endpoint, $this->token_a, $query );

		$this->assertSame( 200, $response->get_status(), "{$endpoint} 應回 200" );

		return (array) $response->get_data();
	}

	/**
	 * 發出報表請求
	 *
	 * @param string                $endpoint kpi / trend / settlements
	 * @param string                $token    partner token
	 * @param array<string, string> $query    額外 query string
	 *
	 * @return \WP_REST_Response
	 */
	private function request_report( string $endpoint, string $token, array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', "/power-shop/partner-reports/{$endpoint}" );
		$request->set_header( 'X-Partner-Token', $token );
		if ( [] !== $query ) {
			$request->set_query_params( $query );
		}

		return \rest_do_request( $request );
	}

	/**
	 * 建一張訂單 + 一筆帶結算 meta 的 line item
	 *
	 * @param int    $partner_term_id 夥伴 term id
	 * @param string $status          結算狀態
	 * @param string $actual_price    實收單價
	 * @param string $profit_amount   應分潤金額
	 */
	private function seed_settlement(
		int $partner_term_id,
		string $status,
		string $actual_price,
		string $profit_amount
	): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => 'partner-report-e2e-' . uniqid(),
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
				shop_id: 880001,
				partner_term_id: $partner_term_id,
				rate: new ProfitRate( 15 ),
				actual_price: $actual_price,
				profit_amount: $profit_amount,
				status: $status,
			)
		);

		$this->order_ids[] = (int) $order->get_id();
	}
}
