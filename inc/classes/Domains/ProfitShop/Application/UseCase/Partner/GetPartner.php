<?php
/**
 * 取得分潤夥伴 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Support\PartnerHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;

/**
 * 依 ID 取得分潤夥伴 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 */
final class GetPartner {

	/**
	 * 建構子
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner Repository
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo
	) {}

	/**
	 * 執行查詢
	 *
	 * @param int $id Partner term ID
	 *
	 * @return PartnerOutput
	 *
	 * @throws PartnerNotFound 當 partner 不存在或 id <= 0
	 */
	public function execute( int $id ): PartnerOutput {
		if ( $id <= 0 ) {
			throw new PartnerNotFound( "Partner term {$id} 不合法" );
		}

		$partner = $this->partnerRepo->find_by_id( $id );
		if ( null === $partner ) {
			throw new PartnerNotFound( "找不到 partner term {$id}" );
		}

		return PartnerHydrator::to_output( $partner );
	}
}
