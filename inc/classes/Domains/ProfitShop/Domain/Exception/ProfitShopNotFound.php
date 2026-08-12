<?php
/**
 * 找不到分潤賣場例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當依據識別資訊（ID 或 partner slug）查不到分潤賣場時拋出
 */
final class ProfitShopNotFound extends \DomainException {
}
