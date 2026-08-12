<?php
/**
 * Cart 價格簽章服務（HMAC-SHA256）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Cart 價格簽章服務（HMAC-SHA256）
 *
 * 職責：在前台 add-to-cart 時為 (shop_id, partner_term_id, price, regular_price, sale_price)
 *      產生簽章，於 woocommerce_before_calculate_totals 驗證以防止 cart_item meta 被竄改。
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md（Phase 3-D AddToCart Hook）
 *          specs/plan/2026-08-03-cart-regular-price-override-plan.md（Phase 13 v2 遷移）
 *
 * 安全性要點：
 *   - 用 hash_hmac('sha256', ..., wp_salt('auth')) 防偽造
 *   - verify 用 hash_equals 確保 constant-time（防 timing oracle）
 *   - verify 先過濾 empty / 非 hex / 長度錯誤再做比對
 *   - payload 加 domain prefix（'cart-signature'）防止 cross-protocol HMAC collision，
 *     避免 salt 被其他模組（例如 PartnerTokenStore）以同樣 hash_hmac('sha256', ...) 簽章時混淆
 *   - signature 加版本前綴（'v1.' / 'v2.' / 'v3.'）以利金鑰輪替 / 演算法升級時平滑遷移
 *
 * ═══ 版本遷移（Phase 13 的 v2、2026-08 第 6 輪的 v3）═══
 *
 * v1（既有）：'cart-signature:v1|{shop_id}|{partner_term_id}|{price}'
 * v2（既有）：'cart-signature:v2|{shop_id}|{partner_term_id}|{price}|{regular}|{sale}'
 * v3（現行）：'cart-signature:v3|{shop_id}|{partner_term_id}|{price}|{regular}|{sale}|{product_id}|{variation_id}'
 *
 * sign() 一律產出 v3；verify() **三個版本都收**，依 signature 前綴分派。
 *
 * v3 綁商品身分的理由（S-2 / TD-58）：簽章原本只保護「多少錢」，一組錯配的 meta 頂多把
 * 「本來就可購買的商品」標錯價；但 2026-08 起簽章同時是**「不可購買的商品變不變成可購買」
 * 的唯一授權來源**（見 CartPriceOverrideHook::authorize_cart_item()）。探針實測：把 A
 * 商品的整組 _profit_* meta 原封套到「不在任何賣場」的 B 商品上，B 的 get_price() 變成
 * '27.00'、authorize 回 true。
 *
 * **兩個欄位都簽、不是只簽 target_id**：只簽 `variation_id ?: product_id` 在數學上已足以
 * 阻擋「把 A 的簽章搬到 B」，但額外簽 product_id 還鎖住「同一個 target 不能被宣稱屬於
 * 另一個父商品」——第四道閘門與 CartPriceOverrideHook 的賣場回查用的都是**父商品 ID**，
 * 那個欄位若不在簽章內，改掉它就能讓回查指向錯誤的 OverrideItem。成本是零。
 *
 * 🔴 **v1 / v2 驗證分支不可移除，且不設下架期限。**
 *    登入使用者的持久化購物車（usermeta `_woocommerce_persistent_cart_{blog_id}`）
 *    **沒有任何過期機制**，只有結帳完成或清空購物車才會被刪除。移除舊分支會讓那些
 *    購物車的簽章驗證失敗 → CartPriceOverrideHook 走 strip_profit_meta + reset_to_original_price
 *    → **分潤價靜默消失，顧客與站長都不會收到任何訊息**，站長通常要等到對帳才會發現。
 *    這不是忘了清的死碼（見 specs/tech-debt.md TD-35）。
 *    若日後真的要移除，必須先實作舊版 → 現行版的就地升級路徑並跑滿一段時間。
 *
 * 🔴 **三個版本的 hash 絕不可互通**：payload 的 domain prefix 本身含版本字串
 *    （'cart-signature:v1' vs ':v2' vs ':v3'），所以把舊簽章的前綴硬改成 'v3.'
 *    一定驗不過——這是刻意的，有測試鎖住。
 *
 * 🔴 呼叫端**必須**用 payload_version() 判版本，**絕不可**用「某個 meta 在不在」判斷。
 *    後者可被攻擊者用注入 meta 的方式操縱：保留合法舊簽章 + 注入偽造欄位 → 新版分支
 *    驗失敗 → 落回舊分支 → 通過 → 套用一組從未被簽章保護的值。
 */
final class CartPriceSignatureService {

	/**
	 * 目前的 Payload 版本（sign() 一律產出這個版本）
	 */
	private const PAYLOAD_VERSION = 'v3';

	/**
	 * 只驗證、不再產生的舊 Payload 版本（**由新到舊**，payload_version() 依序比對）
	 *
	 * 🔴 這個陣列只准增加、永遠不准減少。
	 *    見 class docblock：持久化購物車永不過期，拿掉任何一個版本都會讓那些購物車的
	 *    分潤價靜默消失（specs/tech-debt.md TD-35）。
	 *
	 * @var string[]
	 */
	private const LEGACY_PAYLOAD_VERSIONS = [ 'v2', 'v1' ];

	/**
	 * Payload Domain 前綴（防 cross-protocol HMAC collision）
	 *
	 * 與其他 HMAC 用途（例如 PartnerTokenStore）共用 wp_salt('auth') 時，
	 * 必須在 payload 開頭加上唯一 domain prefix，避免簽章可跨用途重放。
	 */
	private const PAYLOAD_DOMAIN = 'cart-signature';

	/**
	 * Payload 欄位分隔符
	 */
	private const PAYLOAD_DELIMITER = '|';

	/**
	 * SHA-256 hex 字串長度
	 */
	private const HASH_LENGTH = 64;

	/**
	 * 建構子
	 *
	 * @param SaltProviderInterface $salt_provider Salt 提供器（DIP，禁 object duck-type）
	 */
	public function __construct(
		private readonly SaltProviderInterface $salt_provider,
	) {}

	/**
	 * 產生簽章（一律為 v3）
	 *
	 * 簽章格式：'v3.' + 64 字元 lowercase hex（sha256），總長 67 字元
	 *
	 * ⚠️ $regular_price / $sale_price 的空字串是**有意義的值**，不是「沒傳」：
	 *    - regular = '' 代表「不覆寫原價」（沿用商品原本的原價）
	 *    - sale    = '' 代表「沒有特價」（呼叫端應清掉商品原本的特價）
	 *    兩者都會進 payload，所以竄改任一個都會讓驗章失敗。
	 *
	 * ⚠️ $product_id / $variation_id 的預設 0 只為了讓既有測試 / 呼叫端不必一次全改；
	 *    production 呼叫端（CartPriceOverrideHook）**必須**傳真值，否則等於簽了一組
	 *    「身分為 0」的簽章，驗證時比對必然失敗、分潤價會 fallback 回原價。
	 *
	 * @param int    $shop_id         ProfitShop CPT ID
	 * @param int    $partner_term_id Partner term ID
	 * @param string $price           已 normalize 的實際生效價格字串
	 * @param string $regular_price   已 normalize 的覆寫原價；'' 表示不覆寫
	 * @param string $sale_price      已 normalize 的覆寫特價；'' 表示無特價
	 * @param int    $product_id      cart item 的父商品 ID（v3 起納入簽章）
	 * @param int    $variation_id    cart item 的變體 ID；0 表示非變體（v3 起納入簽章）
	 *
	 * @return string 形如 'v3.{64 hex}' 的版本化簽章
	 */
	public function sign(
		int $shop_id,
		int $partner_term_id,
		string $price,
		string $regular_price = '',
		string $sale_price = '',
		int $product_id = 0,
		int $variation_id = 0
	): string {
		$payload = $this->build_payload(
			self::PAYLOAD_VERSION,
			$shop_id,
			$partner_term_id,
			$price,
			$regular_price,
			$sale_price,
			$product_id,
			$variation_id
		);
		$hash    = hash_hmac( 'sha256', $payload, $this->salt_provider->get( 'auth' ) );
		return self::PAYLOAD_VERSION . '.' . $hash;
	}

	/**
	 * 驗證簽章（constant-time）
	 *
	 * 驗證流程：
	 *   1. 由 signature 前綴判斷版本（v3 / v2 / v1），不認得的前綴直接 false
	 *   2. 取出 hash 部分，檢查長度（64）與 hex 格式
	 *   3. 依該版本重新組 payload，以 hash_equals 比對 hash 部分
	 *
	 * ⚠️ v1 分支**完全忽略** $regular_price / $sale_price；v1 / v2 分支**完全忽略**
	 *    $product_id / $variation_id——那些欄位不在該版本的 payload 裡，簽章從未保護過。
	 *    呼叫端必須據此**不套用 / 不比對**那些值，否則攻擊者可以保留合法的舊簽章再注入
	 *    偽造欄位（見 CartPriceOverrideHook 的版本分派註解）。
	 *
	 * @param int    $shop_id         ProfitShop CPT ID
	 * @param int    $partner_term_id Partner term ID
	 * @param string $price           已 normalize 的價格字串
	 * @param string $signature       客戶端送來的 signature（含版本前綴）
	 * @param string $regular_price   已 normalize 的覆寫原價（v1 忽略）
	 * @param string $sale_price      已 normalize 的覆寫特價（v1 忽略）
	 * @param int    $product_id      cart item 的父商品 ID（僅 v3 納入比對）
	 * @param int    $variation_id    cart item 的變體 ID（僅 v3 納入比對）
	 *
	 * @return bool 簽章是否有效
	 */
	public function verify(
		int $shop_id,
		int $partner_term_id,
		string $price,
		string $signature,
		string $regular_price = '',
		string $sale_price = '',
		int $product_id = 0,
		int $variation_id = 0
	): bool {
		$version = $this->payload_version( $signature );

		if ( null === $version ) {
			return false;
		}

		$hash_part = substr( $signature, strlen( $version ) + 1 );
		if ( strlen( $hash_part ) !== self::HASH_LENGTH || ! ctype_xdigit( $hash_part ) ) {
			return false;
		}

		// 舊版本 payload 不含後面幾個欄位，build_payload() 的對應分支本來就會忽略它們。
		$payload       = $this->build_payload(
			$version,
			$shop_id,
			$partner_term_id,
			$price,
			$regular_price,
			$sale_price,
			$product_id,
			$variation_id
		);
		$expected_hash = hash_hmac( 'sha256', $payload, $this->salt_provider->get( 'auth' ) );

		return hash_equals( $expected_hash, $hash_part );
	}

	/**
	 * 由 signature 前綴判斷 payload 版本
	 *
	 * 呼叫端（CartPriceOverrideHook）**必須**用本方法判斷版本，
	 * 而不是用「regular/sale meta 存不存在」——後者可被攻擊者用注入 meta 的方式操縱。
	 *
	 * @param string $signature 完整簽章字串
	 *
	 * @return string|null 'v3' / 'v2' / 'v1'；前綴不認得（含裸 hash、空字串、未來版本）回 null
	 */
	public function payload_version( string $signature ): ?string {
		if ( str_starts_with( $signature, self::PAYLOAD_VERSION . '.' ) ) {
			return self::PAYLOAD_VERSION;
		}

		foreach ( self::LEGACY_PAYLOAD_VERSIONS as $legacy ) {
			if ( str_starts_with( $signature, $legacy . '.' ) ) {
				return $legacy;
			}
		}

		return null;
	}

	/**
	 * 組出指定版本的 payload 字串
	 *
	 * 三個版本的格式：
	 *   v1：{domain}:v1|{shop_id}|{partner_term_id}|{price}
	 *   v2：{domain}:v2|{shop_id}|{partner_term_id}|{price}|{regular}|{sale}
	 *   v3：{domain}:v3|{shop_id}|{partner_term_id}|{price}|{regular}|{sale}|{product_id}|{variation_id}
	 *
	 * Domain prefix 內含版本字串，這是「各版本的 hash 天然不可互通」的來源——
	 * 不要把版本移出 payload，否則舊簽章換個前綴就能冒充新版。
	 *
	 * @param string $version         'v1' / 'v2' / 'v3'
	 * @param int    $shop_id         ProfitShop CPT ID
	 * @param int    $partner_term_id Partner term ID
	 * @param string $price           已 normalize 的價格字串
	 * @param string $regular_price   已 normalize 的覆寫原價（v1 忽略）
	 * @param string $sale_price      已 normalize 的覆寫特價（v1 忽略）
	 * @param int    $product_id      cart item 的父商品 ID（v1 / v2 忽略）
	 * @param int    $variation_id    cart item 的變體 ID（v1 / v2 忽略）
	 *
	 * @return string payload 字串
	 */
	private function build_payload(
		string $version,
		int $shop_id,
		int $partner_term_id,
		string $price,
		string $regular_price,
		string $sale_price,
		int $product_id = 0,
		int $variation_id = 0
	): string {
		$payload = self::PAYLOAD_DOMAIN . ':' . $version
		. self::PAYLOAD_DELIMITER . $shop_id
		. self::PAYLOAD_DELIMITER . $partner_term_id
		. self::PAYLOAD_DELIMITER . $price;

		if ( 'v1' === $version ) {
			return $payload;
		}

		$payload .= self::PAYLOAD_DELIMITER . $regular_price
		. self::PAYLOAD_DELIMITER . $sale_price;

		if ( 'v2' === $version ) {
			return $payload;
		}

		// v3：綁定商品身分。兩個欄位取自 cart item 的原生欄位，不新增任何 meta——
		// 複製一份成 _profit_* meta 只會多一個可以互相矛盾的來源。
		return $payload
		. self::PAYLOAD_DELIMITER . $product_id
		. self::PAYLOAD_DELIMITER . $variation_id;
	}
}
