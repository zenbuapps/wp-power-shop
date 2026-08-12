<?php
/**
 * 刪除分潤夥伴 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerStillInUseException;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;

/**
 * 刪除分潤夥伴 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 *
 * 業務規則：partner 仍掛在任何賣場時禁止刪除（PartnerStillInUseException）。
 */
final class DeletePartner {

	/**
	 * 建構子
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner Repository
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo
	) {}

	/**
	 * 執行刪除
	 *
	 * @param int $id Partner term ID
	 *
	 * @return void
	 *
	 * @throws PartnerNotFound           當 partner 不存在
	 * @throws PartnerStillInUseException 當 partner 仍被任何賣場引用
	 */
	public function execute( int $id ): void {
		$existing = $this->partnerRepo->find_by_id( $id );
		if ( null === $existing ) {
			throw new PartnerNotFound( "找不到 partner term {$id}" );
		}

		if ( $this->partnerRepo->is_in_use( $id ) ) {
			throw new PartnerStillInUseException( "Partner term {$id} 仍被分潤賣場引用，無法刪除" );
		}

		$this->partnerRepo->delete( $id );
	}
}
