<?php
/**
 * Settlement Summary Provider 抽象介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;

/**
 * Partner Report 用的彙總／趨勢／清單查詢介面（Port）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §6.3
 *
 * 設計動機（Hand-off 紀錄）：
 *   - SettlementRepositoryInterface 專注 SettlementRecord CRUD（find_by_order_item / save / find_by_partner / find_by_shop）。
 *   - Partner Report 端點需要的是預聚合過的 KPI / time-series / paginated list（含分頁中介資料），與
 *     CRUD repository 職責不同——故抽出 SettlementSummaryProvider 獨立介面（避免 Repository 膨脹）。
 *   - Phase 3-C 僅實裝 Domain port（讓 UseCase / 測試替身可以共用同一形狀）；具體 WP 實作（SQL JOIN
 *     wc_order_itemmeta）留待 Phase 3-D 完成。Phase 3-C V2Api report 端點以「無資料時回 0/空陣列」
 *     的 placeholder 行為先讓紅燈過。
 *
 * 形狀與 Tests\Support\InMemorySettlementSummaryProvider 對齊（紅燈合約鎖定）。
 */
interface SettlementSummaryProviderInterface {

	/**
	 * 回傳指定 partner 的 KPI 概要
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<string, mixed> KpiReport::from_array 可吃的形狀（total_sales / profit_pending / profit_paid / profit_refunded / period_start / period_end）
	 */
	public function summary_for_partner( int $partner_term_id, FilterCriteria $criteria ): array;

	/**
	 * 回傳指定 partner 的時間序列
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 * @param string         $interval        粒度（day/week/month）
	 *
	 * @return array<int, array<string, mixed>> 每筆含 date / profit / sales 等欄位
	 */
	public function trend_for_partner( int $partner_term_id, FilterCriteria $criteria, string $interval ): array;

	/**
	 * 回傳指定 partner 的 settlement list（含分頁所需資訊由 caller 從 criteria 推算）
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<int, array<string, mixed>> 每筆 settlement 的鍵值陣列
	 */
	public function find_by_partner( int $partner_term_id, FilterCriteria $criteria ): array;
}
