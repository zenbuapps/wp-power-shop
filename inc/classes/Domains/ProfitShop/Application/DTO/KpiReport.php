<?php
/**
 * KPI 報表 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * KPI 報表 DTO（指定期間內的彙總指標）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6 / §7
 *
 * 數值欄位採字串保存以保留小數精度。
 */
final class KpiReport {

	/**
	 * 建構子
	 *
	 * @param string $total_sales     總銷售額
	 * @param string $profit_pending  待結算分潤
	 * @param string $profit_paid     已結算分潤
	 * @param string $profit_refunded 已退款分潤
	 * @param string $period_start    期間起點（Y-m-d）
	 * @param string $period_end      期間終點（Y-m-d）
	 */
	public function __construct(
		public readonly string $total_sales,
		public readonly string $profit_pending,
		public readonly string $profit_paid,
		public readonly string $profit_refunded,
		public readonly string $period_start,
		public readonly string $period_end
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
			period_start: (string) ( $data['period_start'] ?? '' ),
			period_end: (string) ( $data['period_end'] ?? '' ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return [
			'total_sales'     => $this->total_sales,
			'profit_pending'  => $this->profit_pending,
			'profit_paid'     => $this->profit_paid,
			'profit_refunded' => $this->profit_refunded,
			'period_start'    => $this->period_start,
			'period_end'      => $this->period_end,
		];
	}
}
