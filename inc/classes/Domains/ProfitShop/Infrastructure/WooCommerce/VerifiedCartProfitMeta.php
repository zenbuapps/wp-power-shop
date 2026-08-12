<?php
/**
 * 驗章通過的 cart item 分潤 meta 快照
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

/**
 * `CartProfitMetaVerifier::verify()` 的回傳值——一組**已通過 HMAC 驗章**的分潤 meta
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.2、§5.7
 *
 * ## `regular` / `sale` 為什麼是 nullable，而不是空字串
 *
 * 三種值在下游是**三件不同的事**，混用會直接寫錯價格：
 *
 * | 值 | 語意 | 下游行為 |
 * |---|---|---|
 * | `null` | 這個版本的簽章**從未保護過**這個欄位（v1） | 完全不動商品原本的 regular / sale |
 * | `''`   | 簽章保護過，內容是「不覆寫原價」／「無特價」 | `sale` 必須顯式 `set_sale_price('')` 清掉商品原本的特價 |
 * | 其他   | 簽章保護過的實際覆寫值 | 照寫 |
 *
 * 🔴 `null` 與 `''` 的差別是安全性而非風格：v1 payload 沒有簽過 regular / sale，
 * 攻擊者可以保留合法的 v1 簽章再注入一筆偽造的 `_profit_regular_price_override`。
 * 用 `null` 表達「這個版本沒有這個欄位」，讓呼叫端在型別上就分得出來、不可能誤用。
 *
 * 兩個呼叫端各自把 `null` 轉成自己需要的形式：
 *   - `CartPriceOverrideHook`：原樣傳遞 `null`（`apply_prices_to_product()` 用 null 判斷不動）
 *   - `OrderLineItemSettlementHook`：`?? ''`（order itemmeta 是字串欄位，空字串即「未覆寫」）
 *
 * ## `product_id` / `variation_id` 取自 cart item 的**原生欄位**
 *
 * v3 簽章綁定商品身分時用的就是這兩個欄位，**不另存 `_profit_*` meta**——
 * 複製一份只會多一個可以互相矛盾的來源。v1 / v2 時它們仍會被填入（供呼叫端記錄 / log），
 * 但**沒有經過簽章保護**，不可用來做安全判斷。
 */
final class VerifiedCartProfitMeta {

	/**
	 * 建構子
	 *
	 * @param string      $version         簽章 payload 版本（`v1` / `v2` / `v3`）
	 * @param int         $shop_id         賣場 ID（已驗章）
	 * @param int         $partner_term_id 分潤夥伴 term ID（已驗章）
	 * @param string      $price           實際生效價格，已 normalize（已驗章）
	 * @param string|null $regular         覆寫原價；`null` = 該版本未簽此欄位，`''` = 不覆寫
	 * @param string|null $sale            覆寫特價；`null` = 該版本未簽此欄位，`''` = 無特價
	 * @param int         $product_id      cart item 的父商品 ID（v3 已驗章；v1 / v2 未受保護）
	 * @param int         $variation_id    cart item 的變體 ID（v3 已驗章；v1 / v2 未受保護）
	 */
	public function __construct(
		public readonly string $version,
		public readonly int $shop_id,
		public readonly int $partner_term_id,
		public readonly string $price,
		public readonly ?string $regular,
		public readonly ?string $sale,
		public readonly int $product_id,
		public readonly int $variation_id
	) {}
}
