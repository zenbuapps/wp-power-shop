<?php
/**
 * 取得單一分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 依 ID 取得分潤賣場 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 */
final class GetShop {

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shopRepo Shop Repository
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shopRepo
	) {}

	/**
	 * 執行查詢
	 *
	 * @param int $id 賣場 ID
	 *
	 * @return ProfitShopOutput
	 *
	 * @throws ProfitShopNotFound 當賣場不存在或 id <= 0
	 */
	public function execute( int $id ): ProfitShopOutput {
		if ( $id <= 0 ) {
			throw new ProfitShopNotFound( "賣場 ID {$id} 不合法" );
		}

		$shop = $this->shopRepo->find( $id );
		if ( null === $shop ) {
			throw new ProfitShopNotFound( "找不到賣場 ID {$id}" );
		}

		return ProfitShopHydrator::to_output( $shop );
	}
}
