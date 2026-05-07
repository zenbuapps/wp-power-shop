<?php
/**
 * Cart 價格簽章服務（HMAC-SHA256）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Cart 價格簽章服務（HMAC-SHA256）
 *
 * 職責：在前台 add-to-cart 時為 (shop_id, partner_term_id, price) 三元組產生簽章，
 *      於 woocommerce_before_calculate_totals 驗證以防止 cart_item meta 被竄改。
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md（Phase 3-D AddToCart Hook）
 *
 * 安全性要點：
 *   - 用 hash_hmac('sha256', ..., wp_salt('auth')) 防偽造
 *   - verify 用 hash_equals 確保 constant-time（防 timing oracle）
 *   - verify 先過濾 empty / 非 hex / 長度錯誤再做比對
 *   - payload 加 domain prefix（'cart-signature'）防止 cross-protocol HMAC collision，
 *     避免 salt 被其他模組（例如 PartnerTokenStore）以同樣 hash_hmac('sha256', ...) 簽章時混淆
 *   - signature 加版本前綴（'v1.'）以利未來金鑰輪替 / 演算法升級時平滑遷移
 */
final class CartPriceSignatureService {

	/**
	 * Payload 版本（簽章演算法／格式版本，未來升 v2 時併用 PAYLOAD_VERSION = 'v2'）
	 */
	private const PAYLOAD_VERSION = 'v1';

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
	 * 建構子
	 *
	 * @param SaltProviderInterface $salt_provider Salt 提供器（DIP，禁 object duck-type）
	 */
	public function __construct(
		private readonly SaltProviderInterface $salt_provider,
	) {}

	/**
	 * 產生簽章
	 *
	 * 簽章格式：'v1.' + 64 字元 lowercase hex（sha256），總長 67 字元
	 *
	 * @param int    $shop_id         ProfitShop CPT ID
	 * @param int    $partner_term_id Partner term ID
	 * @param string $price           已 normalize 的價格字串（整數 / 兩位小數）
	 *
	 * @return string 形如 'v1.{64 hex}' 的版本化簽章
	 */
	public function sign( int $shop_id, int $partner_term_id, string $price ): string {
		$payload = self::PAYLOAD_DOMAIN . ':' . self::PAYLOAD_VERSION
		. self::PAYLOAD_DELIMITER . $shop_id
		. self::PAYLOAD_DELIMITER . $partner_term_id
		. self::PAYLOAD_DELIMITER . $price;
		$hash    = hash_hmac( 'sha256', $payload, $this->salt_provider->get( 'auth' ) );
		return self::PAYLOAD_VERSION . '.' . $hash;
	}

	/**
	 * 驗證簽章（constant-time）
	 *
	 * 驗證流程：
	 *   1. 檢查版本前綴（'v1.'），不符直接 false
	 *   2. 取出 hash 部分，檢查長度（64）與 hex 格式
	 *   3. 重新計算期望簽章後以 hash_equals 比對 hash 部分
	 *
	 * @param int    $shop_id         ProfitShop CPT ID
	 * @param int    $partner_term_id Partner term ID
	 * @param string $price           已 normalize 的價格字串
	 * @param string $signature       客戶端送來的 signature（含版本前綴）
	 *
	 * @return bool 簽章是否有效
	 */
	public function verify( int $shop_id, int $partner_term_id, string $price, string $signature ): bool {
		$prefix = self::PAYLOAD_VERSION . '.';
		if ( ! str_starts_with( $signature, $prefix ) ) {
			return false;
		}
		$hash_part = substr( $signature, strlen( $prefix ) );
		if ( strlen( $hash_part ) !== 64 || ! ctype_xdigit( $hash_part ) ) {
			return false;
		}
		$expected_full = $this->sign( $shop_id, $partner_term_id, $price );
		$expected_hash = substr( $expected_full, strlen( $prefix ) );
		return hash_equals( $expected_hash, $hash_part );
	}
}
