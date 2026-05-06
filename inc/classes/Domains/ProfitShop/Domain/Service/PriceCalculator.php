<?php
/**
 * 結帳價格計算服務（Fallback chain）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\ProductSnapshot;

/**
 * 計算 line item 在分潤賣場內的實際結帳價。
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.3
 *
 * 結帳價 Fallback chain（calculate）：
 *   1. variation override sale_price
 *   2. parent override sale_price
 *   3. variation original sale_price
 *   4. parent original sale_price
 *   5. variation original regular_price
 *   6. parent original regular_price
 *
 * 訂閱首期費 Fallback chain（calculate_signup_fee）：
 *   1. variation override signup_fee
 *   2. parent override signup_fee
 *   3. variation original signup_fee
 *   4. parent original signup_fee
 *   （全 null 回 null）
 */
final class PriceCalculator {

	/**
	 * 計算結帳價
	 *
	 * @param OverrideItem    $item         分潤賣場內的商品覆寫項目。
	 * @param ProductSnapshot $product      商品資訊快照。
	 * @param int|null        $variation_id Variation ID（null 表示非變體商品或不指定變體）。
	 *
	 * @return string 十進位字串格式的結帳價。
	 */
	public function calculate(
		OverrideItem $item,
		ProductSnapshot $product,
		?int $variation_id = null
	): string {
		$variation_override = $variation_id !== null
		? $item->get_variation_override( $variation_id )
		: null;

		$variation_original = $variation_id !== null
		? ( $product->variations[ $variation_id ] ?? null )
		: null;

		// Layer 1: variation override sale_price.
		if ( $variation_override !== null && $variation_override->sale_price !== null ) {
			return $variation_override->sale_price;
		}

		// Layer 2: parent override sale_price.
		if ( $item->override->sale_price !== null ) {
			return $item->override->sale_price;
		}

		// Layer 3: variation original sale_price.
		if ( $variation_original !== null && ( $variation_original['sale_price'] ?? null ) !== null ) {
			return $variation_original['sale_price'];
		}

		// Layer 4: parent original sale_price.
		if ( $product->sale_price !== null ) {
			return $product->sale_price;
		}

		// Layer 5: variation original regular_price.
		if ( $variation_original !== null ) {
			return $variation_original['regular_price'];
		}

		// Layer 6: parent original regular_price.
		return $product->regular_price;
	}

	/**
	 * 計算訂閱首期費用（signup fee）
	 *
	 * @param OverrideItem    $item         分潤賣場內的商品覆寫項目。
	 * @param ProductSnapshot $product      商品資訊快照。
	 * @param int|null        $variation_id Variation ID（null 表示非變體商品或不指定變體）。
	 *
	 * @return string|null 十進位字串格式的 signup fee；若所有層皆 null 則回傳 null。
	 */
	public function calculate_signup_fee(
		OverrideItem $item,
		ProductSnapshot $product,
		?int $variation_id = null
	): ?string {
		$variation_override = $variation_id !== null
		? $item->get_variation_override( $variation_id )
		: null;

		$variation_original = $variation_id !== null
		? ( $product->variations[ $variation_id ] ?? null )
		: null;

		// Layer 1: variation override signup_fee.
		if ( $variation_override !== null && $variation_override->signup_fee !== null ) {
			return $variation_override->signup_fee;
		}

		// Layer 2: parent override signup_fee.
		if ( $item->override->signup_fee !== null ) {
			return $item->override->signup_fee;
		}

		// Layer 3: variation original signup_fee.
		if ( $variation_original !== null && ( $variation_original['signup_fee'] ?? null ) !== null ) {
			return $variation_original['signup_fee'];
		}

		// Layer 4: parent original signup_fee.
		return $product->signup_fee;
	}
}
