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
	 */
	public function set_up(): void {
		parent::set_up();

		// 清掉可能殘留的舊設定（現在不影響取值，但保持測試環境乾淨）
		\delete_option( self::LEGACY_SETTINGS_OPTION );

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
	 * @test
	 * @group happy
	 */
	public function test_profit_report_query_var_is_registered(): void {
		global $wp;

		$this->assertContains(
			RewriteRules::QUERY_VAR,
			$wp->public_query_vars,
			'profit_partner_report query var 應被註冊到 $wp->public_query_vars'
		);
	}
}
