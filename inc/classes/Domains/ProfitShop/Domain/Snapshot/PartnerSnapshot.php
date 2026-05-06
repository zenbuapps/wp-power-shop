<?php
/**
 * Partner Term 資訊快照
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Snapshot;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;

/**
 * Domain 層的 Partner KOL 資訊 DTO
 *
 * Infrastructure 層負責從 WP_Term + termmeta 映射到此 DTO，
 * 讓 Domain Service 維持純粹（不依賴 WP 函式）。
 *
 * 不含密碼欄位——密碼為 Infrastructure 層責任，Domain 不該碰。
 */
final class PartnerSnapshot {

	/**
	 * 建構子
	 *
	 * @param int         $term_id       Partner term ID
	 * @param string      $name          Partner 顯示名稱
	 * @param PartnerSlug $slug          Partner slug（已驗證之 ValueObject）
	 * @param string|null $contact_email 聯絡 email（nullable）
	 */
	public function __construct(
		public readonly int $term_id,
		public readonly string $name,
		public readonly PartnerSlug $slug,
		public readonly ?string $contact_email = null
	) {
	}
}
