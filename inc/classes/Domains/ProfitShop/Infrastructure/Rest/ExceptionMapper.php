<?php
/**
 * Domain Exception → HTTP 回應對映器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\Forbidden;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidShopMode;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidStatusTransition;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidVariation;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\LegacyShopNotImportable;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerStillInUseException;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PersistenceFailure;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductAlreadyInShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotInShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\RateLimitExceeded;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;

/**
 * Domain Exception 對映成 WP_REST_Response 的工具
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §7.1、§7.2、§7.3
 *
 * 對照表：
 *   404 not_found              ProfitShopNotFound / PartnerNotFound / ProductNotFound
 *   400 validation_failed      InvalidPriceOverride / InvalidProfitRate / InvalidPartnerSlug / InvalidVariation
 *   409 slug_conflict          SlugConflictException（payload 帶 conflicts）
 *   422 invalid_state_transition InvalidStatusTransition
 *   409 partner_in_use         PartnerStillInUseException
 *   401 unauthorized           InvalidCredentials
 *   429 rate_limited           TooManyAttempts / RateLimitExceeded（含 Retry-After header）
 *   403 forbidden              Forbidden
 *   422 legacy_unimportable    LegacyShopNotImportable（payload 帶 reason）
 *   500 internal_error         PersistenceFailure / 其它 Throwable
 */
final class ExceptionMapper {

	/**
	 * 對映 Throwable 為 WP_REST_Response
	 *
	 * @param \Throwable $e 任何例外
	 *
	 * @return \WP_REST_Response
	 */
	public static function map( \Throwable $e ): \WP_REST_Response {
		[ $status, $code, $extra, $headers ] = self::resolve( $e );

		$body = [
			'code'    => $code,
			'message' => $e->getMessage(),
		];
		if ( ! empty( $extra ) ) {
			$body = array_merge( $body, $extra );
		}

		// 500 internal_error 一律附 error_id 與寫 log，方便 debug。
		if ( 500 === $status ) {
			$error_id        = self::generate_error_id();
			$body['error_id'] = $error_id;

			// 寫 log 前過濾換行 / tab（log injection 防禦：避免 attacker 透過例外訊息偽造 log 紀錄）
			$msg_for_log = str_replace( [ "\r", "\n", "\t" ], ' ', $e->getMessage() );
			\error_log( "[power-shop] {$error_id} {$msg_for_log}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// 生產環境遮蔽原始 message，避免洩漏內部錯誤細節（DB schema、檔案路徑等）
			if ( self::should_mask() ) {
				$body['message'] = '伺服器發生錯誤，請聯絡管理員';
			}
		}

		$response = new \WP_REST_Response( $body, $status );
		foreach ( $headers as $name => $value ) {
			$response->header( $name, (string) $value );
		}
		return $response;
	}

	/**
	 * 解析 Throwable 對應的 HTTP status / code / extra payload / headers
	 *
	 * @param \Throwable $e 任何例外
	 *
	 * @return array{0:int, 1:string, 2:array<string, mixed>, 3:array<string, scalar>}
	 */
	private static function resolve( \Throwable $e ): array {
		// 404 not_found
		if (
			$e instanceof ProfitShopNotFound
			|| $e instanceof PartnerNotFound
			|| $e instanceof ProductNotFound
		) {
			return [ 404, 'not_found', [], [] ];
		}

		// 400 validation_failed
		if (
			$e instanceof InvalidPriceOverride
			|| $e instanceof InvalidProfitRate
			|| $e instanceof InvalidPartnerSlug
			|| $e instanceof InvalidVariation
			|| $e instanceof InvalidShopMode
		) {
			return [ 400, 'validation_failed', [], [] ];
		}

		// 409 slug_conflict（含 conflicts payload）
		if ( $e instanceof SlugConflictException ) {
			$conflicts_payload = [];
			foreach ( $e->getConflicts() as $conflict ) {
				$conflicts_payload[] = $conflict->to_array();
			}
			return [ 409, 'slug_conflict', [ 'conflicts' => $conflicts_payload ], [] ];
		}

		// 409 partner_in_use
		if ( $e instanceof PartnerStillInUseException ) {
			return [ 409, 'partner_in_use', [], [] ];
		}

		// 422 invalid_state_transition
		if ( $e instanceof InvalidStatusTransition ) {
			return [ 422, 'invalid_state_transition', [], [] ];
		}

		// 422 legacy_unimportable
		if ( $e instanceof LegacyShopNotImportable ) {
			return [ 422, 'legacy_unimportable', [ 'reason' => $e->getReason() ], [] ];
		}

		// 401 unauthorized
		if ( $e instanceof InvalidCredentials ) {
			return [ 401, 'unauthorized', [], [] ];
		}

		// 429 rate_limited（含 Retry-After header）
		if ( $e instanceof TooManyAttempts || $e instanceof RateLimitExceeded ) {
			$retry_after = method_exists( $e, 'getRetryAfter' ) ? (int) $e->getRetryAfter() : 60;
			return [
				429,
				'rate_limited',
				[ 'retry_after' => $retry_after ],
				[ 'Retry-After' => $retry_after ],
			];
		}

		// 403 forbidden
		if ( $e instanceof Forbidden ) {
			return [ 403, 'forbidden', [], [] ];
		}

		// 409 conflict（額外：商品已存在 / 不存在於賣場）
		if ( $e instanceof ProductAlreadyInShop ) {
			return [ 409, 'product_already_in_shop', [], [] ];
		}
		if ( $e instanceof ProductNotInShop ) {
			return [ 404, 'product_not_in_shop', [], [] ];
		}

		// 500 internal_error（PersistenceFailure 明確列示）
		if ( $e instanceof PersistenceFailure ) {
			return [ 500, 'internal_error', [], [] ];
		}

		// 其他未列示的 \DomainException 一律視為 400 validation_failed（避免使用者輸入錯誤
		// 漏掉特定例外類別後被誤對映成 500）。InvalidArgumentException 同理。
		if ( $e instanceof \DomainException || $e instanceof \InvalidArgumentException ) {
			return [ 400, 'validation_failed', [], [] ];
		}

		return [ 500, 'internal_error', [], [] ];
	}

	/**
	 * 產生 error_id（PS-{ts}-{rand}）
	 *
	 * @return string
	 */
	private static function generate_error_id(): string {
		return sprintf( 'PS-%d-%s', time(), \wp_generate_password( 6, false, false ) );
	}

	/**
	 * 是否應該遮蔽錯誤訊息（500 internal_error）
	 *
	 * 預設行為：當 `WP_DEBUG` 未啟用時遮蔽（生產模式）；啟用時保留原始訊息（開發模式）。
	 *
	 * Test seam：透過 `power_shop_exception_mapper_mask` filter 可在測試環境強制覆寫，
	 * 例如 `add_filter('power_shop_exception_mapper_mask', '__return_true')` 強制遮蔽。
	 *
	 * @return bool
	 */
	private static function should_mask(): bool {
		$default = ! ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		return (bool) \apply_filters( 'power_shop_exception_mapper_mask', $default );
	}
}
