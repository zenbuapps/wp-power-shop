<?php
/**
 * ExportSettlementsUseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3、§6.9
 *          specs/features/profit-shop/export-settlements-csv.feature
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group settlement
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementExportOutput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\CsvExporter;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\ExportSettlementsUseCase;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\InMemorySettlementQueryProvider;

/**
 * 結算 CSV 匯出 UseCase 契約測試
 */
final class ExportSettlementsUseCaseTest extends TestCase {

	private const NOW = 1_700_000_000;

	private InMemorySettlementQueryProvider $query;
	private ExportSettlementsUseCase $useCase;

	protected function setUp(): void {
		parent::setUp();

		$this->query   = new InMemorySettlementQueryProvider();
		$this->useCase = new ExportSettlementsUseCase(
			query: $this->query,
			csv: new CsvExporter(),
			clock: new FixedClock( self::NOW ),
		);
	}

	/**
	 * 解出 CSV 全文（測試一律透過此方法讀，避免忘記 base64_decode）
	 *
	 * @param SettlementExportOutput $output 匯出結果
	 *
	 * @return string
	 */
	private function decode( SettlementExportOutput $output ): string {
		$decoded = base64_decode( $output->content_base64, true );
		$this->assertIsString( $decoded, 'content_base64 必須是合法的 base64' );
		return $decoded;
	}

	/**
	 * 產生 N 筆假資料
	 *
	 * @param int $count 筆數
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( int $count ): array {
		$rows = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = [
				'order_item_id' => 7000 + $i,
				'order_id'      => 8000 + $i,
				'product_name'  => "商品 {$i}",
				'partner_name'  => '夥伴A',
				'shop_name'     => '夏季賣場',
				'actual_price'  => '240.00',
				'profit_amount' => '48.00',
				'status'        => 'pending',
				'order_date'    => '2026-08-01 10:30:00',
			];
		}
		return $rows;
	}

	/**
	 * 🔴 happy：首列為規格逐字鎖定的 9 個標題、順序固定
	 *
	 * @group happy
	 */
	public function test_header_matches_spec_exactly(): void {
		$this->query->seed_rows( [] );

		$csv   = $this->decode( $this->useCase->execute( SettlementFilterInput::from_array( [] ) ) );
		$lines = explode( "\r\n", ltrim( $csv, "\u{FEFF}" ) );

		$this->assertSame(
			'訂單編號,訂單品項編號,商品名稱,分潤夥伴,分潤賣場,實際單價,應分潤金額,結算狀態,訂單日期',
			$lines[0],
			'欄位順序與標題由 export-settlements-csv.feature 逐字鎖定'
		);
	}

	/**
	 * 🔴 happy：資料列的欄位順序與標題列一致（規格的 Example 逐字比對）
	 *
	 * @group happy
	 */
	public function test_data_row_matches_spec_example(): void {
		$this->query->seed_rows(
			[
				[
					'order_item_id' => 7001,
					'order_id'      => 8001,
					'product_name'  => '筆記型電腦',
					'partner_name'  => '夥伴A',
					'shop_name'     => '夏季賣場',
					'actual_price'  => '240.00',
					'profit_amount' => '48.00',
					'status'        => 'pending',
					'order_date'    => '2026-08-01 09:00:00',
				],
			]
		);

		$csv   = $this->decode( $this->useCase->execute( SettlementFilterInput::from_array( [] ) ) );
		$lines = explode( "\r\n", ltrim( $csv, "\u{FEFF}" ) );

		$this->assertSame(
			'8001,7001,筆記型電腦,夥伴A,夏季賣場,240.00,48.00,pending,2026-08-01',
			$lines[1]
		);
	}

	/**
	 * happy：訂單日期只輸出日期（對帳維度），不含時分秒
	 *
	 * @group happy
	 */
	public function test_order_date_is_date_only(): void {
		$this->query->seed_rows( $this->rows( 1 ) );

		$csv = $this->decode( $this->useCase->execute( SettlementFilterInput::from_array( [] ) ) );

		$this->assertStringContainsString( '2026-08-01', $csv );
		$this->assertStringNotContainsString( '10:30:00', $csv );
	}

	/**
	 * 🔴 happy：解碼後的內容以 UTF-8 BOM 開頭
	 *
	 * @group happy
	 */
	public function test_content_starts_with_utf8_bom(): void {
		$this->query->seed_rows( $this->rows( 1 ) );

		$csv = $this->decode( $this->useCase->execute( SettlementFilterInput::from_array( [] ) ) );

		$this->assertStringStartsWith( "\u{FEFF}", $csv, '少了 BOM，Excel 會把中文開成亂碼' );
	}

	/**
	 * 🔴 happy：回應以 base64 承載（決策 D-5），不是原始字串
	 *
	 * @group happy
	 */
	public function test_output_carries_base64_not_raw_content(): void {
		$this->query->seed_rows( $this->rows( 1 ) );

		$array = $this->useCase->execute( SettlementFilterInput::from_array( [] ) )->to_array();

		$this->assertArrayHasKey( 'content_base64', $array );
		$this->assertArrayNotHasKey( 'content', $array, '不得同時回傳未編碼內容（規格只認 content_base64）' );
		$this->assertArrayHasKey( 'filename', $array );
	}

	/**
	 * 🔴 happy：匯出整個篩選結果，而不是只有一頁
	 *
	 * @group happy
	 */
	public function test_export_paginates_through_all_results(): void {
		$this->query->seed_rows( $this->rows( 250 ) );

		$output = $this->useCase->execute( SettlementFilterInput::from_array( [ 'per_page' => 20 ] ) );

		$this->assertSame( 250, $output->row_count, '匯出必須涵蓋全部筆數，不受 per_page 影響' );
		$this->assertStringContainsString( '商品 250', $this->decode( $output ) );
		$this->assertGreaterThan( 1, count( $this->query->filters_seen ), '應該有翻頁' );
	}

	/**
	 * happy：規格明訂「一次產出整份檔案」，不得有業務層的筆數上限
	 *
	 * @group happy
	 */
	public function test_export_has_no_business_row_cap(): void {
		$this->query->seed_rows( $this->rows( 1200 ) );

		$output = $this->useCase->execute( SettlementFilterInput::from_array( [] ) );

		$this->assertSame( 1200, $output->row_count );
	}

	/**
	 * happy：傳入的 page 被忽略（一律從第 1 頁開始抓）
	 *
	 * @group happy
	 */
	public function test_incoming_page_is_ignored(): void {
		$this->query->seed_rows( $this->rows( 5 ) );

		$output = $this->useCase->execute( SettlementFilterInput::from_array( [ 'page' => 7 ] ) );

		$this->assertSame( 5, $output->row_count );
		$this->assertSame( 1, $this->query->filters_seen[0]->page, '第一次查詢必須是第 1 頁' );
	}

	/**
	 * happy：非分頁的過濾條件原封傳給 Provider
	 *
	 * @group happy
	 */
	public function test_non_pagination_filters_are_preserved(): void {
		$this->query->seed_rows( $this->rows( 1 ) );

		$this->useCase->execute(
			SettlementFilterInput::from_array(
				[
					'partner_id' => 5,
					'status'     => 'paid',
					'date_start' => 1700000000,
				]
			)
		);

		$seen = $this->query->filters_seen[0];
		$this->assertSame( [ 5 ], $seen->partner_term_ids );
		$this->assertSame( [ 'paid' ], $seen->statuses );
		$this->assertSame( 1700000000, $seen->date_start );
	}

	/**
	 * 🔴 security：商品名稱、分潤夥伴、分潤賣場**三個**可輸入欄位都要跳脫
	 *
	 * 規格明寫「只跳脫商品名稱，攻擊者就改用賣場名稱」。
	 *
	 * @group security
	 */
	public function test_all_three_user_input_columns_are_escaped(): void {
		$payload = "=cmd|'/c calc'!A1";
		$this->query->seed_rows(
			[
				[
					'order_item_id' => 1,
					'order_id'      => 2,
					'product_name'  => $payload,
					'partner_name'  => $payload,
					'shop_name'     => $payload,
					'actual_price'  => '1.00',
					'profit_amount' => '1.00',
					'status'        => 'pending',
					'order_date'    => '2026-08-01 00:00:00',
				],
			]
		);

		$csv = $this->decode( $this->useCase->execute( SettlementFilterInput::from_array( [] ) ) );

		// 三格都要出現中和後的形式，且不得有任何未中和的殘留
		$this->assertSame( 3, substr_count( $csv, "'" . $payload ), '三個欄位都必須被前置單引號' );
		$this->assertStringNotContainsString( ',' . $payload, $csv );
	}

	/**
	 * security：以危險字元開頭的商品名稱被中和（規格 Examples 逐項）
	 *
	 * @group security
	 */
	public function test_dangerous_prefixes_from_spec_examples_are_escaped(): void {
		foreach ( [ '=1+1', '+886912345678', '-100', '@SUM(A1:A9)' ] as $name ) {
			$this->query = new InMemorySettlementQueryProvider();
			$useCase     = new ExportSettlementsUseCase(
				query: $this->query,
				csv: new CsvExporter(),
				clock: new FixedClock( self::NOW ),
			);
			$this->query->seed_rows(
				[
					[
						'order_item_id' => 1,
						'order_id'      => 2,
						'product_name'  => $name,
						'partner_name'  => '夥伴A',
						'shop_name'     => '夏季賣場',
						'profit_amount' => '1.00',
						'status'        => 'pending',
					],
				]
			);

			$csv = $this->decode( $useCase->execute( SettlementFilterInput::from_array( [] ) ) );

			$this->assertStringContainsString( "'" . $name, $csv, "「{$name}」必須被前置單引號" );
		}
	}

	/**
	 * happy：已刪除的賣場與夥伴以佔位文字匯出（不可讓整列消失）
	 *
	 * @group happy
	 */
	public function test_deleted_shop_and_partner_use_placeholder_text(): void {
		$this->query->seed_rows(
			[
				[
					'order_item_id' => 7001,
					'order_id'      => 8001,
					'product_name'  => '筆記型電腦',
					'partner_name'  => '[已停用夥伴]',
					'shop_name'     => '[已刪除賣場]',
					'actual_price'  => '240.00',
					'profit_amount' => '48.00',
					'status'        => 'pending',
					'order_date'    => '2026-08-01 09:00:00',
				],
			]
		);

		$csv = $this->decode( $this->useCase->execute( SettlementFilterInput::from_array( [] ) ) );

		$this->assertStringContainsString( '[已停用夥伴]', $csv );
		$this->assertStringContainsString( '[已刪除賣場]', $csv );
		$this->assertStringContainsString( '48.00', $csv, '金額已凍結，那筆錢仍然要付給人' );
	}

	/**
	 * edge：篩選結果為空時仍產出只有標題列的檔案，且不視為錯誤
	 *
	 * @group edge
	 */
	public function test_empty_result_still_produces_header_only_csv(): void {
		$this->query->seed_rows( [] );

		$output = $this->useCase->execute( SettlementFilterInput::from_array( [] ) );
		$csv    = $this->decode( $output );

		$lines = array_values( array_filter( explode( "\r\n", ltrim( $csv, "\u{FEFF}" ) ), static fn( $l ): bool => '' !== $l ) );

		$this->assertSame( 0, $output->row_count );
		$this->assertCount( 1, $lines, '只有標題列時不應有任何資料列' );
		$this->assertStringContainsString( '訂單編號', $lines[0] );
	}

	/**
	 * edge：含逗號與引號的名稱依 CSV 規則正確跳脫（可被標準解析器還原）
	 *
	 * @group edge
	 */
	public function test_names_with_comma_and_quote_are_csv_escaped(): void {
		$this->query->seed_rows(
			[
				[
					'order_item_id' => 1,
					'order_id'      => 2,
					'product_name'  => '筆電, 15" 螢幕',
					'partner_name'  => '夥伴A',
					'shop_name'     => '夏季賣場',
					'profit_amount' => '1.00',
					'status'        => 'pending',
				],
			]
		);

		$csv   = $this->decode( $this->useCase->execute( SettlementFilterInput::from_array( [] ) ) );
		$lines = explode( "\r\n", ltrim( $csv, "\u{FEFF}" ) );

		$this->assertSame( '筆電, 15" 螢幕', str_getcsv( $lines[1] )[2], '標準 CSV 解析器應能還原原始欄位值' );
	}

	/**
	 * smoke：檔名含日期與 .csv 副檔名，MIME type 正確
	 *
	 * @group smoke
	 */
	public function test_filename_and_mime_type(): void {
		$this->query->seed_rows( [] );

		$output = $this->useCase->execute( SettlementFilterInput::from_array( [] ) );

		$this->assertStringEndsWith( '.csv', $output->filename );
		$this->assertStringContainsString( gmdate( 'Ymd', self::NOW ), $output->filename, '檔名須含日期以免多次匯出互相覆蓋' );
		$this->assertStringContainsString( 'text/csv', $output->mime_type );
		$this->assertStringContainsString( 'utf-8', $output->mime_type );
	}
}
