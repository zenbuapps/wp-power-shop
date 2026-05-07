<?php
/**
 * UpdateSettings 改 slug 後 RewriteRules::flush_rules_if_needed 被呼叫 IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 *
 * @group profit_shop
 * @group rest
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

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/**
	 * PUT /profit-settings 改 report slug → applied_slug option 被更新
	 * （flush_rules_if_needed 內部會在 slug 改時更新這個 option）
	 *
	 * @test
	 * @group happy
	 */
	public function test_update_settings_with_new_slug_triggers_flush(): void {
		// 紀錄舊 applied slug
		\update_option( 'power_shop_profit_applied_report_slug', 'profit-report', false );

		// 注意：rewrite_slug 不能用 'shop'（會被 SlugConflictDetector 擋下回 409，
		// 因為 'shop' 是 WooCommerce 商店頁的保留 slug）
		$request = new \WP_REST_Request( 'PUT', '/power-shop/profit-settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'rewrite_slug'  => 'profit-test-shop',
					'report_slug'   => 'kpi-report',
					'default_rate'  => 0,
					'page_template' => 'default',
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		// 驗證 RewriteRules 內部用來追蹤 applied slug 的 option 已被更新
		$applied = (string) \get_option( 'power_shop_profit_applied_report_slug', '' );
		$this->assertSame(
			'kpi-report',
			$applied,
			'spec §4.8：UpdateSettings 改 report slug 後應觸發 flush_rules_if_needed，使 applied slug option 同步'
		);
	}

	/**
	 * PUT /profit-settings 把 slug 改回原本值 → 不需 flush（applied slug 不變）
	 *
	 * @test
	 * @group edge
	 */
	public function test_update_settings_with_same_slug_does_not_overwrite_applied(): void {
		\update_option( 'power_shop_profit_applied_report_slug', 'profit-report-same', false );
		\update_option( CptRegistrar::APPLIED_SLUG_OPTION, 'profit-shop-same', false );

		// 用無衝突的 slug（避免 SlugConflictDetector 攔下回 409）
		$request = new \WP_REST_Request( 'PUT', '/power-shop/profit-settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'rewrite_slug'  => 'profit-shop-same',
					'report_slug'   => 'profit-report-same',
					'default_rate'  => 0,
					'page_template' => 'default',
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		// 確認 applied slug 仍維持原樣（沒有被 noop flush 路徑誤改）
		$this->assertSame( 'profit-report-same', (string) \get_option( 'power_shop_profit_applied_report_slug', '' ) );
	}
}
