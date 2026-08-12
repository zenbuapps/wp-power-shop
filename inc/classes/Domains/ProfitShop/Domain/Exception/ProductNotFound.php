<?php
/**
 * 找不到商品例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當查無對應商品（依 product_id）時拋出
 *
 * 統一 extends \DomainException：Application 層可一併 catch \DomainException。
 */
final class ProductNotFound extends \DomainException {
}
