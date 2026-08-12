<?php
/**
 * DeletePartner UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 *
 * 業務規則：刪除 partner 前必須先檢查 is_in_use（仍有 shop 掛在 partner）。
 * 若仍有掛載 → 拋 PartnerStillInUseException。
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\DeletePartner;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerStillInUseException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * DeletePartner UseCase 測試
 */
final class DeletePartnerTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
	}

	/**
	 * happy：partner 存在且未被使用 → 刪除成功
	 *
	 * @group happy
	 */
	public function test_deletes_idle_partner(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry' );

		$useCase = new DeletePartner( partnerRepo: $this->partnerRepo );

		$useCase->execute( id: 5 );

		$this->assertNull( $this->partnerRepo->find_by_id( 5 ) );
	}

	/**
	 * error：partner 不存在 → 拋 PartnerNotFound
	 *
	 * @group error
	 */
	public function test_throws_partner_not_found_when_id_invalid(): void {
		$useCase = new DeletePartner( partnerRepo: $this->partnerRepo );

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( id: 9999 );
	}

	/**
	 * error：partner 仍掛在賣場上 → 拋 PartnerStillInUseException
	 *
	 * 對應 ExceptionMapper 的 422 partner_in_use 對映。
	 *
	 * @group error
	 */
	public function test_throws_partner_still_in_use_when_partner_has_shops(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$this->partnerRepo->mark_in_use( 5, true );

		$useCase = new DeletePartner( partnerRepo: $this->partnerRepo );

		$this->expectException( PartnerStillInUseException::class );
		$useCase->execute( id: 5 );
	}
}
