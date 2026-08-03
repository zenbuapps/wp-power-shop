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
 *
 * `image_url` 是由 `image_id` 推導出的**唯讀衍生欄位**（Infrastructure 層在 hydrate 時
 * 以 `wp_get_attachment_image_url()` 解析），僅供顯示。持久化只寫 `image_id`，
 * `save()` 一律忽略傳入的 `image_url`——避免 URL 被當成真相來源而寫死進 DB
 * （站台換網域 / 換 CDN 時 URL 會失效，attachment ID 不會）。
 *
 * `report_url` 同樣是**唯讀衍生欄位**，由 `slug` 加上 report 前綴
 * （`RewriteRules::get_report_slug()`）即時組出，一樣不持久化。它存在的理由是：
 * 報表網址的組成規則只有 PHP 端知道，前端若自行拼接就得複製一份前綴的真相來源，
 * 日後前綴一改就會出現前後端不一致。改由後端衍生後，前端零 hardcode。
 * （前綴自身現為固定常數，站長無法修改——見 `RewriteRules::DEFAULT_REPORT_SLUG`。）
 */
final class PartnerSnapshot {

	/**
	 * 建構子
	 *
	 * @param int         $term_id       Partner term ID
	 * @param string      $name          Partner 顯示名稱
	 * @param PartnerSlug $slug          Partner slug（已驗證之 ValueObject）
	 * @param string|null $contact_email 聯絡 email（nullable）
	 * @param int|null    $image_id      夥伴圖片 attachment ID（nullable，null 代表未設定）
	 * @param string|null $image_url     夥伴圖片 URL（**唯讀衍生欄位**，由 image_id 推導）
	 * @param string|null $report_url    夥伴報表頁網址（**唯讀衍生欄位**，由 slug + report slug 推導）
	 */
	public function __construct(
		public readonly int $term_id,
		public readonly string $name,
		public readonly PartnerSlug $slug,
		public readonly ?string $contact_email = null,
		public readonly ?int $image_id = null,
		public readonly ?string $image_url = null,
		public readonly ?string $report_url = null
	) {
	}
}
