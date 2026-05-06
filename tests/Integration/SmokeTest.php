<?php
/**
 * Power Shop Smoke Test
 * 確認測試環境基礎元件均已正確載入。
 */

declare( strict_types=1 );

namespace Tests\Integration;

/**
 * 冒煙測試
 *
 * @group smoke
 */
final class SmokeTest extends TestCase {

	/**
	 * WordPress 已載入
	 */
	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( defined( 'ABSPATH' ), 'WordPress ABSPATH 未定義，WP 測試環境未正確啟動' );
	}

	/**
	 * WooCommerce 已載入
	 */
	public function test_woocommerce_is_loaded(): void {
		$this->assertTrue( class_exists( '\WooCommerce' ), 'WooCommerce class 不存在，WC plugin 未正確載入' );
	}

	/**
	 * Power Shop plugin 已載入
	 */
	public function test_power_shop_is_loaded(): void {
		$this->assertTrue( class_exists( '\J7\PowerShop\Plugin' ), 'J7\PowerShop\Plugin class 不存在，Power Shop plugin 未正確載入' );
	}
}
