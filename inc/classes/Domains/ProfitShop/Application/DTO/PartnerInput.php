<?php
/**
 * Partner 輸入 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Partner 寫入用 DTO（建立 / 更新）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §6.3
 *
 * 攜帶明文密碼供 Repository::save() 雜湊；不存於日誌與其它 DTO 內。
 *
 * @internal 此 DTO 內含明文密碼欄位，{@see self::to_array()} 不可序列化到 REST response，
 *           請改用 PartnerOutput 對外回傳。
 */
final class PartnerInput {

	/**
	 * 建構子
	 *
	 * @param int|null    $id            Partner term ID（建立時為 null）
	 * @param string      $name          名稱
	 * @param string      $slug          slug（唯一）
	 * @param string|null $contact_email 聯絡 email（可為 null）
	 * @param string|null $password      明文密碼（null 代表不變更）
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly string $slug,
		public readonly ?string $contact_email,
		public readonly ?string $password
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
			id: isset( $data['id'] ) ? (int) $data['id'] : null,
			name: (string) ( $data['name'] ?? '' ),
			slug: (string) ( $data['slug'] ?? '' ),
			contact_email: isset( $data['contact_email'] ) ? (string) $data['contact_email'] : null,
			password: isset( $data['password'] ) ? (string) $data['password'] : null,
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   id: int|null,
	 *   name: string,
	 *   slug: string,
	 *   contact_email: string|null,
	 *   password: string|null
	 * }
	 */
	public function to_array(): array {
		return [
			'id'            => $this->id,
			'name'          => $this->name,
			'slug'          => $this->slug,
			'contact_email' => $this->contact_email,
			'password'      => $this->password,
		];
	}
}
