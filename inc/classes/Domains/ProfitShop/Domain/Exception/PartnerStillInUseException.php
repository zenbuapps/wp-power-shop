<?php
/**
 * 分潤夥伴仍被分潤賣場使用中例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當嘗試刪除仍被任何分潤賣場引用的分潤夥伴時拋出
 */
final class PartnerStillInUseException extends \DomainException {
}
