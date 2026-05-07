<?php
/**
 * 取消發佈分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 取消發佈分潤賣場 UseCase（status: publish → draft）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 *
 * 已是 draft 時為冪等操作。
 */
final class UnpublishShop {

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shopRepo Shop Repository
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shopRepo
	) {}

	/**
	 * 執行取消發佈
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

		if ( 'draft' !== $shop->status() ) {
			$shop->change_status( 'draft' );
			$this->shopRepo->save( $shop );
			$shop = $this->shopRepo->find( $id ) ?? $shop;
		}

		return ProfitShopHydrator::to_output( $shop );
	}
}
