<?php
/**
 * 商品已存在於分潤賣場例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當嘗試重複加入已存在於同一分潤賣場的商品時拋出
 */
final class ProductAlreadyInShop extends \DomainException {
}
