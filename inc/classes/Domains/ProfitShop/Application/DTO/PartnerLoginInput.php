<?php
/**
 * Partner 登入輸入 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Partner 登入請求 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 / §7
 */
final class PartnerLoginInput {

	/**
	 * 建構子
	 *
	 * @param string $slug     Partner slug
	 * @param string $password 明文密碼
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $password
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
			slug: (string) ( $data['slug'] ?? '' ),
			password: (string) ( $data['password'] ?? '' ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{slug: string, password: string}
	 */
	public function to_array(): array {
		return [
			'slug'     => $this->slug,
			'password' => $this->password,
		];
	}
}
