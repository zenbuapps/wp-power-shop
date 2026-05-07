<?php
/**
 * Partner Term Repository 介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Repository;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PersistenceFailure;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;

/**
 * Partner KOL 的持久化抽象。
 *
 * Infrastructure 層提供具體實作（TermPartnerRepository），
 * 內部以 WP custom taxonomy term + termmeta 儲存。
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4。
 */
interface PartnerRepositoryInterface {

	/**
	 * 依 slug 取得 Partner
	 *
	 * @param string $slug Partner slug 字串
	 *
	 * @return PartnerSnapshot|null 找不到時回傳 null
	 */
	public function find_by_slug( string $slug ): ?PartnerSnapshot;

	/**
	 * 依 term ID 取得 Partner
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return PartnerSnapshot|null 找不到時回傳 null
	 */
	public function find_by_id( int $term_id ): ?PartnerSnapshot;

	/**
	 * 儲存 Partner（含密碼雜湊）
	 *
	 * @param PartnerSnapshot $partner        Partner 資訊
	 * @param string|null     $plain_password 明文密碼（null 代表不變更）
	 *
	 * @return int term ID
	 */
	public function save( PartnerSnapshot $partner, ?string $plain_password = null ): int;

	/**
	 * 檢查 Partner 是否還掛在任何賣場上
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return bool 仍被綁定回傳 true
	 */
	public function is_in_use( int $term_id ): bool;

	/**
	 * 驗證 Partner 密碼
	 *
	 * @param int    $term_id        Partner term ID
	 * @param string $plain_password 明文密碼
	 *
	 * @return bool 密碼正確回傳 true
	 */
	public function verify_password( int $term_id, string $plain_password ): bool;

	/**
	 * 刪除 Partner（含 termmeta cleanup）
	 *
	 * 注意：呼叫端應先透過 is_in_use() 檢查，避免刪除仍綁定賣場的 Partner。
	 * wp_delete_term 會自動清除所有 termmeta，無須額外處理。
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @throws PersistenceFailure 當 wp_delete_term 失敗或 term 不存在時拋出
	 *
	 * @return void
	 */
	public function delete( int $term_id ): void;

	/**
	 * 列出全部 Partner（id 升冪排序）
	 *
	 * 對應 spec §4.2 GET /profit-partners 列表端點。
	 *
	 * ⚠️ 警告：此方法無分頁，會載入所有 Partner 並對每筆做 O(n) termmeta hydrate（N+1 query）。
	 *          適用於資料量 < 200 的後台 admin list。超過此規模請改用後續 phase 將要新增的
	 *          paginate( int $page, int $per_page, FilterCriteria $criteria )。
	 *
	 * @return PartnerSnapshot[]
	 */
	public function all(): array;
}
