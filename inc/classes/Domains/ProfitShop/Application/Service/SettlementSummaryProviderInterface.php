<?php
/**
 * Settlement Summary Provider 抽象介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;

/**
 * Partner Report 用的彙總／趨勢／清單查詢介面（Port）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §6.3
 *
 * 設計動機（Hand-off 紀錄）：
 *   - SettlementRepositoryInterface 專注 SettlementRecord CRUD（find_by_order_item / save / find_by_partner / find_by_shop）。
 *   - Partner Report 端點需要的是預聚合過的 KPI / time-series / paginated list（含分頁中介資料），與
 *     CRUD repository 職責不同——故抽出 SettlementSummaryProvider 獨立介面（避免 Repository 膨脹）。
 *   - Phase 3-C 僅實裝 Domain port（讓 UseCase / 測試替身可以共用同一形狀）；具體 WP 實作（SQL JOIN
 *     wc_order_itemmeta）留待 Phase 3-D 完成。Phase 3-C V2Api report 端點以「無資料時回 0/空陣列」
 *     的 placeholder 行為先讓紅燈過。
 *
 * 形狀與 Tests\Support\InMemorySettlementSummaryProvider 對齊（紅燈合約鎖定）。
 */
interface SettlementSummaryProviderInterface {

	/**
	 * 回傳指定 partner 的 KPI 概要
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<string, mixed> KpiReport::from_array 可吃的形狀（total_sales / profit_pending / profit_paid / profit_refunded / period_start / period_end）
	 */
	public function summary_for_partner( int $partner_term_id, FilterCriteria $criteria ): array;

	/**
	 * 回傳指定 partner 的時間序列
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 * @param string         $interval        粒度（day/week/month）
	 *
	 * @return array<int, array<string, mixed>> 每筆含 date / profit / sales 等欄位
	 */
	public function trend_for_partner( int $partner_term_id, FilterCriteria $criteria, string $interval ): array;

	/**
	 * 回傳指定 partner 的 settlement list（含分頁所需資訊由 caller 從 criteria 推算）
	 *
	 * @param int            $partner_term_id partner term ID
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<int, array<string, mixed>> 每筆 settlement 的鍵值陣列
	 */
	public function find_by_partner( int $partner_term_id, FilterCriteria $criteria ): array;

	/**
	 * 回傳該 partner 報表可篩選的賣場清單
	 *
	 * 🔴 **必須是兩個來源的聯集**，缺任一邊都會讓夥伴少看到自己的賣場：
	 *
	 *   A. **目前指派給他的賣場**（powershop CPT，`status = publish`）
	 *      → 涵蓋「站長指派了但還沒出單」的新賣場。
	 *   B. **結算紀錄中出現過的 shop_id**（order itemmeta 凍結值）
	 *      → 涵蓋「賣場已被刪除 / 已改派給別人」但歷史業績仍屬於他的情況。
	 *
	 * 兩者互不包含，所以不能只取其一：
	 *
	 *   | 情況 | 只有 A | 只有 B |
	 *   |---|---|---|
	 *   | 新賣場、零業績 | ✅ 列得到 | ❌ 消失 |
	 *   | 賣場已刪除、業績還在 | ❌ 消失（但總計仍含它 ⇒ 數字對不起來） | ✅ 列得到 |
	 *   | 賣場改派給別人、舊業績仍是他的 | ❌ 消失 | ✅ 列得到 |
	 *
	 * ⚠️ 曾經只用 B 而漏掉 A：夥伴名下兩個賣場只顯示出一個，而且他**無從分辨**
	 *    「那個賣場這期間沒業績」與「系統漏了它」——後者才是使用者實際回報的困惑。
	 *    「選了看到 0」不是缺點，那是誠實的答案；看不到選項才是資訊缺失。
	 *
	 * ⚠️ A 只取 `publish`：`draft` 賣場沒有可用的前台網址，對夥伴而言還不存在，
	 *    列出來只會讓人以為已經上線。若它曾經上線並產生過業績，B 會涵蓋它。
	 *
	 * 🔴 **IDOR 在結構上不可能**：A 查 `_profit_partner_term_id = $partner_term_id` 的 postmeta、
	 *    B 查同名 order itemmeta，兩者都鎖在該 partner 上；而真正的資料過濾另外由
	 *    `FilterCriteria::$shop_ids` 疊在已鎖 partner 的主查詢上——就算清單被竄改，
	 *    拿別人的 shop_id 去篩也只會得到空集合。
	 *
	 * ⚠️ 回傳的 `shop_name` 已由實作端解析完成（查不到或已 trash 一律給明確的佔位文字），
	 *    呼叫端**不需要**也**不應該**再自己查一次 post title——那會讓「已刪除」的判斷
	 *    出現兩份定義。
	 *
	 * @param int $partner_term_id partner term ID
	 *
	 * @return array<int, array{shop_id: int, shop_name: string}> 依賣場名稱排序
	 */
	public function shops_for_partner( int $partner_term_id ): array;
}
