<?php
/**
 * 固定 Salt 提供器（測試替身）
 *
 * 用於 CartPriceSignatureService / PartnerTokenStore 等需要 salt 的純 PHP 單元測試。
 * 不依賴 WordPress wp_salt()，可在 tests/Unit/** 不啟 WP 的環境跑過。
 */

declare(strict_types=1);

namespace Tests\Support;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SaltProviderInterface;

/**
 * 固定值 Salt Provider
 *
 * 用法：
 *   $salt = new FixedSaltProvider();                     // 預設 salt
 *   $salt = new FixedSaltProvider( 'custom_salt_value' ); // 指定 salt（測試對比用）
 *   $salt->get( 'auth' );    // 'xxx:auth'
 *   $salt->get( 'nonce' );   // 'xxx:nonce'
 *
 * 行為與 WpSaltProvider 對稱：context 必須在 ALLOWED_CONTEXTS 白名單中，
 * 否則拋 \InvalidArgumentException——確保 fakes 在測試中能捕捉到上游錯誤呼叫。
 */
final class FixedSaltProvider implements SaltProviderInterface {

	/**
	 * 與 WpSaltProvider 對稱的白名單，確保 fake 行為等同 production
	 */
	private const ALLOWED_CONTEXTS = [ 'auth', 'logged_in', 'nonce', 'secure_auth' ];

	/**
	 * 建構子
	 *
	 * @param string $salt 固定 salt 值（預設 64+ chars 以模擬 wp_salt 高熵輸出）
	 */
	public function __construct(
		private readonly string $salt = 'test_fixed_salt_for_unit_tests_64chars_minimum_length_padding!'
	) {}

	/**
	 * 取得 salt（context 後綴用以區分不同用途）
	 *
	 * @param string $context 'auth' | 'logged_in' | 'nonce' | 'secure_auth'
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException 當 context 不在 ALLOWED_CONTEXTS 白名單中
	 */
	public function get( string $context = 'auth' ): string {
		if ( ! in_array( $context, self::ALLOWED_CONTEXTS, true ) ) {
			throw new \InvalidArgumentException(
				"未支援的 salt context：{$context}（允許：" . implode( ', ', self::ALLOWED_CONTEXTS ) . '）'
			);
		}
		return $this->salt . ':' . $context;
	}
}
