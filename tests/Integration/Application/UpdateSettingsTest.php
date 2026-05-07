<?php
/**
 * UpdateSettings IT — slug conflict 防綁架
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5、§6.11
 * 對應 reviewer 必修：H1 + H2（admin 不可把 rewrite_slug 設為 wp-admin / shop / cart 等既有頁面 slug）
 *
 * @group profit_shop
 * @group rest
 * @group settings
 */

declare( strict_types=1 );

namespace Tests\Integration\Application;

use Tests\Integration\TestCase;

/**
 * UpdateSettings 行為合約 IT
 */
final class UpdateSettingsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/**
	 * H1：rewrite_slug 設為 WP 保留字 'feed' → 409 slug_conflict（被擋下）
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_throws_slug_conflict_when_rewrite_slug_is_wp_reserved(): void {
		$response = $this->put_settings(
			[
				'rewrite_slug' => 'feed',
				'report_slug'  => 'profit-report',
				'default_rate' => 10,
			]
		);

		$this->assertSame( 409, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'slug_conflict', $body['code'] ?? null );
	}

	/**
	 * H1：rewrite_slug 設為 'shop'（WC 商店頁）→ 409 slug_conflict
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_throws_slug_conflict_when_rewrite_slug_is_wc_shop(): void {
		$response = $this->put_settings(
			[
				'rewrite_slug' => 'shop',
				'report_slug'  => 'profit-report',
				'default_rate' => 10,
			]
		);

		$this->assertSame( 409, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'slug_conflict', $body['code'] ?? null );
	}

	/**
	 * H2：rewrite_slug 含非法字元 → 400 validation_failed（白名單正則擋下）
	 *
	 * @test
	 * @group error
	 */
	public function test_rejects_invalid_slug_format(): void {
		$response = $this->put_settings(
			[
				'rewrite_slug' => '$$$@@@',
				'report_slug'  => 'profit-report',
				'default_rate' => 10,
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'validation_failed', $body['code'] ?? null );
	}

	/**
	 * happy：合法 slug → 200 + 設定持久化
	 *
	 * @test
	 * @group happy
	 */
	public function test_persists_when_slug_is_unique(): void {
		$response = $this->put_settings(
			[
				'rewrite_slug' => 'profit-shop-test',
				'report_slug'  => 'profit-report-test',
				'default_rate' => 25,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'profit-shop-test', $body['data']['rewrite_slug'] );
		$this->assertSame( 25, $body['data']['default_rate'] );
	}

	/**
	 * M7：default_rate 超出 0-100 → 自動 clamp（admin friendly normalize）
	 *
	 * @test
	 * @group edge
	 */
	public function test_clamps_default_rate_when_out_of_range(): void {
		$response = $this->put_settings(
			[
				'rewrite_slug' => 'profit-shop-clamp',
				'report_slug'  => 'profit-report-clamp',
				'default_rate' => 999,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 100, $body['data']['default_rate'], 'default_rate 應被 clamp 至 100' );
	}

	/**
	 * 透過 PUT /profit-settings 套用 partial body
	 *
	 * @param array<string, mixed> $payload 設定欄位
	 */
	private function put_settings( array $payload ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'PUT', '/power-shop/profit-settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( \wp_json_encode( $payload ) );
		return \rest_do_request( $request );
	}
}
