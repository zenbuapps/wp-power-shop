<?php
/**
 * ExceptionMapper 深度對映 IT（Phase 3-B observation 3 補強 + Phase 3-C）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §7.1、§7.2、§7.3
 *
 * 補完 ExceptionMapperTest 缺漏的對映：
 *   - InvalidStatusTransition → 422 invalid_state_transition
 *   - TooManyAttempts        → 429 rate_limited（含 retry_after + Retry-After header）
 *   - Forbidden              → 403 forbidden
 *   - PersistenceFailure     → 500 internal_error（生產 mode 不洩 message）
 *
 * 此測試直接呼叫 ExceptionMapper::map()，不走 V2Api callback——
 * 確保 mapper 本身行為正確，與 endpoint 層 decoupled。
 *
 * @group profit_shop
 * @group rest
 * @group exception_mapper
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\Forbidden;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidStatusTransition;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PersistenceFailure;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest\ExceptionMapper;
use Tests\Integration\TestCase;

/**
 * ExceptionMapper 對映補強
 */
final class ExceptionMapperDepthTest extends TestCase {

	/**
	 * @group error
	 */
	public function test_invalid_status_transition_maps_to_422(): void {
		$response = ExceptionMapper::map( new InvalidStatusTransition( '無法從 paid 轉換到 cancelled' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'invalid_state_transition', $response->get_data()['code'] ?? null );
	}

	/**
	 * @group error
	 * @group security
	 */
	public function test_too_many_attempts_maps_to_429_with_retry_after(): void {
		$response = ExceptionMapper::map( new TooManyAttempts( 120 ) );

		$this->assertSame( 429, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'rate_limited', $body['code'] ?? null );
		$this->assertSame( 120, $body['retry_after'] ?? null );

		$headers     = $response->get_headers();
		$retry_after = $headers['Retry-After'] ?? ( $headers['retry-after'] ?? null );
		$retry_value = is_array( $retry_after ) ? (int) $retry_after[0] : (int) $retry_after;
		$this->assertSame( 120, $retry_value );
	}

	/**
	 * @group error
	 * @group security
	 */
	public function test_forbidden_maps_to_403(): void {
		$response = ExceptionMapper::map( new Forbidden( '不可編輯非自己的賣場' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'forbidden', $response->get_data()['code'] ?? null );
	}

	/**
	 * @group error
	 * @group security
	 */
	public function test_persistence_failure_maps_to_500_and_masks_message_in_production(): void {
		// 確保不在 WP_DEBUG mode
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->markTestSkipped( '此測試需在 WP_DEBUG=false 環境跑（生產模式遮蔽訊息）' );
		}

		$secret_message = 'SQL ERROR: Table foo doesn\'t exist at /var/www/secret/path.php:123';
		$response       = ExceptionMapper::map( new PersistenceFailure( $secret_message ) );

		$this->assertSame( 500, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'internal_error', $body['code'] ?? null );

		// 生產 mode 不洩漏內部錯誤訊息
		$this->assertStringNotContainsString(
			'SQL ERROR',
			(string) $body['message'],
			'生產 mode 必須遮蔽原始 PersistenceFailure message'
		);
		$this->assertStringNotContainsString(
			'/var/www/secret',
			(string) $body['message'],
			'生產 mode 不洩漏檔案路徑'
		);

		// error_id 必須存在（讓運維追蹤）
		$this->assertArrayHasKey( 'error_id', $body );
		$this->assertNotEmpty( $body['error_id'] );
	}

	/**
	 * @group error
	 */
	public function test_persistence_failure_includes_error_id_for_tracing(): void {
		$response = ExceptionMapper::map( new PersistenceFailure( 'whatever' ) );
		$body     = $response->get_data();

		$this->assertArrayHasKey( 'error_id', $body );
		$this->assertMatchesRegularExpression(
			'/^PS-\d+-[a-zA-Z0-9]+$/',
			(string) $body['error_id'],
			'error_id 格式應為 PS-{ts}-{rand}'
		);
	}
}
