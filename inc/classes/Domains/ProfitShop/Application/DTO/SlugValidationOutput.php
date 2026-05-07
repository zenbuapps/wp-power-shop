<?php
/**
 * Slug 驗證結果 Output DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;

/**
 * Slug 驗證結果輸出
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 *
 * 由 ValidateSlugUseCase 產出，用於 GET /profit-shops/validate-slug 回應。
 *
 * - available=true 代表完全沒有衝突，conflicts 為空陣列。
 * - available=false 代表至少一個衝突，conflicts 為完整衝突清單（不截斷）。
 */
final class SlugValidationOutput {

	/**
	 * 建構子
	 *
	 * @param bool           $available 此 slug 是否可用（無衝突）
	 * @param SlugConflict[] $conflicts 衝突清單；無衝突時為空陣列
	 */
	public function __construct(
		public readonly bool $available,
		public readonly array $conflicts
	) {}

	/**
	 * 取得是否可用
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   available: bool,
	 *   conflicts: array<int, array{
	 *     conflict_kind: string,
	 *     conflicting_slug: string,
	 *     conflicting_id: int|null,
	 *     conflicting_label: string
	 *   }>
	 * }
	 */
	public function to_array(): array {
		return [
			'available' => $this->available,
			'conflicts' => array_map(
				static fn( SlugConflict $c ): array => $c->to_array(),
				$this->conflicts
			),
		];
	}
}
