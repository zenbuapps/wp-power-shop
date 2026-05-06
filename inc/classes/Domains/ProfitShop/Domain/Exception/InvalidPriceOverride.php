<?php
/**
 * 價格覆寫驗證失敗例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當 PriceOverride 的欄位不合法（負數、非數字字串、sale > regular）時拋出
 */
final class InvalidPriceOverride extends \DomainException {
}
