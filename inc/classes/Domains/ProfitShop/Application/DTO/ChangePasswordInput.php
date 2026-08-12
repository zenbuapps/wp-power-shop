<?php
/**
 * Partner 自助修密碼輸入 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Partner 自助修密碼請求 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 Partner 自助修密碼
 *
 * @internal 此 DTO 攜帶明文密碼欄位（current_password / new_password），
 *   **禁止**呼叫 to_array() 序列化到 REST response 或 audit log；
 *   亦不提供 to_array()，避免誤用。
 *
 * IP 不放入此 DTO（語意上 IP 不是 client payload；與 PartnerLoginInput 一致），
 * 由 V2Api callback 從 $_SERVER['REMOTE_ADDR'] 取得後以 UseCase::execute 第二參數傳入。
 */
final class ChangePasswordInput {

	/**
	 * 建構子
	 *
	 * @param int    $partner_term_id  Partner term ID（永遠由 caller 從 token 解出，禁從 query string 取）
	 * @param string $current_password 當前明文密碼（不過 sanitize_text_field）
	 * @param string $new_password     新明文密碼（不過 sanitize_text_field）
	 */
	public function __construct(
		public readonly int $partner_term_id,
		public readonly string $current_password,
		public readonly string $new_password
	) {}
}
