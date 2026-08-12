<?php
/**
 * GetPartnerKpiUseCase 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6 / §7
 *
 * 紅燈合約：
 *   class GetPartnerKpiUseCase {
 *     public function __construct(InMemorySettlementSummaryProvider $summary);
 *     // production：注入 SettlementRepositoryInterface 或 SettlementSummaryProvider
 *     public function execute(int $partner_term_id, FilterCriteria $criteria): KpiReport;
 *   }
 *
 * 核心 invariant：
 *   - partner_term_id 必須由 caller（controller 從 token 解出）指定，UseCase 不接受
 *     從 input/criteria 帶來的覆蓋值。
 *   - KpiReport 數值欄位必須為字串（保留小數精度）。
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\KpiReport;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\GetPartnerKpiUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySettlementSummaryProvider;

/**
 * GetPartnerKpiUseCase 紅燈合約測試
 */
final class GetPartnerKpiUseCaseTest extends TestCase {

	private InMemorySettlementSummaryProvider $summary;

	protected function setUp(): void {
		parent::setUp();
		$this->summary = new InMemorySettlementSummaryProvider();
	}

	/**
	 * happy：partner 5 + 期間 → 回對應的 KpiReport
	 *
	 * @group happy
	 */
	public function test_happy_returns_kpi_report_for_partner(): void {
		$this->summary->seed_kpi(
			5,
			[
				'total_sales'     => '12345.67',
				'profit_pending'  => '500.00',
				'profit_paid'     => '300.00',
				'profit_refunded' => '50.00',
				'period_start'    => '2026-01-01',
				'period_end'      => '2026-01-31',
			]
		);

		$useCase = new GetPartnerKpiUseCase( summary: $this->summary );

		$kpi = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(
				date_start: \strtotime( '2026-01-01' ),
				date_end: \strtotime( '2026-01-31' ),
			),
		);

		$this->assertInstanceOf( KpiReport::class, $kpi );
		$this->assertSame( '12345.67', $kpi->total_sales );
		$this->assertSame( '500.00', $kpi->profit_pending );
		$this->assertSame( '300.00', $kpi->profit_paid );
		$this->assertSame( '50.00', $kpi->profit_refunded );

		// invariant：summary 被呼叫時 partner_term_id 必須是 caller 傳的 5
		$last = $this->summary->last_invocation();
		$this->assertNotNull( $last );
		$this->assertSame( 'summary_for_partner', $last['method'] );
		$this->assertSame( 5, $last['partner_term_id'] );
	}

	/**
	 * happy：empty data → 回 0.00 系列
	 *
	 * @group happy
	 */
	public function test_no_data_returns_zero_kpi(): void {
		$useCase = new GetPartnerKpiUseCase( summary: $this->summary );

		$kpi = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
		);

		$this->assertSame( '0.00', $kpi->total_sales );
		$this->assertSame( '0.00', $kpi->profit_pending );
		$this->assertSame( '0.00', $kpi->profit_paid );
		$this->assertSame( '0.00', $kpi->profit_refunded );
	}

	/**
	 * security：partner_term_id 必須以 caller 傳入為準
	 *
	 * 即使 summary fake 被預埋多個 partner 的資料，UseCase 必須只查 caller 指定的
	 * partner_term_id。此測試確認 UseCase 沒有從 criteria 或 input 拉其他 partner_term_id。
	 *
	 * @group security
	 */
	public function test_partner_term_id_is_locked_to_caller_argument(): void {
		$this->summary->seed_kpi(
			5,
			[ 'total_sales' => '100.00', 'profit_pending' => '0.00', 'profit_paid' => '0.00', 'profit_refunded' => '0.00', 'period_start' => '', 'period_end' => '' ]
		);
		$this->summary->seed_kpi(
			6,
			[ 'total_sales' => '999.00', 'profit_pending' => '0.00', 'profit_paid' => '0.00', 'profit_refunded' => '0.00', 'period_start' => '', 'period_end' => '' ]
		);

		$useCase = new GetPartnerKpiUseCase( summary: $this->summary );

		$kpi = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
		);

		$this->assertSame( '100.00', $kpi->total_sales, 'UseCase 必須以 caller 傳入的 partner_term_id 查詢' );

		$last = $this->summary->last_invocation();
		$this->assertSame( 5, $last['partner_term_id'] );
	}
}
