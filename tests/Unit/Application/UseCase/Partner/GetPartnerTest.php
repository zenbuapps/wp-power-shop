<?php
/**
 * GetPartner UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\GetPartner;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * GetPartner UseCase 測試
 */
final class GetPartnerTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
	}

	/**
	 * happy：partner 存在 → 回傳 PartnerOutput
	 *
	 * @group happy
	 */
	public function test_returns_partner_output_when_id_exists(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry', 'jerry@example.com' );

		$useCase = new GetPartner( partnerRepo: $this->partnerRepo );

		$output = $useCase->execute( id: 5 );

		$this->assertInstanceOf( PartnerOutput::class, $output );
		$this->assertSame( 5, $output->id );
		$this->assertSame( 'Jerry', $output->name );
	}

	/**
	 * error：partner 不存在 → 拋 PartnerNotFound
	 *
	 * @group error
	 */
	public function test_throws_partner_not_found_when_id_invalid(): void {
		$useCase = new GetPartner( partnerRepo: $this->partnerRepo );

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( id: 9999 );
	}

	/**
	 * edge：id <= 0 → 拋 PartnerNotFound
	 *
	 * @group edge
	 */
	public function test_throws_partner_not_found_when_id_is_zero(): void {
		$useCase = new GetPartner( partnerRepo: $this->partnerRepo );

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( id: 0 );
	}
}
