<?php
/**
 * OrderItemSettlementRepository 整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5、§5、§6.6（HPOS 相容）
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/Persistence/OrderItemSettlementRepository.php
 *
 * 重點：
 * - SettlementRecord 透過 wc_order_itemmeta 持久化
 * - 由 wc_*_order_item_meta API 處理，HPOS 開/關都能跑
 * - 不測試 _override_* 三欄位（Phase 4 OrderHooks 職責，spec 已標 write-only by 上游）
 *
 * @group profit_shop
 * @group infrastructure
 * @group persistence
 * @group repository
 * @group hpos_compatible
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Persistence;

use Automattic\WooCommerce\Utilities\OrderUtil;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use Tests\Integration\TestCase;

/**
 * 透過真實 WC 訂單 + order_itemmeta 驗證 SettlementRecord 存取行為
 */
final class OrderItemSettlementRepositoryTest extends TestCase {

	private OrderItemSettlementRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = OrderItemSettlementRepository::instance();
	}

	// ========== Happy ==========

	/**
	 * save() 必須將 SettlementRecord 各欄位寫入 wc_order_itemmeta
	 *
	 * @test
	 * @group happy
	 */
	public function test_save_writes_order_item_meta(): void {
		$item_id = $this->create_line_item();

		$record = new SettlementRecord(
			order_item_id: $item_id,
			shop_id: 501,
			partner_term_id: 7,
			rate: new ProfitRate( 20 ),
			actual_price: '888.00',
			profit_amount: '177.60',
			status: SettlementRecord::STATUS_PENDING,
			settled_at: null,
			settled_by: null,
			is_refund_after_paid: false
		);

		$this->repo->save( $record );

		$this->assertOrderItemMetaEquals( $item_id, '_profit_shop_id', '501' );
		$this->assertOrderItemMetaEquals( $item_id, '_profit_partner_term_id', '7' );
		$this->assertOrderItemMetaEquals( $item_id, '_profit_rate', '20' );
		$this->assertOrderItemMetaEquals( $item_id, '_actual_price', '888.00' );
		$this->assertOrderItemMetaEquals( $item_id, '_profit_amount', '177.60' );
		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', SettlementRecord::STATUS_PENDING );
	}

	/**
	 * find_by_order_item() 必須能從 itemmeta 重建 SettlementRecord
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_by_order_item_returns_record(): void {
		$item_id = $this->create_line_item();
		$record  = $this->save_settlement(
			$item_id,
			[
				'shop_id'         => 501,
				'partner_term_id' => 7,
				'rate'            => 25,
				'actual_price'    => '500',
				'profit_amount'   => '125',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		$loaded = $this->repo->find_by_order_item( $item_id );

		$this->assertInstanceOf( SettlementRecord::class, $loaded );
		$this->assertSame( $item_id, $loaded->order_item_id );
		$this->assertSame( 501, $loaded->shop_id );
		$this->assertSame( 7, $loaded->partner_term_id );
		$this->assertSame( 25, $loaded->rate->value() );
		$this->assertSame( '500', $loaded->actual_price );
		$this->assertSame( '125', $loaded->profit_amount );
		$this->assertSame( SettlementRecord::STATUS_PENDING, $loaded->status );
		$this->assertNull( $loaded->settled_at );
		$this->assertNull( $loaded->settled_by );
	}

	/**
	 * 狀態從 pending → paid 後，重新 save 應同步更新 itemmeta（含 settled_at、settled_by）
	 *
	 * @test
	 * @group happy
	 */
	public function test_status_transition_pending_to_paid_updates_meta(): void {
		$item_id = $this->create_line_item();
		$record  = $this->save_settlement(
			$item_id,
			[
				'shop_id'         => 501,
				'partner_term_id' => 7,
				'rate'            => 20,
				'actual_price'    => '100',
				'profit_amount'   => '20',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		$record->mark_paid( by: 42, at: 1_700_000_000 );
		$this->repo->save( $record );

		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', SettlementRecord::STATUS_PAID );
		$this->assertOrderItemMetaEquals( $item_id, '_settled_at', '1700000000' );
		$this->assertOrderItemMetaEquals( $item_id, '_settled_by', '42' );
	}

	/**
	 * find_by_partner() 應依 partner term id 撈到符合的 SettlementRecord
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_by_partner_returns_matching_records(): void {
		$jerry_term_id = 7;
		$other_term_id = 99;

		// Jerry 兩筆
		$item_a = $this->create_line_item();
		$this->save_settlement(
			$item_a,
			[
				'shop_id'         => 501,
				'partner_term_id' => $jerry_term_id,
				'rate'            => 20,
				'actual_price'    => '300',
				'profit_amount'   => '60',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);
		$item_b = $this->create_line_item();
		$this->save_settlement(
			$item_b,
			[
				'shop_id'         => 502,
				'partner_term_id' => $jerry_term_id,
				'rate'            => 30,
				'actual_price'    => '400',
				'profit_amount'   => '120',
				'status'          => SettlementRecord::STATUS_PAID,
			]
		);

		// Other partner 一筆，必須被排除
		$item_c = $this->create_line_item();
		$this->save_settlement(
			$item_c,
			[
				'shop_id'         => 700,
				'partner_term_id' => $other_term_id,
				'rate'            => 10,
				'actual_price'    => '50',
				'profit_amount'   => '5',
				'status'          => SettlementRecord::STATUS_PENDING,
			]
		);

		$found = $this->repo->find_by_partner( $jerry_term_id, new FilterCriteria() );

		$this->assertCount( 2, $found, 'Jerry 應有 2 筆結算紀錄' );
		foreach ( $found as $record ) {
			$this->assertSame( $jerry_term_id, $record->partner_term_id );
		}
	}

	/**
	 * 確認 OrderUtil 已載入（HPOS 工具 class 存在），證明測試在 WC HPOS 相容環境執行
	 *
	 * 真正的 HPOS 開關差異交給 CI matrix（with-wc-classic / with-wc-hpos）兩種情境跑同一份測試。
	 *
	 * @test
	 * @group happy
	 */
	public function test_woocommerce_orderutil_is_available(): void {
		$this->assertTrue(
			class_exists( OrderUtil::class ),
			'WooCommerce OrderUtil class 應存在；本測試假設 wc_*_order_item_meta API 在 HPOS 開/關都能正常運作'
		);

		// 不論 HPOS 開或關，wc_get_order_item_meta 都應可取用
		$this->assertTrue(
			function_exists( 'wc_get_order_item_meta' ),
			'wc_get_order_item_meta 應在 WC 載入後可用'
		);
		$this->assertTrue(
			function_exists( 'wc_update_order_item_meta' ),
			'wc_update_order_item_meta 應在 WC 載入後可用'
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立一筆真實的 WC line item（含 product），回傳 order_item_id
	 *
	 * 過程：建立 simple product → 建立 WC_Order → 透過 add_product() 新增 line item
	 */
	private function create_line_item(): int {
		$product = $this->createSimpleProduct(
			[
				'name'          => '結算測試商品',
				'regular_price' => '888',
			]
		);

		$order   = new \WC_Order();
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		$this->assertGreaterThan( 0, $item_id, '建立 WC line item 失敗' );

		return (int) $item_id;
	}

	/**
	 * 直接透過 Repository save 一筆 SettlementRecord，回傳該 Entity（方便鏈式 mark_paid 等）
	 *
	 * @param int                   $item_id order_item_id
	 * @param array<string, mixed>  $args    SettlementRecord 欄位
	 */
	private function save_settlement( int $item_id, array $args ): SettlementRecord {
		$record = new SettlementRecord(
			order_item_id: $item_id,
			shop_id: (int) ( $args['shop_id'] ?? 0 ),
			partner_term_id: (int) ( $args['partner_term_id'] ?? 0 ),
			rate: new ProfitRate( (int) ( $args['rate'] ?? 0 ) ),
			actual_price: (string) ( $args['actual_price'] ?? '0' ),
			profit_amount: (string) ( $args['profit_amount'] ?? '0' ),
			status: (string) ( $args['status'] ?? SettlementRecord::STATUS_PENDING ),
			settled_at: $args['settled_at'] ?? null,
			settled_by: $args['settled_by'] ?? null,
			is_refund_after_paid: (bool) ( $args['is_refund_after_paid'] ?? false )
		);
		$this->repo->save( $record );
		return $record;
	}
}
