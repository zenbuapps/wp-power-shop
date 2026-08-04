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
 *   - signature 加版本前綴（'v1.' / 'v2.'）以利金鑰輪替 / 演算法升級時平滑遷移
 *
 * ═══ 版本遷移（Phase 13）═══
 *
 * v1（既有）：'cart-signature:v1|{shop_id}|{partner_term_id}|{price}'
 * v2（現行）：'cart-signature:v2|{shop_id}|{partner_term_id}|{price}|{regular}|{sale}'
 *
 * sign() 一律產出 v2；verify() **兩個版本都收**，依 signature 前綴分派。
 *
 * 🔴 **v1 驗證分支不可移除，且不設下架期限。**
 *    登入使用者的持久化購物車（usermeta `_woocommerce_persistent_cart_{blog_id}`）
 *    **沒有任何過期機制**，只有結帳完成或清空購物車才會被刪除。移除 v1 分支會讓那些
 *    購物車的簽章驗證失敗 → CartPriceOverrideHook 走 strip_profit_meta + reset_to_original_price
 *    → **分潤價靜默消失，顧客與站長都不會收到任何訊息**，站長通常要等到對帳才會發現。
 *    這不是忘了清的死碼（見 specs/tech-debt.md TD-35）。
 *    若日後真的要移除，必須先實作 v1 → v2 的就地升級路徑並跑滿一段時間。
 *
 * 🔴 **兩個版本的 hash 絕不可互通**：payload 的 domain prefix 本身含版本字串
 *    （'cart-signature:v1' vs 'cart-signature:v2'），所以把 v1 簽章的前綴硬改成 'v2.'
 *    一定驗不過——這是刻意的，有測試鎖住。
 */
final class CartPriceSignatureService {

	/**
	 * 目前的 Payload 版本（sign() 一律產出這個版本）
	 */
	private const PAYLOAD_VERSION = 'v2';

	/**
	 * 舊 Payload 版本（只驗證、不再產生）
	 *
	 * 見 class docblock：持久化購物車永不過期，此分支必須永久保留。
	 */
	private const LEGACY_PAYLOAD_VERSION = 'v1';

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
	 * 產生簽章（一律為 v2）
	 *
	 * 簽章格式：'v2.' + 64 字元 lowercase hex（sha256），總長 67 字元
	 *
	 * ⚠️ $regular_price / $sale_price 的空字串是**有意義的值**，不是「沒傳」：
	 *    - regular = '' 代表「不覆寫原價」（沿用商品原本的原價）
	 *    - sale    = '' 代表「沒有特價」（呼叫端應清掉商品原本的特價）
	 *    兩者都會進 payload，所以竄改任一個都會讓驗章失敗。
	 *
	 * @param int    $shop_id         ProfitShop CPT ID
	 * @param int    $partner_term_id Partner term ID
	 * @param string $price           已 normalize 的實際生效價格字串
	 * @param string $regular_price   已 normalize 的覆寫原價；'' 表示不覆寫
	 * @param string $sale_price      已 normalize 的覆寫特價；'' 表示無特價
	 *
	 * @return string 形如 'v2.{64 hex}' 的版本化簽章
	 */
	public function sign(
		int $shop_id,
		int $partner_term_id,
		string $price,
		string $regular_price = '',
		string $sale_price = ''
	): string {
		$payload = $this->build_payload(
			self::PAYLOAD_VERSION,
			$shop_id,
			$partner_term_id,
			$price,
			$regular_price,
			$sale_price
		);
		$hash    = hash_hmac( 'sha256', $payload, $this->salt_provider->get( 'auth' ) );
		return self::PAYLOAD_VERSION . '.' . $hash;
	}

	/**
	 * 驗證簽章（constant-time）
	 *
	 * 驗證流程：
	 *   1. 由 signature 前綴判斷版本（v2 / v1），不認得的前綴直接 false
	 *   2. 取出 hash 部分，檢查長度（64）與 hex 格式
	 *   3. 依該版本重新組 payload，以 hash_equals 比對 hash 部分
	 *
	 * ⚠️ v1 分支**完全忽略** $regular_price / $sale_price 兩個參數——v1 payload 裡沒有
	 *    這兩個欄位，簽章從未保護過它們。呼叫端必須據此**不套用**任何 regular/sale，
	 *    否則攻擊者可以保留合法的 v1 簽章再注入偽造的原價 meta（見 CartPriceOverrideHook
	 *    的版本分派註解）。
	 *
	 * @param int    $shop_id         ProfitShop CPT ID
	 * @param int    $partner_term_id Partner term ID
	 * @param string $price           已 normalize 的價格字串
	 * @param string $signature       客戶端送來的 signature（含版本前綴）
	 * @param string $regular_price   已 normalize 的覆寫原價（僅 v2 納入比對）
	 * @param string $sale_price      已 normalize 的覆寫特價（僅 v2 納入比對）
	 *
	 * @return bool 簽章是否有效
	 */
	public function verify(
		int $shop_id,
		int $partner_term_id,
		string $price,
		string $signature,
		string $regular_price = '',
		string $sale_price = ''
	): bool {
		$version = $this->payload_version( $signature );

		if ( null === $version ) {
			return false;
		}

		$hash_part = substr( $signature, strlen( $version ) + 1 );
		if ( strlen( $hash_part ) !== self::HASH_LENGTH || ! ctype_xdigit( $hash_part ) ) {
			return false;
		}

		// v1 payload 不含 regular/sale，一律以空字串組（build_payload 的 v1 分支本來就會忽略）
		$payload       = $this->build_payload(
			$version,
			$shop_id,
			$partner_term_id,
			$price,
			$regular_price,
			$sale_price
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
	 * @return string|null 'v2' / 'v1'；前綴不認得（含裸 hash、空字串、未來版本）回 null
	 */
	public function payload_version( string $signature ): ?string {
		if ( str_starts_with( $signature, self::PAYLOAD_VERSION . '.' ) ) {
			return self::PAYLOAD_VERSION;
		}
		if ( str_starts_with( $signature, self::LEGACY_PAYLOAD_VERSION . '.' ) ) {
			return self::LEGACY_PAYLOAD_VERSION;
		}
		return null;
	}

	/**
	 * 組出指定版本的 payload 字串
	 *
	 * 兩個版本的格式：
	 *   v1：{domain}:v1|{shop_id}|{partner_term_id}|{price}
	 *   v2：{domain}:v2|{shop_id}|{partner_term_id}|{price}|{regular}|{sale}
	 *
	 * Domain prefix 內含版本字串，這是「兩個版本的 hash 天然不可互通」的來源——
	 * 不要把版本移出 payload，否則 v1 簽章換個前綴就能冒充 v2。
	 *
	 * @param string $version         'v1' / 'v2'
	 * @param int    $shop_id         ProfitShop CPT ID
	 * @param int    $partner_term_id Partner term ID
	 * @param string $price           已 normalize 的價格字串
	 * @param string $regular_price   已 normalize 的覆寫原價（v1 忽略）
	 * @param string $sale_price      已 normalize 的覆寫特價（v1 忽略）
	 *
	 * @return string payload 字串
	 */
	private function build_payload(
		string $version,
		int $shop_id,
		int $partner_term_id,
		string $price,
		string $regular_price,
		string $sale_price
	): string {
		$payload = self::PAYLOAD_DOMAIN . ':' . $version
		. self::PAYLOAD_DELIMITER . $shop_id
		. self::PAYLOAD_DELIMITER . $partner_term_id
		. self::PAYLOAD_DELIMITER . $price;

		if ( self::LEGACY_PAYLOAD_VERSION === $version ) {
			return $payload;
		}

		return $payload
		. self::PAYLOAD_DELIMITER . $regular_price
		. self::PAYLOAD_DELIMITER . $sale_price;
	}
}
