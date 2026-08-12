<?php
/**
 * ReversePaidSettlementsUseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.3、§5.3、§7.5
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group settlement
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditRecorder;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\ReversePaidSettlementsUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\InMemorySettlementAuditLog;
use Tests\Support\InMemorySettlementRepository;
use Tests\Support\InMemorySettlementStatusMutator;

/**
 * 人工沖回（paid → refunded）UseCase 契約測試
 */
final class ReversePaidSettlementsUseCaseTest extends TestCase {

	private const NOW = 1_700_000_000;

	private InMemorySettlementRepository $repo;
	private InMemorySettlementStatusMutator $mutator;
	private InMemorySettlementAuditLog $audit;
	private ReversePaidSettlementsUseCase $useCase;

	protected function setUp(): void {
		parent::setUp();

		$this->repo    = new InMemorySettlementRepository();
		$this->mutator = new InMemorySettlementStatusMutator();
		$this->audit   = new InMemorySettlementAuditLog();

		$this->useCase = new ReversePaidSettlementsUseCase(
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
		string $status = SettlementRecord::STATUS_PAID,
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
			settled_at: SettlementRecord::STATUS_PAID === $status ? 1_699_000_000 : null,
			settled_by: SettlementRecord::STATUS_PAID === $status ? 7 : null,
		);
		$this->repo->seed( $record );
		$this->mutator->seed_status( $order_item_id, $status );
		return $record;
	}

	/**
	 * happy：paid 轉 refunded，並標記為「已撥款後退款」
	 *
	 * @group happy
	 */
	public function test_paid_records_are_reversed_to_refunded(): void {
		$this->seed( 101 );

		$output = $this->useCase->execute( [ 101 ], 42 );

		$this->assertSame( [ 101 ], $output->updated_ids );

		$this->assertSame( SettlementRecord::STATUS_REFUNDED, $this->mutator->current_status( 101 ) );
		$this->assertTrue(
			$this->repo->find_by_order_item( 101 )?->is_refund_after_paid,
			'paid → refunded 必須在 Entity 上標記 is_refund_after_paid'
		);
	}

	/**
	 * 🔴 security：pending **不可**被「人工沖回」
	 *
	 * Domain 狀態機接受 pending → refunded，所以這道守門必須在 UseCase 自己做。
	 * 少了它，一筆根本還沒撥出去的款會產生 refund_after_paid 稽核紀錄，
	 * 站長下次撥款照那筆扣抵 → 少付夥伴一次，而且該筆也不會再出現在待撥款清單。
	 *
	 * @group security
	 */
	public function test_pending_records_cannot_be_reversed(): void {
		$this->seed( 201, SettlementRecord::STATUS_PENDING );

		$output = $this->useCase->execute( [ 201 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertArrayHasKey( 201, $output->skipped );
		$this->assertStringContainsString( '標記取消', $output->skipped[201], '應引導站長改用正確的操作' );

		$this->assertSame( SettlementRecord::STATUS_PENDING, $this->mutator->current_status( 201 ), 'pending 必須原封不動' );
		$this->assertCount( 0, $this->mutator->cas_calls, '前置檢查就該擋下，不該打到 DB' );
		$this->assertCount( 0, $this->audit->read( 5 ), '不得留下任何 refund 稽核紀錄' );
	}

	/**
	 * security：refunded / cancelled 亦不可沖回
	 *
	 * @group security
	 */
	public function test_refunded_and_cancelled_cannot_be_reversed(): void {
		$this->seed( 301, SettlementRecord::STATUS_REFUNDED );
		$this->seed( 302, SettlementRecord::STATUS_CANCELLED );

		$output = $this->useCase->execute( [ 301, 302 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertCount( 2, $output->skipped );
		$this->assertCount( 0, $this->mutator->cas_calls );
	}

	/**
	 * security：重複沖回同一筆不會重複計入
	 *
	 * @group security
	 */
	public function test_reversing_twice_does_not_double_count(): void {
		$this->seed( 101, SettlementRecord::STATUS_PAID, '250.00' );

		$first  = $this->useCase->execute( [ 101 ], 42 );
		$second = $this->useCase->execute( [ 101 ], 42 );

		$this->assertSame( '250.00', $first->total_amount );
		$this->assertSame( [], $second->updated_ids );
		$this->assertSame( '0.00', $second->total_amount );
		$this->assertCount( 1, $this->audit->read( 5 ) );
	}

	/**
	 * happy：稽核紀錄型別為 refund_after_paid（spec §7.5 指定）
	 *
	 * @group happy
	 */
	public function test_audit_entry_uses_refund_after_paid_type(): void {
		$this->seed( 101, SettlementRecord::STATUS_PAID, '120.00' );

		$this->useCase->execute( [ 101 ], 42 );

		$entries = $this->audit->read( 5 );
		$this->assertCount( 1, $entries );
		$this->assertSame( SettlementAuditRecorder::TYPE_REFUND_AFTER_PAID, $entries[0]['type'] );
		$this->assertSame( '120.00', $entries[0]['total'] );
		$this->assertSame( 42, $entries[0]['by'] );
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
		$this->useCase->execute( range( 1, ReversePaidSettlementsUseCase::MAX_BATCH_SIZE + 1 ), 42 );
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
	 * edge：混合 paid 與 pending 的批次，只有 paid 被處理
	 *
	 * @group edge
	 */
	public function test_mixed_batch_only_reverses_paid(): void {
		$this->seed( 101, SettlementRecord::STATUS_PAID, '100.00' );
		$this->seed( 201, SettlementRecord::STATUS_PENDING, '999.00' );

		$output = $this->useCase->execute( [ 101, 201 ], 42 );

		$this->assertSame( [ 101 ], $output->updated_ids );
		$this->assertSame( '100.00', $output->total_amount, '金額不得含被跳過的 pending' );
		$this->assertArrayHasKey( 201, $output->skipped );
	}

	/**
	 * 🔴 security：狀態轉換走 compare-and-set（paid → refunded）
	 *
	 * @group security
	 */
	public function test_transition_goes_through_compare_and_set(): void {
		$this->seed( 101 );

		$this->useCase->execute( [ 101 ], 42 );

		$this->assertCount( 1, $this->mutator->cas_calls );
		$this->assertSame( SettlementRecord::STATUS_PAID, $this->mutator->cas_calls[0]['from'] );
		$this->assertSame( SettlementRecord::STATUS_REFUNDED, $this->mutator->cas_calls[0]['to'] );
	}

	/**
	 * 🔴 security：CAS 沒搶到時不生效且明確回報
	 *
	 * @group security
	 */
	public function test_losing_the_race_skips_the_record(): void {
		$this->seed( 101 );
		$this->mutator->simulate_race_for( 101, SettlementRecord::STATUS_REFUNDED );

		$output = $this->useCase->execute( [ 101 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertArrayHasKey( 101, $output->skipped );
		$this->assertStringContainsString( '未重複計入', $output->skipped[101] );
		$this->assertCount( 0, $this->audit->read( 5 ) );
	}

	/**
	 * smoke：輸出形狀正確
	 *
	 * @group smoke
	 */
	public function test_output_shape(): void {
		$this->seed( 101 );

		$array = $this->useCase->execute( [ 101 ], 42 )->to_array();

		$this->assertSame( 1, $array['updated_count'] );
		$this->assertSame( 0, $array['skipped_count'] );
		$this->assertSame( [], $array['audit_log_failed_partner_ids'] );
	}
}
