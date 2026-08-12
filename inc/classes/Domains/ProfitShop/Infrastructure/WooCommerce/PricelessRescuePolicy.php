<?php
/**
 * 「無定價商品能不能靠賣場覆寫價取得價格」的唯一政策（R-4 裁決）
 *
 * ═══ 為什麼要有這個 class ═══
 *
 * 它存在的理由與 Domain\Service\OverridePriceResolver 完全相同：
 * **結構上讓兩條路徑無法分歧**。
 *
 * R-4 第一次實作時只放在 OverridePriceFilters::resolve_for_product()（顯示層），
 * CartPriceOverrideHook::add_cart_item_data()（加購層）完全沒有這道判斷——後者走
 * OverridePriceResolver 直呼，而該 resolver 照 Phase 13 契約會 fallback 到商品層覆寫，
 * 於是對「半成品變體」一樣解得出價格 → arm_pending_add() 開旗標 → 閘門 ② 放行。
 *
 * 實測後果（安全 / 回歸 / 真實站台三個 lens 各自獨立驗到同一件事）：
 * 賣場只設**商品層**覆寫 500/450、某個變體自身 `_price` 為空時，
 * 前台卡片正確顯示「暫無可購買規格」且 `data-ps-disabled="1"`，
 * 但 `POST /?wc-ajax=add_to_cart` 帶 `product_id={半成品變體}` **加購成功**，
 * 帶合法簽章、price=450 / regular=500，重載 /cart/ 後該列仍在。
 * ⇒ 站長從未定價、前台永遠不顯示、因此**在自己的賣場頁上看不到**的 SKU
 *   會產生真實訂單、需要出貨。（specs/tech-debt.md TD-56）
 *
 * ═══ 🔴 但「共用同一份」還不夠：判準必須與 OverridePriceResolver 對齊 ═══
 *
 * 抽成本 class 之後仍出過一次事：本 class 用「變體條目**存在**」判斷，
 * 而真正決定價格的 OverridePriceResolver 用「條目存在**且有 ≥1 個非 null 價格**」——
 * 於是「全 null 空條目」的變體被政策放行、再被 resolver fallback 到商品層覆寫，
 * 正是 R-4 / S-1 要禁止的那個組合。詳見 has_variation_price_override() 的 docblock。
 *
 * ═══ 🔴 絕不可把這段邏輯搬進 OverridePriceResolver ═══
 *
 * 那是 Phase 13 的唯一取價真相：搬進去會同時改掉購物車的 fallback 語意，讓
 * 「已經合法存在、變體自身無定價但吃商品層覆寫」的既有 cart item 突然失去價格；
 * 而且它是純 Domain 服務（tests/Unit/Domain/ 不啟 WP 直接測），不該知道 WC 的 get_price()。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;

/**
 * R-4 政策：無定價商品是否允許被賣場覆寫價「救起來」
 */
final class PricelessRescuePolicy {

	/**
	 * 這個商品是否允許套用賣場覆寫價
	 *
	 * 🔴 回傳 true **不代表**「正在救援」——商品自身本來就有價格時也回 true，
	 *    意思是「這道政策不阻擋」，Phase 13 既有的 fallback 語意一個字都沒有變。
	 *    只有唯一一種組合回 false：**變體**自身無定價 **且** 沒有變體層覆寫。
	 *
	 * 為什麼一般（非變體）商品即使無定價也放行：站長把它加進賣場並設了覆寫價，
	 * 是明確的逐商品意圖，沒有「被順帶上架」的第三方。而站長在賣場設「商品層」覆寫
	 * 的意圖是「這個商品在我的賣場賣 X 元」，不會預期連沒填價格的半成品變體都一起上架
	 * ——ItemsEditor 的展開列本來就提供逐變體設定的入口，要救就明確設變體層覆寫，
	 * 操作與結果一一對應。
	 *
	 * 三條相依（改動前先確認仍成立）：
	 *   1. get_price('edit') 不會遞迴回任何價格 filter：
	 *      includes/abstracts/abstract-wc-data.php 的 get_prop() 只在 'view' context
	 *      才 apply_filters，'edit' 直接回原始值。
	 *   2. 「有沒有變體層覆寫**價**」的判準必須與 OverridePriceResolver:54-57 逐字相同，
	 *      見下方 has_variation_price_override() 的 docblock。
	 *   3. 呼叫端傳進來的必須是「實際會被定價的那個物件」——cart 端是
	 *      `variation_id ?: product_id` 載入的商品（那才是 includes/class-wc-cart.php:1229
	 *      `! $product_data->is_purchasable()` 檢查的物件），不是父商品。
	 *
	 * @param \WC_Product  $product 要判斷的商品／變體
	 * @param OverrideItem $item    該商品在賣場內的覆寫項目
	 *
	 * @return bool 允許套用覆寫價為 true
	 */
	public static function should_rescue_priceless( \WC_Product $product, OverrideItem $item ): bool {
		if ( '' !== (string) $product->get_price( 'edit' ) ) {
			// 商品自身有價格 ⇒ 根本不是救援情境，這道政策不介入。
			return true;
		}

		if ( $product instanceof \WC_Product_Variation ) {
			return self::has_variation_price_override( $item, (int) $product->get_id() );
		}

		return true;
	}

	/**
	 * 該變體是否真的有「變體層覆寫價」
	 *
	 * ═══ 🔴 條目存在 ≠ 有變體層覆寫價 ═══
	 *
	 * `OverrideItem::get_variation_override()` 回的是 `$this->variations[$vid]?->override`
	 * ——**只要那個鍵存在就回一個 PriceOverride 物件**，即使三個價格欄位全是 null。
	 * 因此「條目存在」這個判準會把「全 null 空條目」誤判成「有覆寫」。
	 *
	 * 空條目不需要竄改就會出現，是站長的正常後台操作產物：
	 * `ItemsEditor.tsx` 的 handlePatchVariationInflatedCount 在站長替某個變體設定
	 * **灌水購買人數**時，會用 `emptyVariationOverride()`（三個價格皆 null）建出條目；
	 * handlePatchVariationOverride 在站長「打了價格又清空」時同樣留下空條目。
	 * 送出路徑（`Edit/index.tsx` 原樣送 `variations`）與後端 `ItemValidator`
	 * （只驗變體存在且屬於該商品）都不會清掉它——「設了灌水人數但不覆寫價格」
	 * 本來就是**合法狀態**，要修的是判準，不是資料。
	 *
	 * 實測後果（判準對齊前）：variable 商品兩個變體皆無定價、賣場只設**商品層**覆寫
	 * 500/450、變體[0] 帶一個空條目 ⇒ 政策放行 ⇒ OverridePriceResolver 依 Phase 13
	 * 契約 fallback 到商品層 ⇒ 該變體以 NT$450 加購成功、帶合法 v3 簽章、跨請求存活；
	 * 前台卡片 `data-ps-disabled="0"` 可點、Modal 照印、該變體可選——顧客不需要偽造
	 * 任何請求，賣場頁自己就送上門。淨效果與 S-1 相同：站長從未定價的 SKU 變成可下單。
	 *
	 * ═══ 這次的教訓（寫給下一個要抽共用 helper 的人）═══
	 *
	 * 上一輪把兩個呼叫端（顯示層 OverridePriceFilters、加購層 CartPriceOverrideHook）
	 * 的行內判斷抽成本 class，確實消滅了「兩個呼叫端各寫一份」的分歧——
	 * **但從未與「真正決定行為的那個下游」（OverridePriceResolver）對齊**。
	 * 政策說「可以救」，resolver 卻用另一套判準決定「救成什麼價」，
	 * 於是兩份定義還在，只是換了位置繼續放著。
	 * 抽共用 helper 時，要對齊的不是「其他呼叫端」，而是**這個判斷最終服務的那個決策**。
	 *
	 * ⚠️ `signup_fee` 刻意不納入判斷：OverridePriceResolver 取價時本來就不看它
	 *    （:54-57 只檢查 sale_price / regular_price），納入會再度造成判準分歧。
	 *
	 * ⚠️ 這裡**不可**改成呼叫 OverridePriceResolver 來判斷：它會 fallback 到商品層，
	 *    對空條目一樣解得出價格，等於把守門變成恆真。判斷必須發生在 resolve **之前**。
	 *
	 * @param OverrideItem $item         該商品在賣場內的覆寫項目
	 * @param int          $variation_id 變體 ID
	 *
	 * @return bool 有變體層覆寫價為 true
	 */
	private static function has_variation_price_override( OverrideItem $item, int $variation_id ): bool {
		$override = $item->get_variation_override( $variation_id );

		return null !== $override
		&& ( null !== $override->regular_price || null !== $override->sale_price );
	}
}
