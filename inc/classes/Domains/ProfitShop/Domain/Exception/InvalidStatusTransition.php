<?php
/**
 * 非法狀態轉換例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當分潤賣場狀態轉換不符合允許規則時拋出
 */
final class InvalidStatusTransition extends \DomainException {
}
