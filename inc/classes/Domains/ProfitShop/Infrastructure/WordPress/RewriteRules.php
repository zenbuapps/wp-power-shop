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
	 * Query var 名稱（前台賣場 slug；Phase 4-C1）
	 */
	public const SHOP_QUERY_VAR = 'profit_shop_slug';

	/**
	 * 前台賣場前綴（固定）
	 */
	public const SHOP_REWRITE_PREFIX = 'profit-shop';

	/**
	 * Partner 報表前綴（固定）
	 *
	 * ⚠️ 這是**固定常數**，站長無法修改 —— 與 `SHOP_REWRITE_PREFIX` 同性質。
	 * 曾經開放為設定項（wp_options `power_shop_profit_settings.report_slug`），後來移除：
	 * 改動它會讓所有已經發給 partner 的報表網址一次失效，而站長換前綴得不到任何實質好處。
	 * wp_options 內若有舊的殘留設定，一律忽略（不需 migration）。
	 */
	public const DEFAULT_REPORT_SLUG = 'profit-report';

	/**
	 * Rewrite 規則定義的版本
	 *
	 * ⚠️ **改動本類任何 rewrite rule（新增 / 移除 / 改前綴常數）時必須 bump 這個版本號**，
	 * 否則既有站台不會重新 flush，新規則會安靜地失效（網址 404、無錯誤訊息）。
	 */
	private const REWRITE_VERSION = '1';

	/**
	 * 上次已套用的 rewrite 版本 option key
	 *
	 * 刻意 autoload = true：`flush_rules_if_needed()` 每個請求都要讀它，
	 * 走 alloptions cache 才不會每次多一次 DB query。
	 */
	private const APPLIED_VERSION_OPTION = 'power_shop_profit_applied_rewrite_version';

	/**
	 * Constructor
	 *
	 * 掛在 init priority 12，需在 CptRegistrar/TaxonomyRegistrar（priority 11）之後執行；
	 * flush 再晚一階（13），確保 register() 已把規則加進 rewrite 系統才 flush。
	 */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register' ], 12 );
		\add_action( 'init', [ __CLASS__, 'flush_rules_if_needed' ], 13 );
		\add_filter( 'query_vars', [ __CLASS__, 'add_query_var' ] );
	}

	/**
	 * 註冊 rewrite rule
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_rewrite_rule(
			'^' . self::DEFAULT_REPORT_SLUG . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		// Phase 4-C1：前台賣場 /profit-shop/{slug}/
		\add_rewrite_rule(
			'^' . self::SHOP_REWRITE_PREFIX . '/([^/]+)/?$',
			'index.php?' . self::SHOP_QUERY_VAR . '=$matches[1]',
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
		$vars[] = self::SHOP_QUERY_VAR;
		return $vars;
	}

	/**
	 * 取得 report slug（固定常數）
	 *
	 * 保留此 method 而非讓 caller 直接讀常數，是為了維持「report 前綴的單一取值入口」——
	 * `PartnerTermRepository::resolve_report_url()` 等 caller 不需要知道它是設定值還是常數。
	 *
	 * @return string report slug
	 */
	public static function get_report_slug(): string {
		return self::DEFAULT_REPORT_SLUG;
	}

	/**
	 * 規則版本與上次套用的不同時，flush rewrite rules
	 *
	 * 掛在 `init` priority 13 自動執行，**不需要任何 UI 觸發**。
	 *
	 * ⚠️ 這個機制取代了舊版「站長在設定頁改 slug 才 flush」的做法。舊做法有個隱性缺陷：
	 * 全專案沒有 `register_activation_hook`，唯一的 `flush_rewrite_rules()` 呼叫點就藏在
	 * 設定頁的儲存流程裡——新站台安裝後若站長從未進設定頁按儲存，`/profit-shop/{slug}/`
	 * 與 `/profit-report/{slug}/` 會直接 404，且沒有任何提示說明原因。
	 *
	 * 現在 rewrite 規則全部是常數（`SHOP_REWRITE_PREFIX` / `DEFAULT_REPORT_SLUG` /
	 * `CptRegistrar::DEFAULT_REWRITE_SLUG`），不再有「使用者改設定」這個變因，
	 * 唯一需要重新 flush 的時機就是「開發者改了規則定義」——改的時候 bump
	 * `REWRITE_VERSION` 即可，站台下次載入自動生效。
	 *
	 * 成本：每個請求多一次 `get_option()`，但該 option 是 autoload，走 alloptions
	 * cache、零額外 DB query；版本相同時直接 return，不會呼叫昂貴的 flush。
	 *
	 * @return void
	 */
	public static function flush_rules_if_needed(): void {
		$applied_version = (string) \get_option( self::APPLIED_VERSION_OPTION, '' );

		if ( self::REWRITE_VERSION === $applied_version ) {
			return;
		}

		\flush_rewrite_rules( false );

		\update_option( self::APPLIED_VERSION_OPTION, self::REWRITE_VERSION, true );
	}
}
