<?php
/**
 * 結算 Audit Log 抽象介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * 撥款 / 沖回的業務稽核紀錄 Port
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5、§7.5
 *
 * Production 由 `PartnerSettledLogRepository` 寫入 partner termmeta `_partner_settled_log`；
 * 測試由 `Tests\Support\InMemorySettlementAuditLog` 取代。
 *
 * ⚠️ 這是**業務稽核**（站長對帳用）而非技術 log（`WC::logger`）——spec §7.5 明確把兩者
 * 分開。撥款是真實金流動作，「誰在什麼時候把多少錢標記成已撥給誰」必須留在資料庫裡，
 * 不能只寫進會被輪替 / 清空的 log 檔。
 *
 * 寫入失敗的處理紀律在 UseCase 端（`append()` 的例外**不得**讓已經成功的狀態變更回滾——
 * 狀態已經寫進 order itemmeta，此時拋例外只會讓站長看到「操作失敗」卻發現狀態其實已改，
 * 反而更危險）。
 */
interface SettlementAuditLogInterface {

	/**
	 * 在指定 partner 的稽核紀錄後方追加一筆
	 *
	 * @param int                  $partner_term_id Partner term ID
	 * @param array<string, mixed> $entry           稽核項目（spec §7.5 形狀：
	 *                                              date / type / total / item_count / by）
	 *
	 * @return void
	 */
	public function append( int $partner_term_id, array $entry ): void;

	/**
	 * 讀取指定 partner 的全部稽核紀錄（供後台檢視 / 測試斷言）
	 *
	 * @param int $partner_term_id Partner term ID
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read( int $partner_term_id ): array;
}
