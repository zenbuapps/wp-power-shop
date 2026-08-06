<?php
/**
 * 後台結算列表查詢 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementListOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementQueryProviderInterface;

/**
 * 取得後台結算列表（GET profit-settlements）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3
 *
 * ⚠️ `total` 走 `count_settlements()` 另一次查詢，**不是** `count($items)`——
 * `$items` 只有當前頁，用它當 total 會讓前端分頁器永遠只顯示一頁。
 * （`ListPartnerSettlementsUseCase` 目前就是 `count($items)`，那是 partner 報表的既有
 * 行為，不在本次範圍內；兩者不共用同一個 provider，改動不會互相影響。）
 */
final class ListSettlementsUseCase {

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
	 * @return SettlementListOutput
	 */
	public function execute( SettlementFilterInput $filter ): SettlementListOutput {
		$items = $this->query->find_settlements( $filter );
		$total = $this->query->count_settlements( $filter );

		return new SettlementListOutput(
			items: $items,
			total: $total,
			page: $filter->page,
			per_page: $filter->per_page,
		);
	}
}
