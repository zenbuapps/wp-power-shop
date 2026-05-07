<?php
/**
 * 批次操作結果 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * 批次操作（如批次刪除商品、批次匯入）結果 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6
 *
 * - success_ids：成功處理的 ID 陣列
 * - failures：id => error_message 的對照表
 */
final class BulkResult {

	/**
	 * 建構子
	 *
	 * @param int[]              $success_ids 成功處理的 ID 清單
	 * @param array<int, string> $failures    id => 錯誤訊息對照表
	 */
	public function __construct(
		public readonly array $success_ids,
		public readonly array $failures
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
			success_ids: (array) ( $data['success_ids'] ?? [] ),
			failures: (array) ( $data['failures'] ?? [] ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   success_ids: int[],
	 *   failures: array<int, string>
	 * }
	 */
	public function to_array(): array {
		return [
			'success_ids' => $this->success_ids,
			'failures'    => $this->failures,
		];
	}
}
