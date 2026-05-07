<?php
/**
 * Partner Brute-force 防護 IT（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8
 *
 * 紅燈驗證：
 *   1. 連續 5 次錯誤密碼 → 第 6 次回 429（含 Retry-After header）
 *   2. 視窗（15 分鐘）過期後重置 → 可再登入
 *
 * 視窗模擬手段：直接刪除 ps_partner_login_fail_* transient（綠燈時 master 應採用
 * 可注入 clock，使測試能跨時鐘推進；此 IT 採刪 transient 模擬視窗到期）。
 *
 * @group profit_shop
 * @group rest
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use Tests\Integration\TestCase;

/**
 * Partner login brute-force IT
 */
final class PartnerBruteForceTest extends TestCase {

	private int $partner_term_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( 'jerry' ),
				contact_email: null
			),
			'correct-pw'
		);
	}

	/**
	 * @group security
	 */
	public function test_five_failures_block_with_429(): void {
		// 失敗 5 次（每次都拋 401 unauthorized）
		for ( $i = 0; $i < 5; $i++ ) {
			$response = $this->login( 'jerry', 'wrong-pw' );
			$this->assertSame(
				401,
				$response->get_status(),
				"第 " . ( $i + 1 ) . " 次失敗應回 401（目前狀態：" . $response->get_status() . '）'
			);
		}

		// 第 6 次（即使密碼正確）→ 429
		$response = $this->login( 'jerry', 'correct-pw' );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limited', $response->get_data()['code'] ?? null );

		// Retry-After header 必須存在
		$headers       = $response->get_headers();
		$retry_after   = $headers['Retry-After'] ?? ( $headers['retry-after'] ?? null );
		$this->assertNotNull( $retry_after, '429 必須附 Retry-After header' );
		$this->assertGreaterThan( 0, (int) ( is_array( $retry_after ) ? $retry_after[0] : $retry_after ) );
	}

	/**
	 * @group security
	 */
	public function test_window_reset_unblocks_login(): void {
		// 拉滿失敗（鎖定）
		for ( $i = 0; $i < 5; $i++ ) {
			$this->login( 'jerry', 'wrong-pw' );
		}
		$this->assertSame( 429, $this->login( 'jerry', 'correct-pw' )->get_status() );

		// 模擬「等過視窗」：直接刪光 brute-force 計數 transient
		$this->expire_brute_force_window();

		// 視窗過期後，正確密碼應可登入
		$response = $this->login( 'jerry', 'correct-pw' );
		$this->assertSame( 200, $response->get_status(), '視窗過期後應可重登' );
	}

	// ========== helper ==========

	private function login( string $slug, string $password ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( \wp_json_encode( [ 'slug' => $slug, 'password' => $password ] ) );
		return \rest_do_request( $request );
	}

	/**
	 * 將 brute-force 計數 transient 全部清除（模擬視窗 expire）
	 */
	private function expire_brute_force_window(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_ps_partner_login_fail_%',
				'_transient_timeout_ps_partner_login_fail_%'
			)
		);
		\wp_cache_flush();
	}
}
