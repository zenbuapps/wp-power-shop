<?php
/**
 * 分潤訂單 ID 清單輸出 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * 依分潤條件取得的 order_id 清單（供訂單列表過濾用）
 *
 * 對應決策：D-6（訂單列表「分潤賣場」過濾）
 *
 * ## 為什麼需要這個端點
 *
 * WooCommerce REST 的 `orders` 端點**無法依 order item meta 篩選**——分潤歸屬
 * （`_profit_shop_id`）寫在 line item 上，不在訂單本體。因此「只看某個賣場的訂單」
 * 這件事得分兩步：先向本端點拿 order_id 陣列，再把它當成 `wc-rest` 的
 * `include` filter 送出。
 *
 * ## 🔴 `truncated` 必須被前端據實呈現
 *
 * `include` 是 query string 參數，過長會被伺服器截斷或直接拒絕，所以這裡硬性 cap。
 * 被截斷時如果前端只是照常顯示結果，站長會看到一份不完整的訂單列表卻**完全沒有
 * 徵兆**——他會直接得出「這個賣場只有這幾張訂單」的錯誤結論。
 * 因此 `truncated` 為 true 時必須明確提示「結果過多，請縮小篩選範圍」。
 */
final class SettlementOrderIdsOutput {

	/**
	 * 建構子
	 *
	 * @param int[] $order_ids 訂單 ID 陣列（新到舊）
	 * @param int   $total     符合條件的訂單總數（未受 limit 影響）
	 * @param int   $limit     本次套用的上限
	 * @param bool  $truncated 是否因為超過上限而被截斷
	 */
	public function __construct(
		public readonly array $order_ids = [],
		public readonly int $total = 0,
		public readonly int $limit = 0,
		public readonly bool $truncated = false
	) {}

	/**
	 * 從原始陣列建立 DTO
	 *
	 * @param array<string, mixed> $data 原始輸入陣列
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			order_ids: array_values( array_map( 'intval', (array) ( $data['order_ids'] ?? [] ) ) ),
			total: (int) ( $data['total'] ?? 0 ),
			limit: (int) ( $data['limit'] ?? 0 ),
			truncated: (bool) ( $data['truncated'] ?? false ),
		);
	}

	/**
	 * 序列化為陣列（與 from_array 對稱）
	 *
	 * @return array{
	 *   order_ids: int[],
	 *   total: int,
	 *   limit: int,
	 *   truncated: bool
	 * }
	 */
	public function to_array(): array {
		return [
			'order_ids' => $this->order_ids,
			'total'     => $this->total,
			'limit'     => $this->limit,
			'truncated' => $this->truncated,
		];
	}
}
