<?php
/**
 * 複製分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 複製分潤賣場 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 *
 * 行為：
 * - 新賣場 ID 由 Repository 配發（不沿用原 ID）
 * - slug 加上 -copy 後綴
 * - title 加上 (Copy) 後綴
 * - status 強制為 draft（避免立即上線）
 */
final class DuplicateShop {

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shopRepo Shop Repository
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shopRepo
	) {}

	/**
	 * 執行複製
	 *
	 * @param int $id 原始賣場 ID
	 *
	 * @return ProfitShopOutput 複製出的新賣場
	 *
	 * @throws ProfitShopNotFound 當原始賣場不存在
	 */
	public function execute( int $id ): ProfitShopOutput {
		$source = $this->shopRepo->find( $id );
		if ( null === $source ) {
			throw new ProfitShopNotFound( "找不到原始賣場 ID {$id}" );
		}

		$copy = new ProfitShop(
			id: 0,
			title: $source->title . ' (Copy)',
			slug: $source->slug . '-copy',
			status: 'draft',
			mode: $source->mode,
			partner_term_id: $source->partner_term_id,
			rate: $source->rate,
			items: $source->items(),
			settings: $source->settings
		);

		$new_id    = $this->shopRepo->save( $copy );
		$persisted = $this->shopRepo->find( $new_id );
		if ( null === $persisted ) {
			throw new ProfitShopNotFound( "複製後找不到賣場 ID {$new_id}" );
		}

		return ProfitShopHydrator::to_output( $persisted );
	}
}
