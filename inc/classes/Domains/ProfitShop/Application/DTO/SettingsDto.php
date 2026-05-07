<?php
/**
 * 全域設定 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * Profit Shop 全域設定 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.5
 *
 * 提供 Admin 設定頁面的讀寫資料載體。
 */
final class SettingsDto {

	/**
	 * 建構子
	 *
	 * @param string $rewrite_slug  賣場頁面的 rewrite slug（例如 'shop'）
	 * @param string $report_slug   報表頁面的 rewrite slug（例如 'report'）
	 * @param int    $default_rate  預設分潤比例（0-100）
	 * @param string $page_template 預設賣場頁面模板代號
	 */
	public function __construct(
		public readonly string $rewrite_slug,
		public readonly string $report_slug,
		public readonly int $default_rate,
		public readonly string $page_template
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
			rewrite_slug: (string) ( $data['rewrite_slug'] ?? 'shop' ),
			report_slug: (string) ( $data['report_slug'] ?? 'report' ),
			default_rate: (int) ( $data['default_rate'] ?? 0 ),
			page_template: (string) ( $data['page_template'] ?? 'default' ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   rewrite_slug: string,
	 *   report_slug: string,
	 *   default_rate: int,
	 *   page_template: string
	 * }
	 */
	public function to_array(): array {
		return [
			'rewrite_slug'  => $this->rewrite_slug,
			'report_slug'   => $this->report_slug,
			'default_rate'  => $this->default_rate,
			'page_template' => $this->page_template,
		];
	}
}
