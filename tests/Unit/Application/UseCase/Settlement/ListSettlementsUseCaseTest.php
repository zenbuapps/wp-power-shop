<?php
/**
 * ListSettlementsUseCase / GetSettlementSummaryUseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group settlement
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementAdminSummaryOutput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementListOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\GetSettlementSummaryUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\ListSettlementsUseCase;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySettlementQueryProvider;

/**
 * 後台結算列表與摘要 UseCase 契約測試
 */
final class ListSettlementsUseCaseTest extends TestCase {

	private InMemorySettlementQueryProvider $query;

	protected function setUp(): void {
		parent::setUp();
		$this->query = new InMemorySettlementQueryProvider();
	}

	/**
	 * 產生 N 筆假資料
	 *
	 * @param int $count 筆數
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( int $count ): array {
		$rows = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = [
				'order_item_id' => 1000 + $i,
				'order_id'      => 500 + $i,
				'product_name'  => "商品 {$i}",
				'profit_amount' => '100.00',
				'status'        => 'pending',
			];
		}
		return $rows;
	}

	/**
	 * happy：回傳 SettlementListOutput 含分頁中介資料
	 *
	 * @group happy
	 */
	public function test_returns_paginated_list(): void {
		$this->query->seed_rows( $this->rows( 5 ) );

		$useCase = new ListSettlementsUseCase( query: $this->query );
		$output  = $useCase->execute( SettlementFilterInput::from_array( [ 'per_page' => 20 ] ) );

		$this->assertInstanceOf( SettlementListOutput::class, $output );
		$this->assertCount( 5, $output->items );
		$this->assertSame( 5, $output->total );
		$this->assertSame( 1, $output->page );
		$this->assertSame( 20, $output->per_page );
	}

	/**
	 * 🔴 happy：total 來自 count 查詢，不是當前頁的筆數
	 *
	 * 用 count($items) 當 total 會讓前端分頁器永遠只顯示一頁。
	 *
	 * @group happy
	 */
	public function test_total_is_full_count_not_page_size(): void {
		$this->query->seed_rows( $this->rows( 57 ) );

		$useCase = new ListSettlementsUseCase( query: $this->query );
		$output  = $useCase->execute( SettlementFilterInput::from_array( [ 'per_page' => 20 ] ) );

		$this->assertCount( 20, $output->items, '當前頁只有 20 筆' );
		$this->assertSame( 57, $output->total, 'total 必須是全部符合條件的筆數' );
	}

	/**
	 * happy：第二頁取到正確的切片
	 *
	 * @group happy
	 */
	public function test_second_page_returns_correct_slice(): void {
		$this->query->seed_rows( $this->rows( 25 ) );

		$useCase = new ListSettlementsUseCase( query: $this->query );
		$output  = $useCase->execute(
			SettlementFilterInput::from_array(
				[
					'page'     => 2,
					'per_page' => 20,
				]
			)
		);

		$this->assertCount( 5, $output->items );
		$this->assertSame( 1021, $output->items[0]['order_item_id'] );
		$this->assertSame( 2, $output->page );
	}

	/**
	 * happy：過濾條件原封不動傳給 Provider
	 *
	 * @group happy
	 */
	public function test_filter_is_passed_through_to_provider(): void {
		$this->query->seed_rows( [] );

		$filter = SettlementFilterInput::from_array(
			[
				'partner_id' => 5,
				'shop_ids'   => [ 100, 200 ],
				'statuses'   => [ 'paid' ],
			]
		);

		( new ListSettlementsUseCase( query: $this->query ) )->execute( $filter );

		$this->assertCount( 1, $this->query->filters_seen );
		$seen = $this->query->filters_seen[0];
		$this->assertSame( [ 5 ], $seen->partner_term_ids );
		$this->assertSame( [ 100, 200 ], $seen->shop_ids );
		$this->assertSame( [ 'paid' ], $seen->statuses );
	}

	/**
	 * edge：查無資料時 items 為空、total 為 0（不是錯誤）
	 *
	 * @group edge
	 */
	public function test_empty_result_is_not_an_error(): void {
		$this->query->seed_rows( [] );

		$output = ( new ListSettlementsUseCase( query: $this->query ) )
			->execute( SettlementFilterInput::from_array( [] ) );

		$this->assertSame( [], $output->items );
		$this->assertSame( 0, $output->total );
	}

	/**
	 * happy：摘要輸出對映到 SettlementAdminSummaryOutput
	 *
	 * @group happy
	 */
	public function test_summary_maps_provider_output(): void {
		$this->query->seed_summary(
			[
				'pending_amount'  => '1500.00',
				'pending_count'   => 23,
				'paid_amount'     => '800.50',
				'paid_count'      => 10,
				'refunded_amount' => '99.99',
				'refunded_count'  => 2,
				'total_sales'     => '12000.00',
				'total_count'     => 35,
			]
		);

		$output = ( new GetSettlementSummaryUseCase( query: $this->query ) )
			->execute( SettlementFilterInput::from_array( [] ) );

		$this->assertInstanceOf( SettlementAdminSummaryOutput::class, $output );
		$this->assertSame( '1500.00', $output->pending_amount );
		$this->assertSame( 23, $output->pending_count );
		$this->assertSame( '800.50', $output->paid_amount );
		$this->assertSame( 10, $output->paid_count );
		$this->assertSame( '99.99', $output->refunded_amount );
		$this->assertSame( 2, $output->refunded_count );
		$this->assertSame( '12000.00', $output->total_sales );
	}

	/**
	 * edge：Provider 回空陣列時摘要退回全 0（而不是缺鍵爆掉）
	 *
	 * @group edge
	 */
	public function test_summary_defaults_to_zero_when_provider_returns_nothing(): void {
		$output = ( new GetSettlementSummaryUseCase( query: $this->query ) )
			->execute( SettlementFilterInput::from_array( [] ) );

		$this->assertSame( '0.00', $output->pending_amount );
		$this->assertSame( 0, $output->pending_count );
		$this->assertSame( '0.00', $output->paid_amount );
		$this->assertSame( 0, $output->total_count );
	}

	/**
	 * 🔴 happy：摘要與列表吃到完全相同的過濾條件
	 *
	 * 兩者若不同步，摘要卡的金額會與表格內容對不起來——而站長是照卡片金額去轉帳的。
	 *
	 * @group happy
	 */
	public function test_summary_and_list_receive_identical_filter(): void {
		$this->query->seed_rows( $this->rows( 3 ) );
		$this->query->seed_summary( [ 'pending_amount' => '300.00' ] );

		$filter = SettlementFilterInput::from_array(
			[
				'partner_id' => 5,
				'status'     => 'pending',
				'date_start' => 1700000000,
			]
		);

		( new ListSettlementsUseCase( query: $this->query ) )->execute( $filter );
		( new GetSettlementSummaryUseCase( query: $this->query ) )->execute( $filter );

		$this->assertEquals(
			$this->query->filters_seen[0]->to_array(),
			$this->query->summary_filters_seen[0]->to_array(),
			'摘要與列表必須以同一組條件查詢'
		);
	}

	/**
	 * smoke：金額欄位一律是字串（不得被轉成 float）
	 *
	 * @group smoke
	 */
	public function test_summary_amounts_stay_strings(): void {
		$this->query->seed_summary( [ 'paid_amount' => '0.10' ] );

		$array = ( new GetSettlementSummaryUseCase( query: $this->query ) )
			->execute( SettlementFilterInput::from_array( [] ) )
			->to_array();

		$this->assertIsString( $array['paid_amount'] );
		$this->assertSame( '0.10', $array['paid_amount'] );
	}
}
