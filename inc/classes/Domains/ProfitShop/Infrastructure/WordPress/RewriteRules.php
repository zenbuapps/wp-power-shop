<?php
/**
 * 分潤夥伴報表前台 Rewrite Rules
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

/**
 * 註冊 /profit-report/{partner-slug}/ rewrite rule + query var
 *
 * 同時負責偵測 CPT rewrite slug 與 report slug 的變更並觸發 flush rewrite rules。
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.8
 *
 * Phase 2 僅註冊 rewrite + query var；template_include 載入前台版型留待 Phase 6 Frontend 實作。
 */
final class RewriteRules {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Query var 名稱（解析後的 partner slug 會放在此 var）
	 */
	public const QUERY_VAR = 'profit_partner_report';

	/**
	 * 全站設定的 wp_options key
	 */
	public const OPTIONS_KEY = 'power_shop_profit_settings';

	/**
	 * 預設 report slug
	 */
	public const DEFAULT_REPORT_SLUG = 'profit-report';

	/**
	 * 上次套用的 report slug 暫存 option key（用於偵測 slug 變更後 flush）
	 */
	private const APPLIED_SLUG_OPTION = 'power_shop_profit_applied_report_slug';

	/**
	 * Constructor
	 *
	 * 掛在 init priority 12，需在 CptRegistrar/TaxonomyRegistrar（priority 11）之後執行。
	 */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register' ], 12 );
		\add_filter( 'query_vars', [ __CLASS__, 'add_query_var' ] );
	}

	/**
	 * 註冊 rewrite rule
	 *
	 * @return void
	 */
	public static function register(): void {
		$report_slug = self::get_report_slug();
		$pattern     = '^' . preg_quote( $report_slug, '#' ) . '/([^/]+)/?$';

		\add_rewrite_rule(
			$pattern,
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * 註冊 query var
	 *
	 * @param string[] $vars 既有 query vars
	 *
	 * @return string[] 加入本外掛 query var 後的清單
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * 從 wp_options 讀取 report slug，找不到或不合法時 fallback 到預設值
	 *
	 * 經過 \sanitize_title() 過濾，避免 wp_options 被注入非法字元（空白、html、控制字元等）
	 * 影響 rewrite system。sanitize 後若為空字串，亦 fallback 到預設。
	 *
	 * @return string report slug
	 */
	public static function get_report_slug(): string {
		$settings = \get_option( self::OPTIONS_KEY, [] );
		if ( ! is_array( $settings ) ) {
			return self::DEFAULT_REPORT_SLUG;
		}

		$slug = $settings['report_slug'] ?? self::DEFAULT_REPORT_SLUG;
		if ( ! is_string( $slug ) || $slug === '' ) {
			return self::DEFAULT_REPORT_SLUG;
		}

		$sanitized = \sanitize_title( $slug );
		return '' === $sanitized ? self::DEFAULT_REPORT_SLUG : $sanitized;
	}

	/**
	 * 偵測 report slug 或 CPT rewrite slug 是否異動，若有異動則 flush rewrite rules
	 *
	 * 設定頁更新後應呼叫此方法。為避免每次請求都 flush，僅在任一 slug 與上次套用不同時才執行。
	 *
	 * 同時檢查兩個 slug 的原因：rewrite rules 註冊牽涉到 CPT（permalink）與報表 rewrite，
	 * 任一變更都需要重新生成 rules。共用同一機制可避免兩端重複 flush。
	 *
	 * @return void
	 */
	public static function flush_rules_if_needed(): void {
		$current_report_slug = self::get_report_slug();
		$applied_report_slug = (string) \get_option( self::APPLIED_SLUG_OPTION, '' );

		$current_cpt_slug = CptRegistrar::get_rewrite_slug();
		$applied_cpt_slug = (string) \get_option( CptRegistrar::APPLIED_SLUG_OPTION, '' );

		$report_changed = $current_report_slug !== $applied_report_slug;
		$cpt_changed    = $current_cpt_slug !== $applied_cpt_slug;

		if ( ! $report_changed && ! $cpt_changed ) {
			return;
		}

		// 重新註冊 report rule，確保最新 slug 已加入 rewrite 系統。
		// CPT rewrite 由 CptRegistrar::register() 在 init priority 11 處理，此處只需 flush。
		self::register();
		\flush_rewrite_rules( false );

		if ( $report_changed ) {
			\update_option( self::APPLIED_SLUG_OPTION, $current_report_slug, false );
		}
		if ( $cpt_changed ) {
			\update_option( CptRegistrar::APPLIED_SLUG_OPTION, $current_cpt_slug, false );
		}
	}
}
