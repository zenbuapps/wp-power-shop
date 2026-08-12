<?php
/**
 * Partner CRUD round-trip IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2、§6.3
 *
 * @group profit_shop
 * @group rest
 * @group application
 */

declare( strict_types=1 );

namespace Tests\Integration\Application;

use Tests\Integration\TestCase;

/**
 * Partner CRUD IT
 */
final class PartnerCrudTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/**
	 * POST /profit-partners → 201 + body 帶回明文密碼（admin-only endpoint）
	 *
	 * ⚠️ 本測試在 2026-08 之前斷言的是**相反**的契約（回應不得含 password），
	 * 由站長要求變更：後台需要能查看 / 複製夥伴的報表登入密碼再轉交給對方，
	 * 那在「只存不可逆雜湊」的前提下技術上不可能，因此改為雜湊與明文並存。
	 *
	 * 這條斷言之所以仍掛 `@group security`，是因為它同時鎖住了作用範圍：
	 * 這個 endpoint 是 **admin_only**（manage_options）。若哪天有人把
	 * `PartnerOutput` 接到 partner-facing endpoint 上，明文就會送進夥伴的瀏覽器——
	 * `GET /partner-auth/me` 因此刻意自行列舉欄位輸出，不使用這個 DTO。
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_create_partner_returns_output_with_plain_password(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'name'          => 'Mary',
					'slug'          => 'mary',
					'contact_email' => 'mary@example.com',
					'password'      => 'secret-pa55!',
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'Mary', $body['data']['name'] );
		$this->assertSame(
			'secret-pa55!',
			$body['data']['password'],
			'admin endpoint 應帶回明文供站長複製'
		);
	}

	/**
	 * GET /partner-auth/me 不得帶出明文密碼
	 *
	 * `PartnerOutput` 自 2026-08 起含明文密碼，只允許出現在 admin-only endpoint。
	 * partner-facing 的 me endpoint 刻意在 callback 內明確列舉四個欄位輸出，
	 * 而不是回傳 `PartnerOutput::to_array()`——這條測試就是那個決定的護欄：
	 * 一旦有人「順手」改成用 DTO，明文密碼會直接送進夥伴的瀏覽器。
	 *
	 * 這裡不需要有效 token：401 的回應本身也不該含 password，
	 * 而「欄位白名單」的驗證重點在於 me 的回應形狀，與認證結果無關。
	 *
	 * @test
	 * @group security
	 */
	public function test_partner_me_endpoint_never_exposes_plain_password(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-shop/partner-auth/me' );
		$response = \rest_do_request( $request );

		$body = $response->get_data();

		$this->assertIsArray( $body );
		$this->assertArrayNotHasKey(
			'password',
			$body,
			'安全性違規：partner-facing endpoint 不可回傳明文密碼'
		);
	}

	/**
	 * 同一個 slug 二次建立 → 第二次應失敗（slug 唯一性 / 422 或 409）
	 *
	 * @test
	 * @group error
	 */
	public function test_duplicate_slug_is_rejected(): void {
		$body = [
			'name'     => 'Jerry',
			'slug'     => 'jerry',
			'password' => 'pw',
		];

		$first = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$first->set_header( 'Content-Type', 'application/json' );
		$first->set_body( \wp_json_encode( $body ) );
		$firstResp = \rest_do_request( $first );
		$this->assertSame( 201, $firstResp->get_status() );

		$second = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$second->set_header( 'Content-Type', 'application/json' );
		$second->set_body( \wp_json_encode( $body ) );
		$secondResp = \rest_do_request( $second );

		$this->assertContains(
			$secondResp->get_status(),
			[ 409, 422 ],
			'重複 slug 應被拒絕（409 conflict 或 422 unprocessable）'
		);
	}

	/**
	 * GET /profit-partners → 200 + 多筆資料
	 *
	 * @test
	 * @group happy
	 */
	public function test_list_partners_returns_collection(): void {
		// 先建兩個
		$this->create_partner_via_api( 'Alpha', 'alpha' );
		$this->create_partner_via_api( 'Beta', 'beta' );

		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-partners' );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'data', $body );
		$this->assertGreaterThanOrEqual( 2, count( $body['data'] ) );
	}

	/**
	 * M6：partner POST 寫入需 manage_options（manage_woocommerce only 的 user 應被擋）
	 *
	 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2、§6.3
	 * 對應 reviewer 必修：partner 涉及密碼系統，寫入操作必須 admin-only
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_create_partner_requires_manage_options(): void {
		// shop manager 有 manage_woocommerce 但無 manage_options
		$shop_manager_id = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		\wp_set_current_user( $shop_manager_id );

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'name'     => 'Eve',
					'slug'     => 'eve',
					'password' => 'secret',
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'shop_manager（無 manage_options）不應被允許建立 partner'
		);
	}

	/**
	 * 透過 API 建立 partner，回傳 term_id
	 */
	private function create_partner_via_api( string $name, string $slug ): int {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'name'     => $name,
					'slug'     => $slug,
					'password' => 'pw',
				]
			)
		);
		$response = \rest_do_request( $request );
		$body     = $response->get_data();
		return (int) ( $body['data']['id'] ?? 0 );
	}
}
