<?php
/**
 * PartnerReportScopeIsolation 單元測試（Phase 3-C 紅燈，核心 invariant）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8（partner_id 範圍鎖死）
 *
 * 核心 invariant：
 *   所有 Partner Report UseCase 必須將 partner_term_id 鎖死於 caller 傳入值，
 *   不接受從 input/criteria 帶來的覆蓋值。即使 attacker 在 query string 帶
 *   ?partner_term_id=B_id，UseCase 必須只用 controller 從 token 解出的 A_id 查詢。
 *
 *   FilterCriteria 沒有 partner_term_id 欄位（這是設計意圖；驗證一下沒人把它偷塞進去）。
 *
 * 此測試**集中驗證跨 partner_term_id 不被混搭**——任一 UseCase 出錯都會導致紅燈不過。
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\GetPartnerKpiUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\GetPartnerTrendUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\ListPartnerSettlementsUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySettlementSummaryProvider;

/**
 * Partner Report 範圍隔離測試
 */
final class PartnerReportScopeIsolationTest extends TestCase {

	private InMemorySettlementSummaryProvider $summary;

	protected function setUp(): void {
		parent::setUp();
		$this->summary = new InMemorySettlementSummaryProvider();

		// 預埋 partner 5（受害者）+ partner 6（攻擊目標）的所有資料
		$this->summary->seed_kpi(
			5,
			[ 'total_sales' => '100.00', 'profit_pending' => '0.00', 'profit_paid' => '0.00', 'profit_refunded' => '0.00', 'period_start' => '', 'period_end' => '' ]
		);
		$this->summary->seed_kpi(
			6,
			[ 'total_sales' => '999999.99', 'profit_pending' => '0.00', 'profit_paid' => '0.00', 'profit_refunded' => '0.00', 'period_start' => '', 'period_end' => '' ]
		);
		$this->summary->seed_trend( 5, [ [ 'date' => 'A' ] ] );
		$this->summary->seed_trend( 6, [ [ 'date' => 'B-attacker' ] ] );
		$this->summary->seed_list( 5, [ [ 'order_item_id' => 101, 'profit_amount' => '50.00' ] ] );
		$this->summary->seed_list( 6, [ [ 'order_item_id' => 999, 'profit_amount' => '999.99' ] ] );
	}

	/**
	 * security：FilterCriteria 沒有 partner_term_id 屬性（防止意外被加入）
	 *
	 * 此檢查在 design-time 鎖定，避免有人後續 PR 偷加 partner_term_id 進 FilterCriteria
	 * 而導致 attacker 可從 query string 蓋掉。
	 *
	 * @group security
	 */
	public function test_filter_criteria_does_not_expose_partner_term_id_field(): void {
		$reflection = new \ReflectionClass( FilterCriteria::class );
		$properties = $reflection->getProperties();

		foreach ( $properties as $prop ) {
			$this->assertNotSame(
				'partner_term_id',
				$prop->getName(),
				'FilterCriteria 不可有 partner_term_id 屬性，避免 attacker 透過 query string 跨 partner 查詢'
			);
		}
	}

	/**
	 * security：GetPartnerKpiUseCase 嚴格使用 caller 的 partner_term_id
	 *
	 * @group security
	 */
	public function test_kpi_use_case_locks_partner_term_id(): void {
		$useCase = new GetPartnerKpiUseCase( summary: $this->summary );

		$kpi = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
		);

		$this->assertSame( '100.00', $kpi->total_sales );
		$this->assertSame( 5, $this->summary->last_invocation()['partner_term_id'] );
	}

	/**
	 * security：GetPartnerTrendUseCase 嚴格使用 caller 的 partner_term_id
	 *
	 * @group security
	 */
	public function test_trend_use_case_locks_partner_term_id(): void {
		$this->summary->reset_invocations();
		$useCase = new GetPartnerTrendUseCase( summary: $this->summary );

		$trend = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
			interval: 'day',
		);

		$this->assertSame( 'A', $trend->series[0]['date'] );
		$this->assertSame( 5, $this->summary->last_invocation()['partner_term_id'] );
	}

	/**
	 * security：ListPartnerSettlementsUseCase 嚴格使用 caller 的 partner_term_id
	 *
	 * @group security
	 */
	public function test_list_use_case_locks_partner_term_id(): void {
		$this->summary->reset_invocations();
		$useCase = new ListPartnerSettlementsUseCase( summary: $this->summary );

		$output = $useCase->execute(
			partner_term_id: 5,
			criteria: new FilterCriteria(),
		);

		$this->assertCount( 1, $output->items );
		$this->assertSame( 101, $output->items[0]['order_item_id'] );
		$this->assertSame( 5, $this->summary->last_invocation()['partner_term_id'] );

		// 重要：output 不可包含 partner 6 的資料
		foreach ( $output->items as $item ) {
			$this->assertNotSame( 999, $item['order_item_id'] );
		}
	}

	/**
	 * security：execute 介面不接受 partner_term_id 從別處覆蓋
	 *
	 * 透過 Reflection 確認 execute 方法簽名第一個參數叫 partner_term_id 且為 int。
	 *
	 * @group security
	 */
	public function test_execute_signature_takes_partner_term_id_as_first_explicit_arg(): void {
		foreach (
			[
				GetPartnerKpiUseCase::class,
				GetPartnerTrendUseCase::class,
				ListPartnerSettlementsUseCase::class,
			] as $class
		) {
			$ref    = new \ReflectionMethod( $class, 'execute' );
			$params = $ref->getParameters();
			$this->assertGreaterThanOrEqual( 1, count( $params ), "{$class}::execute 至少需要一個參數" );
			$this->assertSame(
				'partner_term_id',
				$params[0]->getName(),
				"{$class}::execute 第一個參數必須叫 partner_term_id（caller 從 token 解出）"
			);
			$type = $params[0]->getType();
			$this->assertInstanceOf( \ReflectionNamedType::class, $type );
			$this->assertSame( 'int', $type->getName() );
		}
	}
}
