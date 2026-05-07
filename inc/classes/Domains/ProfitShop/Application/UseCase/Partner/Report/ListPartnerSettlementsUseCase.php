<?php
/**
 * Partner 結算列表查詢 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementListOutput;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;

/**
 * 取得指定 Partner 的結算列表（partner-reports/settlements）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3、§7
 *
 * 第一參數 partner_term_id 由 caller（V2Api）從 token 解出，UseCase 不接受從 criteria 帶來。
 */
final class ListPartnerSettlementsUseCase {

	/**
	 * 建構子
	 *
	 * @param object $summary 提供 find_by_partner(int, FilterCriteria): array 的物件
	 */
	public function __construct(
		private readonly object $summary
	) {}

	/**
	 * 執行結算列表查詢
	 *
	 * @param int            $partner_term_id Partner term ID（鎖死，caller 從 token 解出）
	 * @param FilterCriteria $criteria        過濾條件（含分頁參數）
	 *
	 * @return SettlementListOutput
	 */
	public function execute( int $partner_term_id, FilterCriteria $criteria ): SettlementListOutput {
		$items = $this->summary->find_by_partner( $partner_term_id, $criteria );

		return new SettlementListOutput(
			items: $items,
			total: count( $items ),
			page: $criteria->page,
			per_page: $criteria->per_page,
		);
	}
}
