<?php
/**
 * 結算狀態原子轉換 Port
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * 結算狀態的**原子**轉換 Port（compare-and-set）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5
 *
 * ## 🔴 為什麼需要這個 Port —— read-check-write 會造成雙重撥款
 *
 * 「先 `find_by_order_item()` 讀出狀態、確認是 pending、再 `save()`」是典型的
 * **read-check-write**：兩位管理員在兩個分頁同時按下撥款時，兩邊都會讀到 `pending`、
 * 都通過檢查、都寫入 `paid`——系統回報兩次成功，站長真的轉兩次帳。
 *
 * `SettlementRecord::mark_paid()` 內建的 pending 檢查**擋不住**這件事，因為它檢查的是
 * 記憶體中那份已經讀出來的快照，不是資料庫當下的值。它是 Domain 規則的守門
 * （「這個轉換合不合法」），不是併發的守門（「誰搶到了」）。
 *
 * 唯一可靠的做法是把「比對」與「寫入」壓進**同一個 SQL 陳述式**：
 * `UPDATE ... SET meta_value = {to} WHERE meta_key = '_settlement_status' AND meta_value = {from}`，
 * 再以 **affected rows** 判定誰搶到。資料庫的 row lock 保證只有一個請求會拿到 1。
 *
 * ## 兩層守門的分工（缺一不可）
 *
 * | 層 | 負責 | 失敗時 |
 * |---|---|---|
 * | `SettlementRecord::mark_*()` | **規則**：這個狀態轉換合不合法 | 拋 `InvalidStatusTransition` |
 * | `compare_and_set()` | **併發**：這一輪誰搶到 | 回 `false`，呼叫端列入「已跳過」 |
 *
 * 兩者都保留：只有 CAS 會讓 Domain 狀態機失去意義（`from` 參數變成規則的唯一來源）；
 * 只有 Domain 會回到 read-check-write。
 */
interface SettlementStatusMutatorInterface {

	/**
	 * 原子地把結算狀態從 $from 換成 $to
	 *
	 * @param int    $order_item_id 訂單品項 ID
	 * @param string $from          期望的當前狀態（比對值）
	 * @param string $to            目標狀態
	 *
	 * @return bool true = 本次呼叫搶到並完成轉換；false = 狀態已非 $from（別人先改了 / 紀錄不存在）
	 */
	public function compare_and_set( int $order_item_id, string $from, string $to ): bool;

	/**
	 * 寫入撥款稽核欄位（`_settled_at` / `_settled_by`）
	 *
	 * ⚠️ **只有 CAS 搶到的那一方可以呼叫**。這兩欄是「誰在何時撥的款」的唯一證據，
	 * 讓沒搶到的請求也寫入會讓證據指向錯的人與錯的時間。
	 *
	 * @param int $order_item_id 訂單品項 ID
	 * @param int $settled_at    撥款時間（unix timestamp）
	 * @param int $settled_by    操作者 user ID
	 *
	 * @return void
	 */
	public function mark_settled( int $order_item_id, int $settled_at, int $settled_by ): void;

	/**
	 * 讀取當前結算狀態（供 CAS 失敗後回報準確原因用）
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return string|null 當前狀態；狀態 meta 不存在時回 null
	 */
	public function current_status( int $order_item_id ): ?string;
}
