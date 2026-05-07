<?php
/**
 * 登入憑證不合法例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當 Partner 登入憑證錯誤時拋出
 *
 * 為避免帳號列舉攻擊（user enumeration），message 一律使用固定字樣
 * 'INVALID_CREDENTIALS'，不洩漏「帳號不存在」「密碼錯誤」等可區分性訊息。
 */
final class InvalidCredentials extends \DomainException {

	/**
	 * 建構子
	 *
	 * @param string $message 例外訊息（預設為固定字樣 'INVALID_CREDENTIALS'）
	 */
	public function __construct( string $message = 'INVALID_CREDENTIALS' ) {
		parent::__construct( $message );
	}
}
