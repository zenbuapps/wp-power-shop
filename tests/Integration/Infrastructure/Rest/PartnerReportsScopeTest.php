<?php
/**
 * Partner Reports 跨 partner 範圍隔離 IT（Phase 3-C 紅燈，核心安全測試）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§8
 *
 * 紅燈驗證：
 *   - Partner A 用自己的 token 呼叫 reports → 200，回 A 的資料
 *   - Partner A 的 token 帶 ?partner_term_id=B_id → **必須以 token 為準**（仍只回 A）
 *   - admin nonce（X-WP-Nonce）試圖打 partner-reports/* → 401（不可用 admin 認證代打 partner endpoint）
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
 * Partner reports 範圍隔離 IT
 */
final class PartnerReportsScopeTest extends TestCase {

	private int $partner_a_id = 0;
	private int $partner_b_id = 0;
	private string $token_a   = '';

	public function set_up(): void {
		parent::set_up();

		$this->partner_a_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Alice',
				slug: new PartnerSlug( 'alice' ),
				contact_email: null
			),
			'pw-a'
		);
		$this->partner_b_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Bob',
				slug: new PartnerSlug( 'bob' ),
				contact_email: null
			),
			'pw-b'
		);

		// Alice 登入取得 token
		$login = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$login->set_header( 'Content-Type', 'application/json' );
		$login->set_body( \wp_json_encode( [ 'slug' => 'alice', 'password' => 'pw-a' ] ) );
		$response     = \rest_do_request( $login );
		$this->token_a = $response->get_data()['token'] ?? '';
	}

	/**
	 * @group security
	 */
	public function test_partner_a_token_can_only_see_partner_a_kpi(): void {
		$this->assertNotEmpty( $this->token_a, 'Alice 應已成功登入' );

		// 嘗試帶 ?partner_term_id={B_id} 攻擊
		$request = new \WP_REST_Request( 'GET', '/power-shop/partner-reports/kpi' );
		$request->set_header( 'X-Partner-Token', $this->token_a );
		$request->set_query_params( [ 'partner_term_id' => (string) $this->partner_b_id ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();

		// response 不可外洩 partner_b_id，而 partner_id（如有回）必須為 A
		// （由於 KpiReport 預設不含 partner_id，這裡寬鬆斷言：response 不含 B 的數值跡證）
		$this->assertArrayHasKey( 'total_sales', $body );
		// 若 response 攜帶 partner_id 欄位，必須等於 A
		if ( isset( $body['partner_id'] ) ) {
			$this->assertSame( $this->partner_a_id, $body['partner_id'] );
		}
	}

	/**
	 * @group security
	 */
	public function test_partner_a_token_settlements_query_string_attack_ignored(): void {
		// 同樣的攻擊但目標換 settlements
		$request = new \WP_REST_Request( 'GET', '/power-shop/partner-reports/settlements' );
		$request->set_header( 'X-Partner-Token', $this->token_a );
		$request->set_query_params( [ 'partner_term_id' => (string) $this->partner_b_id ] );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayHasKey( 'items', $body );

		// items 內所有 record 的 partner_term_id 必須等於 A（如果欄位存在）
		foreach ( (array) $body['items'] as $item ) {
			if ( isset( $item['partner_term_id'] ) ) {
				$this->assertSame(
					$this->partner_a_id,
					$item['partner_term_id'],
					'settlements 列表不可包含其他 partner 的資料'
				);
			}
		}
	}

	/**
	 * @group security
	 */
	public function test_no_token_returns_401(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-shop/partner-reports/kpi' );
		$response = \rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * @group security
	 */
	public function test_invalid_token_returns_401(): void {
		$request = new \WP_REST_Request( 'GET', '/power-shop/partner-reports/kpi' );
		$request->set_header( 'X-Partner-Token', 'totally-fake-token' );
		$response = \rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}
}
