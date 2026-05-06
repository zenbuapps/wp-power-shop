<?php
/**
 * 結算查詢過濾條件
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Criteria;

/**
 * SettlementRepository::find_by_partner() 等查詢的過濾條件 DTO
 *
 * 純值物件，由 Application 層組裝，傳遞給 Repository 用於資料庫查詢。
 */
final class FilterCriteria {

	/**
	 * 建構子
	 *
	 * @param int|null $date_start 起始時間（unix timestamp，null 代表不限）
	 * @param int|null $date_end   結束時間（unix timestamp，null 代表不限）
	 * @param int[]    $shop_ids   賣場 ID 過濾（空陣列代表不限）
	 * @param string[] $statuses   狀態過濾（pending/paid/refunded/cancelled，空陣列代表不限）
	 * @param int      $page       分頁頁碼（從 1 起算）
	 * @param int      $per_page   每頁筆數
	 */
	public function __construct(
		public readonly ?int $date_start = null,
		public readonly ?int $date_end = null,
		public readonly array $shop_ids = [],
		public readonly array $statuses = [],
		public readonly int $page = 1,
		public readonly int $per_page = 20
	) {
	}
}
