<?php
/**
 * Settlement Summary Provider（測試替身）
 *
 * Phase 3-C 紅燈規範：
 *   GetPartnerKpiUseCase / GetPartnerTrendUseCase / ListPartnerSettlementsUseCase
 *   會透過 SettlementRepositoryInterface 取得 summary / series / list，但目前介面
 *   還沒有 summary_for_partner / trend_for_partner 等方法——這是綠燈階段要補的。
 *
 *   本 fake 不 implement SettlementRepositoryInterface（避免汙染 production
 *   介面契約），而是以 spy 形式紀錄被 Use Case 呼叫時的參數。
 *
 *   master 綠燈時應決定：
 *     (a) 在 SettlementRepositoryInterface 加 summary_for_partner / trend_for_partner，或
 *     (b) 抽 SettlementSummaryProvider 介面（推薦），讓 Repository 專注 CRUD。
 *
 *   兩種設計皆可被本 fake 取代。
 */

declare(strict_types=1);

namespace Tests\Support;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementSummaryProviderInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;

/**
 * Settlement summary / trend / list 的測試替身
 */
final class InMemorySettlementSummaryProvider implements SettlementSummaryProviderInterface {

	/**
	 * 預埋：partner_term_id => KpiReport 風格陣列
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $kpi_by_partner = [];

	/**
	 * 預埋：partner_term_id => series 陣列
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $trend_by_partner = [];

	/**
	 * 預埋：partner_term_id => settlement list（list shape 由 Use Case 決定）
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $list_by_partner = [];

	/**
	 * 紀錄被呼叫過的 (method, partner_term_id, criteria) — 驗證 partner_term_id 鎖定行為
	 *
	 * @var array<int, array{method: string, partner_term_id: int, criteria: FilterCriteria|null, extra: array<string, mixed>}>
	 */
	private array $invocations = [];

	// ========== production-shaped methods（master 綠燈時應在 production 暴露相同 signature） ==========

	/**
	 * 回傳指定 partner 的 KPI 概要
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<string, mixed> KpiReport from_array 可吃的形狀
	 */
	public function summary_for_partner( int $partner_term_id, FilterCriteria $criteria ): array {
		$this->invocations[] = [
			'method'          => 'summary_for_partner',
			'partner_term_id' => $partner_term_id,
			'criteria'        => $criteria,
			'extra'           => [],
		];
		return $this->kpi_by_partner[ $partner_term_id ] ?? [
			'total_sales'     => '0.00',
			'profit_pending'  => '0.00',
			'profit_paid'     => '0.00',
			'profit_refunded' => '0.00',
			'period_start'    => '',
			'period_end'      => '',
		];
	}

	/**
	 * 回傳指定 partner 的時間序列
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 * @param string         $interval        粒度（day/week/month）
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function trend_for_partner( int $partner_term_id, FilterCriteria $criteria, string $interval ): array {
		$this->invocations[] = [
			'method'          => 'trend_for_partner',
			'partner_term_id' => $partner_term_id,
			'criteria'        => $criteria,
			'extra'           => [ 'interval' => $interval ],
		];
		return $this->trend_by_partner[ $partner_term_id ] ?? [];
	}

	/**
	 * 回傳指定 partner 的 settlement list
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_partner( int $partner_term_id, FilterCriteria $criteria ): array {
		$this->invocations[] = [
			'method'          => 'find_by_partner',
			'partner_term_id' => $partner_term_id,
			'criteria'        => $criteria,
			'extra'           => [],
		];
		return $this->list_by_partner[ $partner_term_id ] ?? [];
	}

	// ========== test seeding ==========

	/**
	 * 預埋指定 partner 的 KPI
	 *
	 * @param int                  $partner_term_id partner term ID
	 * @param array<string, mixed> $kpi             kpi 陣列
	 */
	public function seed_kpi( int $partner_term_id, array $kpi ): void {
		$this->kpi_by_partner[ $partner_term_id ] = $kpi;
	}

	/**
	 * 預埋指定 partner 的 trend series
	 *
	 * @param int                              $partner_term_id partner term ID
	 * @param array<int, array<string, mixed>> $series          時間序列
	 */
	public function seed_trend( int $partner_term_id, array $series ): void {
		$this->trend_by_partner[ $partner_term_id ] = $series;
	}

	/**
	 * 預埋指定 partner 的 settlement list
	 *
	 * @param int                              $partner_term_id partner term ID
	 * @param array<int, array<string, mixed>> $list            settlement list
	 */
	public function seed_list( int $partner_term_id, array $list ): void {
		$this->list_by_partner[ $partner_term_id ] = $list;
	}

	// ========== assertions helper ==========

	/**
	 * 取得所有呼叫紀錄
	 *
	 * @return array<int, array{method: string, partner_term_id: int, criteria: FilterCriteria|null, extra: array<string, mixed>}>
	 */
	public function invocations(): array {
		return $this->invocations;
	}

	/**
	 * 取得最後一次呼叫
	 *
	 * @return array{method: string, partner_term_id: int, criteria: FilterCriteria|null, extra: array<string, mixed>}|null
	 */
	public function last_invocation(): ?array {
		return $this->invocations[ array_key_last( $this->invocations ) ] ?? null;
	}

	/**
	 * 重置呼叫紀錄
	 */
	public function reset_invocations(): void {
		$this->invocations = [];
	}
}
