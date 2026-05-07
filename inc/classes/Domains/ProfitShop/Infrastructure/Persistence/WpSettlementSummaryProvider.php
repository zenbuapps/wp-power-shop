<?php
/**
 * Settlement Summary Provider WP 實作（Phase 3-C placeholder）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementSummaryProviderInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;

/**
 * Partner Report 用的 WP 實作（Phase 3-C placeholder）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3、§5、§7、§8
 *
 * 實作策略 / Hand-off 紀錄：
 *   - Phase 3-C 重點在認證與 partner_term_id 範圍鎖死，**不進** wc_order_itemmeta 真實聚合。
 *   - 本 placeholder 回 0 / 空陣列，讓 V2Api report 端點能成功 200，並通過範圍隔離測試
 *     （PartnerReportsScopeTest 只檢查不洩漏到 partner B 的資料；空 list 自然滿足）。
 *   - 真實 SQL（JOIN wc_order_itemmeta、彙總、time-series buckets）待 Phase 3-D
 *     落地。屆時將以 $wpdb->prepare 拼 query，用 partner_term_id 過濾，依 FilterCriteria
 *     的 statuses / shop_ids / date_start / date_end 加 WHERE。
 *
 * 安全：partner_term_id 永遠以參數注入，不從 SQL 拼字串。
 */
final class WpSettlementSummaryProvider implements SettlementSummaryProviderInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 預設 KPI（無資料時）
	 *
	 * @return array<string, string>
	 */
	private static function empty_kpi(): array {
		return [
			'total_sales'     => '0.00',
			'profit_pending'  => '0.00',
			'profit_paid'     => '0.00',
			'profit_refunded' => '0.00',
			'period_start'    => '',
			'period_end'      => '',
		];
	}

	/**
	 * 取得指定 partner 的 KPI 概要（Phase 3-C placeholder：永遠回 0）
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<string, mixed>
	 */
	public function summary_for_partner( int $partner_term_id, FilterCriteria $criteria ): array {
		// Phase 3-C：回 0；Phase 3-D 將以 wc_order_itemmeta JOIN 計算
		return self::empty_kpi();
	}

	/**
	 * 取得指定 partner 的時間序列（Phase 3-C placeholder：永遠回空）
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 * @param string         $interval        粒度
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function trend_for_partner( int $partner_term_id, FilterCriteria $criteria, string $interval ): array {
		return [];
	}

	/**
	 * 取得指定 partner 的 settlement list（Phase 3-C placeholder：永遠回空）
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_partner( int $partner_term_id, FilterCriteria $criteria ): array {
		return [];
	}
}
