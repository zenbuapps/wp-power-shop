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
	 * Slug 白名單正則：必須以英數字開頭，後續 1~29 個英數字或連字號
	 */
	private const SLUG_REGEX = '/^[a-z0-9][a-z0-9\-]{0,29}$/';

	/**
	 * Default_rate 下限
	 */
	private const RATE_MIN = 0;

	/**
	 * Default_rate 上限
	 */
	private const RATE_MAX = 100;

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
	 * 對 rewrite_slug 與 report_slug 套：
	 *  - sanitize_title() 正規化（小寫、連字號、英數字）
	 *  - 長度 1~30 字元
	 *  - 白名單正則 ^[a-z0-9][a-z0-9\-]{0,29}$
	 *  - 不符合 → 拋 \DomainException( 'invalid_slug_format' )（會被 ExceptionMapper 對映為 400 validation_failed）
	 *
	 * default_rate 若超出 0~100 範圍會被 clamp（容錯處理，不擋使用者）。
	 *
	 * @param array<string, mixed> $data 原始輸入陣列
	 *
	 * @throws \DomainException 當 rewrite_slug / report_slug 經 sanitize 後仍不符合格式時拋出
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$rewrite_slug_raw = (string) ( $data['rewrite_slug'] ?? 'shop' );
		$report_slug_raw  = (string) ( $data['report_slug'] ?? 'report' );

		$rewrite_slug = self::normalize_slug( $rewrite_slug_raw, 'rewrite_slug' );
		$report_slug  = self::normalize_slug( $report_slug_raw, 'report_slug' );

		$default_rate_raw = (int) ( $data['default_rate'] ?? 10 );
		$default_rate     = max( self::RATE_MIN, min( self::RATE_MAX, $default_rate_raw ) );

		return new self(
			rewrite_slug: $rewrite_slug,
			report_slug: $report_slug,
			default_rate: $default_rate,
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

	/**
	 * 正規化並驗證 slug
	 *
	 * @param string $raw       原始輸入字串
	 * @param string $field_key 欄位 key（用於錯誤訊息）
	 *
	 * @throws \DomainException 當不符合白名單格式時拋出
	 *
	 * @return string 已正規化的 slug
	 */
	private static function normalize_slug( string $raw, string $field_key ): string {
		// 若 WordPress 函式可用則用 sanitize_title 做標準正規化；單元測試（純 PHP）走 fallback。
		if ( function_exists( '\sanitize_title' ) ) {
			$normalized = (string) \sanitize_title( $raw );
		} else {
			$normalized = self::fallback_sanitize( $raw );
		}

		if ( '' === $normalized || strlen( $normalized ) > 30 ) {
			throw new \DomainException( "invalid_slug_format:{$field_key}" );
		}

		if ( 1 !== preg_match( self::SLUG_REGEX, $normalized ) ) {
			throw new \DomainException( "invalid_slug_format:{$field_key}" );
		}

		return $normalized;
	}

	/**
	 * 純 PHP 環境 fallback（不依賴 WP）
	 *
	 * 邏輯近似 sanitize_title_with_dashes：小寫、空白與底線轉連字號、過濾非英數連字號。
	 *
	 * @param string $raw 原始字串
	 *
	 * @return string
	 */
	private static function fallback_sanitize( string $raw ): string {
		$str = strtolower( trim( $raw ) );
		$str = preg_replace( '/[\s_]+/', '-', $str ) ?? '';
		$str = preg_replace( '/[^a-z0-9\-]/', '', $str ) ?? '';
		$str = preg_replace( '/-+/', '-', $str ) ?? '';
		return trim( $str, '-' );
	}
}
