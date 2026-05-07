<?php
/**
 * Settlement 摘要輸出 DTO（API Response）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Settlement 摘要讀取 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §7
 *
 * 數值欄位採字串保存以保留小數精度（避免 float 誤差）。
 */
final class SettlementSummaryOutput {

	/**
	 * 建構子
	 *
	 * @param string $total_sales     總銷售額（字串以保留小數精度）
	 * @param string $profit_pending  待結算分潤
	 * @param string $profit_paid     已結算分潤
	 * @param string $profit_refunded 已退款分潤
	 */
	public function __construct(
		public readonly string $total_sales,
		public readonly string $profit_pending,
		public readonly string $profit_paid,
		public readonly string $profit_refunded
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
			total_sales: (string) ( $data['total_sales'] ?? '0.00' ),
			profit_pending: (string) ( $data['profit_pending'] ?? '0.00' ),
			profit_paid: (string) ( $data['profit_paid'] ?? '0.00' ),
			profit_refunded: (string) ( $data['profit_refunded'] ?? '0.00' ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   total_sales: string,
	 *   profit_pending: string,
	 *   profit_paid: string,
	 *   profit_refunded: string
	 * }
	 */
	public function to_array(): array {
		return [
			'total_sales'     => $this->total_sales,
			'profit_pending'  => $this->profit_pending,
			'profit_paid'     => $this->profit_paid,
			'profit_refunded' => $this->profit_refunded,
		];
	}
}
