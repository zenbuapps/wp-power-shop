<?php
/**
 * 分潤賣場 CPT 註冊器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

/**
 * 註冊 powershop CPT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §1.1、§2.2、§6.7
 *
 * - 後台 UI 走 React SPA（不註冊 metabox）。
 * - rewrite slug 從 wp_options `power_shop_profit_settings.rewrite_slug` 讀取，預設 `powershop`。
 */
final class CptRegistrar {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * CPT 識別字串
	 */
	public const POST_TYPE = 'powershop';

	/**
	 * 全站設定的 wp_options key
	 */
	public const OPTIONS_KEY = 'power_shop_profit_settings';

	/**
	 * 預設 rewrite slug
	 */
	public const DEFAULT_REWRITE_SLUG = 'powershop';

	/**
	 * Constructor
	 *
	 * 掛在 init priority 11，避開 powerhouse 自身於 init priority 10 的初始化。
	 */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register' ], 11 );
	}

	/**
	 * 註冊 powershop CPT
	 *
	 * @return void
	 */
	public static function register(): void {
		$labels = [
			'name'                  => \__( '分潤賣場', 'power_shop' ),
			'singular_name'         => \__( '分潤賣場', 'power_shop' ),
			'menu_name'             => \__( '分潤賣場', 'power_shop' ),
			'name_admin_bar'        => \__( '分潤賣場', 'power_shop' ),
			'add_new'               => \__( '新增分潤賣場', 'power_shop' ),
			'add_new_item'          => \__( '新增分潤賣場', 'power_shop' ),
			'new_item'              => \__( '新分潤賣場', 'power_shop' ),
			'edit_item'             => \__( '編輯分潤賣場', 'power_shop' ),
			'view_item'             => \__( '檢視分潤賣場', 'power_shop' ),
			'all_items'             => \__( '所有分潤賣場', 'power_shop' ),
			'search_items'          => \__( '搜尋分潤賣場', 'power_shop' ),
			'not_found'             => \__( '找不到任何分潤賣場', 'power_shop' ),
			'not_found_in_trash'    => \__( '回收桶中沒有任何分潤賣場', 'power_shop' ),
			'featured_image'        => \__( '賣場主視覺', 'power_shop' ),
			'set_featured_image'    => \__( '設定主視覺', 'power_shop' ),
			'remove_featured_image' => \__( '移除主視覺', 'power_shop' ),
			'use_featured_image'    => \__( '使用主視覺', 'power_shop' ),
		];

		$rewrite_slug = self::get_rewrite_slug();

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false, // 後台 UI 走 React SPA，不掛在原生選單。
			'show_in_rest'       => true,
			'rest_base'          => self::POST_TYPE,
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_icon'          => 'dashicons-money-alt',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
			'rewrite'            => [
				'slug'       => $rewrite_slug,
				'with_front' => false,
			],
			'capability_type'    => 'post',
		];

		\register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * 從 wp_options 讀取 rewrite slug，找不到則使用預設值
	 *
	 * @return string rewrite slug
	 */
	public static function get_rewrite_slug(): string {
		$settings = \get_option( self::OPTIONS_KEY, [] );
		if ( ! is_array( $settings ) ) {
			return self::DEFAULT_REWRITE_SLUG;
		}

		$slug = $settings['rewrite_slug'] ?? self::DEFAULT_REWRITE_SLUG;
		if ( ! is_string( $slug ) || $slug === '' ) {
			return self::DEFAULT_REWRITE_SLUG;
		}

		return $slug;
	}
}
