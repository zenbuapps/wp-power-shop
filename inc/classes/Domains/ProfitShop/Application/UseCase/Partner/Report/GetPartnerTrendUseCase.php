<?php
/**
 * Partner 趨勢查詢 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\TrendReport;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementSummaryProviderInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;

/**
 * 取得指定 Partner 的時間序列（partner-reports/trend）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§7
 *
 * 第一參數 partner_term_id 由 caller（V2Api）從 token 解出，UseCase 不接受從 criteria 帶來。
 */
final class GetPartnerTrendUseCase {

	/**
	 * 建構子
	 *
	 * @param SettlementSummaryProviderInterface $summary 結算彙總 Provider
	 */
	public function __construct(
		private readonly SettlementSummaryProviderInterface $summary
	) {}

	/**
	 * 執行趨勢查詢
	 *
	 * @param int            $partner_term_id Partner term ID（鎖死，caller 從 token 解出）
	 * @param FilterCriteria $criteria        過濾條件
	 * @param string         $interval        粒度（day/week/month）
	 *
	 * @return TrendReport
	 */
	public function execute( int $partner_term_id, FilterCriteria $criteria, string $interval ): TrendReport {
		$series = $this->summary->trend_for_partner( $partner_term_id, $criteria, $interval );

		return new TrendReport( series: $series );
	}
}
