<?php
/**
 * 後台結算查詢 Provider 抽象介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;

/**
 * 後台結算頁的查詢 Port（跨 partner，admin-only）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3
 *
 * 🔴 **與 SettlementSummaryProviderInterface 刻意分成兩個介面，這是安全設計不是重複**：
 *
 * | | SettlementSummaryProviderInterface | 本介面 |
 * |---|---|---|
 * | 消費端 | `partner-reports/*`（partner token） | `profit-settlements/*`（admin） |
 * | 第一參數 | `int $partner_term_id`（**必填、鎖死**） | `SettlementFilterInput`（partner 是可選篩選） |
 * | 實作的守門 | `partner_term_id <= 0` 一律 short-circuit 回空 | 無 partner 限制（admin 本就看全部） |
 *
 * 把兩者合併成一個「partner_term_id 可為 null」的介面，會讓 partner 端點在型別上
 * **可以**呼叫到不帶 partner 範圍的查詢——只要有人日後手滑傳了 null，跨 partner 越權
 * 就會發生，而且沒有任何測試會紅（型別是通的、SQL 也是對的）。分成兩個介面，這件事
 * 在結構上做不到。`profit-shop.rule.md` §7「partner_term_id 鎖死於 token」是同一條紀律。
 *
 * 實作端（Infrastructure）仍須各自做 SQL 白名單與 prepare——本介面只保證「呼叫得到的
 * 範圍」，不保證輸入已清理。
 */
interface SettlementQueryProviderInterface {

	/**
	 * 依過濾條件取得結算列表（含分頁）
	 *
	 * @param SettlementFilterInput $filter 過濾條件（含 page / per_page）
	 *
	 * @return array<int, array<string, mixed>> 每筆含 order_item_id / order_id / product_name /
	 *                                          partner_term_id / shop_id / actual_price /
	 *                                          profit_amount / rate / status / settled_at / order_date
	 */
	public function find_settlements( SettlementFilterInput $filter ): array;

	/**
	 * 依過濾條件取得符合的總筆數（忽略 page / per_page）
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return int
	 */
	public function count_settlements( SettlementFilterInput $filter ): int;

	/**
	 * 依過濾條件取得各狀態的金額與筆數彙總（忽略 page / per_page）
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return array<string, mixed> SettlementAdminSummaryOutput::from_array 可吃的形狀
	 */
	public function summarize_settlements( SettlementFilterInput $filter ): array;

	/**
	 * 依過濾條件取得符合的 order_id 清單（去重，忽略 page / per_page）
	 *
	 * 供「訂單列表依分潤賣場過濾」使用——WooCommerce REST 的 `orders` 端點無法依
	 * order item meta 篩選，前端得先拿到 order_id 陣列再當成 `include` filter 送出。
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 * @param int                   $limit  最多回傳幾筆（`include` query string 有長度上限）
	 *
	 * @return int[]
	 */
	public function find_order_ids( SettlementFilterInput $filter, int $limit ): array;

	/**
	 * 依過濾條件取得符合的**訂單**總數（去重，忽略 limit）
	 *
	 * 用來判斷 `find_order_ids()` 的結果有沒有被 `$limit` 截斷——
	 * 靜默截斷會讓站長以為「這個賣場只有這幾張訂單」。
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return int
	 */
	public function count_order_ids( SettlementFilterInput $filter ): int;
}
