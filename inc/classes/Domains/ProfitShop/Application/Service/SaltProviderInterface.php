<?php
/**
 * Salt 提供器抽象（Port）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Salt 提供器抽象（DIP）
 *
 * Production 由 Infrastructure/WordPress/WpSaltProvider 注入（用 wp_salt('auth')）；
 * Unit Test 由 tests/Support/FixedSaltProvider 提供固定值。
 *
 * 用此抽象避免 PartnerTokenStore / CartPriceSignatureService 直接依賴 wp_salt() 函式，
 * 讓純 PHP 單元測試（不啟 WP）也能跑。
 */
interface SaltProviderInterface {

	/**
	 * 取得 salt 字串
	 *
	 * @param string $context 'auth' | 'logged_in' | 'nonce' | 'secure_auth'
	 *
	 * @return string 高熵 salt（建議 ≥ 64 bytes 以滿足 HMAC-SHA256 推薦 key 長度）
	 *
	 * @throws \InvalidArgumentException 當 context 不在實作的白名單時
	 */
	public function get( string $context = 'auth' ): string;
}
