<?php
/**
 * Partner 密碼 ValueObject
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\WeakPassword;

/**
 * Partner 自助修密碼用的密碼 ValueObject
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3
 *
 * 規則（cumulative，所有違反一次回 reasons）：
 *   - 長度 < 8                 → 'too_short'
 *   - 不含任何英文字母         → 'missing_letter'
 *   - 不含任何數字             → 'missing_digit'
 *
 * 違反任一條 → 拋 WeakPassword(reasons)。
 *
 * 此 ValueObject 不 trim、不 normalize、不 mask，value() 原樣回傳輸入字串，
 * 將 normalization 控制權留給 caller（避免規則被前後處理偷偷改變）。
 *
 * 長度檢查以**字元（codepoint）**為單位（mb_strlen UTF-8），多位元字元（中文 / emoji）
 * 算 1 個 codepoint，與 byte 計數（strlen）不同——避免 ASCII 字元 vs 中文字元
 * 規則語意不一致（reviewer L-2 phase 6-A1）。
 *
 * 純 PHP，禁依賴 WP / WC。
 */
final class PartnerPassword {

	/**
	 * 最小長度
	 *
	 * @var int
	 */
	private const MIN_LENGTH = 8;

	/**
	 * 原始密碼字串
	 *
	 * @var string
	 */
	private readonly string $value;

	/**
	 * 建構子
	 *
	 * @param string $value 明文密碼
	 *
	 * @throws WeakPassword 當違反任一複雜度規則.
	 */
	public function __construct( string $value ) {
		$reasons = [];

		// reviewer L-2 phase 6-A1：以 codepoint 為單位（mb_strlen UTF-8），
		// 避免 byte count 對 multibyte 字元造成的語意混亂.
		if ( mb_strlen( $value, 'UTF-8' ) < self::MIN_LENGTH ) {
			$reasons[] = 'too_short';
		}
		if ( 0 === preg_match( '/[a-zA-Z]/', $value ) ) {
			$reasons[] = 'missing_letter';
		}
		if ( 0 === preg_match( '/[0-9]/', $value ) ) {
			$reasons[] = 'missing_digit';
		}

		if ( ! empty( $reasons ) ) {
			throw new WeakPassword( $reasons );
		}

		$this->value = $value;
	}

	/**
	 * 取得原始密碼字串（不 trim、不 normalize）
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}
}
