<?php
/**
 * Partner KPI 查詢 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\KpiReport;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementSummaryProviderInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;

/**
 * 取得指定 Partner 的 KPI 概要（partner-reports/kpi）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§7
 *
 * **核心 invariant（Hand-off 必看）**：
 *   `execute()` 第一個參數必須是 caller（V2Api）從 token 解出的 partner_term_id；
 *   絕不從 input/criteria 取，避免 attacker 透過 query string 跨 partner 查詢。
 *   FilterCriteria 設計上**沒有 partner_term_id 屬性**——這由 PartnerReportScopeIsolationTest 鎖定。
 *
 * Provider 介面採 nominal interface（SettlementSummaryProviderInterface）；
 * Production: WpSettlementSummaryProvider；Test: Tests\Support\InMemorySettlementSummaryProvider 同樣 implements 此 interface。
 */
final class GetPartnerKpiUseCase {

	/**
	 * 建構子
	 *
	 * @param SettlementSummaryProviderInterface $summary 結算彙總 Provider
	 */
	public function __construct(
		private readonly SettlementSummaryProviderInterface $summary
	) {}

	/**
	 * 執行 KPI 查詢
	 *
	 * @param int            $partner_term_id Partner term ID（由 controller 從 token 解出，**鎖死**）
	 * @param FilterCriteria $criteria        過濾條件（不含 partner_term_id，禁止）
	 *
	 * @return KpiReport
	 */
	public function execute( int $partner_term_id, FilterCriteria $criteria ): KpiReport {
		$raw = $this->summary->summary_for_partner( $partner_term_id, $criteria );

		return KpiReport::from_array( $raw );
	}
}
