<?php
/**
 * WpSettlementStatusMutator 整合測試（真實 SQL 的 compare-and-set）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/Persistence/WpSettlementStatusMutator.php
 *
 * 這裡測的是**真實條件式 UPDATE 的語意**——UseCase 層的測試用的是記憶體 fake，
 * 而 affected rows 判定、快取失效這兩件事只有在真的打到資料庫時才存在。
 *
 * @group profit_shop
 * @group settlement
 * @group persistence
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpSettlementStatusMutator;
use Tests\Integration\TestCase;

/**
 * compare-and-set 契約測試
 */
final class WpSettlementStatusMutatorTest extends TestCase {

	private WpSettlementStatusMutator $mutator;

	/**
	 * 設定
	 */
	public function set_up(): void {
		parent::set_up();
		$this->mutator = WpSettlementStatusMutator::instance();
	}

	/**
	 * 建立一個帶結算狀態的 order item，回傳 order_item_id
	 *
	 * @param string|null $status 初始狀態（null = 刻意不寫 _settlement_status）
	 *
	 * @return int
	 */
	private function seed_item( ?string $status = 'pending' ): int {
		$order = \wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'CAS 測試品項' );
		$item->set_quantity( 1 );
		$order->add_item( $item );
		$order->save();

		$item_id = $item->get_id();
		$this->assertGreaterThan( 0, $item_id );

		if ( null !== $status ) {
			\wc_update_order_item_meta( $item_id, '_settlement_status', $status );
		}

		return $item_id;
	}

	/**
	 * happy：狀態相符時搶到並完成轉換
	 *
	 * @group happy
	 */
	public function test_cas_succeeds_when_status_matches(): void {
		$item_id = $this->seed_item( 'pending' );

		$this->assertTrue( $this->mutator->compare_and_set( $item_id, 'pending', 'paid' ) );
		$this->assertSame( 'paid', $this->mutator->current_status( $item_id ) );
	}

	/**
	 * 🔴 security：同一筆連續兩次 CAS，第二次必須回 false 且狀態不被二次改寫
	 *
	 * 這正是「兩位管理員同時按下撥款」的核心情境：第一個請求把 pending 換成 paid，
	 * 第二個請求帶著同樣的 `from = pending` 進來，資料庫的 row lock 保證它拿到
	 * 0 affected rows。若這裡回 true，站長就會轉兩次帳。
	 *
	 * @group security
	 */
	public function test_second_cas_with_same_from_loses_the_race(): void {
		$item_id = $this->seed_item( 'pending' );

		$first  = $this->mutator->compare_and_set( $item_id, 'pending', 'paid' );
		$second = $this->mutator->compare_and_set( $item_id, 'pending', 'paid' );

		$this->assertTrue( $first, '第一次應搶到' );
		$this->assertFalse( $second, '第二次必須沒搶到——否則就是重複撥款' );
		$this->assertSame( 'paid', $this->mutator->current_status( $item_id ), '狀態不得被二次改寫' );
	}

	/**
	 * 🔴 security：`from` 與當下狀態不符時一律失敗
	 *
	 * @group security
	 */
	public function test_cas_fails_when_status_differs(): void {
		$item_id = $this->seed_item( 'paid' );

		$this->assertFalse( $this->mutator->compare_and_set( $item_id, 'pending', 'paid' ) );
		$this->assertSame( 'paid', $this->mutator->current_status( $item_id ) );
	}

	/**
	 * 🔴 security：**這一條是 CAS 與 read-check-write 的唯一鑑別點**
	 *
	 * ## 為什麼其他測試證明不了 CAS 有在守門
	 *
	 * PHPUnit 是單執行緒，無法製造真正的交錯執行。而「連續呼叫兩次、第二次要失敗」
	 * 這種**循序**測試，read-check-write 也會通過（第二次讀到 paid、比對 pending 不符、
	 * 回 false）——`test_second_cas_with_same_from_loses_the_race` 與
	 * `test_running_same_batch_twice_does_not_double_count` 都屬於這一類，
	 * 它們測的是「行為結果」，不是「守門寫在哪一層」。
	 *
	 * ## 這條測的是「守門確實寫在 SQL 的 WHERE 裡」
	 *
	 * read-check-write 在 SQL 層的形狀就是**無條件 UPDATE**（比對發生在 PHP）。
	 * 因此把 `$wpdb->update()` 的 WHERE 陣列拿掉 `'meta_value' => $from` 之後，
	 * 只有 `current ∉ {from, to}` 的情況才會露餡：
	 *
	 * | 現況 | from | to | CAS | 無條件 UPDATE |
	 * |---|---|---|---|---|
	 * | paid | pending | paid | 0 rows → false | 值沒變 → 0 rows → false（**測不出來**） |
	 * | **cancelled** | pending | paid | 0 rows → false | 值改變 → 1 row → **true（露餡）** |
	 *
	 * 所以這裡刻意用 `cancelled`：它既不是 `from` 也不是 `to`。
	 * 拿掉 WHERE 的 `meta_value` 條件時，本測試的兩條斷言會同時轉紅
	 * （回傳 true、且狀態被改成 paid）。
	 *
	 * ⚠️ 不要把 `cancelled` 改成 `paid`「讓它跟其他測試一致」——那會讓這條測試
	 * 退化成測不出任何東西的假綠燈。
	 *
	 * @group security
	 */
	public function test_cas_guard_lives_in_sql_where_clause_not_in_php(): void {
		$item_id = $this->seed_item( 'cancelled' );

		$won = $this->mutator->compare_and_set( $item_id, 'pending', 'paid' );

		$this->assertFalse( $won, '現況是 cancelled，CAS 的 WHERE meta_value = pending 應命中 0 列' );
		$this->assertSame(
			'cancelled',
			$this->mutator->current_status( $item_id ),
			'狀態不得被改動——若變成 paid，代表 UPDATE 是無條件的（= read-check-write）'
		);
	}

	/**
	 * 🔴 security：`_settlement_status` meta 不存在時 fail-closed
	 *
	 * Repository 對缺這個 meta 的紀錄會預設回 `pending`，但 CAS 的 WHERE 對不存在的
	 * 資料列必然是 0 rows。少撥一筆（站長會看到跳過原因）遠優於重複撥款。
	 *
	 * @group security
	 */
	public function test_cas_fails_closed_when_status_meta_missing(): void {
		$item_id = $this->seed_item( null );

		$this->assertFalse( $this->mutator->compare_and_set( $item_id, 'pending', 'paid' ) );
		$this->assertNull( $this->mutator->current_status( $item_id ) );
	}

	/**
	 * 🔴 edge：CAS 之後快取必須失效，否則同請求後續讀到舊值
	 *
	 * 本類直接下 SQL，繞過了 WooCommerce / WordPress 的 meta 快取失效機制。
	 * 少了手動清快取，`wc_get_order_item_meta()` 會回轉換**前**的值——
	 * 症狀是「撥款成功但後續邏輯看到的還是 pending」，且沒有任何錯誤訊息。
	 *
	 * @group edge
	 */
	public function test_cache_is_invalidated_so_wc_getter_sees_new_value(): void {
		$item_id = $this->seed_item( 'pending' );

		// 先讀一次把舊值灌進快取（這是真實請求裡一定會發生的事）
		$this->assertSame( 'pending', \wc_get_order_item_meta( $item_id, '_settlement_status', true ) );

		$this->mutator->compare_and_set( $item_id, 'pending', 'paid' );

		$this->assertSame(
			'paid',
			\wc_get_order_item_meta( $item_id, '_settlement_status', true ),
			'CAS 後 wc_get_order_item_meta() 必須讀到新值（快取要被清掉）'
		);
	}

	/**
	 * edge：from === to 一律拒絕（MySQL 對「值沒變」回 0 rows，與「沒搶到」無法區分）
	 *
	 * @group edge
	 */
	public function test_cas_rejects_identical_from_and_to(): void {
		$item_id = $this->seed_item( 'pending' );

		$this->assertFalse( $this->mutator->compare_and_set( $item_id, 'pending', 'pending' ) );
	}

	/**
	 * edge：非法 order_item_id 不得造成任何寫入
	 *
	 * @group edge
	 */
	public function test_cas_rejects_invalid_item_id(): void {
		$this->assertFalse( $this->mutator->compare_and_set( 0, 'pending', 'paid' ) );
		$this->assertFalse( $this->mutator->compare_and_set( -1, 'pending', 'paid' ) );
		$this->assertNull( $this->mutator->current_status( 0 ) );
	}

	/**
	 * happy：mark_settled 寫入撥款時間與操作者
	 *
	 * @group happy
	 */
	public function test_mark_settled_writes_audit_fields(): void {
		$item_id = $this->seed_item( 'pending' );

		$this->mutator->mark_settled( $item_id, 1_700_000_000, 42 );

		$this->assertOrderItemMetaEquals( $item_id, '_settled_at', '1700000000' );
		$this->assertOrderItemMetaEquals( $item_id, '_settled_by', '42' );
	}

	/**
	 * smoke：current_status 讀得到剛寫入的值
	 *
	 * @group smoke
	 */
	public function test_current_status_reads_persisted_value(): void {
		$item_id = $this->seed_item( 'refunded' );

		$this->assertSame( 'refunded', $this->mutator->current_status( $item_id ) );
	}
}
