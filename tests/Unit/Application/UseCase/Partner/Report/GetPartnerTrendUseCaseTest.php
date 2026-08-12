<?php
/**
 * GetPartnerTrendUseCase 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6 / §7
 *
 * 紅燈合約：
 *   class GetPartnerTrendUseCase {
 *     public function __construct(InMemorySettlementSummaryProvider $summary);
 *     public function execute(int $partner_term_id, FilterCriteria $criteria, string $interval): TrendReport;
 *   }
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\TrendReport;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\GetPartnerTrendUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySettlementSummaryProvider;

/**
 * GetPartnerTrendUseCase 紅燈合約測試
 */
final class GetPartnerTrendUseCaseTest extends TestCase {

	private InMemorySettlementSummaryProvider $summary;

	protected function setUp(): void {
		parent::setUp();
		$this->summary = new InMemorySettlementSummaryProvider();
	}

	/**
	 * happy：回 TrendReport 含 series
	 *
	 * @group happy
	 */
	public function test_happy_returns_trend_series(): void {
		$this->summary->seed_trend(
			5,
			[
				[ 'date' => '2026-01-01', 'profit' => '100.00', 'sales' => '500.00' ],
				[ 'date' => '2026-01-02', 'profit' => '150.00', 'sales' => '750.00' ],
			]
		);

		$useCase = new GetPartnerTrendUseCase( summary: $this->summary );

		$trend = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
			interval: 'day',
		);

		$this->assertInstanceOf( TrendReport::class, $trend );
		$this->assertCount( 2, $trend->series );
		$this->assertSame( '2026-01-01', $trend->series[0]['date'] );

		$last = $this->summary->last_invocation();
		$this->assertSame( 'trend_for_partner', $last['method'] );
		$this->assertSame( 5, $last['partner_term_id'] );
		$this->assertSame( 'day', $last['extra']['interval'] );
	}

	/**
	 * happy：interval=week 應傳遞給 summary provider
	 *
	 * @group happy
	 */
	public function test_passes_interval_to_provider(): void {
		$useCase = new GetPartnerTrendUseCase( summary: $this->summary );

		$useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
			interval: 'week',
		);

		$this->assertSame( 'week', $this->summary->last_invocation()['extra']['interval'] );
	}

	/**
	 * security：partner_term_id 鎖死於 caller 傳入值
	 *
	 * @group security
	 */
	public function test_partner_term_id_locked_to_caller(): void {
		$this->summary->seed_trend( 5, [ [ 'date' => 'A' ] ] );
		$this->summary->seed_trend( 6, [ [ 'date' => 'B' ] ] );

		$useCase = new GetPartnerTrendUseCase( summary: $this->summary );

		$trend = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
			interval: 'day',
		);

		$this->assertSame( 'A', $trend->series[0]['date'] );
		$this->assertSame( 5, $this->summary->last_invocation()['partner_term_id'] );
	}
}
