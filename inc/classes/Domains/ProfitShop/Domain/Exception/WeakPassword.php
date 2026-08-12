<?php
/**
 * 弱密碼例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當 Partner 自助修密碼時新密碼不符合複雜度規則時拋出
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§7
 *
 * reasons 為 string[]，由 PartnerPassword ValueObject 在 constructor 期間累計，
 * 例如 ['too_short', 'missing_letter', 'missing_digit']。
 *
 * Presentation 層 ExceptionMapper 會將此例外對映為 422 + body.data.reasons，
 * 前端據以告知使用者「哪些規則未通過」並引導修正（與 SlugConflictException 攜帶
 * conflicts payload 同模式）。
 *
 * 必須 `extends \DomainException`：避免 ExceptionMapper 的 `\DomainException` fallback 漏接。
 */
final class WeakPassword extends \DomainException {

	/**
	 * 違反的規則代碼列表
	 *
	 * @var string[]
	 */
	private readonly array $reasons;

	/**
	 * 建構子
	 *
	 * @param string[] $reasons 違反的規則代碼（如 'too_short' / 'missing_letter' / 'missing_digit'）
	 * @param string   $message 例外訊息（未提供時自動以 reasons 組成繁中訊息）
	 */
	public function __construct( array $reasons, string $message = '' ) {
		$this->reasons = $reasons;
		if ( '' === $message ) {
			$message = '密碼複雜度不足：' . implode( ',', $reasons );
		}
		parent::__construct( $message );
	}

	/**
	 * 取得違反的規則代碼列表
	 *
	 * @return string[]
	 */
	public function getReasons(): array {
		return $this->reasons;
	}
}
