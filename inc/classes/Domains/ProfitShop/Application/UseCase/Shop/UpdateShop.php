<?php
/**
 * 更新分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ItemValidatorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 更新分潤賣場 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1、§6.11
 */
final class UpdateShop {

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shopRepo      Shop Repository
	 * @param PartnerRepositoryInterface    $partnerRepo   Partner Repository
	 * @param ItemValidatorInterface        $itemValidator Item 驗證器
	 * @param SlugConflictDetectorInterface $slugDetector  Slug 衝突偵測器
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shopRepo,
		private readonly PartnerRepositoryInterface $partnerRepo,
		private readonly ItemValidatorInterface $itemValidator,
		private readonly SlugConflictDetectorInterface $slugDetector
	) {}

	/**
	 * 執行更新
	 *
	 * @param int             $id    賣場 ID
	 * @param ProfitShopInput $input 輸入 DTO
	 *
	 * @return ProfitShopOutput
	 *
	 * @throws ProfitShopNotFound    當賣場不存在
	 * @throws PartnerNotFound       當 partner 不存在
	 * @throws SlugConflictException 當新 slug 與其他資源衝突
	 */
	public function execute( int $id, ProfitShopInput $input ): ProfitShopOutput {
		$existing = $this->shopRepo->find( $id );
		if ( null === $existing ) {
			throw new ProfitShopNotFound( "找不到賣場 ID {$id}" );
		}

		if ( null === $this->partnerRepo->find_by_id( $input->partner_term_id ) ) {
			throw new PartnerNotFound( "Partner term {$input->partner_term_id} 不存在" );
		}

		$this->itemValidator->validate( $input->items );

		// slug 沒變則跳過衝突檢查（避免自己跟自己衝突）。
		if ( $input->slug !== $existing->slug ) {
			$conflicts = $this->slugDetector->detect( $input->slug, 'profit_shop_slug' );
			if ( ! empty( $conflicts ) ) {
				throw new SlugConflictException( $conflicts );
			}
		}

		$shop = ProfitShopHydrator::from_input( $id, $input );
		$this->shopRepo->save( $shop );

		$persisted = $this->shopRepo->find( $id );
		if ( null === $persisted ) {
			throw new ProfitShopNotFound( "更新後找不到賣場 ID {$id}" );
		}

		return ProfitShopHydrator::to_output( $persisted );
	}
}
