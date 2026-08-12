<?php
/**
 * MarkSettlementsPaidUseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5、§7.5
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group settlement
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Settlement;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementBatchOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditRecorder;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\MarkSettlementsPaidUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\InMemorySettlementAuditLog;
use Tests\Support\InMemorySettlementRepository;
use Tests\Support\InMemorySettlementStatusMutator;

/**
 * 批次撥款 UseCase 契約測試
 */
final class MarkSettlementsPaidUseCaseTest extends TestCase {

	private const NOW = 1_700_000_000;

	private InMemorySettlementRepository $repo;
	private InMemorySettlementStatusMutator $mutator;
	private InMemorySettlementAuditLog $audit;
	private FixedClock $clock;
	private MarkSettlementsPaidUseCase $useCase;

	protected function setUp(): void {
		parent::setUp();

		$this->repo    = new InMemorySettlementRepository();
		$this->mutator = new InMemorySettlementStatusMutator();
		$this->audit   = new InMemorySettlementAuditLog();
		$this->clock   = new FixedClock( self::NOW );

		$this->useCase = new MarkSettlementsPaidUseCase(
			settlements: $this->repo,
			mutator: $this->mutator,
			auditor: new SettlementAuditRecorder( log: $this->audit, clock: $this->clock ),
			clock: $this->clock,
		);
	}

	/**
	 * 建立一筆結算紀錄，同時預埋進 Repository 與狀態轉換器
	 *
	 * @param int    $order_item_id   訂單品項 ID
	 * @param string $status          初始狀態
	 * @param string $profit_amount   分潤金額
	 * @param int    $partner_term_id Partner term ID
	 *
	 * @return SettlementRecord
	 */
	private function seed(
		int $order_item_id,
		string $status = SettlementRecord::STATUS_PENDING,
		string $profit_amount = '100.00',
		int $partner_term_id = 5
	): SettlementRecord {
		$record = new SettlementRecord(
			order_item_id: $order_item_id,
			shop_id: 900,
			partner_term_id: $partner_term_id,
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
	 * happy：pending 轉 paid，並寫入 settled_at / settled_by
	 *
	 * @group happy
	 */
	public function test_pending_records_are_marked_paid(): void {
		$this->seed( 101 );
		$this->seed( 102 );

		$output = $this->useCase->execute( [ 101, 102 ], 42 );

		$this->assertInstanceOf( SettlementBatchOutput::class, $output );
		$this->assertSame( [ 101, 102 ], $output->updated_ids );
		$this->assertSame( [], $output->skipped );

		$this->assertSame( SettlementRecord::STATUS_PAID, $this->mutator->current_status( 101 ) );
		$this->assertSame( self::NOW, $this->mutator->settled[101]['settled_at'] );
		$this->assertSame( 42, $this->mutator->settled[101]['settled_by'] );
	}

	/**
	 * 🔴 security：狀態轉換必須走 compare-and-set，不是 read-check-write
	 *
	 * 這條鎖住實作手法本身。若有人把 CAS 換回「讀出來確認是 pending 再 save()」，
	 * `cas_calls` 會是空的而讓這條紅燈——那個改動在功能上看起來完全正常，
	 * 只有在兩位管理員同時按下撥款時才會以「重複付錢」的形式爆發。
	 *
	 * @group security
	 */
	public function test_transition_goes_through_compare_and_set(): void {
		$this->seed( 101 );

		$this->useCase->execute( [ 101 ], 42 );

		$this->assertCount( 1, $this->mutator->cas_calls, '必須且只能對每筆呼叫一次 CAS' );
		$this->assertSame(
			[
				'order_item_id' => 101,
				'from'          => SettlementRecord::STATUS_PENDING,
				'to'            => SettlementRecord::STATUS_PAID,
				'won'           => true,
			],
			$this->mutator->cas_calls[0],
			'CAS 的比對來源必須是 pending、目標必須是 paid'
		);
	}

	/**
	 * 🔴 security：CAS 沒搶到時該筆被跳過、不得寫入撥款證據
	 *
	 * 模擬兩位管理員同時按下撥款：本請求讀到的還是 pending，但在寫入前
	 * 對手已搶先改成 paid。此時本次必須完全不生效。
	 *
	 * @group security
	 */
	public function test_losing_the_race_skips_the_record_and_writes_nothing(): void {
		$this->seed( 101, SettlementRecord::STATUS_PENDING, '250.00' );
		$this->mutator->simulate_race_for( 101, SettlementRecord::STATUS_PAID );

		$output = $this->useCase->execute( [ 101 ], 42 );

		$this->assertSame( [], $output->updated_ids, '沒搶到就不算數' );
		$this->assertSame( '0.00', $output->total_amount, '金額不得計入' );
		$this->assertArrayHasKey( 101, $output->skipped );
		$this->assertStringContainsString( '未重複計入', $output->skipped[101], '必須明確告知站長本次未生效' );

		$this->assertArrayNotHasKey( 101, $this->mutator->settled, '沒搶到的一方不得寫撥款時間 / 撥款人' );
		$this->assertCount( 0, $this->audit->read( 5 ), '沒搶到就不該留下稽核紀錄' );
	}

	/**
	 * 🔴 security：兩次連續執行只有第一次生效（冪等）
	 *
	 * @group security
	 */
	public function test_running_same_batch_twice_does_not_double_count(): void {
		$this->seed( 101, SettlementRecord::STATUS_PENDING, '300.00' );

		$first  = $this->useCase->execute( [ 101 ], 42 );
		$second = $this->useCase->execute( [ 101 ], 43 );

		$this->assertSame( '300.00', $first->total_amount );
		$this->assertSame( [], $second->updated_ids );
		$this->assertSame( '0.00', $second->total_amount );
		$this->assertArrayHasKey( 101, $second->skipped );

		$this->assertSame( 42, $this->mutator->settled[101]['settled_by'], '撥款人不得被第二次操作覆寫' );
		$this->assertCount( 1, $this->audit->read( 5 ), '稽核紀錄只該有一筆' );
	}

	/**
	 * security：已是 paid 的項目在 Domain 層就被擋下，且明確回報
	 *
	 * @group security
	 */
	public function test_already_paid_records_are_skipped_and_reported(): void {
		$this->seed( 101 );
		$this->seed( 202, SettlementRecord::STATUS_PAID );

		$output = $this->useCase->execute( [ 101, 202 ], 42 );

		$this->assertSame( [ 101 ], $output->updated_ids );
		$this->assertArrayHasKey( 202, $output->skipped, '狀態不符者必須出現在 skipped，不可靜默忽略' );
		$this->assertStringContainsString( 'paid', $output->skipped[202] );
		$this->assertArrayNotHasKey( 202, $this->mutator->settled );
	}

	/**
	 * error：找不到紀錄的 ID 被跳過並說明原因
	 *
	 * @group error
	 */
	public function test_missing_records_are_reported_as_skipped(): void {
		$this->seed( 101 );

		$output = $this->useCase->execute( [ 101, 999 ], 42 );

		$this->assertSame( [ 101 ], $output->updated_ids );
		$this->assertArrayHasKey( 999, $output->skipped );
		$this->assertStringContainsString( '找不到', $output->skipped[999] );
	}

	/**
	 * error：cancelled / refunded 皆不可轉 paid
	 *
	 * @group error
	 */
	public function test_cancelled_and_refunded_cannot_be_marked_paid(): void {
		$this->seed( 301, SettlementRecord::STATUS_CANCELLED );
		$this->seed( 302, SettlementRecord::STATUS_REFUNDED );

		$output = $this->useCase->execute( [ 301, 302 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertArrayHasKey( 301, $output->skipped );
		$this->assertArrayHasKey( 302, $output->skipped );
		$this->assertCount( 0, $this->mutator->cas_calls, 'Domain 已擋下，不該再打 DB' );
	}

	/**
	 * edge：狀態欄位不存在時 fail-closed（不猜測、不放行）
	 *
	 * @group edge
	 */
	public function test_record_without_status_meta_is_skipped(): void {
		$record = new SettlementRecord(
			order_item_id: 501,
			shop_id: 900,
			partner_term_id: 5,
			rate: new ProfitRate( 20 ),
			actual_price: '500.00',
			profit_amount: '100.00',
		);
		$this->repo->seed( $record );
		// 刻意不呼叫 seed_status()：模擬 _settlement_status meta 不存在

		$output = $this->useCase->execute( [ 501 ], 42 );

		$this->assertSame( [], $output->updated_ids );
		$this->assertArrayHasKey( 501, $output->skipped );
		$this->assertArrayNotHasKey( 501, $this->mutator->settled );
	}

	/**
	 * error：空清單直接 fail-fast
	 *
	 * @group error
	 */
	public function test_empty_id_list_throws(): void {
		$this->expectException( \DomainException::class );
		$this->useCase->execute( [], 42 );
	}

	/**
	 * error：全是非法值的清單等同空清單
	 *
	 * @group error
	 */
	public function test_all_invalid_ids_throws(): void {
		$this->expectException( \DomainException::class );
		$this->useCase->execute( [ 0, -1, 'abc', null ], 42 );
	}

	/**
	 * security：操作者 ID 為 0 時 fail-fast（不留下無法歸責的撥款紀錄）
	 *
	 * @group security
	 */
	public function test_missing_operator_throws(): void {
		$this->seed( 101 );

		$this->expectException( \DomainException::class );
		$this->useCase->execute( [ 101 ], 0 );
	}

	/**
	 * security：超過批次上限直接拒絕（DoS 防護）
	 *
	 * @group security
	 */
	public function test_batch_over_limit_throws(): void {
		$ids = range( 1, MarkSettlementsPaidUseCase::MAX_BATCH_SIZE + 1 );

		$this->expectException( \DomainException::class );
		$this->useCase->execute( $ids, 42 );
	}

	/**
	 * edge：重複的 ID 只被處理一次
	 *
	 * @group edge
	 */
	public function test_duplicate_ids_are_processed_once(): void {
		$this->seed( 101, SettlementRecord::STATUS_PENDING, '50.00' );

		$output = $this->useCase->execute( [ 101, 101, '101' ], 42 );

		$this->assertSame( [ 101 ], $output->updated_ids );
		$this->assertSame( '50.00', $output->total_amount );
		$this->assertCount( 1, $this->mutator->cas_calls );
	}

	/**
	 * happy：稽核紀錄依 partner 分開寫，各自只含自己的金額
	 *
	 * @group happy
	 */
	public function test_audit_log_is_written_per_partner(): void {
		$this->seed( 101, SettlementRecord::STATUS_PENDING, '100.00', 5 );
		$this->seed( 102, SettlementRecord::STATUS_PENDING, '200.00', 5 );
		$this->seed( 201, SettlementRecord::STATUS_PENDING, '300.00', 9 );

		$output = $this->useCase->execute( [ 101, 102, 201 ], 42 );

		$this->assertSame( '600.00', $output->total_amount );

		$partner5 = $this->audit->read( 5 );
		$this->assertCount( 1, $partner5 );
		$this->assertSame( SettlementAuditRecorder::TYPE_SETTLE, $partner5[0]['type'] );
		$this->assertSame( '300.00', $partner5[0]['total'], 'partner 5 只該看到自己的 100+200' );
		$this->assertSame( 2, $partner5[0]['item_count'] );
		$this->assertSame( 42, $partner5[0]['by'] );
		$this->assertSame( gmdate( 'Y-m-d', self::NOW ), $partner5[0]['date'] );

		$partner9 = $this->audit->read( 9 );
		$this->assertCount( 1, $partner9 );
		$this->assertSame( '300.00', $partner9[0]['total'] );
	}

	/**
	 * edge：金額以 bcmath 累加，不受浮點誤差影響
	 *
	 * @group edge
	 */
	public function test_amounts_are_summed_with_bcmath(): void {
		$this->seed( 101, SettlementRecord::STATUS_PENDING, '0.10' );
		$this->seed( 102, SettlementRecord::STATUS_PENDING, '0.20' );

		$output = $this->useCase->execute( [ 101, 102 ], 42 );

		$this->assertSame( '0.30', $output->total_amount );
	}

	/**
	 * edge：稽核紀錄寫入失敗時，狀態仍已更新且失敗被誠實回報
	 *
	 * @group edge
	 */
	public function test_audit_failure_is_reported_without_rolling_back_status(): void {
		$this->seed( 101, SettlementRecord::STATUS_PENDING, '100.00', 5 );
		$this->audit->fail_for( 5 );

		$output = $this->useCase->execute( [ 101 ], 42 );

		$this->assertSame( [ 101 ], $output->updated_ids, '稽核失敗不得讓已成功的狀態變更消失' );
		$this->assertSame( [ 5 ], $output->audit_log_failed_partner_ids );
		$this->assertSame( SettlementRecord::STATUS_PAID, $this->mutator->current_status( 101 ) );
	}

	/**
	 * smoke：輸出可序列化且含筆數欄位（前端直接讀）
	 *
	 * @group smoke
	 */
	public function test_output_serialises_with_counts(): void {
		$this->seed( 101 );
		$this->seed( 202, SettlementRecord::STATUS_PAID );

		$array = $this->useCase->execute( [ 101, 202 ], 42 )->to_array();

		$this->assertSame( 1, $array['updated_count'] );
		$this->assertSame( 1, $array['skipped_count'] );
		$this->assertSame( [ 101 ], $array['updated_ids'] );
		$this->assertArrayHasKey( 'total_amount', $array );
		$this->assertArrayHasKey( 'audit_log_failed_partner_ids', $array );
	}
}
