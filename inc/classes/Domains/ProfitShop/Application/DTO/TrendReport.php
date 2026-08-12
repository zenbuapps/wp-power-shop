<?php
/**
 * 趨勢報表 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * 趨勢報表 DTO（時間序列資料）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6 / §7
 *
 * series 為時間序列陣列，每筆含 date / profit / sales。
 */
final class TrendReport {

	/**
	 * 建構子
	 *
	 * @param array<int, array<string, mixed>> $series 時間序列點陣列
	 */
	public function __construct(
		public readonly array $series
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
			series: (array) ( $data['series'] ?? [] ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{series: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return [
			'series' => $this->series,
		];
	}
}
