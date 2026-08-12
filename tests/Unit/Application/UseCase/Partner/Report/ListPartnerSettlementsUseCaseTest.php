<?php
/**
 * ListPartnerSettlementsUseCase 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3、§7
 *
 * 紅燈合約：
 *   class ListPartnerSettlementsUseCase {
 *     public function __construct(InMemorySettlementSummaryProvider $summary);
 *     public function execute(int $partner_term_id, FilterCriteria $criteria): SettlementListOutput;
 *   }
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementListOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\ListPartnerSettlementsUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySettlementSummaryProvider;

/**
 * ListPartnerSettlementsUseCase 紅燈合約測試
 */
final class ListPartnerSettlementsUseCaseTest extends TestCase {

	private InMemorySettlementSummaryProvider $summary;

	protected function setUp(): void {
		parent::setUp();
		$this->summary = new InMemorySettlementSummaryProvider();
	}

	/**
	 * happy：回 SettlementListOutput 含分頁中介資料
	 *
	 * @group happy
	 */
	public function test_happy_returns_paginated_list(): void {
		$this->summary->seed_list(
			5,
			[
				[ 'order_item_id' => 101, 'profit_amount' => '50.00', 'status' => 'pending' ],
				[ 'order_item_id' => 102, 'profit_amount' => '80.00', 'status' => 'paid' ],
			]
		);

		$useCase = new ListPartnerSettlementsUseCase( summary: $this->summary );

		$output = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria( page: 1, per_page: 20 ),
		);

		$this->assertInstanceOf( SettlementListOutput::class, $output );
		$this->assertCount( 2, $output->items );
		$this->assertSame( 1, $output->page );
		$this->assertSame( 20, $output->per_page );
	}

	/**
	 * happy：empty list → items 空 + total=0
	 *
	 * @group happy
	 */
	public function test_empty_list_returns_zero_total(): void {
		$useCase = new ListPartnerSettlementsUseCase( summary: $this->summary );

		$output = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
		);

		$this->assertSame( [], $output->items );
		$this->assertSame( 0, $output->total );
	}

	/**
	 * security：跨 partner 不洩漏資料
	 *
	 * 預埋 partner 5 跟 6 的資料；caller 用 5 → 不可看到 6 的東西
	 *
	 * @group security
	 */
	public function test_does_not_leak_other_partners_settlements(): void {
		$this->summary->seed_list(
			5,
			[ [ 'order_item_id' => 101, 'profit_amount' => '50.00' ] ]
		);
		$this->summary->seed_list(
			6,
			[ [ 'order_item_id' => 999, 'profit_amount' => '999.99' ] ]
		);

		$useCase = new ListPartnerSettlementsUseCase( summary: $this->summary );

		$output = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
		);

		$this->assertCount( 1, $output->items );
		$this->assertSame( 101, $output->items[0]['order_item_id'] );
		// 不可外洩 partner 6 的 999 筆
		foreach ( $output->items as $item ) {
			$this->assertNotSame( 999, $item['order_item_id'] );
		}
	}
}
