<?php
/**
 * Partner 輸出 DTO（API Response）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Partner 讀取 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §6.3
 *
 * ⚠️ **本 DTO 含明文密碼**（`password`），僅供站長在後台查看 / 複製後轉交夥伴。
 *
 * 🔴 **`password` 欄位的可見範圍是 `manage_options`，不是「所有能打到這個 endpoint 的人」**——
 * 這兩者在本專案**並不相同**，過去這段 docblock 曾把它們混為一談：
 *
 * | endpoint | permission_callback | 誰通得過 |
 * |---|---|---|
 * | `GET /profit-partners`、`GET /profit-partners/{id}` | `null` | **`manage_woocommerce`**（含 shop_manager） |
 * | `POST|PUT|DELETE /profit-partners{/id}` | `admin_only_permission` | `manage_options` |
 *
 * 也就是說兩個 GET 的權限**比寫入側寬**，光靠 endpoint 權限並擋不住 shop_manager 讀到明文。
 * 因此那兩個 GET callback 在輸出前會再跑一次
 * `V2Api::mask_partner_password_for_current_user()`，非 `manage_options` 者拿到的
 * `password` 一律為 null。**新增任何回傳本 DTO 的 endpoint 時必須照做**——
 * 「這個 endpoint 是後台的」不等於「它的讀取者都是站長」。
 *
 * **絕不可**用於任何 partner-facing endpoint。`GET /partner-auth/me` 刻意不使用本 DTO、
 * 而是在 V2Api callback 內明確列舉四個欄位輸出——新增 partner-facing endpoint 時請照做，
 * 不要「順手」改成回傳 `PartnerOutput::to_array()`，那會把明文密碼送進 partner 的瀏覽器。
 *
 * 明文的來源、風險與可逆性說明見 `PartnerTermRepository` 的 class docblock。
 */
final class PartnerOutput {

	/**
	 * 建構子
	 *
	 * @param int         $id            Partner term ID
	 * @param string      $name          名稱
	 * @param string      $slug          slug
	 * @param string|null $contact_email 聯絡 email（可為 null）
	 * @param string      $created_at    建立時間（Y-m-d H:i:s）
	 * @param int|null    $image_id      夥伴圖片 attachment ID（可為 null）
	 * @param string|null $image_url     夥伴圖片 URL（由 image_id 推導；attachment 已被刪除時為 null）
	 * @param string|null $report_url    夥伴報表頁網址（由 slug + 站台 report slug 推導；唯讀衍生欄位）
	 * @param string|null $password      明文密碼（admin-only；null = 該夥伴建立於明文功能上線前）
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $slug,
		public readonly ?string $contact_email,
		public readonly string $created_at,
		public readonly ?int $image_id = null,
		public readonly ?string $image_url = null,
		public readonly ?string $report_url = null,
		public readonly ?string $password = null
	) {}

	/**
	 * 從原始陣列建立 DTO
	 *
	 * @param array<string, mixed> $data 原始輸入陣列
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			id: (int) ( $data['id'] ?? 0 ),
			name: (string) ( $data['name'] ?? '' ),
			slug: (string) ( $data['slug'] ?? '' ),
			contact_email: isset( $data['contact_email'] ) ? (string) $data['contact_email'] : null,
			created_at: (string) ( $data['created_at'] ?? '' ),
			image_id: isset( $data['image_id'] ) && (int) $data['image_id'] > 0 ? (int) $data['image_id'] : null,
			image_url: isset( $data['image_url'] ) && '' !== $data['image_url'] ? (string) $data['image_url'] : null,
			report_url: isset( $data['report_url'] ) && '' !== $data['report_url'] ? (string) $data['report_url'] : null,
			password: isset( $data['password'] ) && '' !== $data['password'] ? (string) $data['password'] : null,
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   id: int,
	 *   name: string,
	 *   slug: string,
	 *   contact_email: string|null,
	 *   created_at: string,
	 *   image_id: int|null,
	 *   image_url: string|null,
	 *   report_url: string|null,
	 *   password: string|null
	 * }
	 */
	public function to_array(): array {
		return [
			'id'            => $this->id,
			'name'          => $this->name,
			'slug'          => $this->slug,
			'contact_email' => $this->contact_email,
			'created_at'    => $this->created_at,
			'image_id'      => $this->image_id,
			'image_url'     => $this->image_url,
			'report_url'    => $this->report_url,
			'password'      => $this->password,
		];
	}
}
