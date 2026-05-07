<?php
/**
 * 找不到 Partner 例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當查無對應 Partner（依 slug / term_id）時拋出
 *
 * 統一 extends \DomainException：Application 層可一併 catch \DomainException。
 */
final class PartnerNotFound extends \DomainException {
}
