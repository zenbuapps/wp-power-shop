<?php
/**
 * 商品項目驗證介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidVariation;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotFound;

/**
 * 商品項目驗證抽象
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.1
 *
 * UseCase 透過此介面注入 ItemValidator，方便單元測試以 fake 替換。
 */
interface ItemValidatorInterface {

	/**
	 * 驗證商品項目陣列
	 *
	 * @param array<int, array<string, mixed>> $items 商品覆寫項目原始陣列
	 *
	 * @throws ProductNotFound 當任一 product_id 不存在
	 * @throws InvalidVariation 當任一 variation 不屬於對應 product
	 * @throws PartnerNotFound  當 partner_term_id 不存在
	 *
	 * @return void
	 */
	public function validate( array $items ): void;
}
