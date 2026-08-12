<?php
/**
 * Production SaltProvider（使用 WordPress wp_salt()）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SaltProviderInterface;

/**
 * Production SaltProvider，使用 WordPress wp_salt() 函式。
 *
 * Application 層透過 SaltProviderInterface 注入此類別，
 * 隔離 WP 函式依賴，讓 Application 純 PHP 可單元測試。
 *
 * 安全強化：context 透過白名單驗證，避免上游不慎傳入未支援字串導致 wp_salt()
 * fallback 行為（fallback 仍可運作但不在預期範圍內，違反 fail-fast 原則）。
 */
final class WpSaltProvider implements SaltProviderInterface {

	/**
	 * WordPress 支援的 salt context 白名單
	 *
	 * 對應 wp_salt() 在 wp-includes/pluggable.php 的 4 種已知 scheme。
	 */
	private const ALLOWED_CONTEXTS = [ 'auth', 'logged_in', 'nonce', 'secure_auth' ];

	/**
	 * 取得 salt 字串
	 *
	 * @param string $context 'auth' | 'logged_in' | 'nonce' | 'secure_auth'
	 *
	 * @return string WordPress salt（高熵字串，由 wp-config.php 的 SALT 常數推導）
	 *
	 * @throws \InvalidArgumentException 當 context 不在 ALLOWED_CONTEXTS 白名單中
	 */
	public function get( string $context = 'auth' ): string {
		if ( ! in_array( $context, self::ALLOWED_CONTEXTS, true ) ) {
			throw new \InvalidArgumentException(
				"未支援的 salt context：{$context}（允許：" . implode( ', ', self::ALLOWED_CONTEXTS ) . '）'
			);
		}
		return \wp_salt( $context );
	}
}
