<?php
/**
 * 列出分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 列出分潤賣場 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.2、§4.1
 *
 * 支援可選的 partner_term_id 過濾。
 */
final class ListShops {

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shopRepo Shop Repository
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shopRepo
	) {}

	/**
	 * 執行列表查詢
	 *
	 * @param int|null $partner_term_id 可選的 partner 過濾條件（null 代表不過濾）
	 *
	 * @return ProfitShopOutput[]
	 */
	public function execute( ?int $partner_term_id = null ): array {
		$shops = ( null === $partner_term_id || $partner_term_id <= 0 )
		? $this->shopRepo->all()
		: $this->shopRepo->find_by_partner( $partner_term_id );

		$out = [];
		foreach ( $shops as $shop ) {
			$out[] = ProfitShopHydrator::to_output( $shop );
		}
		return $out;
	}
}
