<?php
/**
 * RewriteRules 版本比對式 flush IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.8
 *
 * ⚠️ 本檔原本測的是「PUT /profit-settings 改 slug → 觸發 flush」。
 * `#/profit-settings` 設定頁已整組移除（兩個欄位都是「有消費點，但不該讓站長改」），
 * flush 機制隨之從「設定變更時觸發」改為「rewrite 規則版本變更時，於 init 自動觸發」。
 *
 * 這個改動同時修掉一個既有缺陷：全專案沒有 `register_activation_hook`，
 * 舊版唯一的 `flush_rewrite_rules()` 呼叫點藏在設定頁儲存流程裡——新站台安裝後
 * 若站長從未進設定頁按儲存，`/profit-shop/{slug}/` 與 `/profit-report/{slug}/`
 * 會直接 404 且無任何提示。
 *
 * @group profit_shop
 * @group application
 * @group settings
 */

declare( strict_types=1 );

namespace Tests\Integration\Application;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
use Tests\Integration\TestCase;

/**
 * Flush Rewrite IT
 */
final class FlushRewriteTest extends TestCase {

	/**
	 * 取得 RewriteRules 的 private 常數值（測試需要知道當前版本號與 option key）
	 *
	 * 刻意用 reflection 而非把常數改成 public：那兩個常數是實作細節，
	 * 對外公開只會讓別的程式碼開始依賴它們。
	 *
	 * @param string $name 常數名稱
	 *
	 * @return string
	 */
	private function constant( string $name ): string {
		$reflection = new \ReflectionClass( RewriteRules::class );
		return (string) $reflection->getConstant( $name );
	}

	/**
	 * 版本 option 不存在（= 全新安裝）→ flush 並寫入當前版本
	 *
	 * 這是本機制存在的主要理由：新站台不需要任何 UI 操作，
	 * 賣場前台與夥伴報表網址就能直接生效。
	 *
	 * @test
	 * @group happy
	 */
	public function test_flushes_and_records_version_on_fresh_install(): void {
		$option_key = $this->constant( 'APPLIED_VERSION_OPTION' );
		\delete_option( $option_key );

		RewriteRules::flush_rules_if_needed();

		$this->assertSame(
			$this->constant( 'REWRITE_VERSION' ),
			(string) \get_option( $option_key, '' ),
			'全新安裝應 flush 一次並記錄當前 rewrite 版本'
		);
	}

	/**
	 * 版本相同 → 不覆寫 option（代表走了 early return，沒有呼叫昂貴的 flush）
	 *
	 * @test
	 * @group edge
	 */
	public function test_does_not_reflush_when_version_matches(): void {
		$option_key = $this->constant( 'APPLIED_VERSION_OPTION' );
		\update_option( $option_key, $this->constant( 'REWRITE_VERSION' ), true );

		// rewrite_rules 清空；若 flush 真的被呼叫，它會被重新填回。
		\delete_option( 'rewrite_rules' );

		RewriteRules::flush_rules_if_needed();

		$this->assertFalse(
			\get_option( 'rewrite_rules' ),
			'版本相同時應 early return，不呼叫 flush_rewrite_rules()'
		);
	}

	/**
	 * 版本不同（= 開發者 bump 過 REWRITE_VERSION）→ 重新 flush 並更新 option
	 *
	 * @test
	 * @group happy
	 */
	public function test_reflushes_when_version_changed(): void {
		$option_key = $this->constant( 'APPLIED_VERSION_OPTION' );
		\update_option( $option_key, 'stale-version', true );

		RewriteRules::flush_rules_if_needed();

		$this->assertSame(
			$this->constant( 'REWRITE_VERSION' ),
			(string) \get_option( $option_key, '' ),
			'版本不同時應重新 flush 並記錄新版本'
		);
	}

	/**
	 * 回歸鎖：兩個 rewrite 前綴與 CPT slug 都是固定常數，不再讀 wp_options
	 *
	 * 舊版這三個值有兩個來自 `power_shop_profit_settings`。設定頁移除後，
	 * 就算 wp_options 內還有殘留設定，也必須完全不影響取值——否則
	 * `REWRITE_VERSION` 版本比對的前提（規則是常數）就不成立，
	 * 會出現「站台 A 和站台 B 同版本號卻有不同 rewrite 規則」的狀況。
	 *
	 * @test
	 * @group edge
	 */
	public function test_slugs_ignore_stale_settings_option(): void {
		\update_option(
			'power_shop_profit_settings',
			[
				'rewrite_slug' => 'legacy-custom-shop',
				'report_slug'  => 'legacy-custom-report',
			],
			false
		);

		$this->assertSame( 'powershop', CptRegistrar::get_rewrite_slug() );
		$this->assertSame( 'profit-report', RewriteRules::get_report_slug() );
		$this->assertSame( 'profit-report', RewriteRules::DEFAULT_REPORT_SLUG );
		$this->assertSame( 'profit-shop', RewriteRules::SHOP_REWRITE_PREFIX );
	}
}
