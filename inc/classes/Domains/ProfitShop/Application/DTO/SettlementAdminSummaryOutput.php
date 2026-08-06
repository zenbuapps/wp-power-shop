<?php
/**
 * 後台結算摘要卡輸出 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * 後台結算頁摘要卡（待撥款 / 已撥款 / 退款）輸出 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5
 *   「摘要卡：待撥款金額（筆數） / 已撥款金額（筆數） / 退款金額」
 *
 * 與既有 SettlementSummaryOutput 的差異（刻意不共用）：
 *   - SettlementSummaryOutput 服務 **partner 自己的報表**，只有金額、沒有筆數
 *   - 本 DTO 服務 **後台結算頁**，每個狀態都要「金額 + 筆數」讓站長對帳
 *
 * 🔴 所有金額欄位一律是**十進位字串**（bcmath 產出），不是 float——
 * 撥款金額用 float 累加會在幾千筆之後出現分位誤差，而這裡的數字會直接變成
 * 轉給夥伴的金額。任何一端把它轉成 float 再轉回來都算破壞契約。
 */
final class SettlementAdminSummaryOutput {

	/**
	 * 建構子
	 *
	 * @param string $pending_amount   待撥款分潤金額（十進位字串）
	 * @param int    $pending_count    待撥款筆數
	 * @param string $paid_amount      已撥款分潤金額（十進位字串）
	 * @param int    $paid_count       已撥款筆數
	 * @param string $refunded_amount  已退款分潤金額（十進位字串）
	 * @param int    $refunded_count   已退款筆數
	 * @param string $cancelled_amount 已取消分潤金額（十進位字串）
	 * @param int    $cancelled_count  已取消筆數
	 * @param string $total_sales      總成交金額（actual_price 加總，十進位字串）
	 * @param int    $total_count      總筆數（四種狀態合計）
	 */
	public function __construct(
		public readonly string $pending_amount = '0.00',
		public readonly int $pending_count = 0,
		public readonly string $paid_amount = '0.00',
		public readonly int $paid_count = 0,
		public readonly string $refunded_amount = '0.00',
		public readonly int $refunded_count = 0,
		public readonly string $cancelled_amount = '0.00',
		public readonly int $cancelled_count = 0,
		public readonly string $total_sales = '0.00',
		public readonly int $total_count = 0
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
			pending_amount: (string) ( $data['pending_amount'] ?? '0.00' ),
			pending_count: (int) ( $data['pending_count'] ?? 0 ),
			paid_amount: (string) ( $data['paid_amount'] ?? '0.00' ),
			paid_count: (int) ( $data['paid_count'] ?? 0 ),
			refunded_amount: (string) ( $data['refunded_amount'] ?? '0.00' ),
			refunded_count: (int) ( $data['refunded_count'] ?? 0 ),
			cancelled_amount: (string) ( $data['cancelled_amount'] ?? '0.00' ),
			cancelled_count: (int) ( $data['cancelled_count'] ?? 0 ),
			total_sales: (string) ( $data['total_sales'] ?? '0.00' ),
			total_count: (int) ( $data['total_count'] ?? 0 ),
		);
	}

	/**
	 * 序列化為陣列（與 from_array 對稱）
	 *
	 * @return array{
	 *   pending_amount: string,
	 *   pending_count: int,
	 *   paid_amount: string,
	 *   paid_count: int,
	 *   refunded_amount: string,
	 *   refunded_count: int,
	 *   cancelled_amount: string,
	 *   cancelled_count: int,
	 *   total_sales: string,
	 *   total_count: int
	 * }
	 */
	public function to_array(): array {
		return [
			'pending_amount'   => $this->pending_amount,
			'pending_count'    => $this->pending_count,
			'paid_amount'      => $this->paid_amount,
			'paid_count'       => $this->paid_count,
			'refunded_amount'  => $this->refunded_amount,
			'refunded_count'   => $this->refunded_count,
			'cancelled_amount' => $this->cancelled_amount,
			'cancelled_count'  => $this->cancelled_count,
			'total_sales'      => $this->total_sales,
			'total_count'      => $this->total_count,
		];
	}
}
