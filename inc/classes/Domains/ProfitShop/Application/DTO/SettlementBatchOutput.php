<?php
/**
 * 結算批次操作結果 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * 結算批次操作（mark-paid / mark-cancelled / reverse-paid）的結果
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5、§7.5
 *
 * ## 為何不直接用既有的 BulkResult
 *
 * `BulkResult`（success_ids + failures）能表達「成功 / 失敗」，但表達不了本情境的兩件事，
 * 而這兩件事都會直接影響站長的對帳判斷：
 *
 * 1. **`total_amount`**——這次到底把多少錢標記成已撥款。站長要拿這個數字去跟銀行轉帳
 *    對照，回應裡沒有的話他得自己把每一筆加起來（而那正是浮點誤差最容易發生的地方）。
 * 2. **`audit_log_failed_partner_ids`**——狀態已經改成功、但稽核紀錄沒寫進去。
 *    這是**部分成功**，不是失敗：把它併進 `failures` 會讓站長以為那些品項沒被撥款而重撥；
 *    完全不報又會讓 `_partner_settled_log` 悄悄少一筆、日後對不上帳。
 *
 * ## `skipped` 的語意（安全關鍵）
 *
 * `skipped` 是 `order_item_id => 原因`，涵蓋「找不到」與「狀態不符」兩類。
 * 🔴 狀態不符**必須**出現在這裡而不是被靜默略過——兩個管理員同時按下「標記已撥款」時，
 * 第二個人得看到「這 12 筆已經是 paid 了，本次未重複計入」，否則他會以為自己撥了兩次
 * 而真的去轉第二次帳。這就是併發防護要「明確回報」而不只是「不重複寫入」的理由。
 */
final class SettlementBatchOutput {

	/**
	 * 建構子
	 *
	 * @param int[]              $updated_ids                  實際完成狀態轉換的 order_item_id
	 * @param array<int, string> $skipped                      被跳過者：order_item_id => 原因
	 * @param string             $total_amount                 本次異動的分潤金額合計（十進位字串）
	 * @param int[]              $audit_log_failed_partner_ids 稽核紀錄寫入失敗的 partner term ID
	 */
	public function __construct(
		public readonly array $updated_ids = [],
		public readonly array $skipped = [],
		public readonly string $total_amount = '0.00',
		public readonly array $audit_log_failed_partner_ids = []
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
			updated_ids: array_values( array_map( 'intval', (array) ( $data['updated_ids'] ?? [] ) ) ),
			skipped: (array) ( $data['skipped'] ?? [] ),
			total_amount: (string) ( $data['total_amount'] ?? '0.00' ),
			audit_log_failed_partner_ids: array_values(
				array_map( 'intval', (array) ( $data['audit_log_failed_partner_ids'] ?? [] ) )
			),
		);
	}

	/**
	 * 序列化為陣列（與 from_array 對稱）
	 *
	 * `skipped` 以物件形式輸出（`{"123": "原因"}`），JSON 化後鍵是字串——
	 * 前端讀回來要記得 `Number(key)`。
	 *
	 * @return array{
	 *   updated_ids: int[],
	 *   updated_count: int,
	 *   skipped: array<int, string>,
	 *   skipped_count: int,
	 *   total_amount: string,
	 *   audit_log_failed_partner_ids: int[]
	 * }
	 */
	public function to_array(): array {
		return [
			'updated_ids'                  => $this->updated_ids,
			'updated_count'                => count( $this->updated_ids ),
			'skipped'                      => $this->skipped,
			'skipped_count'                => count( $this->skipped ),
			'total_amount'                 => $this->total_amount,
			'audit_log_failed_partner_ids' => $this->audit_log_failed_partner_ids,
		];
	}
}
