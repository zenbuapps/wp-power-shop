<?php
/**
 * Settlement 列表輸出 DTO（API Response）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Settlement 列表讀取 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §7
 *
 * 包含分頁中介資料（total / page / per_page）。
 */
final class SettlementListOutput {

	/**
	 * 建構子
	 *
	 * @param array<int, array<string, mixed>> $items    結算記錄陣列
	 * @param int                              $total    總筆數
	 * @param int                              $page     當前頁碼（1-based）
	 * @param int                              $per_page 每頁筆數
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $page,
		public readonly int $per_page
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
			items: (array) ( $data['items'] ?? [] ),
			total: (int) ( $data['total'] ?? 0 ),
			page: (int) ( $data['page'] ?? 1 ),
			per_page: (int) ( $data['per_page'] ?? 20 ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   items: array<int, array<string, mixed>>,
	 *   total: int,
	 *   page: int,
	 *   per_page: int
	 * }
	 */
	public function to_array(): array {
		return [
			'items'    => $this->items,
			'total'    => $this->total,
			'page'     => $this->page,
			'per_page' => $this->per_page,
		];
	}
}
