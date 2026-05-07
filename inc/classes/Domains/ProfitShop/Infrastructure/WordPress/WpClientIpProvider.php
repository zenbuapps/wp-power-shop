<?php
/**
 * Production Client IP Provider（從 $_SERVER 取得）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\ClientIpProviderInterface;

/**
 * WordPress 環境 client IP 提供者
 *
 * IP 取得策略（Phase 5-A.1 reviewer 二輪修正：越專屬、越可信的越優先）：
 *   1. CF-Connecting-IP（Cloudflare 邊緣寫死，client 不可偽造）
 *   2. True-Client-IP（Akamai / Cloudflare Enterprise）
 *   3. X-Real-IP（Nginx 標準，由 trusted proxy 設置）
 *   4. X-Forwarded-For 第一段（attacker 可偽造，最後才用）
 *   5. REMOTE_ADDR（無代理場景）
 *   6. 全失敗回 null（fail-open by caller）
 *
 * Trust ranking 設計理由：
 *   - 1～3 為「上游基礎設施寫死」的 header，client 端 spoof 會被覆寫；
 *   - 4 在反向代理未 strip incoming spoof header 時可被偽造，故置於專屬 header 之後；
 *   - 5 是無代理或所有上游 header 皆無時的最終 fallback。
 *
 * 安全提醒：X-Forwarded-For 仍可被 client 偽造，
 * **必須**確認部署的反向代理層 strip incoming spoof header。
 * 站台部署若位於非 CF / Akamai / Nginx 標準環境，請使用 `power_shop_client_ip` filter
 * 自訂解析策略（例如 REMOTE_ADDR only / 自家信任 proxy 名單解析）。
 *
 * 額外規範化：
 *   - IPv6 mapped IPv4（::ffff:1.2.3.4）→ 1.2.3.4
 *   - 透過 filter_var FILTER_VALIDATE_IP 過濾非法字串
 */
final class WpClientIpProvider implements ClientIpProviderInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 取得當前請求的 client IP
	 *
	 * 內部解析後透過 `power_shop_client_ip` filter 給站台覆寫機會，
	 * 預設策略適合多數 CDN / 反向代理場景，但在特殊部署
	 * （例如代理層未 strip incoming spoof header）建議覆寫。
	 *
	 * @return string|null 合法 IP 或 null
	 */
	public function get_ip(): ?string {
		$ip     = $this->resolve_ip_internal();
		$remote = $this->get_server( 'REMOTE_ADDR' );

		/**
		 * Filter the resolved client IP used for page rate-limit / audit logging.
		 *
		 * 站台部署在反向代理後可用此 filter 切換 IP 取得策略：
		 *   - 預設策略易受 X-Forwarded-For 偽造攻擊（若代理層未 strip incoming spoof header）
		 *   - 推薦自訂：return REMOTE_ADDR only / 自家信任 proxy 名單解析
		 *   - 回傳 null 代表「取不到 IP」，下游 caller 應 fail-open
		 *
		 * @param string|null $ip     解析後 IP（已通過 filter_var FILTER_VALIDATE_IP）；無效為 null
		 * @param string      $remote REMOTE_ADDR 原始值（fallback / 對照用，未驗證）
		 */
		$filtered = \apply_filters( 'power_shop_client_ip', $ip, $remote );

		if ( null === $filtered ) {
			return null;
		}
		if ( ! is_string( $filtered ) ) {
			return null;
		}
		// filter 回傳值再走一次 normalize 防呆
		return $this->normalize( $filtered );
	}

	/**
	 * 內部 IP 解析邏輯（依 trust ranking 由高到低）
	 *
	 * @return string|null 合法 IP 或 null
	 */
	private function resolve_ip_internal(): ?string {
		// 1. CF-Connecting-IP（Cloudflare 邊緣寫死，client 不可偽造）
		$cf = $this->get_server( 'HTTP_CF_CONNECTING_IP' );
		if ( '' !== $cf ) {
			$ip = $this->normalize( trim( $cf ) );
			if ( null !== $ip ) {
				return $ip;
			}
		}

		// 2. True-Client-IP（Akamai / Cloudflare Enterprise）
		$true_client = $this->get_server( 'HTTP_TRUE_CLIENT_IP' );
		if ( '' !== $true_client ) {
			$ip = $this->normalize( trim( $true_client ) );
			if ( null !== $ip ) {
				return $ip;
			}
		}

		// 3. X-Real-IP（Nginx 標準，由 trusted proxy 設置）
		$real = $this->get_server( 'HTTP_X_REAL_IP' );
		if ( '' !== $real ) {
			$ip = $this->normalize( trim( $real ) );
			if ( null !== $ip ) {
				return $ip;
			}
		}

		// 4. X-Forwarded-For 第一段（attacker 可偽造，最後才用）
		$xff = $this->get_server( 'HTTP_X_FORWARDED_FOR' );
		if ( '' !== $xff ) {
			$first = trim( explode( ',', $xff )[0] );
			$ip    = $this->normalize( $first );
			if ( null !== $ip ) {
				return $ip;
			}
		}

		// 5. REMOTE_ADDR（無代理場景）
		$remote = $this->get_server( 'REMOTE_ADDR' );
		if ( '' !== $remote ) {
			$ip = $this->normalize( trim( $remote ) );
			if ( null !== $ip ) {
				return $ip;
			}
		}

		return null;
	}

	/**
	 * 安全讀取 $_SERVER key
	 *
	 * @param string $key $_SERVER 鍵名
	 *
	 * @return string sanitize 後的字串（不存在回空字串）
	 */
	private function get_server( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		return \sanitize_text_field( \wp_unslash( (string) $_SERVER[ $key ] ) );
	}

	/**
	 * 標準化並驗證 IP 字串
	 *
	 * - IPv6 mapped IPv4（::ffff:1.2.3.4）→ 1.2.3.4
	 * - filter_var FILTER_VALIDATE_IP 把關
	 *
	 * @param string $ip 原始 IP 字串
	 *
	 * @return string|null 合法 IP 或 null
	 */
	private function normalize( string $ip ): ?string {
		if ( '' === $ip ) {
			return null;
		}

		// IPv6 mapped IPv4 → 純 IPv4
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$ip = substr( $ip, 7 );
		}

		$validated = filter_var( $ip, FILTER_VALIDATE_IP );
		if ( false === $validated ) {
			return null;
		}
		return (string) $validated;
	}
}
