<?php
/**
 * 刪除分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 刪除分潤賣場 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 *
 * 實際呼叫 Repository::delete()，內部走 wp_trash_post。
 */
final class DeleteShop {

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shopRepo Shop Repository
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shopRepo
	) {}

	/**
	 * 執行刪除
	 *
	 * @param int $id 賣場 ID
	 *
	 * @return void
	 *
	 * @throws ProfitShopNotFound 當賣場不存在或 id <= 0
	 */
	public function execute( int $id ): void {
		if ( $id <= 0 ) {
			throw new ProfitShopNotFound( "賣場 ID {$id} 不合法" );
		}

		$existing = $this->shopRepo->find( $id );
		if ( null === $existing ) {
			throw new ProfitShopNotFound( "找不到賣場 ID {$id}" );
		}

		$this->shopRepo->delete( $id );
	}
}
