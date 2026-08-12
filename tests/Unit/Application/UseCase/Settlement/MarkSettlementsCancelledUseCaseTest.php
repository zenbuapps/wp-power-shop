<?php
/**
 * MarkSettlementsCancelledUseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§5.5
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group settlement
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditRecorder;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\MarkSettlementsCancelledUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\InMemorySettlementAuditLog;
use Tests\Support\InMemorySettlementRepository;
use Tests\Support\InMemorySettlementStatusMutator;

/**
 * 批次取消 UseCase 契約測試
 */
final class MarkSettlementsCancelledUseCaseTest extends TestCase {

	private const NOW = 1_700_000_000;

	private InMemorySettlementRepository $repo;
	private InMemorySettlementStatusMutator $mutator;
	private InMemorySettlementAuditLog $audit;
	private MarkSettlementsCancelledUseCase $useCase;

	protected function setUp(): void {
		parent::setUp();

		$this->repo    = new InMemorySettlementRepository();
		$this->mutator = new InMemorySettlementStatusMutator();
		$this->audit   = new InMemorySettlementAuditLog();

		$this->useCase = new MarkSettlementsCancelledUseCase(
			settlements: $this->repo,
			mutator: $this->mutator,
			auditor: new SettlementAuditRecorder(
				log: $this->audit,
				clock: new FixedClock( self::NOW )
			),
		);
	}

	/**
	 * 建立一筆結算紀錄並預埋進 Repository
	 *
	 * @param int    $order_item_id 訂單品項 ID
	 * @param string $status        初始狀態
	 * @param string $profit_amount 分潤金額
	 *
	 * @return SettlementRecord
	 */
	private function seed(
		int $order_item_id,
		string $status = SettlementRecord::STATUS_PENDING,
		string $profit_amount = '100.00'
	): SettlementRecord {
		$record = new SettlementRecord(
			order_item_id: $order_item_id,
			shop_id: 900,
			partner_term_id: 5,
			rate: new ProfitRate( 20 ),
			actual_price: '500.00',
			profit_amount: $profit_amount,
			status: $status,
		);
		$this->repo->seed( $record );
		$this->mutator->seed_status( $order_item_id, $status );
		return $record;
	}

	/**
	 * happy：pending 轉 cancelled
	 *
	 * @group happy
	 */
	public function test_pending_records_are_cancelled(): void {
		$this->seed( 101 );

		$output = $this->useCase->execute( [ 101 ], 42 );

		$this->assertSame( [ 101 ], $output->updated_ids );
		$this->assertSame( SettlementRecord::STATUS_CANCELLED, $this->mutator->current_status( 101 ) );
	}

	/**
	 * security：取消**不得**寫入 settled_at / settled_by
	 *
	 * 那兩欄的語意是「撥款時間 / 撥款人」，被取消操作污染後，任何以 settled_at
	 * 統計實際撥款的報表都會算進根本沒撥出去的品項。
	 *
	 * @group security
	 */
	public function test_cancel_does_not_write_settlement_audit_fields(): void {
		$this->seed( 101 );

		$this->useCase->execute( [ 101 ], 42 );

		$this->assertSame(
			[],
			$this->mutator->settled,
			'取消不是撥款，不該寫入 _settled_at / _settled_by（否則以 settled_at 統計實際撥款的報表會算進沒撥出去的品項）'
		);
	}

	/**
	 * security：已撥款（paid）不可被取消——那等於帳面上抹掉一筆真實支出
	 *
	 * @group security
	 */
	public function test_paid_records_cannot_be_cancelled(): void {
		$this->seed( 202, SettlementRecord::STATUS_PAID );

		$output = $this->useCase->execute( [ 202 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertArrayHasKey( 202, $output->skipped );
		$this->assertStringContainsString( '人工沖回', $output->skipped[202], '應引導站長改用正確的操作' );

		$this->assertSame( SettlementRecord::STATUS_PAID, $this->mutator->current_status( 202 ) );
		$this->assertCount( 0, $this->mutator->cas_calls, 'Domain 已擋下，不該再打 DB' );
	}

	/**
	 * error：refunded / cancelled 亦不可再取消
	 *
	 * @group error
	 */
	public function test_refunded_and_cancelled_are_skipped(): void {
		$this->seed( 301, SettlementRecord::STATUS_REFUNDED );
		$this->seed( 302, SettlementRecord::STATUS_CANCELLED );

		$output = $this->useCase->execute( [ 301, 302 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertCount( 2, $output->skipped );
	}

	/**
	 * happy：稽核紀錄型別為 cancel（不可與 settle 混用）
	 *
	 * @group happy
	 */
	public function test_audit_entry_uses_cancel_type(): void {
		$this->seed( 101, SettlementRecord::STATUS_PENDING, '80.00' );

		$this->useCase->execute( [ 101 ], 42 );

		$entries = $this->audit->read( 5 );
		$this->assertCount( 1, $entries );
		$this->assertSame( SettlementAuditRecorder::TYPE_CANCEL, $entries[0]['type'] );
		$this->assertSame( '80.00', $entries[0]['total'] );
	}

	/**
	 * error：找不到的 ID 進 skipped
	 *
	 * @group error
	 */
	public function test_missing_record_is_skipped(): void {
		$output = $this->useCase->execute( [ 999 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertArrayHasKey( 999, $output->skipped );
	}

	/**
	 * error：空清單 fail-fast
	 *
	 * @group error
	 */
	public function test_empty_id_list_throws(): void {
		$this->expectException( \DomainException::class );
		$this->useCase->execute( [], 42 );
	}

	/**
	 * security：超過批次上限直接拒絕
	 *
	 * @group security
	 */
	public function test_batch_over_limit_throws(): void {
		$this->expectException( \DomainException::class );
		$this->useCase->execute( range( 1, MarkSettlementsCancelledUseCase::MAX_BATCH_SIZE + 1 ), 42 );
	}

	/**
	 * security：操作者 ID 為 0 時 fail-fast
	 *
	 * @group security
	 */
	public function test_missing_operator_throws(): void {
		$this->seed( 101 );

		$this->expectException( \DomainException::class );
		$this->useCase->execute( [ 101 ], 0 );
	}

	/**
	 * 🔴 security：狀態轉換走 compare-and-set（pending → cancelled）
	 *
	 * @group security
	 */
	public function test_transition_goes_through_compare_and_set(): void {
		$this->seed( 101 );

		$this->useCase->execute( [ 101 ], 42 );

		$this->assertCount( 1, $this->mutator->cas_calls );
		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->mutator->cas_calls[0]['from'] );
		$this->assertSame( SettlementRecord::STATUS_CANCELLED, $this->mutator->cas_calls[0]['to'] );
		$this->assertTrue( $this->mutator->cas_calls[0]['won'] );
	}

	/**
	 * 🔴 security：CAS 沒搶到時不生效且明確回報
	 *
	 * @group security
	 */
	public function test_losing_the_race_skips_the_record(): void {
		$this->seed( 101 );
		$this->mutator->simulate_race_for( 101, SettlementRecord::STATUS_PAID );

		$output = $this->useCase->execute( [ 101 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertArrayHasKey( 101, $output->skipped );
		$this->assertStringContainsString( '未重複計入', $output->skipped[101] );
		$this->assertCount( 0, $this->audit->read( 5 ) );
	}

	/**
	 * smoke：混合成功與跳過時輸出形狀正確
	 *
	 * @group smoke
	 */
	public function test_mixed_batch_output_shape(): void {
		$this->seed( 101 );
		$this->seed( 202, SettlementRecord::STATUS_PAID );

		$array = $this->useCase->execute( [ 101, 202 ], 42 )->to_array();

		$this->assertSame( 1, $array['updated_count'] );
		$this->assertSame( 1, $array['skipped_count'] );
		$this->assertSame( '100.00', $array['total_amount'] );
	}
}
