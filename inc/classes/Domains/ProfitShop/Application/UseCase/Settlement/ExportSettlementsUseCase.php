<?php
/**
 * 結算 CSV 匯出 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementExportOutput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ClockInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\CsvExporter;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementQueryProviderInterface;

/**
 * 把過濾後的結算明細匯出成 CSV（POST profit-settlements/export）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3、§6.9
 *          specs/features/profit-shop/export-settlements-csv.feature
 *
 * ## 匯出的是「整個篩選結果」而不是「當前這一頁」
 *
 * 站長按匯出是為了拿去對帳，只給他螢幕上那 20 筆沒有意義。因此本 UseCase
 * **忽略傳入 filter 的 page**，自己從第 1 頁開始逐塊抓，直到抓完為止。
 *
 * 規格明訂「系統一次產出整份檔案，不提供背景排程或分批下載」，因此這裡
 * **沒有業務上的筆數上限**——`MAX_PAGES` 純粹是防止 Provider 行為異常時無限迴圈的
 * 保險絲（100 萬列），不是給站長的配額。逐塊抓取是為了避免單次 SQL 拉回巨量結果集，
 * 與「限制匯出筆數」是兩回事。
 *
 * ⚠️ 已知取捨：整份檔案在記憶體中組完才回傳，極大量資料仍有 memory_limit 風險。
 * 規格刻意把分批機制排除在 v1 之外，若日後站台資料量成長到會觸發，需要的是
 * 背景排程 + 檔案下載，而不是靜默截斷（截斷會讓站長拿著少了幾百筆的明細去轉帳）。
 *
 * ## 欄位（9 欄，順序與標題由規格逐字鎖定）
 *
 * 訂單編號／訂單品項編號／商品名稱／分潤夥伴／分潤賣場／實際單價／應分潤金額／結算狀態／訂單日期
 *
 * 其中「訂單品項編號」是撥款動作的鍵——站長對帳後要能用它回頭操作；
 * 「訂單日期」是對帳的基本維度。缺這兩欄的 CSV 對帳時等於不可用。
 *
 * ## 公式注入
 *
 * 每一格都經 `CsvExporter::escape_cell()` 中和（spec §6.9）。
 * 🔴 **商品名稱、分潤夥伴、分潤賣場三欄都是使用者可輸入的字串**，三個都要跳脫——
 * 只跳脫商品名稱的話，攻擊者改用賣場名稱即可。（`CsvExporter::build()` 對每一格
 * 無差別跳脫，所以這件事在結構上不會漏，但欄位增修時仍要記得這個理由。）
 */
final class ExportSettlementsUseCase {

	/**
	 * 每次向 Provider 取的筆數
	 *
	 * 與 `SettlementFilterInput::MAX_PER_PAGE` 對齊——`with_pagination()` 會 clamp，
	 * 設更大也不會生效。
	 *
	 * @var int
	 */
	public const CHUNK_SIZE = 100;

	/**
	 * 迴圈保險絲（不是業務配額，見 class docblock）
	 *
	 * @var int
	 */
	public const MAX_PAGES = 10000;

	/**
	 * CSV 表頭
	 *
	 * 🔴 順序與字面值由 `export-settlements-csv.feature`
	 * 「Rule: 後置（欄位）- 欄位順序與標題固定」逐字鎖定，改動前先改規格。
	 * `build_row()` 的欄位順序必須與此完全一致。
	 *
	 * @var string[]
	 */
	private const HEADER = [
		'訂單編號',
		'訂單品項編號',
		'商品名稱',
		'分潤夥伴',
		'分潤賣場',
		'實際單價',
		'應分潤金額',
		'結算狀態',
		'訂單日期',
	];

	/**
	 * 建構子
	 *
	 * @param SettlementQueryProviderInterface $query 結算查詢 Provider
	 * @param CsvExporter                      $csv   CSV 產生器（含公式注入中和）
	 * @param ClockInterface                   $clock 時鐘（用於組檔名）
	 */
	public function __construct(
		private readonly SettlementQueryProviderInterface $query,
		private readonly CsvExporter $csv,
		private readonly ClockInterface $clock
	) {}

	/**
	 * 執行匯出
	 *
	 * @param SettlementFilterInput $filter 過濾條件（page 會被忽略，由本方法自行翻頁）
	 *
	 * @return SettlementExportOutput
	 */
	public function execute( SettlementFilterInput $filter ): SettlementExportOutput {
		$rows = [];

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$chunk = $this->query->find_settlements( $filter->with_pagination( $page, self::CHUNK_SIZE ) );

			foreach ( $chunk as $item ) {
				$rows[] = self::build_row( is_array( $item ) ? $item : [] );
			}

			// 不足一整塊代表已經是最後一頁。
			if ( count( $chunk ) < self::CHUNK_SIZE ) {
				break;
			}
		}

		// UTF-8 BOM：少了它 Excel 會以系統預設編碼開啟，中文欄位整片亂碼。
		$csv_body = "\u{FEFF}" . $this->csv->build( self::HEADER, $rows );

		return SettlementExportOutput::from_csv(
			sprintf( 'profit-settlements-%s.csv', gmdate( 'Ymd-His', $this->clock->now() ) ),
			$csv_body,
			count( $rows )
		);
	}

	/**
	 * 把 Provider 回傳的一筆資料轉成 CSV 欄位（順序需與 HEADER 一致）
	 *
	 * 夥伴 / 賣場欄輸出的是**名稱**而非 ID——站長對帳看的是「付給誰」。
	 * 已刪除者由 Provider 填入 `[已停用夥伴]` / `[已刪除賣場]` 佔位文字，
	 * 那筆錢仍然要付給人，不可讓整列消失。
	 *
	 * @param array<string, mixed> $item Provider 回傳的單筆資料
	 *
	 * @return array<int, mixed>
	 */
	private static function build_row( array $item ): array {
		return [
			$item['order_id'] ?? '',
			$item['order_item_id'] ?? '',
			$item['product_name'] ?? '',
			$item['partner_name'] ?? '',
			$item['shop_name'] ?? '',
			$item['actual_price'] ?? '',
			$item['profit_amount'] ?? '',
			$item['status'] ?? '',
			self::to_date( (string) ( $item['order_date'] ?? '' ) ),
		];
	}

	/**
	 * 把訂單建立時間正規化成 `Y-m-d`
	 *
	 * Provider 回的是 `Y-m-d H:i:s`（GMT），但對帳只看日期；規格的資料列範例
	 * 也是 `2026-08-01` 這種純日期形式。
	 *
	 * @param string $raw 原始日期字串
	 *
	 * @return string
	 */
	private static function to_date( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		return substr( $raw, 0, 10 );
	}
}
