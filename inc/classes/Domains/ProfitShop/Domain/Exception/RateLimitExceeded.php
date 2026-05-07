<?php
/**
 * 速率限制超出例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當請求超過速率限制時拋出（例如 Partner login / report API）
 *
 * 攜帶 retry_after（秒數）讓 Application 層轉換為 HTTP 429 + Retry-After header。
 */
final class RateLimitExceeded extends \DomainException {

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
	 * @param string $message     例外訊息（預設為固定字樣 'RATE_LIMITED'）
	 */
	public function __construct( int $retry_after, string $message = 'RATE_LIMITED' ) {
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
