<?php
/**
 * 結算批次操作的共用輸入守門
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\Support;

/**
 * 三個批次 UseCase（mark-paid / mark-cancelled / reverse-paid）共用的輸入守門
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5、§7.2
 *
 * 三個 UseCase 的**狀態轉換規則刻意不同**（分別要求 pending / pending / paid），
 * 那部分不共用；但「輸入長什麼樣才算合法」對三者完全一致，抽在這裡避免三份實作
 * 各自漂移——尤其是批次上限：漏在其中一個端點上，那個端點就是整條鏈的 DoS 缺口。
 *
 * 全部例外都是 `\DomainException`，經 `ExceptionMapper` 對映成 400 validation_failed。
 */
final class SettlementBatchGuard {

	/**
	 * 正規化並驗證 order_item_id 清單
	 *
	 * - 一律 `intval` 後只留正整數（`"12abc"` → 12、`"abc"` → 0 被丟棄）
	 * - 去重（前端重複勾選同一筆時不該被處理兩次）
	 * - 空清單與超量都 fail-fast
	 *
	 * @param array<int, mixed> $raw_ids    原始 ID 清單
	 * @param int               $max_batch  單次批次上限
	 *
	 * @throws \DomainException 清單為空或超過上限時拋出
	 *
	 * @return int[] 去重後的正整數清單（保持首次出現順序）
	 */
	public static function normalize_ids( array $raw_ids, int $max_batch ): array {
		$ids = [];
		foreach ( $raw_ids as $raw ) {
			if ( is_array( $raw ) || is_object( $raw ) || is_bool( $raw ) ) {
				continue;
			}
			$id = (int) $raw;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			throw new \DomainException( 'order_item_ids 不可為空，且必須是正整數' );
		}

		if ( count( $ids ) > $max_batch ) {
			throw new \DomainException(
				sprintf( '單次最多只能處理 %d 筆結算紀錄，本次收到 %d 筆', $max_batch, count( $ids ) )
			);
		}

		return $ids;
	}

	/**
	 * 驗證操作者 user ID
	 *
	 * 撥款紀錄要寫進 `_settled_by` 與稽核紀錄的 `by` 欄位——這是「誰撥的款」的唯一
	 * 證據。若 caller 拿到 0（未登入 / capability 流程有缺陷），寫下去會產生一筆
	 * **無法歸責的撥款紀錄**，事後對帳查不出是誰操作的。寧可 fail-fast。
	 *
	 * @param int $operator_user_id 操作者 user ID
	 *
	 * @throws \DomainException 操作者 ID 不是正整數時拋出
	 *
	 * @return void
	 */
	public static function assert_operator( int $operator_user_id ): void {
		if ( $operator_user_id <= 0 ) {
			throw new \DomainException( '無法辨識操作者身分，請重新登入後再試' );
		}
	}

	/**
	 * 把 Domain 狀態機拋出的非法轉換例外，翻成站長看得懂、且能據以行動的訊息
	 *
	 * `InvalidStatusTransition` 的原始訊息是「無法從 refunded 轉換到 paid」——
	 * 正確但沒說「那我該怎麼辦」。這裡補上該用哪個操作。
	 *
	 * @param \Throwable $e             Domain 拋出的例外
	 * @param string     $action_label  這次想做的事（例：`已撥款`）
	 * @param string     $allowed_label 允許的來源狀態說明（例：`待撥款（pending）`）
	 *
	 * @return string
	 */
	public static function describe_illegal_transition( \Throwable $e, string $action_label, string $allowed_label ): string {
		return sprintf(
			'%s（只有%s可標記為%s）',
			$e->getMessage(),
			$allowed_label,
			$action_label
		);
	}

	/**
	 * 組出「CAS 沒搶到」的說明訊息
	 *
	 * 🔴 訊息一定要寫出「本次未重複計入」：第二位管理員若不知道自己那次沒生效，
	 * 很可能真的再去銀行轉一次帳——這正是併發防護要「明確回報」而不只是
	 * 「不重複寫入」的理由。
	 *
	 * @param string|null $current_status CAS 失敗後重新讀出的當下狀態（null = 狀態 meta 不存在）
	 * @param string      $action_label   這次想做的事（例：`標記為已撥款`）
	 *
	 * @return string
	 */
	public static function describe_lost_race( ?string $current_status, string $action_label ): string {
		if ( null === $current_status ) {
			return sprintf(
				'這筆紀錄沒有結算狀態欄位，無法安全地%s，本次未重複計入（請確認該訂單品項是否由分潤流程建立）',
				$action_label
			);
		}

		return sprintf(
			'狀態已被其他操作改為 %s，本次未%s、未重複計入',
			$current_status,
			$action_label
		);
	}
}
