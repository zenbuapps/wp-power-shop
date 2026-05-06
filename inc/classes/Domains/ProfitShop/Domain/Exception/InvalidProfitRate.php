<?php
/**
 * 分潤比例驗證失敗例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當分潤比例不在 0-100 範圍時拋出
 */
final class InvalidProfitRate extends \DomainException {
}
