<?php
/**
 * 商品不存在於分潤賣場例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當嘗試操作（移除、更新覆寫等）一個不存在於該分潤賣場的商品時拋出
 */
final class ProductNotInShop extends \DomainException {
}
