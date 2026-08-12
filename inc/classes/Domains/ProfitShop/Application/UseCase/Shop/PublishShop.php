<?php
/**
 * 發佈分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 發佈分潤賣場 UseCase（status: draft → publish）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 *
 * 已是 publish 時為冪等操作（不拋例外）。
 */
final class PublishShop {

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shopRepo Shop Repository
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shopRepo
	) {}

	/**
	 * 執行發佈
	 *
	 * @param int $id 賣場 ID
	 *
	 * @return ProfitShopOutput
	 *
	 * @throws ProfitShopNotFound 當賣場不存在
	 */
	public function execute( int $id ): ProfitShopOutput {
		$shop = $this->shopRepo->find( $id );
		if ( null === $shop ) {
			throw new ProfitShopNotFound( "找不到賣場 ID {$id}" );
		}

		if ( 'publish' !== $shop->status() ) {
			$shop->change_status( 'publish' );
			$this->shopRepo->save( $shop );
			$shop = $this->shopRepo->find( $id ) ?? $shop;
		}

		return ProfitShopHydrator::to_output( $shop );
	}
}
