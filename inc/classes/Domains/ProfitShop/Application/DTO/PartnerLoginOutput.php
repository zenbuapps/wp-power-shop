<?php
/**
 * Partner 登入輸出 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Partner 登入回應 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 / §7
 *
 * 攜帶 token + 過期時間 + Partner 公開資訊。
 *
 * `expires_at` 採字串型別保持序列化彈性（DtoRoundTripTest 鎖定 round-trip 不變更）；
 * Production 由 LoginPartnerUseCase 將 PartnerTokenStore::issue() 回傳的 unix timestamp（int）
 * 轉成字串（以 `(string)` cast，無格式化），前端視需求自行 parse。
 */
final class PartnerLoginOutput {

	/**
	 * 建構子
	 *
	 * @param string $token        驗證 token
	 * @param string $expires_at   過期時間（unix timestamp 字串化；前端 parseInt 即得秒數）
	 * @param int    $partner_id   Partner term ID
	 * @param string $partner_name Partner 名稱
	 */
	public function __construct(
		public readonly string $token,
		public readonly string $expires_at,
		public readonly int $partner_id,
		public readonly string $partner_name
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
			token: (string) ( $data['token'] ?? '' ),
			expires_at: (string) ( $data['expires_at'] ?? '' ),
			partner_id: (int) ( $data['partner_id'] ?? 0 ),
			partner_name: (string) ( $data['partner_name'] ?? '' ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   token: string,
	 *   expires_at: string,
	 *   partner_id: int,
	 *   partner_name: string
	 * }
	 */
	public function to_array(): array {
		return [
			'token'        => $this->token,
			'expires_at'   => $this->expires_at,
			'partner_id'   => $this->partner_id,
			'partner_name' => $this->partner_name,
		];
	}
}
