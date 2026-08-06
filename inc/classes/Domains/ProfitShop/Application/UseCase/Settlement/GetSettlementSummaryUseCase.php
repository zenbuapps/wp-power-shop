<?php
/**
 * 後台結算摘要卡 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementAdminSummaryOutput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementQueryProviderInterface;

/**
 * 取得後台結算摘要卡（GET profit-settlements/summary）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5
 *   「摘要卡：待撥款金額（筆數） / 已撥款金額（筆數） / 退款金額」
 *
 * 摘要與列表吃**同一個** `SettlementFilterInput`：站長把篩選條件改成「只看 Partner A
 * 的 4 月」時，摘要卡必須跟著只算那個範圍，否則卡片數字與下方表格對不起來，
 * 而站長是照著卡片的金額去轉帳的。
 *
 * ⚠️ 摘要**忽略 page / per_page**（由 Infrastructure 的 `summarize_settlements()` 保證），
 * 否則翻到第 2 頁時「待撥款總額」會變成只算第 2 頁那 20 筆。
 */
final class GetSettlementSummaryUseCase {

	/**
	 * 建構子
	 *
	 * @param SettlementQueryProviderInterface $query 結算查詢 Provider
	 */
	public function __construct(
		private readonly SettlementQueryProviderInterface $query
	) {}

	/**
	 * 執行查詢
	 *
	 * @param SettlementFilterInput $filter 過濾條件（已於 DTO 層正規化）
	 *
	 * @return SettlementAdminSummaryOutput
	 */
	public function execute( SettlementFilterInput $filter ): SettlementAdminSummaryOutput {
		$raw = $this->query->summarize_settlements( $filter );

		return SettlementAdminSummaryOutput::from_array( $raw );
	}
}
