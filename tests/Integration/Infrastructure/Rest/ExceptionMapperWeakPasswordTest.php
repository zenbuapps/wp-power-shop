<?php
/**
 * ExceptionMapper WeakPassword 對映 IT（Phase 6-A1 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §7
 *
 * 紅燈合約：
 *   ExceptionMapper::map( WeakPassword $e ) →
 *     - status: 422
 *     - body.code: 'weak_password'
 *     - body.data.reasons: string[]（從 WeakPassword::getReasons() 取得，原樣攜帶）
 *
 *   原因：partner self-service 修密碼若新密碼弱，前端必須能精準告知使用者「哪些規則沒過」，
 *         才能引導使用者修正。和 SlugConflictException 攜帶 conflicts payload 同模式。
 *
 * 此 IT 走 wp tests bootstrap 是因為 ExceptionMapper::should_mask 用 apply_filters，
 * 在純 Unit 中無 WordPress filter 系統。
 *
 * @group profit_shop
 * @group rest
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\WeakPassword;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest\ExceptionMapper;
use Tests\Integration\TestCase;

/**
 * ExceptionMapper WeakPassword 對映合約測試
 */
final class ExceptionMapperWeakPasswordTest extends TestCase {

	/**
	 * WeakPassword → 422 + code='weak_password' + body.data.reasons 攜帶原 array
	 *
	 * @group security
	 */
	public function test_weak_password_maps_to_422_with_reasons(): void {
		$reasons = [ 'too_short', 'missing_letter' ];
		$ex      = new WeakPassword( $reasons );

		$response = ExceptionMapper::map( $ex );

		$this->assertSame( 422, $response->get_status(), 'WeakPassword 應對映 422' );

		$body = $response->get_data();
		$this->assertIsArray( $body );
		$this->assertSame( 'weak_password', $body['code'] ?? null );

		// reasons 可能在 body['data']['reasons']（與 SlugConflictException 的 conflicts 同模式）
		// 或 body['reasons']（扁平），兩種都接受但需要存在
		$payload_reasons = $body['data']['reasons'] ?? ( $body['reasons'] ?? null );
		$this->assertIsArray( $payload_reasons, 'body 必須含 reasons array' );
		$this->assertSame(
			$reasons,
			$payload_reasons,
			'reasons 必須原樣攜帶（不可改寫 / 翻譯 / reorder），前端負責 i18n'
		);
	}
}
