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
 * 不含密碼欄位，僅暴露公開資訊。
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
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $slug,
		public readonly ?string $contact_email,
		public readonly string $created_at
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
	 *   created_at: string
	 * }
	 */
	public function to_array(): array {
		return [
			'id'            => $this->id,
			'name'          => $this->name,
			'slug'          => $this->slug,
			'contact_email' => $this->contact_email,
			'created_at'    => $this->created_at,
		];
	}
}
