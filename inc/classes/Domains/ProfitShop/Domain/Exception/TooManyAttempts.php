<?php
/**
 * 登入嘗試次數過多例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當登入嘗試次數超過閾值時拋出（Partner 帳號鎖定）
 *
 * 攜帶 retry_after（秒數）讓 Application 層轉換為 HTTP 429 + Retry-After header。
 */
final class TooManyAttempts extends \DomainException {

	/**
	 * 建議重試秒數
	 *
	 * @var int
	 */
	private int $retry_after;

	/**
	 * 建構子
	 *
	 * @param int    $retry_after 建議重試秒數（>= 0）
	 * @param string $message     例外訊息（預設為固定字樣 'TOO_MANY_ATTEMPTS'）
	 */
	public function __construct( int $retry_after, string $message = 'TOO_MANY_ATTEMPTS' ) {
		parent::__construct( $message );
		$this->retry_after = $retry_after;
	}

	/**
	 * 取得建議重試秒數
	 *
	 * @return int
	 */
	public function getRetryAfter(): int {
		return $this->retry_after;
	}
}
