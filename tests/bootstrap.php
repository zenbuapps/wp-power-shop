<?php
/**
 * PHPUnit 整合測試引導文件
 * 載入順序（重要，不可更改）：
 * 1. Composer autoloader
 * 2. 解析 WP_TESTS_DIR 路徑
 * 3. 確認 WP 測試套件檔案存在
 * 4. 定義 WP_TESTS_PHPUNIT_POLYFILLS_PATH
 * 5. 載入 WP 測試函式 (functions.php)
 * 6. 透過 muplugins_loaded hook 載入插件（Powerhouse → WooCommerce → Power Shop）
 * 7. 載入 WP 測試 bootstrap (bootstrap.php)
 */

declare( strict_types=1 );

// 載入 Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Stub WordPress Abilities API（WP 6.9 前尚未進 core，部份依賴會用到）
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?array { return null; }
}
if ( ! function_exists( 'wp_get_abilities' ) ) {
	function wp_get_abilities(): array { return []; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args = [] ): void {}
}
if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $slug, array $args = [] ): void {}
}

// 取得 wp-phpunit 提供的測試目錄路徑
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// 優先使用 wp-phpunit vendor 套件提供的路徑
	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

// 確認 WP 測試套件存在
if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "找不到 WordPress 測試套件：{$_tests_dir}/includes/functions.php\n";
	exit( 1 );
}

// 設定 PHPUnit Polyfills 路徑（yoast/phpunit-polyfills 需要）
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// 載入 WP 測試函式
require_once "{$_tests_dir}/includes/functions.php";

/**
 * 在 WordPress muplugins_loaded 時載入插件
 * 順序：Powerhouse → WooCommerce → Power Shop
 * （Powerhouse 提供 J7\WpUtils 工具庫，必須最先）
 */
function _power_shop_manually_load_plugin(): void {
	// 1. 載入 Powerhouse（提供 J7\WpUtils 工具庫）
	$powerhouse_path = WP_PLUGIN_DIR . '/powerhouse/plugin.php';
	if ( file_exists( $powerhouse_path ) ) {
		require_once $powerhouse_path;
	} else {
		echo "警告：Powerhouse 不存在於 {$powerhouse_path}\n";
	}

	// 2. 載入 WooCommerce
	$woo_path = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if ( file_exists( $woo_path ) ) {
		require_once $woo_path;
	} else {
		echo "警告：WooCommerce 不存在於 {$woo_path}\n";
	}

	// 3. 載入 Power Shop plugin
	require dirname( __DIR__ ) . '/plugin.php';
}

tests_add_filter( 'muplugins_loaded', '_power_shop_manually_load_plugin' );

// 設定測試語系為 zh_TW
tests_add_filter(
	'locale',
	function () {
		return 'zh_TW';
	}
);

/**
 * 在 plugins_loaded 後強制初始化 Bootstrap
 * 原因：PluginTrait::check_required_plugins() 會檢查 active_plugins，
 * 但測試環境中 WooCommerce 和 Powerhouse 是透過 muplugins_loaded 手動載入，
 * 並未在 active_plugins DB 選項中登記，需要強制初始化以確保 hooks 註冊。
 */
function _power_shop_force_bootstrap(): void {
	if ( class_exists( 'J7\PowerShop\Bootstrap' ) ) {
		\J7\PowerShop\Bootstrap::instance();
	}
}

tests_add_filter( 'plugins_loaded', '_power_shop_force_bootstrap', 20 );

// 啟動 WP 測試套件
require "{$_tests_dir}/includes/bootstrap.php";
