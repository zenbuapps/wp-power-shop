<?php
/**
 * 更新分潤夥伴 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Support\PartnerHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;

/**
 * 更新分潤夥伴 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 */
final class UpdatePartner {

	/**
	 * 建構子
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner Repository
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo
	) {}

	/**
	 * 執行更新
	 *
	 * @param int          $id    Partner term ID
	 * @param PartnerInput $input Partner 輸入 DTO
	 *
	 * @return PartnerOutput 更新後輸出 DTO（不含密碼）
	 *
	 * @throws PartnerNotFound 當 partner 不存在（slug 不合法時 PartnerSlug 會額外拋 InvalidPartnerSlug）
	 */
	public function execute( int $id, PartnerInput $input ): PartnerOutput {
		$existing = $this->partnerRepo->find_by_id( $id );
		if ( null === $existing ) {
			throw new PartnerNotFound( "找不到 partner term {$id}" );
		}

		$slug = new PartnerSlug( $input->slug );

		$snapshot = new PartnerSnapshot(
			term_id: $id,
			name: $input->name,
			slug: $slug,
			contact_email: $input->contact_email
		);

		$this->partnerRepo->save( $snapshot, $input->password );

		$persisted = $this->partnerRepo->find_by_id( $id );
		if ( null === $persisted ) {
			throw new PartnerNotFound( "更新後找不到 partner term {$id}" );
		}

		return PartnerHydrator::to_output( $persisted );
	}
}
