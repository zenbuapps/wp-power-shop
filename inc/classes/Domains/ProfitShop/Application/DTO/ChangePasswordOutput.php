<?php
/**
 * Partner 自助修密碼輸出 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Partner 自助修密碼成功回應 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3
 *
 * 不含任何敏感欄位（password / hash / token），可安全序列化至 REST response。
 *
 * `password_changed_at` 為 unix timestamp（int），前端可選擇據以提示「上次改密時間」，
 * 後端則用同值寫入 termmeta `_partner_password_changed_at`，作為 PartnerTokenStore::verify
 * 比對「token 簽發時間 < password_changed_at → 視為已撤銷」的依據。
 */
final class ChangePasswordOutput {

	/**
	 * 建構子
	 *
	 * @param bool $success             是否成功（目前 happy path 永遠 true，保留欄位以便未來擴充 partial success）
	 * @param int  $password_changed_at 變更後的 password_changed_at（unix timestamp）
	 */
	public function __construct(
		public readonly bool $success,
		public readonly int $password_changed_at
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
			success: (bool) ( $data['success'] ?? false ),
			password_changed_at: (int) ( $data['password_changed_at'] ?? 0 ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{success: bool, password_changed_at: int}
	 */
	public function to_array(): array {
		return [
			'success'             => $this->success,
			'password_changed_at' => $this->password_changed_at,
		];
	}
}
