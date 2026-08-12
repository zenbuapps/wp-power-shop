<?php
/**
 * Slug 衝突資訊 Value Object
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\ValueObject;

/**
 * Slug 衝突資訊 Value Object（單筆衝突記錄）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.1
 *
 * 描述「域內 slug 與外部資源（profit_shop / product / page）衝突」這件 Domain fact，
 * 本質為 Value Object（無身分、不可變、欄位 readonly），歸屬 Domain 層。
 *
 * conflict_kind 可能值：'profit_shop' | 'product' | 'page' | ...
 * 由 SlugConflictDetector 偵測並包入 SlugConflictException::$conflicts。
 */
final class SlugConflict {

	/**
	 * 建構子
	 *
	 * @param string   $conflict_kind     衝突來源類型（machine code）
	 * @param string   $conflicting_slug  衝突的 slug 字串
	 * @param int|null $conflicting_id    衝突資源的 ID（可為 null）
	 * @param string   $conflicting_label 衝突資源的人類可讀名稱
	 */
	public function __construct(
		public readonly string $conflict_kind,
		public readonly string $conflicting_slug,
		public readonly ?int $conflicting_id,
		public readonly string $conflicting_label
	) {}

	/**
	 * 從原始陣列建立 Value Object
	 *
	 * @param array<string, mixed> $data 原始輸入陣列
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			conflict_kind: (string) ( $data['conflict_kind'] ?? '' ),
			conflicting_slug: (string) ( $data['conflicting_slug'] ?? '' ),
			conflicting_id: isset( $data['conflicting_id'] ) ? (int) $data['conflicting_id'] : null,
			conflicting_label: (string) ( $data['conflicting_label'] ?? '' ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   conflict_kind: string,
	 *   conflicting_slug: string,
	 *   conflicting_id: int|null,
	 *   conflicting_label: string
	 * }
	 */
	public function to_array(): array {
		return [
			'conflict_kind'     => $this->conflict_kind,
			'conflicting_slug'  => $this->conflicting_slug,
			'conflicting_id'    => $this->conflicting_id,
			'conflicting_label' => $this->conflicting_label,
		];
	}
}
