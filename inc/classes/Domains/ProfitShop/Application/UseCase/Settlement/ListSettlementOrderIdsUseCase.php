<?php
/**
 * 分潤訂單 ID 清單 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementOrderIdsOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementQueryProviderInterface;

/**
 * 取得符合分潤條件的 order_id 清單（GET profit-settlements/order-ids）
 *
 * 對應決策：D-6（訂單列表「分潤賣場」過濾）
 *
 * WooCommerce REST 的 `orders` 端點無法依 order item meta 篩選，因此前端得先向本端點
 * 取回 order_id 陣列，再當成 `wc-rest` 的 `include` filter 送出。
 *
 * ## 🔴 上限與截斷回報
 *
 * `include` 走 query string，過長會被伺服器截斷或拒絕，因此硬性 cap 在
 * `DEFAULT_LIMIT`。**被截斷時必須誠實回報**（`truncated = true`）：
 * 靜默截斷會讓站長看到一份不完整的訂單列表卻毫無徵兆，直接得出
 * 「這個賣場只有這幾張訂單」的錯誤結論。
 *
 * `total` 走另一次 count 查詢而非 `count($order_ids)`——後者在截斷時等於 limit，
 * 那樣 `truncated` 就永遠算不出來。
 */
final class ListSettlementOrderIdsUseCase {

	/**
	 * 回傳筆數上限（v1 保守值，見 class docblock）
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 200;

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
	 * @param SettlementFilterInput $filter 過濾條件（page / per_page 會被忽略）
	 * @param int|null              $limit  上限（null = DEFAULT_LIMIT；一律 clamp 到 1..DEFAULT_LIMIT）
	 *
	 * @return SettlementOrderIdsOutput
	 */
	public function execute( SettlementFilterInput $filter, ?int $limit = null ): SettlementOrderIdsOutput {
		// 上限只能變小、不能變大——否則呼叫端傳個大數字就繞過了 query string 長度的保護。
		$effective_limit = null === $limit
		? self::DEFAULT_LIMIT
		: max( 1, min( self::DEFAULT_LIMIT, $limit ) );

		$order_ids = $this->query->find_order_ids( $filter, $effective_limit );
		$total     = $this->query->count_order_ids( $filter );

		return new SettlementOrderIdsOutput(
			order_ids: $order_ids,
			total: $total,
			limit: $effective_limit,
			truncated: $total > $effective_limit,
		);
	}
}
