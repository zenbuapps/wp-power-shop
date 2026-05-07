<?php
/**
 * ListPartners UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2（GET profit-partners）
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\ListPartners;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * ListPartners UseCase 測試
 */
final class ListPartnersTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
	}

	/**
	 * happy：repository 有資料 → 全部以 PartnerOutput 陣列回傳，且不包含密碼
	 *
	 * @group happy
	 * @group security
	 */
	public function test_returns_all_partners_without_password(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry', 'jerry@example.com' );
		$this->partnerRepo->seed( 6, 'Mary', 'mary', null );

		$useCase = new ListPartners( partnerRepo: $this->partnerRepo );

		$output = $useCase->execute();

		$this->assertCount( 2, $output );
		foreach ( $output as $partner ) {
			$arr = $partner->to_array();
			$this->assertArrayNotHasKey( 'password', $arr, 'PartnerOutput 不應含 password 欄位' );
		}
	}

	/**
	 * edge：repository 為空 → 回空陣列
	 *
	 * @group edge
	 */
	public function test_returns_empty_array_when_no_partners(): void {
		$useCase = new ListPartners( partnerRepo: $this->partnerRepo );

		$this->assertSame( [], $useCase->execute() );
	}

	/**
	 * happy：order by id ascending（穩定的回傳順序）
	 *
	 * @group happy
	 */
	public function test_returns_partners_in_id_ascending_order(): void {
		$this->partnerRepo->seed( 7, 'C', 'c-slug' );
		$this->partnerRepo->seed( 5, 'A', 'a-slug' );
		$this->partnerRepo->seed( 6, 'B', 'b-slug' );

		$useCase = new ListPartners( partnerRepo: $this->partnerRepo );

		$output = $useCase->execute();

		$ids = array_map( static fn( $p ): int => $p->id, $output );
		sort( $ids );
		$this->assertSame( [ 5, 6, 7 ], $ids );
	}
}
