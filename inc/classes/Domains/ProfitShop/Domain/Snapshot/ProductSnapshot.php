<?php
/**
 * 商品資訊快照（給 Domain 層使用，不依賴 WC）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Snapshot;

/**
 * Domain 層的商品資訊 DTO。
 *
 * Infrastructure 層負責從 WC_Product 映射到此 DTO，
 * 讓 Domain Service 維持純粹（不依賴 WC / WP 函式）。
 */
final class ProductSnapshot {

	/**
	 * 建構子
	 *
	 * @param int                                                                                        $id            商品 ID。
	 * @param string                                                                                     $regular_price 原價（十進位字串）。
	 * @param string|null                                                                                $sale_price    特價（十進位字串，nullable）。
	 * @param string|null                                                                                $signup_fee    訂閱首期費用（十進位字串，nullable）。
	 * @param array<int, array{regular_price: string, sale_price: string|null, signup_fee: string|null}> $variations    Variation 對照表（variation_id => 價格資訊）。
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $regular_price,
		public readonly ?string $sale_price = null,
		public readonly ?string $signup_fee = null,
		public readonly array $variations = []
	) {
	}
}
