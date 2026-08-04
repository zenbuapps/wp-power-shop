<?php
/**
 * 賣場覆寫價格解析器（cart 與單品頁的唯一取價真相）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;

/**
 * 把 OverrideItem（+ 可選的 variation_id）解析成 {price, regular, sale} 三元組
 *
 * ⚠️ 這個類別存在的唯一理由是「結構上讓兩條取價路徑無法分歧」：
 *   - SingleProductPriceHook（WC 單品頁顯示價）
 *   - CartPriceOverrideHook（購物車實收價與劃線價）
 * 兩者以前各自實作一份 fallback chain，靠註解要求「語意必須逐字一致」——
 * 這個約定失敗過：cart 端的版本只回傳單一價格（?string），結構上丟失了「原價覆寫」
 * 這個資訊，於是購物車的劃線原價一直顯示商品的**原始** regular_price，
 * 而單品頁顯示的是覆寫後的原價。兩邊都「正常運作」、沒有任何錯誤訊息。
 *
 * 因此：**任何新的取價需求都應該呼叫本類，不要再複製一份 fallback chain。**
 *
 * ⚠️ Fallback 語意（不可改成逐欄混用）：
 *   變體只要有**任一**覆寫欄位（原價或特價），就**整組**採用變體層，
 *   **不與商品層逐欄混用**。若改成逐欄 fallback，會出現
 *   「單品頁顯示商品層特價、購物車實收變體層原價」這種價格跳動——
 *   那正是本類要消滅的問題。
 *
 * 純 Domain 服務：不依賴 WordPress / WooCommerce 任何函式，
 * 可在 tests/Unit/Domain/ 不啟 WP 直接測試。
 */
final class OverridePriceResolver {

	/**
	 * 解析價格三元組
	 *
	 * 回傳值語意：
	 *   - price：實際生效的價格（特價優先，否則原價），必為非 null 字串
	 *   - regular：覆寫後的原價；null 表示「不覆寫原價」（沿用商品原本的原價）
	 *   - sale：覆寫後的特價；null 表示「沒有特價」（呼叫端應清掉商品原本的特價，
	 *           否則會出現「原價劃掉 → 特價」但實收卻是覆寫原價的矛盾）
	 *
	 * @param OverrideItem $item         覆寫項目
	 * @param int|null     $variation_id 變體 ID；null 表示父商品 / 一般商品
	 *
	 * @return array{price: string, regular: string|null, sale: string|null}|null
	 *         該項目完全沒有任何覆寫時回 null（代表不介入，商品走原價）
	 */
	public function resolve( OverrideItem $item, ?int $variation_id ): ?array {
		if ( null !== $variation_id ) {
			$variation_override = $item->get_variation_override( $variation_id );
			if (
				null !== $variation_override
				&& ( null !== $variation_override->sale_price || null !== $variation_override->regular_price )
			) {
				return [
					'price'   => (string) ( $variation_override->sale_price ?? $variation_override->regular_price ),
					'regular' => $variation_override->regular_price,
					'sale'    => $variation_override->sale_price,
				];
			}
		}

		$parent_override = $item->override;
		if ( null !== $parent_override->sale_price || null !== $parent_override->regular_price ) {
			return [
				'price'   => (string) ( $parent_override->sale_price ?? $parent_override->regular_price ),
				'regular' => $parent_override->regular_price,
				'sale'    => $parent_override->sale_price,
			];
		}

		return null;
	}
}
