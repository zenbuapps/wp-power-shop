<?php
/**
 * Partner KPI 查詢 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\KpiReport;
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
 * Provider 介面相容性：
 *   ctor 期望注入 SettlementSummaryProviderInterface 實作（Production: WpSettlementSummaryProvider；
 *   Test: Tests\Support\InMemorySettlementSummaryProvider）。後者並未 nominal implement 此介面（為避免汙染
 *   production），但其 method shape 與 interface 一致，PHP duck-typing via `object` parameter 即可。
 */
final class GetPartnerKpiUseCase {

	/**
	 * 建構子
	 *
	 * @param object $summary 提供 summary_for_partner(int, FilterCriteria): array 的物件（implements SettlementSummaryProviderInterface 或測試替身）
	 */
	public function __construct(
		private readonly object $summary
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
