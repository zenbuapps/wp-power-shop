<?php
/**
 * Plugin Name:       Power Shop | 讓電商管理更便利
 * Plugin URI:        https://powerhouse.cloud/power-shop/
 * Description:       優化 Woocommerce 操作介面，更人性化的方式管理電商平台
 * Version:           3.0.22
 * Requires at least: 5.7
 * Requires PHP:      8.0
 * Author:            J7
 * Author URI:        https://github.com/j7-dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       power_shop
 * Domain Path:       /languages
 * Tags:              woocommerce, ecommerce, power-shop
 */

declare (strict_types = 1);

namespace J7\PowerShop;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

if ( \class_exists( 'J7\PowerShop\Plugin' ) ) {
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

/** Class Plugin */
final class Plugin {
	use \J7\WpUtils\Traits\PluginTrait;
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {

		// self::$template_page_names = [ '404' ];

		$this->required_plugins = [
			[
				'name'     => 'WooCommerce',
				'slug'     => 'woocommerce',
				'required' => true,
				'version'  => '7.6.0',
			],
			[
				'name'     => 'Powerhouse',
				'slug'     => 'powerhouse',
				'source'   => 'https://github.com/zenbuapps/wp-powerhouse/releases/latest/download/powerhouse.zip',
				'version'  => '3.3.20',
				'required' => true,
			],
		];

		$this->init(
			[
				'app_name'    => 'Power Shop',
				'github_repo' => 'https://github.com/zenbuapps/wp-power-shop',
				'callback'    => [ Bootstrap::class, 'instance' ],
			]
		);
	}
}

Plugin::instance();
