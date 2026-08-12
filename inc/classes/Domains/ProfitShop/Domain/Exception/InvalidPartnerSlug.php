<?php
/**
 * Partner Slug 驗證失敗例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當 Partner slug 不符合格式（regex / 長度 / 保留字）時拋出
 */
final class InvalidPartnerSlug extends \DomainException {
}
