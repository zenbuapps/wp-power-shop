<?php
/**
 * 夥伴報表端點的「請求守門 + 回應契約」IT
 *
 * 對應規格：specs/features/profit-shop/query-partner-report.feature
 *   - Rule: 前置（分頁）- 分頁參數必須有上限以防資源耗盡
 *   - Rule: 前置（期間）- 日期區間必須被正規化且有合理上限
 *   - Rule: 後置（格式）- 夥伴端點一律回傳裸 payload
 *
 * ═══ 這個檔案存在的理由：上面三條 Rule 在此之前是零覆蓋 ═══
 *
 * 【分頁】收斂只存在於一個地方——`V2Api::build_filter_criteria_from_request()`：
 *
 *     $page     = isset( $params['page'] )     ? max( 1, min( 10000, (int) $params['page'] ) )     : 1;
 *     $per_page = isset( $params['per_page'] ) ? max( 1, min( 100,   (int) $params['per_page'] ) ) : 20;
 *
 * `FilterCriteria` 本身不做任何收斂（純值物件，建構子只有預設值），
 * 而 `WpSettlementSummaryProvider::find_by_partner()` 只有 `max( 1, ... )` 這個**下限**、
 * **沒有上限** ⇒ 把上面那兩個 `min()` 拿掉，使用者送的 `per_page=500` 會原封不動變成
 * SQL 的 `LIMIT 500`，`page=999999999` 會變成 `OFFSET 19999999960`。
 * 在此之前**沒有任何測試對這兩個端點送過 page / per_page**，那兩個 `min()` 刪掉全綠。
 *
 * 【期間】`format_datetime()` 把時間戳收斂進 `[0, 253402300799]`（9999-12-31 23:59:59 UTC）
 * 之後才進 SQL。實測（容器內 PHP 8.2.33）未收斂時的後果：
 *
 *     (int) "99999999999999999999"                       → 9223372036854775807（飽和成 PHP_INT_MAX）
 *     gmdate( 'Y-m-d H:i:s', PHP_INT_MAX )                → "292277026596-12-04 15:30:07"
 *     gmdate( 'Y-m-d H:i:s', 253402300799 )               → "9999-12-31 23:59:59"
 *
 * 也就是說少了收斂，送進 `DATETIME` 比較的會是一個**年份 12 位數、MySQL 無法解析**的字串。
 * 它仍受 `$wpdb->prepare()` 保護（不構成 injection），但查詢語意已經不可預期。
 *
 * ⚠️ 已知落差（本檔刻意**不**斷言，避免把現況當成規格釘死）：
 *    `WpSettlementSummaryProvider::summary_for_partner()` 回傳的 `period_start` / `period_end`
 *    走的是 `gmdate( 'Y-m-d', $criteria->date_start )` **原始值**，沒有經過 `format_datetime()`。
 *    ⇒ SQL 收斂正確，但 KPI 回應的 `period_end` 會照實回 "292277026596-12-04"。
 *    這是回應顯示層的落差，已另行回報，不在本檔的斷言範圍內。
 *
 * 【裸 payload】夥伴端點不裹 `{code, data}` 是**刻意的契約差異**（admin 端點的
 * `V2Api::ok()` 會裹）。前端 partner portal 直接讀最外層欄位，契約一旦被「順手統一」
 * 成 admin 的包裝形式，portal 會整片壞掉——而在此之前，全 `tests/` 沒有任何一條
 * 對夥伴**報表**端點斷言過回應形狀（既有的 `assertArrayNotHasKey` 只出現在
 * 登入失敗不得夾帶 token 之類的**別的**契約上）。
 * feature 原本把這條 Rule 的 `@covered-by` 指到 `PartnerReportsScopeTest` 的
 * `test_no_token_returns_401` / `test_invalid_token_returns_401`，但那兩條各自只有一行
 * `assertSame( 401, ... )`、與回應形狀完全無關（它們屬於「安全（範圍）」）——
 * 標記已一併更正。
 *
 * ═══ 決定性驗證（實測，非推論）═══
 *
 * 綠燈不代表測得到東西。本檔以「複製一份 probe、只竄改**測試自己的期望值**」的方式
 * 逐條確認斷言真的在讀實際值（**不動 `inc/` 的守門**，避免與其他協作者的檔案窗口衝突，
 * 見 CLAUDE.md Pitfall #36）。實測結果：
 *
 *   期望 LIMIT 999            → 紅，實得 LIMIT 100        （per_page 收斂真的進到 SQL）
 *   期望 OFFSET 19999999960   → 紅，實得 OFFSET 199980    （page 收斂真的進到 SQL）
 *   期望 '8888-12-31 23:59:59'→ 紅，SQL 實含 `o.post_date_gmt <= '9999-12-31 23:59:59'`
 *   側錄陣列改成空陣列        → 紅，「沒有側錄到分頁查詢」（反空轉守門有效）
 *
 * 其中 OFFSET 19999999960 正是「拿掉 `min( 10000, ... )`」會產生的值
 * （`( 999999999 - 1 ) * 20`），所以這條斷言就是那個 `min()` 的回歸鎖。
 *
 * ═══ 為什麼本檔不種結算資料，卻不是空轉測試 ═══
 *
 * 三條 Rule 驗的都是「請求參數怎麼被翻譯成查詢」與「回應長什麼形狀」，
 * 兩者都與「資料列有沒有命中」無關：`find_by_partner()` 只在
 * `partner_term_id <= 0` 或「有送 statuses 但全部不合法」時才提早 return 不發 SQL，
 * 本檔兩者皆不觸發，SQL 一定會發出。
 *
 * 即使如此，`assert_sql_pagination()` 仍會在**側錄不到目標查詢**時直接紅燈——
 * 沒有這道守門，一旦查詢因故沒發出，底下的 LIMIT / OFFSET 斷言就會安靜地不執行。
 *
 * @group profit_shop
 * @group rest
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Integration\TestCase;

/**
 * 夥伴報表端點請求守門與回應契約 IT
 */
final class PartnerReportContractTest extends TestCase {

	/**
	 * 夥伴密碼（登入走真實 endpoint，密碼必須真的對得上）
	 */
	private const PASSWORD = 'contract-pw-2026';

	/**
	 * 夥伴 slug
	 */
	private const SLUG = 'contract-partner';

	/**
	 * admin 端點的包裝鍵——夥伴端點最外層一個都不得出現
	 */
	private const ENVELOPE_KEYS = [ 'code', 'message', 'data' ];

	private int $partner_id = 0;
	private string $token   = '';

	/**
	 * setUp
	 */
	public function set_up(): void {
		parent::set_up();

		$this->partner_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Contract Partner',
				slug: new PartnerSlug( self::SLUG ),
				contact_email: null
			),
			self::PASSWORD
		);

		$this->token = $this->login();

		$this->assertNotSame(
			'',
			$this->token,
			'登入必須拿得到 token，否則本檔每一條都會以 401 紅燈而不是真的驗到契約'
		);
	}

	/**
	 * tearDown
	 */
	public function tear_down(): void {
		if ( $this->partner_id > 0 ) {
			\wp_delete_term( $this->partner_id, 'profit_partner' );
		}
		$this->partner_id = 0;
		$this->token      = '';

		parent::tear_down();
	}

	// ====================================================================
	//  A. 前置（分頁）- 分頁參數必須有上限以防資源耗盡
	// ====================================================================

	/**
	 * 不帶分頁參數時使用預設值 page=1 / per_page=20
	 *
	 * @test
	 * @group happy
	 */
	public function test_default_pagination_is_page_1_and_per_page_20(): void {
		$result = $this->request_settlements_with_sql();

		$this->assertSame( 1, (int) $result['body']['page'], '預設頁碼應為 1' );
		$this->assertSame( 20, (int) $result['body']['per_page'], '預設每頁筆數應為 20' );
		$this->assert_sql_pagination( $result['sql'], 20, 0 );
	}

	/**
	 * 範圍內的分頁參數必須原樣通過，不被收斂
	 *
	 * 這條是上限測試的對照組：少了它，把 `min()` 誤改成「一律回傳上限」
	 * （例如寫死 `$per_page = 100`）也會讓所有上限測試通過。
	 *
	 * @test
	 * @group happy
	 */
	public function test_in_range_pagination_passes_through_untouched(): void {
		$result = $this->request_settlements_with_sql(
			[
				'page'     => '3',
				'per_page' => '20',
			]
		);

		$this->assertSame( 3, (int) $result['body']['page'] );
		$this->assertSame( 20, (int) $result['body']['per_page'] );
		// OFFSET = ( 3 - 1 ) * 20
		$this->assert_sql_pagination( $result['sql'], 20, 40 );
	}

	/**
	 * per_page 超過上限時收斂為 100
	 *
	 * @test
	 * @group edge
	 */
	public function test_per_page_above_limit_is_capped_to_100(): void {
		$result = $this->request_settlements_with_sql( [ 'per_page' => '500' ] );

		$this->assertSame(
			100,
			(int) $result['body']['per_page'],
			'per_page=500 必須收斂為 100'
		);
		$this->assert_sql_pagination( $result['sql'], 100, 0 );
	}

	/**
	 * per_page 為 0 時提升為 1
	 *
	 * 0 若原樣進 SQL 會變成 `LIMIT 0`，夥伴會看到一份永遠空白的明細。
	 *
	 * @test
	 * @group edge
	 */
	public function test_per_page_zero_is_raised_to_one(): void {
		$result = $this->request_settlements_with_sql( [ 'per_page' => '0' ] );

		$this->assertSame( 1, (int) $result['body']['per_page'], 'per_page=0 必須提升為 1' );
		$this->assert_sql_pagination( $result['sql'], 1, 0 );
	}

	/**
	 * page 超過上限時收斂為 10000
	 *
	 * 這條是本組最有價值的一條：`OFFSET` 是 `( page - 1 ) * per_page`，
	 * 少了上限，`page=999999999` 會組出 `OFFSET 19999999960` 直接拖垮資料庫。
	 *
	 * @test
	 * @group edge
	 */
	public function test_page_above_limit_is_capped_to_10000(): void {
		$result = $this->request_settlements_with_sql(
			[
				'page'     => '999999999',
				'per_page' => '20',
			]
		);

		$this->assertSame( 10000, (int) $result['body']['page'], 'page 必須收斂為 10000' );
		// OFFSET = ( 10000 - 1 ) * 20；未收斂則會是 ( 999999999 - 1 ) * 20 = 19999999960
		$this->assert_sql_pagination( $result['sql'], 20, 199980 );
	}

	// ====================================================================
	//  B. 前置（期間）- 日期區間必須被正規化且有合理上限
	// ====================================================================

	/**
	 * 極大的 date_end 必須收斂成 MySQL 可解析的 DATETIME，且不得拋錯
	 *
	 * @test
	 * @group edge
	 */
	public function test_extreme_date_end_is_clamped_to_a_valid_mysql_datetime(): void {
		$result = $this->request_report_with_sql( 'kpi', [ 'date_end' => '99999999999999999999' ] );

		$this->assertSame( 200, $result['status'], '極大時間戳不得造成錯誤' );

		$dated = $this->find_sql_containing( $result['sql'], 'date_created_gmt', 'post_date_gmt' );

		$this->assertStringContainsString(
			'9999-12-31 23:59:59',
			$dated,
			'date_end 必須以上限時間戳（9999-12-31 23:59:59 UTC）進入查詢'
		);

		$this->assertStringNotContainsString(
			'292277026596',
			$dated,
			'未收斂的 PHP_INT_MAX 會被 gmdate 展開成年份 12 位數的字串，MySQL 無法解析'
		);
	}

	/**
	 * 負數 date_start 必須收斂成 epoch
	 *
	 * @test
	 * @group edge
	 */
	public function test_negative_date_start_is_clamped_to_epoch(): void {
		$result = $this->request_report_with_sql( 'kpi', [ 'date_start' => '-99999999999' ] );

		$this->assertSame( 200, $result['status'], '負數時間戳不得造成錯誤' );

		$dated = $this->find_sql_containing( $result['sql'], 'date_created_gmt', 'post_date_gmt' );

		$this->assertStringContainsString(
			'1970-01-01 00:00:00',
			$dated,
			'負數 date_start 必須收斂成 epoch'
		);
	}

	// ====================================================================
	//  C. 後置（格式）- 夥伴端點一律回傳裸 payload
	// ====================================================================

	/**
	 * KPI 回應最外層直接是報表欄位，不裹 code / message / data
	 *
	 * 正反兩面都斷言是刻意的：只斷言「沒有 code」的話，回應變成空陣列也會通過；
	 * 補上「報表欄位就在最外層」之後，一旦有人把 payload 包進 `data`，
	 * 兩個方向會同時紅燈。
	 *
	 * @test
	 * @group happy
	 */
	public function test_kpi_response_is_a_bare_payload(): void {
		$body = $this->get_report( 'kpi' );

		foreach ( [ 'total_sales', 'profit_pending', 'profit_paid', 'profit_refunded', 'period_start', 'period_end' ] as $field ) {
			$this->assertArrayHasKey( $field, $body, "KPI 欄位 {$field} 必須在回應最外層" );
		}

		$this->assert_is_bare_payload( $body, 'KPI' );
	}

	/**
	 * 趨勢與結算明細同樣是裸 payload
	 *
	 * @test
	 * @group happy
	 */
	public function test_trend_and_settlements_responses_are_bare_payloads(): void {
		$settlements = $this->get_report( 'settlements' );
		foreach ( [ 'items', 'total', 'page', 'per_page' ] as $field ) {
			$this->assertArrayHasKey( $field, $settlements, "結算明細欄位 {$field} 必須在回應最外層" );
		}
		$this->assert_is_bare_payload( $settlements, '結算明細' );

		$trend = $this->get_report( 'trend' );
		$this->assertNotSame( [], $trend, '趨勢回應不得為空陣列，否則下面的斷言失去意義' );
		$this->assert_is_bare_payload( $trend, '趨勢' );
	}

	/**
	 * 🔴 裸 payload 斷言本身必須真的擋得下「被包裝過」的回應
	 *
	 * 上面三條斷言的是**某個鍵不存在**。這種斷言最危險的失效方式是「它本來就不會紅」——
	 * 例如 `assert_is_bare_payload()` 哪天被改成只檢查一個永遠不會出現的鍵、
	 * 或 `ENVELOPE_KEYS` 被清空，三條測試會全部繼續綠，而契約已經沒人守。
	 *
	 * 這條把 `V2Api::ok()` 的實際輸出形狀（`{code, data}`）餵進同一個 helper，
	 * 要求它**必須**丟出 AssertionFailedError。helper 一旦失去偵測能力，這條立刻紅。
	 *
	 * 刻意用手工組出的 payload 而不是真的去打一次 admin 端點：後者需要建立 administrator、
	 * 依賴 WooCommerce 的 `manage_woocommerce` capability 與 object cache 狀態，
	 * 實測會受到共用 teardown（`clean_transients()`）偶發 DB deadlock 的影響而 403。
	 * admin 端點確實會裹 `code` 這件事，既有測試（`SlugConflictTest`、
	 * `ExceptionMapperDepthTest` 等多處讀 `$body['code']`）已經鎖住，這裡不需要重複驗證。
	 *
	 * @test
	 * @group happy
	 */
	public function test_bare_payload_assertion_actually_rejects_a_wrapped_response(): void {
		$wrapped = [
			'code' => 'success',
			'data' => [
				'total_sales'    => '0.00',
				'profit_pending' => '0.00',
			],
		];

		try {
			$this->assert_is_bare_payload( $wrapped, '自我檢查' );
		} catch ( AssertionFailedError $e ) {
			$this->assertStringContainsString(
				'code',
				$e->getMessage(),
				'紅燈訊息應指出是哪一個包裝鍵造成的'
			);
			return;
		}

		$this->fail(
			'assert_is_bare_payload() 對 {code, data} 這種被包裝的回應沒有紅燈 '
				. '⇒ 上面三條裸 payload 斷言全部是空轉的'
		);
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 斷言回應最外層不含 admin 端點的包裝鍵
	 *
	 * @param array<string, mixed> $body  回應 body
	 * @param string               $label 出錯時的情境描述
	 */
	private function assert_is_bare_payload( array $body, string $label ): void {
		foreach ( self::ENVELOPE_KEYS as $key ) {
			$this->assertArrayNotHasKey(
				$key,
				$body,
				"{$label} 的回應最外層出現了 \"{$key}\"——夥伴端點必須是裸 payload，"
					. 'partner portal 前端直接讀最外層欄位，包起來會整片壞掉'
			);
		}
	}

	/**
	 * 斷言側錄到的 SQL 內，find_by_partner 的分頁條件符合預期
	 *
	 * `LIMIT %d OFFSET %d` 在 `WpSettlementSummaryProvider` 內只有 `find_by_partner()`
	 * 用到（summary / trend 都沒有分頁），因此「含 oim_partner 且含 LIMIT ... OFFSET」
	 * 足以唯一鎖定目標查詢。
	 *
	 * @param string[] $queries 側錄到的 SQL
	 * @param int      $limit   預期的 LIMIT
	 * @param int      $offset  預期的 OFFSET
	 */
	private function assert_sql_pagination( array $queries, int $limit, int $offset ): void {
		$actual = null;

		foreach ( $queries as $sql ) {
			if ( ! str_contains( $sql, 'oim_partner' ) ) {
				continue;
			}
			if ( 1 === preg_match( '/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $matches ) ) {
				$actual = [ (int) $matches[1], (int) $matches[2] ];
				break;
			}
		}

		$this->assertNotNull(
			$actual,
			'沒有側錄到 find_by_partner 的分頁查詢——底下的 LIMIT / OFFSET 斷言會安靜地不執行，'
				. '先確認查詢真的有發出（例如 partner_term_id 是否 <= 0 而提早 return）'
		);

		$this->assertSame(
			[ $limit, $offset ],
			$actual,
			sprintf(
				'SQL 分頁應為 LIMIT %d OFFSET %d，實得 LIMIT %d OFFSET %d——'
					. '收斂若只做在回應欄位而沒進到查詢，等於完全沒防到',
				$limit,
				$offset,
				$actual[0],
				$actual[1]
			)
		);
	}

	/**
	 * 從側錄的 SQL 中找出第一個同時含有指定片段之一的查詢
	 *
	 * @param string[] $queries   側錄到的 SQL
	 * @param string   ...$needle 任一命中即可的片段（HPOS 與 legacy 欄位名不同）
	 *
	 * @return string 命中的 SQL
	 */
	private function find_sql_containing( array $queries, string ...$needle ): string {
		foreach ( $queries as $sql ) {
			if ( ! str_contains( $sql, 'oim_partner' ) ) {
				continue;
			}
			foreach ( $needle as $fragment ) {
				if ( str_contains( $sql, $fragment ) ) {
					return $sql;
				}
			}
		}

		$this->fail(
			'沒有側錄到帶日期條件的報表查詢（找不到 ' . implode( ' / ', $needle ) . '）——'
				. '底下的收斂斷言會失去意義'
		);
	}

	/**
	 * 走真實 endpoint 登入，回傳 token（失敗回空字串）
	 *
	 * 刻意不直接呼叫 `PartnerTokenStore::issue()`——那會跳過整段密碼驗證流程。
	 *
	 * @return string
	 */
	private function login(): string {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) \wp_json_encode(
				[
					'slug'     => self::SLUG,
					'password' => self::PASSWORD,
				]
			)
		);

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
		$response = $this->request_report( $endpoint, $query );

		$this->assertSame( 200, $response->get_status(), "{$endpoint} 應回 200" );

		return (array) $response->get_data();
	}

	/**
	 * 發出報表請求
	 *
	 * @param string                $endpoint kpi / trend / settlements
	 * @param array<string, string> $query    額外 query string
	 *
	 * @return \WP_REST_Response
	 */
	private function request_report( string $endpoint, array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', "/power-shop/partner-reports/{$endpoint}" );
		$request->set_header( 'X-Partner-Token', $this->token );
		if ( [] !== $query ) {
			$request->set_query_params( $query );
		}

		return \rest_do_request( $request );
	}

	/**
	 * 發出結算明細請求，同時側錄期間發出的所有 SQL
	 *
	 * @param array<string, string> $query 額外 query string
	 *
	 * @return array{status: int, body: array<string, mixed>, sql: string[]}
	 */
	private function request_settlements_with_sql( array $query = [] ): array {
		$result = $this->request_report_with_sql( 'settlements', $query );

		$this->assertSame( 200, $result['status'], '結算明細應回 200' );

		return $result;
	}

	/**
	 * 發出報表請求，同時側錄期間發出的所有 SQL
	 *
	 * 側錄手法：`wpdb::query()` 內對每一條 SQL 套用 `query` filter，
	 * `get_results()` 也走同一條路徑，因此掛上 filter 即可拿到 `prepare()` 之後的成品。
	 * `try/finally` 保證斷言失敗時 filter 一定被移除，不外洩到同進程的其他測試。
	 *
	 * @param string                $endpoint kpi / trend / settlements
	 * @param array<string, string> $query    額外 query string
	 *
	 * @return array{status: int, body: array<string, mixed>, sql: string[]}
	 */
	private function request_report_with_sql( string $endpoint, array $query = [] ): array {
		$captured = [];
		$capture  = static function ( $sql ) use ( &$captured ) {
			$captured[] = (string) $sql;
			return $sql;
		};

		\add_filter( 'query', $capture );
		try {
			$response = $this->request_report( $endpoint, $query );
		} finally {
			\remove_filter( 'query', $capture );
		}

		return [
			'status' => (int) $response->get_status(),
			'body'   => (array) $response->get_data(),
			'sql'    => $captured,
		];
	}
}
