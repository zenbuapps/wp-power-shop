<?php
/**
 * WordPress 測試環境設定
 * 此檔案由 phpunit.xml.dist 透過 WP_PHPUNIT__TESTS_CONFIG 環境變數指定。
 * 在 wp-env 環境下，資料庫設定由 wp-env 自動注入（DB_HOST=tests-mysql:3306）。
 */

/* Path to the WordPress codebase you'd like to test. Add a slash at the end. */
define( 'ABSPATH', getenv( 'WP_TESTS_ABSPATH' ) !== false ? getenv( 'WP_TESTS_ABSPATH' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress/' );

/* Test with WordPress debug turned on */
define( 'WP_DEBUG', true );

// WARNING: the database specified below must exist

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) !== false ? getenv( 'WP_TESTS_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) !== false ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASS' ) !== false ? getenv( 'WP_TESTS_DB_PASS' ) : '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) !== false ? getenv( 'WP_TESTS_DB_HOST' ) : 'tests-mysql:3306' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
