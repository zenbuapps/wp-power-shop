<?php
/**
 * 建立分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ItemValidatorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;

/**
 * 建立分潤賣場 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1、§6.11
 *
 * 流程：
 * 1. 檢查 partner 存在
 * 2. 驗證 items
 * 3. 檢查 slug 衝突
 * 4. 建立 ProfitShop 聚合根並儲存
 * 5. 回傳 ProfitShopOutput
 */
final class CreateShop {

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
	 * 執行建立流程
	 *
	 * @param ProfitShopInput $input 輸入 DTO
	 *
	 * @return ProfitShopOutput 建立後的輸出 DTO
	 *
	 * @throws PartnerNotFound        當 partner 不存在
	 * @throws SlugConflictException  當 slug 衝突
	 * @throws ProfitShopNotFound     當儲存後找不到（理論上不應發生）
	 */
	public function execute( ProfitShopInput $input ): ProfitShopOutput {
		// 先做 ValueObject / 結構驗證（會拋 InvalidProfitRate / InvalidPriceOverride）。
		$shop = ProfitShopHydrator::from_input( 0, $input );

		if ( null === $this->partnerRepo->find_by_id( $input->partner_term_id ) ) {
			throw new PartnerNotFound( "Partner term {$input->partner_term_id} 不存在" );
		}

		$this->itemValidator->validate( $input->items );

		$conflicts = $this->slugDetector->detect( $input->slug, 'profit_shop_slug' );
		if ( ! empty( $conflicts ) ) {
			throw new SlugConflictException( $conflicts );
		}

		$id = $this->shopRepo->save( $shop );

		$persisted = $this->shopRepo->find( $id );
		if ( null === $persisted ) {
			throw new ProfitShopNotFound( "建立後找不到賣場 ID {$id}" );
		}

		return ProfitShopHydrator::to_output( $persisted );
	}
}
