<?php
/**
 * 灌水銷量 ValueObject
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\ValueObject;

/**
 * 灌水銷量
 *
 * 容錯設計：負數會被 sanitize 至 0（不拋例外），允許任意正整數至 PHP_INT_MAX。
 */
final class InflatedCount {

	/**
	 * 灌水銷量值（>= 0）
	 *
	 * @var int
	 */
	public readonly int $value;

	/**
	 * 建構子
	 *
	 * @param int $value 灌水銷量；負數會被自動修正為 0。
	 */
	public function __construct( int $value ) {
		$this->value = max( 0, $value );
	}

	/**
	 * 取得灌水銷量值
	 *
	 * @return int
	 */
	public function value(): int {
		return $this->value;
	}
}
