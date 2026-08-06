<?php
/**
 * Cart item 分潤 meta 的驗章唯一實作
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;

/**
 * 驗證 cart item 的 `_profit_*` meta 並解出快照值（**購物車與結帳共用的唯一實作**）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.2、§5.7
 *
 * ## 🔴 為什麼必須是唯一實作
 *
 * 在此之前，`CartPriceOverrideHook::verify_item()`（購物車套價）與
 * `OrderLineItemSettlementHook::verify_cart_item()`（結帳寫結算快照）是**兩份各自維護**的
 * 版本分派 + HMAC 驗證。兩者一旦分歧，症狀是
 * 「**購物車套了覆寫價、訂單卻沒有分潤紀錄**」——顧客付了分潤價、夥伴永遠拿不到那筆結算，
 * 而且**兩邊都『正常運作』、沒有任何錯誤訊息**，`phpcs` / `phpunit` / `phpstan` 一個都攔不到。
 *
 * 這正是 Phase 13 `Domain\Service\OverridePriceResolver` 誕生的同型問題：當時是
 * 「購物車與單品頁的取價語意必須逐字一致」靠人維持，結果失敗了一次（購物車劃線原價
 * 顯示商品原始價）。結論是**把「靠人記住」換成「結構上做不到分歧」**。
 *
 * ## 這裡集中管理的四件事（任何一件散落出去都會重新製造分歧）
 *
 * | # | 事項 | 分歧的後果 |
 * |---|---|---|
 * | 1 | 版本分派一律用 `payload_version()` | 見下方「絕不可用 meta 在不在判版本」 |
 * | 2 | 各版本必備的 meta 清單 | 少檢查一筆 → 讀到 undefined index；多檢查一筆 → 既有 v1 購物車全被誤判 tampering |
 * | 3 | `verify()` 的參數個數與順序（v1 四個 / v2 六個 / v3 八個） | 傳錯 → 驗章恆失敗 → 分潤靜默消失 |
 * | 4 | 價格 normalize 形式（`wc_format_decimal( $p, '' )`） | sign 端與 verify 端格式漂移 → 驗章恆失敗 |
 *
 * ## 🔴 版本判斷一律用 `payload_version()`，絕不可用「regular / sale meta 在不在」
 *
 * 攻擊者可以保留一組**合法的 v1 簽章**（驗得過），再額外注入一筆偽造的
 * `_profit_regular_price_override`。若靠「meta 存不存在」判版本，就會把它當成 v2 →
 * v2 驗證失敗 → 落回 v1 分支 → **通過** → 於是套用了一個從未被簽章保護的假原價。
 * 因此 v1 分支**無條件忽略**任何 regular / sale meta（回 `null`，見 `VerifiedCartProfitMeta`）。
 *
 * ## 🔴 v1 / v2 刻意不做商品身分交叉比對
 *
 * 那兩版的 payload 從未包含 `product_id` / `variation_id`，簽章沒有保護過它們；而能拿來
 * 比對的兩個來源（cart 陣列欄位、實際物件）**都在攻擊者的同一個控制面內**，比對零安全
 * 增益，卻會把「兩者因任何第三方外掛而暫時不一致」的既有購物車誤殺。這是已知且刻意保留
 * 的缺口（`specs/tech-debt.md` TD-58 對 v2 仍成立），代價是不誤傷既有購物車。
 *
 * ⚠️ `LEGACY` 版本分支**只准增加、永遠不准減少**：登入使用者的持久化購物車
 * （`_woocommerce_persistent_cart_{blog_id}` usermeta）沒有任何過期機制，拔掉舊版本分支
 * 會讓那些購物車的分潤價靜默消失（`specs/tech-debt.md` TD-35）。
 *
 * ## 身分比對由呼叫端提供 predicate，而非搬進本類別
 *
 * 兩個呼叫端手上的物件不同（購物車有「被定價的那個 `WC_Product`」、結帳有「落進訂單的那筆
 * `WC_Order_Item_Product`」），比對方式因此**本來就不同**，而且兩邊的既有語意都必須逐字保留：
 *
 *   - 購物車：`$product->get_id() === ( variation_id ?: product_id )`（單一「被定價物件」比對）
 *   - 結帳　：`$item->get_product_id() === product_id && $item->get_variation_id() === variation_id`
 *
 * 硬把兩者統一會改變行為，正是本次重構明令禁止的事。**政策**（哪些版本要比對、缺身分時
 * fail-closed）留在這裡，**機制**（拿什麼跟什麼比）交給呼叫端——這樣既消滅了會靜默分歧的
 * 那一半，也沒有動到任何一邊的既有行為。
 */
final class CartProfitMetaVerifier {

	/**
	 * Cart_item meta 鍵：來源賣場 ID
	 *
	 * 🔴 這六個常數是 cart item 分潤 meta 鍵名的**唯一真相來源**。
	 * `CartPriceOverrideHook` 與 `OrderLineItemSettlementHook` 的同名 private 常數
	 * 都以 `= CartProfitMetaVerifier::META_*` 別名指向這裡——**不要**在任何地方重新
	 * 寫一份字面值：寫入端與讀取端只要有一個字母不同，驗章就會恆失敗，而症狀是
	 * 「分潤價靜默消失」，沒有任何錯誤訊息。
	 */
	public const META_SHOP_ID = '_profit_shop_id';

	/**
	 * Cart_item meta 鍵：分潤夥伴 term ID
	 */
	public const META_PARTNER_TERM_ID = '_profit_partner_term_id';

	/**
	 * Cart_item meta 鍵：override 後實際生效的價格
	 */
	public const META_PRICE_OVERRIDE = '_profit_price_override';

	/**
	 * Cart_item meta 鍵：override 後的原價（'' 表示不覆寫）
	 */
	public const META_REGULAR_OVERRIDE = '_profit_regular_price_override';

	/**
	 * Cart_item meta 鍵：override 後的特價（'' 表示無特價）
	 */
	public const META_SALE_OVERRIDE = '_profit_sale_price_override';

	/**
	 * Cart_item meta 鍵：HMAC 簽章
	 */
	public const META_SIGNATURE = '_profit_signature';

	/**
	 * 全部版本（v1 / v2 / v3）共同必備的 meta
	 *
	 * @var string[]
	 */
	public const META_KEYS_BASE = [
		self::META_SHOP_ID,
		self::META_PARTNER_TERM_ID,
		self::META_PRICE_OVERRIDE,
		self::META_SIGNATURE,
	];

	/**
	 * 只有 v2 / v3 簽章才必備的 meta（v1 沒有這兩筆）
	 *
	 * ⚠️ v3 **沒有**新增任何 meta：商品身分取自 cart item 的原生 product_id / variation_id
	 *    欄位，複製一份成 `_profit_*` meta 只會多一個可以互相矛盾的來源。
	 *
	 * @var string[]
	 */
	public const META_KEYS_V2 = [
		self::META_REGULAR_OVERRIDE,
		self::META_SALE_OVERRIDE,
	];

	/**
	 * 全部 meta（session restore / strip 用）
	 *
	 * @var string[]
	 */
	public const META_KEYS_ALL = [
		...self::META_KEYS_BASE,
		...self::META_KEYS_V2,
	];

	/**
	 * 建構子
	 *
	 * @param CartPriceSignatureService $signatures 簽章服務（判版與 HMAC 驗證的唯一入口）
	 */
	public function __construct(
		private readonly CartPriceSignatureService $signatures
	) {}

	/**
	 * 驗證 cart item 的分潤 meta，並解出已驗章的快照值
	 *
	 * @param array<string, mixed> $item             cart item 陣列（session 陣列或 `cart_contents` 皆可）
	 * @param \Closure|null        $identity_matches v3 專用的身分比對 predicate，簽名
	 *                                               `fn( int $product_id, int $variation_id ): bool`。
	 *                                               傳 `null` 代表呼叫端此刻拿不到可比對的物件
	 *                                               （例如 session restore 階段），此時**只驗簽章**——
	 *                                               身分已在簽章 payload 內，仍受 HMAC 保護。
	 *
	 * @return VerifiedCartProfitMeta|null 驗章不過一律回 `null`（fail-closed，絕不回部分結果）
	 */
	public function verify( array $item, ?\Closure $identity_matches = null ): ?VerifiedCartProfitMeta {
		$signature = isset( $item[ self::META_SIGNATURE ] ) ? (string) $item[ self::META_SIGNATURE ] : '';
		$version   = $this->signatures->payload_version( $signature );

		if ( null === $version ) {
			return null;
		}

		// v1 只有 4 筆 meta；v2 / v3 另需 regular + sale。
		if ( ! self::has_required_meta( $item, 'v1' !== $version ) ) {
			return null;
		}

		$shop_id         = (int) $item[ self::META_SHOP_ID ];
		$partner_term_id = (int) $item[ self::META_PARTNER_TERM_ID ];
		// verify 端必須與 sign 端用同一 normalize 形式，否則格式漂移會讓驗章靜默失敗。
		$price = self::normalize_price( (string) $item[ self::META_PRICE_OVERRIDE ] );

		$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;

		if ( 'v1' === $version ) {
			if ( ! $this->signatures->verify( $shop_id, $partner_term_id, $price, $signature ) ) {
				return null;
			}

			// regular / sale 一律 null：v1 payload 從未保護過它們，即使 meta 存在也忽略。
			return new VerifiedCartProfitMeta(
				version: $version,
				shop_id: $shop_id,
				partner_term_id: $partner_term_id,
				price: $price,
				regular: null,
				sale: null,
				product_id: $product_id,
				variation_id: $variation_id
			);
		}

		$regular = (string) $item[ self::META_REGULAR_OVERRIDE ];
		$sale    = (string) $item[ self::META_SALE_OVERRIDE ];

		if ( 'v2' === $version ) {
			// 見 class docblock：v2 刻意不做身分比對。
			if ( ! $this->signatures->verify( $shop_id, $partner_term_id, $price, $signature, $regular, $sale ) ) {
				return null;
			}

			return new VerifiedCartProfitMeta(
				version: $version,
				shop_id: $shop_id,
				partner_term_id: $partner_term_id,
				price: $price,
				regular: $regular,
				sale: $sale,
				product_id: $product_id,
				variation_id: $variation_id
			);
		}

		// ===== v3：簽章綁定商品身分 =====
		if ( $product_id <= 0 ) {
			// v3 必須有身分，缺了就 fail-closed（不退回 v2 語意——那等於自願降級）。
			return null;
		}

		if ( null !== $identity_matches && true !== $identity_matches( $product_id, $variation_id ) ) {
			return null;
		}

		if ( ! $this->signatures->verify(
			$shop_id,
			$partner_term_id,
			$price,
			$signature,
			$regular,
			$sale,
			$product_id,
			$variation_id
		) ) {
			return null;
		}

		return new VerifiedCartProfitMeta(
			version: $version,
			shop_id: $shop_id,
			partner_term_id: $partner_term_id,
			price: $price,
			regular: $regular,
			sale: $sale,
			product_id: $product_id,
			variation_id: $variation_id
		);
	}

	/**
	 * 檢查 cart item 是否含該版本必備的 meta
	 *
	 * ⚠️ regular / sale 兩筆 meta **依簽章版本條件必填**，不可無條件必填：
	 * 改版前寫入的 v1 cart item 只有 4 筆 meta，判成必填會讓**所有既有購物車**
	 * 一次被當成 tampering → strip + reset → 分潤價靜默消失。
	 *
	 * 用 `isset()`（而非 `array_key_exists()`）：值為 `null` 的 meta 一律視為缺漏，
	 * 後續 `(int)` / `(string)` 轉型才不會把 null 靜默變成 0 / ''。
	 *
	 * @param array<string, mixed> $item       cart item
	 * @param bool                 $require_v2 是否額外要求 v2 / v3 專屬的 regular / sale meta
	 *
	 * @return bool 完整為 true
	 */
	private static function has_required_meta( array $item, bool $require_v2 ): bool {
		$required = $require_v2 ? self::META_KEYS_ALL : self::META_KEYS_BASE;

		foreach ( $required as $key ) {
			if ( ! isset( $item[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 將價格字串標準化為簽章用的標準形式
	 *
	 * 🔴 `wc_format_decimal( $price, '' )` 會**補足**到 `wc_get_price_decimals()` 位小數
	 * （`'888'` → `'888.00'`），**不是** trim trailing zeros——第二參數 `''` 會走
	 * `number_format()` 分支，而 `$trim_zeros` 預設 `false`。
	 *
	 * ⚠️ **不要**為了符合「trim trailing zeros」的直覺去改它：改了會讓所有既有 v1 / v2
	 * 簽章的 payload 價格字串從 `'888.00'` 變成 `'888'` ⇒ 既有購物車驗章全滅 ⇒ 分潤價
	 * 靜默消失（站長通常要到對帳才會發現）。詳見 `profit-shop.rule.md` §7。
	 *
	 * @param string $price 原始價格字串
	 *
	 * @return string 標準化後的價格字串
	 */
	public static function normalize_price( string $price ): string {
		return (string) \wc_format_decimal( $price, '' );
	}
}
