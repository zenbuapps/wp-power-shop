<?php
/**
 * /profit-report/{partner-slug}/ Rewrite Rule 整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.8
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/WordPress/RewriteRules.php
 *
 * @group profit_shop
 * @group infrastructure
 * @group rewrite
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
use Tests\Integration\TestCase;

/**
 * 驗證 profit-report 前台網址 rewrite 規則 + query var 是否正確註冊
 */
final class RewriteRulesTest extends TestCase {

	/**
	 * 舊版全域設定的 wp_options key
	 *
	 * ⚠️ 刻意寫成字面值而非引用常數——`RewriteRules::OPTIONS_KEY` 已隨全域設定頁移除，
	 * production 端**不該**再有這個常數（有的話代表 rewrite 前綴又變回可設定值了，
	 * 那會讓 `REWRITE_VERSION` 版本比對式 flush 的前提失效）。
	 */
	private const LEGACY_SETTINGS_OPTION = 'power_shop_profit_settings';

	/**
	 * 每個測試前重新觸發 rewrite rule 註冊並 flush，確保 rules 已載入
	 *
	 * 🔴 `set_permalink_structure()` 不可省略。`permalink_structure` 為空（plain
	 * permalinks，`WP_UnitTestCase` 的預設狀態）時，WordPress 的
	 * `WP_Rewrite::wp_rewrite_rules()` **根本不產生任何 rewrite rules**，
	 * `get_option('rewrite_rules')` 會是空字串——與本外掛有沒有註冊規則完全無關。
	 *
	 * ⚠️ `.wp-env.json` 的 `afterStart` 雖然有 `wp rewrite structure /%postname%/`，
	 * 但那一段只對 **cli**（開發站台）容器執行，**tests-cli 沒有**；且 `WP_UnitTestCase`
	 * 每個測試都在 transaction 內跑並 rollback，不能依賴容器層的一次性設定。
	 * 前置條件必須由測試自己建立。
	 *
	 * 本方法只補齊前置條件，兩條測試的斷言內容一字未改。
	 */
	public function set_up(): void {
		parent::set_up();

		// 清掉可能殘留的舊設定（現在不影響取值，但保持測試環境乾淨）
		\delete_option( self::LEGACY_SETTINGS_OPTION );

		// rewrite rules 只在非 plain permalink 結構下才會生成
		$this->set_permalink_structure( '/%postname%/' );

		RewriteRules::register();
		\flush_rewrite_rules( false );
	}

	/**
	 * 測試後清除殘留 settings option
	 */
	public function tear_down(): void {
		\delete_option( self::LEGACY_SETTINGS_OPTION );
		parent::tear_down();
	}

	// ========== Happy ==========

	/**
	 * /profit-report/{slug}/ rewrite rule 應出現於 rewrite_rules option
	 *
	 * @test
	 * @group happy
	 */
	public function test_profit_report_rewrite_rule_exists(): void {
		$rules = \get_option( 'rewrite_rules' );

		$this->assertIsArray( $rules, 'rewrite_rules option 應為陣列（已 flush 過）' );

		$matched_pattern = null;
		foreach ( (array) $rules as $pattern => $target ) {
			if ( str_starts_with( (string) $pattern, '^' . RewriteRules::DEFAULT_REPORT_SLUG . '/' ) ) {
				$matched_pattern = $pattern;
				$this->assertStringContainsString(
					RewriteRules::QUERY_VAR,
					(string) $target,
					'profit-report rewrite target 應包含 profit_partner_report query var'
				);
				break;
			}
		}

		$this->assertNotNull(
			$matched_pattern,
			'rewrite_rules 未包含 ^profit-report/.../?$ 樣式的規則'
		);
	}

	/**
	 * profit_partner_report query var 必須註冊到 query_vars filter
	 *
	 * 🔴 斷言對象刻意是 `apply_filters('query_vars', ...)` 的輸出，**不是**
	 * `$wp->public_query_vars` 這個屬性。兩者不等價：
	 *
	 * `WP::parse_request()` 內才有
	 * `$this->public_query_vars = apply_filters( 'query_vars', $this->public_query_vars )`，
	 * 也就是說**在 `parse_request()` 被呼叫之前**，`$wp->public_query_vars` 只是
	 * `WP::__construct()` 給的核心預設清單，任何外掛透過 filter 加入的 query var
	 * 都還沒進去。整合測試不會走完整的請求生命週期，讀那個屬性等於在檢查一個
	 * 時序上還沒發生的狀態——測的是 WordPress 的內部時序，不是本外掛的行為。
	 *
	 * 改為直接對 filter 取值，測到的正是本測試真正要驗證的那件事：
	 * 「`RewriteRules` 有沒有把 query var 註冊進 `query_vars` filter」。
	 * 這是**收斂**斷言對象、不是放寬——filter 沒掛或 var 名寫錯，一樣立刻紅。
	 *
	 * @test
	 * @group happy
	 */
	public function test_profit_report_query_var_is_registered(): void {
		// filter 掛在 RewriteRules::__construct()，測試環境需確保單例已建立
		RewriteRules::instance();

		/** @var string[] $vars */
		$vars = \apply_filters( 'query_vars', [] );

		$this->assertContains(
			RewriteRules::QUERY_VAR,
			$vars,
			'profit_partner_report query var 應由 RewriteRules 註冊到 query_vars filter'
		);
		$this->assertContains(
			RewriteRules::SHOP_QUERY_VAR,
			$vars,
			'profit_shop_slug query var 應由 RewriteRules 註冊到 query_vars filter'
		);
	}
}
