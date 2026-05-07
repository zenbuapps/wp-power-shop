<?php
/**
 * Partner Password Rotation 撤銷舊 token 端到端 IT（Phase 3-D Batch 2 / T-2）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3（password rotation token revocation）
 * 對應 reviewer 順手清單 #4（regenerate-password 撤銷舊 token）
 *
 * 紅燈合約（端到端）：
 *   1. partner 用密碼 A 登入 → 取得 token_A
 *   2. token_A 呼叫 GET /partner-auth/me → 200（roundtrip 正常）
 *   3. admin POST /profit-partners/{id}/regenerate-password → 200，新密碼 B
 *      （此時 _partner_password_changed_at termmeta 自動更新）
 *   4. 用 token_A 再呼叫 GET /partner-auth/me → 401（撤銷生效！）
 *   5. partner 用密碼 B 登入 → 取得 token_B
 *   6. token_B 呼叫 GET /partner-auth/me → 200（新 token 正常）
 *
 * 紅燈狀態：
 *   PartnerTokenStore 還沒注入 PartnerRepositoryInterface，verify 不會比對 password_changed_at。
 *   step 4 預期失敗（仍回 200）。
 *   或在綠燈中途，V2Api::make_partner_token_store / partner_token_permission 還沒補注入新依賴 → fatal。
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
 * Password rotation 撤銷舊 token IT
 */
final class PartnerPasswordRotationRevokesTokenTest extends TestCase {

	private int $admin_id = 0;
	private int $partner_term_id = 0;
	private const ORIGINAL_PASSWORD = 'password1';
	private const PARTNER_SLUG = 'jerry';

	public function set_up(): void {
		parent::set_up();

		// 建立 admin（用於呼叫 regenerate-password）
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// 建立 partner（含 plain_password → DB 內已寫 _partner_password_changed_at）
		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Jerry',
				slug: new PartnerSlug( self::PARTNER_SLUG ),
				contact_email: null,
			),
			self::ORIGINAL_PASSWORD,
		);
		$this->assertGreaterThan( 0, $this->partner_term_id );
	}

	public function tear_down(): void {
		// 清理建立的 partner term（避免污染後續測試）
		if ( $this->partner_term_id > 0 ) {
			\wp_delete_term( $this->partner_term_id, 'profit_partner' );
		}
		parent::tear_down();
	}

	/**
	 * 端到端：admin 重設密碼 → 舊 token 立即失效 → 新密碼可登入 → 新 token 正常
	 *
	 * @group security
	 * @group edge
	 */
	public function test_old_token_is_revoked_after_admin_regenerates_password(): void {
		// ---------- 步驟 1：partner 登入取得 token_A ----------
		\wp_set_current_user( 0 );
		$old_token = $this->login_partner( self::PARTNER_SLUG, self::ORIGINAL_PASSWORD );
		$this->assertNotEmpty( $old_token, 'partner 應能用原始密碼登入並取得 token' );

		// ---------- 步驟 2：用 token_A 呼叫 me 應 200 ----------
		$me_before = $this->call_me( $old_token );
		$this->assertSame(
			200,
			$me_before->get_status(),
			'token 簽發後立即用同 token 呼叫 me 應 200（roundtrip 正常）'
		);

		// ---------- 步驟 3：admin regenerate-password ----------
		\wp_set_current_user( $this->admin_id );
		$regen_request  = new \WP_REST_Request(
			'POST',
			'/power-shop/profit-partners/' . $this->partner_term_id . '/regenerate-password',
		);
		$regen_response = \rest_do_request( $regen_request );
		$this->assertSame( 200, $regen_response->get_status(), 'admin regenerate-password 應 200' );

		$new_password = $regen_response->get_data()['password'] ?? '';
		$this->assertNotEmpty( $new_password );
		$this->assertNotSame( self::ORIGINAL_PASSWORD, $new_password );

		// ---------- 步驟 4：用 token_A 再呼叫 me 應 401（撤銷生效） ----------
		\wp_set_current_user( 0 );
		$me_after = $this->call_me( $old_token );
		$this->assertSame(
			401,
			$me_after->get_status(),
			'admin 重設密碼後，舊 token 應立即失效（password_changed_at 機制）'
		);

		// ---------- 步驟 5：partner 用新密碼登入取得 token_B ----------
		$new_token = $this->login_partner( self::PARTNER_SLUG, $new_password );
		$this->assertNotEmpty( $new_token, 'partner 應能用新密碼登入並取得新 token' );
		$this->assertNotSame( $old_token, $new_token, '新 token 應與舊 token 不同' );

		// ---------- 步驟 6：用 token_B 呼叫 me 應 200 ----------
		$me_with_new = $this->call_me( $new_token );
		$this->assertSame(
			200,
			$me_with_new->get_status(),
			'新密碼簽發的新 token 應正常運作'
		);
	}

	// ========== helper ==========

	/**
	 * 登入 partner 並回傳 token（失敗回空字串）
	 */
	private function login_partner( string $slug, string $password ): string {
		$request = new \WP_REST_Request( 'POST', '/power-shop/partner-auth/login' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'slug'     => $slug,
					'password' => $password,
				],
			),
		);

		$response = \rest_do_request( $request );
		if ( 200 !== $response->get_status() ) {
			return '';
		}
		$data = $response->get_data();
		return is_array( $data ) ? (string) ( $data['token'] ?? '' ) : '';
	}

	/**
	 * 用指定 token 呼叫 me endpoint
	 */
	private function call_me( string $token ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/power-shop/partner-auth/me' );
		$request->set_header( 'X-Partner-Token', $token );
		$response = \rest_do_request( $request );

		// rest_do_request 可能回 WP_REST_Response 或 WP_Error；這裡確保回 Response
		if ( $response instanceof \WP_REST_Response ) {
			return $response;
		}
		// fallback：把 WP_Error 包成 401 response
		return new \WP_REST_Response( [ 'code' => 'unknown' ], 500 );
	}
}
