<?php
/**
 * 無效的 ShopMode 例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當 input 提供的 mode 字串不在 ShopMode enum 列舉值中時拋出
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.7（ShopMode = page | tab | section）
 *
 * 由 ExceptionMapper 對映為 400 validation_failed。
 */
final class InvalidShopMode extends \DomainException {
}
