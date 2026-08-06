<?php
/**
 * 結算 CSV 匯出輸出 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * 結算 CSV 匯出結果 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.3、§6.9
 *          specs/features/profit-shop/export-settlements-csv.feature
 *
 * ## 為何回傳 base64 而不是直接串流 CSV（決策 D-5）
 *
 * 後台端點一律回 `{code, message, data}` 外層，前端讀取回應時依賴這個固定形狀
 * （`react.rule.md` §3 的 `useCustom` 要剝兩層）。改成串流原始 CSV 會讓這條路徑
 * 變成唯一的例外，前端得為單一端點另開一條 axios 通道。
 *
 * 另一個實際好處：錯誤（權限不足、篩選條件非法）能以一般的 JSON 錯誤格式回應，
 * 而不是讓使用者下載到一個內容其實是錯誤訊息的 `.csv`——那種檔案在 Excel 裡
 * 打開只會看到一堆亂碼，站長無從得知發生了什麼事。
 *
 * ## 為何是 base64 而不是原始字串
 *
 * CSV 內容開頭有 UTF-8 BOM（`\u{FEFF}`），中間可能有 `\r\n` 與引號跳脫。
 * 這些位元組在 JSON 編碼 / 解碼、以及各層 proxy 的字元集轉換中都可能被改動，
 * 而 BOM 只要少一個位元組，Excel 就會以系統預設編碼（繁中 Windows 是 Big5）開啟，
 * 中文欄位整片亂碼。base64 讓位元組原封不動地送達，前端 `atob()` 後直接組 Blob。
 */
final class SettlementExportOutput {

	/**
	 * 建構子
	 *
	 * @param string $filename       建議檔名（已含 .csv 副檔名與日期）
	 * @param string $mime_type      MIME type
	 * @param string $content_base64 CSV 全文的 base64（解碼後含 UTF-8 BOM 與表頭）
	 * @param int    $row_count      資料列數（不含表頭）
	 */
	public function __construct(
		public readonly string $filename,
		public readonly string $mime_type,
		public readonly string $content_base64,
		public readonly int $row_count
	) {}

	/**
	 * 以未編碼的 CSV 原文建立 DTO（編碼在此集中處理，呼叫端不必記得要 base64）
	 *
	 * @param string $filename  建議檔名
	 * @param string $csv       CSV 全文（含 BOM）
	 * @param int    $row_count 資料列數
	 *
	 * @return self
	 */
	public static function from_csv( string $filename, string $csv, int $row_count ): self {
		return new self(
			filename: $filename,
			mime_type: 'text/csv; charset=utf-8',
			content_base64: base64_encode( $csv ),
			row_count: $row_count,
		);
	}

	/**
	 * 從原始陣列建立 DTO
	 *
	 * @param array<string, mixed> $data 原始輸入陣列
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			filename: (string) ( $data['filename'] ?? 'settlements.csv' ),
			mime_type: (string) ( $data['mime_type'] ?? 'text/csv; charset=utf-8' ),
			content_base64: (string) ( $data['content_base64'] ?? '' ),
			row_count: (int) ( $data['row_count'] ?? 0 ),
		);
	}

	/**
	 * 序列化為陣列（與 from_array 對稱）
	 *
	 * @return array{
	 *   filename: string,
	 *   mime_type: string,
	 *   content_base64: string,
	 *   row_count: int
	 * }
	 */
	public function to_array(): array {
		return [
			'filename'       => $this->filename,
			'mime_type'      => $this->mime_type,
			'content_base64' => $this->content_base64,
			'row_count'      => $this->row_count,
		];
	}
}
