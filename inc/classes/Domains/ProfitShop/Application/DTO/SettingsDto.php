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
 *
 * ⚠️ 只放**真的有消費點**的設定。曾經存在的 `default_rate`（預設分潤比例）與
 * `page_template`（頁面模板）已移除 —— 兩者從 Phase 3-B 起就沒有任何一處讀取
 * （新賣場不吃 default_rate、賣場前台固定 require `templates/profit-shop-front.php`），
 * 留著只會讓後續維護者誤以為調整它們會改變行為。新增欄位前請先確定「誰會讀它」。
 */
final class SettingsDto {

	/**
	 * Slug 白名單正則：必須以英數字開頭，後續 1~29 個英數字或連字號
	 */
	private const SLUG_REGEX = '/^[a-z0-9][a-z0-9\-]{0,29}$/';

	/**
	 * 建構子
	 *
	 * @param string $rewrite_slug powershop CPT 的 rewrite slug（例如 'powershop'）。
	 *                             ⚠️ 這條**不是**賣場前台網址 —— 前台走
	 *                             `RewriteRules::SHOP_REWRITE_PREFIX`（固定 'profit-shop'）。
	 * @param string $report_slug  Partner 報表頁面的 rewrite slug（例如 'profit-report'）
	 */
	public function __construct(
		public readonly string $rewrite_slug,
		public readonly string $report_slug
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
	 * 預設值刻意與 `CptRegistrar::DEFAULT_REWRITE_SLUG` / `RewriteRules::DEFAULT_REPORT_SLUG`
	 * 保持一致 —— 兩邊若不同步，reset 後設定頁顯示的值會與實際生效的 rewrite rule 不符，
	 * 站長照畫面去開網址會吃 404。
	 *
	 * 未知的 key（例如舊版殘留在 wp_options 的 default_rate / page_template）一律忽略，
	 * 下次 save 時會被 to_array() 的輸出自然覆蓋掉，不需要 migration。
	 *
	 * @param array<string, mixed> $data 原始輸入陣列
	 *
	 * @throws \DomainException 當 rewrite_slug / report_slug 經 sanitize 後仍不符合格式時拋出
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$rewrite_slug_raw = (string) ( $data['rewrite_slug'] ?? 'powershop' );
		$report_slug_raw  = (string) ( $data['report_slug'] ?? 'profit-report' );

		return new self(
			rewrite_slug: self::normalize_slug( $rewrite_slug_raw, 'rewrite_slug' ),
			report_slug: self::normalize_slug( $report_slug_raw, 'report_slug' ),
		);
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   rewrite_slug: string,
	 *   report_slug: string
	 * }
	 */
	public function to_array(): array {
		return [
			'rewrite_slug' => $this->rewrite_slug,
			'report_slug'  => $this->report_slug,
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
