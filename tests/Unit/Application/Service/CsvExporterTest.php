<?php
/**
 * CsvExporter 單元測試（公式注入中和）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.9
 *
 * @group profit_shop
 * @group application
 * @group settlement
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CsvExporter;
use PHPUnit\Framework\TestCase;

/**
 * CsvExporter 契約測試
 */
final class CsvExporterTest extends TestCase {

	private CsvExporter $csv;

	protected function setUp(): void {
		parent::setUp();
		$this->csv = new CsvExporter();
	}

	/**
	 * security：spec §6.9 的四個危險前綴一律被前綴單引號中和
	 *
	 * @group security
	 */
	public function test_dangerous_prefixes_are_neutralised(): void {
		$cases = [
			'=HYPERLINK("https://evil.tld","x")' => "'=HYPERLINK(\"https://evil.tld\",\"x\")",
			'+1+1'                               => "'+1+1",
			'-2+3'                               => "'-2+3",
			'@SUM(A1:A9)'                        => "'@SUM(A1:A9)",
		];

		foreach ( $cases as $input => $expected ) {
			$this->assertSame(
				$expected,
				CsvExporter::escape_cell( $input ),
				sprintf( '危險前綴 %s 必須被中和', substr( $input, 0, 1 ) )
			);
		}
	}

	/**
	 * security：前置空白後才出現危險字元同樣要被中和
	 *
	 * Excel 解析公式時會忽略開頭空白，只看第 0 個字元的實作會整條漏掉。
	 *
	 * @group security
	 */
	public function test_dangerous_prefix_after_leading_whitespace_is_neutralised(): void {
		$this->assertSame( "' =1+1", CsvExporter::escape_cell( ' =1+1' ) );
		$this->assertSame( "'\t=1+1", CsvExporter::escape_cell( "\t=1+1" ) );
		$this->assertSame( "'\r=cmd|'/C calc'!A0", CsvExporter::escape_cell( "\r=cmd|'/C calc'!A0" ) );
		$this->assertSame( "'   @SUM(A1)", CsvExporter::escape_cell( '   @SUM(A1)' ) );
	}

	/**
	 * security：中和只加前綴，絕不竄改原始內容
	 *
	 * 若改成把 `=` 刪掉 / 換掉，商品名稱真的叫 `=C= 聯名款` 的站長就會拿到被改壞的對帳檔。
	 *
	 * @group security
	 */
	public function test_neutralisation_preserves_original_text(): void {
		$original = '=C= 聯名款';
		$escaped  = CsvExporter::escape_cell( $original );

		$this->assertSame( "'" . $original, $escaped );
		$this->assertSame( $original, substr( $escaped, 1 ), '單引號之後必須是一字不差的原文' );
	}

	/**
	 * happy：安全的值不會被加上任何前綴
	 *
	 * @group happy
	 */
	public function test_safe_values_are_untouched(): void {
		$this->assertSame( '一般商品名稱', CsvExporter::escape_cell( '一般商品名稱' ) );
		$this->assertSame( '1200.00', CsvExporter::escape_cell( '1200.00' ) );
		$this->assertSame( 'pending', CsvExporter::escape_cell( 'pending' ) );
		$this->assertSame( '', CsvExporter::escape_cell( '' ) );
	}

	/**
	 * edge：null / bool / 陣列 / 物件不得讓匯出爆掉
	 *
	 * @group edge
	 */
	public function test_non_string_inputs_degrade_safely(): void {
		$this->assertSame( '', CsvExporter::escape_cell( null ) );
		$this->assertSame( '1', CsvExporter::escape_cell( true ) );
		$this->assertSame( '0', CsvExporter::escape_cell( false ) );
		$this->assertSame( '', CsvExporter::escape_cell( [ 'a' ] ) );
		$this->assertSame( '', CsvExporter::escape_cell( new \stdClass() ) );
		$this->assertSame( '123', CsvExporter::escape_cell( 123 ) );
	}

	/**
	 * edge：只有空白的字串不應被誤加前綴
	 *
	 * @group edge
	 */
	public function test_whitespace_only_value_is_not_prefixed(): void {
		$this->assertSame( '   ', CsvExporter::escape_cell( '   ' ) );
	}

	/**
	 * security：build() 產出的每一格都經過中和（不是只有 escape_cell 自己會）
	 *
	 * @group security
	 */
	public function test_build_neutralises_every_cell(): void {
		$csv = $this->csv->build(
			[ '商品', '金額' ],
			[
				[ '=HYPERLINK("https://evil.tld","x")', '100.00' ],
				[ '正常商品', '=1+1' ],
			]
		);

		$this->assertStringNotContainsString( ',=HYPERLINK', $csv, '欄位不得以未中和的 = 開頭' );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
		$this->assertStringContainsString( "'=1+1", $csv );
	}

	/**
	 * happy：含逗號 / 雙引號 / 換行的欄位交給 fputcsv 正確跳脫
	 *
	 * @group happy
	 */
	public function test_build_escapes_delimiters_via_fputcsv(): void {
		$csv = $this->csv->build(
			[ '商品' ],
			[
				[ '含,逗號' ],
				[ '含"雙引號' ],
			]
		);

		// fputcsv 會把整格用雙引號包起來，內部雙引號變兩個
		$this->assertStringContainsString( '"含,逗號"', $csv );
		$this->assertStringContainsString( '"含""雙引號"', $csv );
	}

	/**
	 * happy：表頭在第一列，資料列依序在後，換行為 CRLF
	 *
	 * @group happy
	 */
	public function test_build_writes_header_then_rows_with_crlf(): void {
		$csv = $this->csv->build( [ 'A', 'B' ], [ [ '1', '2' ], [ '3', '4' ] ] );

		$this->assertStringStartsWith( "A,B\r\n", $csv );
		$this->assertStringContainsString( "1,2\r\n", $csv );
		$this->assertStringContainsString( "3,4\r\n", $csv );
		$this->assertStringNotContainsString( "\r\r\n", $csv, '不得出現重複的 CR' );
	}

	/**
	 * edge：沒有資料列時仍輸出表頭（站長才知道是「查無資料」而不是「匯出壞了」）
	 *
	 * @group edge
	 */
	public function test_build_with_no_rows_still_writes_header(): void {
		$csv = $this->csv->build( [ 'A', 'B' ], [] );

		$this->assertSame( "A,B\r\n", $csv );
	}
}
