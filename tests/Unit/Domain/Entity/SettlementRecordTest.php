<?php
/**
 * SettlementRecord Entity 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5、§5.3、§5.5、§8.5
 * 規範：分潤結算紀錄（line item 級），凍結交易當下的價格與比例。
 *      狀態機：
 *        - pending → paid（mark_paid）
 *        - pending → cancelled（mark_cancelled）
 *        - pending → refunded（mark_refunded）
 *        - paid → refunded（mark_refunded，set is_refund_after_paid = true）
 *        - 其他轉換皆 throw InvalidStatusTransition。
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Entity;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidStatusTransition;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use PHPUnit\Framework\TestCase;

/**
 * SettlementRecord Entity 測試
 */
final class SettlementRecordTest extends TestCase {

	private const STATUS_PENDING   = 'pending';
	private const STATUS_PAID      = 'paid';
	private const STATUS_REFUNDED  = 'refunded';
	private const STATUS_CANCELLED = 'cancelled';

	/**
	 * 預設建構為 pending 狀態
	 *
	 * @group happy
	 */
	public function test_construct_in_pending_state_by_default(): void {
		$record = $this->make_record();

		$this->assertSame( self::STATUS_PENDING, $record->status );
		$this->assertNull( $record->settled_at );
		$this->assertNull( $record->settled_by );
		$this->assertFalse( $record->is_refund_after_paid );
	}

	/**
	 * 可指定初始 status（給從 DB rehydrate 用）
	 *
	 * @group happy
	 */
	public function test_construct_with_custom_status_for_reconstitution(): void {
		$record = new SettlementRecord(
			order_item_id: 5678,
			shop_id: 100,
			partner_term_id: 5,
			rate: new ProfitRate( 10 ),
			actual_price: '799.00',
			profit_amount: '79.90',
			status: self::STATUS_PAID,
			settled_at: 1746537600,
			settled_by: 1,
			is_refund_after_paid: false,
		);

		$this->assertSame( self::STATUS_PAID, $record->status );
		$this->assertSame( 1746537600, $record->settled_at );
		$this->assertSame( 1, $record->settled_by );
	}

	/**
	 * mark_paid 將 pending 轉換為 paid
	 *
	 * @group happy
	 */
	public function test_mark_paid_transitions_pending_to_paid(): void {
		$record = $this->make_record();

		$record->mark_paid( 1, 1746537600 );

		$this->assertSame( self::STATUS_PAID, $record->status );
	}

	/**
	 * mark_paid 應記錄 settled_at 與 settled_by
	 *
	 * @group happy
	 */
	public function test_mark_paid_records_settled_at_and_settled_by(): void {
		$record = $this->make_record();

		$record->mark_paid( 42, 1746537600 );

		$this->assertSame( 1746537600, $record->settled_at );
		$this->assertSame( 42, $record->settled_by );
	}

	/**
	 * mark_cancelled 將 pending 轉換為 cancelled
	 *
	 * @group happy
	 */
	public function test_mark_cancelled_transitions_pending_to_cancelled(): void {
		$record = $this->make_record();

		$record->mark_cancelled();

		$this->assertSame( self::STATUS_CANCELLED, $record->status );
	}

	/**
	 * mark_refunded 從 pending 轉換為 refunded
	 *
	 * @group happy
	 */
	public function test_mark_refunded_from_pending_transitions_to_refunded(): void {
		$record = $this->make_record();

		$record->mark_refunded();

		$this->assertSame( self::STATUS_REFUNDED, $record->status );
	}

	/**
	 * mark_refunded 從 paid 轉換為 refunded，且 is_refund_after_paid = true
	 *
	 * @group happy
	 */
	public function test_mark_refunded_from_paid_sets_refund_after_paid_flag(): void {
		$record = $this->make_record();
		$record->mark_paid( 1, 1746537600 );

		$record->mark_refunded();

		$this->assertSame( self::STATUS_REFUNDED, $record->status );
		$this->assertTrue( $record->is_refund_after_paid );
	}

	/**
	 * mark_refunded 從 pending 不應 set is_refund_after_paid
	 *
	 * @group happy
	 */
	public function test_mark_refunded_from_pending_does_not_set_refund_after_paid_flag(): void {
		$record = $this->make_record();

		$record->mark_refunded();

		$this->assertFalse( $record->is_refund_after_paid );
	}

	/**
	 * 已 paid 不能再 mark_paid
	 *
	 * @group error
	 */
	public function test_mark_paid_throws_when_already_paid(): void {
		$record = $this->make_record();
		$record->mark_paid( 1, 1746537600 );

		$this->expectException( InvalidStatusTransition::class );
		$record->mark_paid( 1, 1746541200 );
	}

	/**
	 * cancelled 不能再 mark_paid
	 *
	 * @group error
	 */
	public function test_mark_paid_throws_when_already_cancelled(): void {
		$record = $this->make_record();
		$record->mark_cancelled();

		$this->expectException( InvalidStatusTransition::class );
		$record->mark_paid( 1, 1746537600 );
	}

	/**
	 * refunded 不能再 mark_paid
	 *
	 * @group error
	 */
	public function test_mark_paid_throws_when_already_refunded(): void {
		$record = $this->make_record();
		$record->mark_refunded();

		$this->expectException( InvalidStatusTransition::class );
		$record->mark_paid( 1, 1746537600 );
	}

	/**
	 * 已 paid 不能 mark_cancelled
	 *
	 * @group error
	 */
	public function test_mark_cancelled_throws_when_already_paid(): void {
		$record = $this->make_record();
		$record->mark_paid( 1, 1746537600 );

		$this->expectException( InvalidStatusTransition::class );
		$record->mark_cancelled();
	}

	/**
	 * refunded 不能再 mark_cancelled
	 *
	 * @group error
	 */
	public function test_mark_cancelled_throws_when_already_refunded(): void {
		$record = $this->make_record();
		$record->mark_refunded();

		$this->expectException( InvalidStatusTransition::class );
		$record->mark_cancelled();
	}

	/**
	 * is_refund_after_paid 預設為 false
	 *
	 * @group happy
	 */
	public function test_is_refund_after_paid_default_false(): void {
		$record = $this->make_record();

		$this->assertFalse( $record->is_refund_after_paid );
	}

	/**
	 * to_array 應包含全部欄位
	 *
	 * @group happy
	 */
	public function test_to_array_includes_all_fields(): void {
		$record = $this->make_record();
		$record->mark_paid( 42, 1746537600 );

		$arr = $record->to_array();

		$this->assertArrayHasKey( 'order_item_id', $arr );
		$this->assertArrayHasKey( 'shop_id', $arr );
		$this->assertArrayHasKey( 'partner_term_id', $arr );
		$this->assertArrayHasKey( 'rate', $arr );
		$this->assertArrayHasKey( 'actual_price', $arr );
		$this->assertArrayHasKey( 'profit_amount', $arr );
		$this->assertArrayHasKey( 'status', $arr );
		$this->assertArrayHasKey( 'settled_at', $arr );
		$this->assertArrayHasKey( 'settled_by', $arr );
		$this->assertArrayHasKey( 'is_refund_after_paid', $arr );

		$this->assertSame( 5678, $arr['order_item_id'] );
		$this->assertSame( 100, $arr['shop_id'] );
		$this->assertSame( 5, $arr['partner_term_id'] );
		$this->assertSame( '799.00', $arr['actual_price'] );
		$this->assertSame( '79.90', $arr['profit_amount'] );
		$this->assertSame( self::STATUS_PAID, $arr['status'] );
		$this->assertSame( 1746537600, $arr['settled_at'] );
		$this->assertSame( 42, $arr['settled_by'] );
		$this->assertFalse( $arr['is_refund_after_paid'] );
	}

	/**
	 * 建立一個 pending 狀態的 SettlementRecord（給多個測試共用）
	 *
	 * @return SettlementRecord
	 */
	private function make_record(): SettlementRecord {
		return new SettlementRecord(
			order_item_id: 5678,
			shop_id: 100,
			partner_term_id: 5,
			rate: new ProfitRate( 10 ),
			actual_price: '799.00',
			profit_amount: '79.90',
		);
	}
}
