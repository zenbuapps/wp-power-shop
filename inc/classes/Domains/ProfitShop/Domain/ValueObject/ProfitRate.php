<?php
/**
 * 分潤比例 ValueObject
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidProfitRate;

/**
 * 分潤比例（百分比，0-100 含邊界）
 */
final class ProfitRate {

	/**
	 * 分潤比例值（百分比，0-100）
	 *
	 * @var int
	 */
	public readonly int $value;

	/**
	 * 建構子
	 *
	 * @param int $value 分潤比例（0-100）
	 *
	 * @throws InvalidProfitRate 當 value 不在 0-100 範圍時拋出
	 */
	public function __construct( int $value ) {
		if ( $value < 0 || $value > 100 ) {
			throw new InvalidProfitRate( "分潤比例必須在 0-100 之間，收到 {$value}" );
		}
		$this->value = $value;
	}

	/**
	 * 取得分潤比例值
	 *
	 * @return int
	 */
	public function value(): int {
		return $this->value;
	}

	/**
	 * 比較兩個 ProfitRate 是否相等
	 *
	 * @param self $other 另一個 ProfitRate
	 *
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->value === $other->value;
	}
}
