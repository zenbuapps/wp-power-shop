<?php
/**
 * 不合法的商品變體例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當 variation_id 不屬於指定 product_id 或 variation 不存在時拋出
 *
 * 統一 extends \DomainException：Application 層可一併 catch \DomainException。
 */
final class InvalidVariation extends \DomainException {
}
