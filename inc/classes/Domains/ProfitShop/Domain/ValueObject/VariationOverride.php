<?php
/**
 * 變體覆寫 ValueObject
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\ValueObject;

/**
 * 分潤賣場內單一 variation（變體）的覆寫設定
 *
 * 聚合「價格覆寫」與「灌水銷量」兩個面向，作為 OverrideItem::$variations 的 value。
 *
 * 為何不直接把 inflated_count 塞進 PriceOverride：
 * PriceOverride 是純粹的三欄價格 VO，同時被 PriceCalculator 的六層 fallback chain 與
 * ProductSnapshot 使用；混入與價格無關的銷量欄位會污染它在那些場景的語意。
 *
 * 序列化形狀（對應 specs/api/api.yml 的 OverrideItemInput.variations.additionalProperties）：
 *   { "override": { regular_price, sale_price, signup_fee }, "inflated_count": int }
 */
final class VariationOverride {

	/**
	 * 建構子
	 *
	 * 兩個參數都是必填：inflated_count 未設定時由 caller 明確傳入 new InflatedCount( 0 )，
	 * 避免「沒傳」與「傳 0」在讀取端產生語意曖昧。
	 *
	 * @param PriceOverride $override       該變體的價格覆寫
	 * @param InflatedCount $inflated_count 該變體的灌水銷量
	 */
	public function __construct(
		public readonly PriceOverride $override,
		public readonly InflatedCount $inflated_count
	) {}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   override: array{regular_price: string|null, sale_price: string|null, signup_fee: string|null},
	 *   inflated_count: int
	 * }
	 */
	public function to_array(): array {
		return [
			'override'       => $this->override->to_array(),
			'inflated_count' => $this->inflated_count->value(),
		];
	}
}
