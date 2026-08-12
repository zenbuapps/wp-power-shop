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
 * - rewrite slug 為固定常數 `powershop`（見 DEFAULT_REWRITE_SLUG）。
 * - `show_in_rest = false`：本 plugin 走自家 `power-shop` REST namespace（V2Api），
 *   不需要 wp/v2 通用 REST，避免 author+ 經由 `/wp-json/wp/v2/powershop/...`
 *   繞過 `manage_woocommerce` 權限檢查。完整 capability 對應交由 Phase 3 V2Api。
 */
final class CptRegistrar {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * CPT 識別字串
	 */
	public const POST_TYPE = 'powershop';

	/**
	 * Rewrite slug（固定）
	 *
	 * ⚠️ 這條產生的是 CPT 的**原生永久連結** `/{DEFAULT_REWRITE_SLUG}/{post-name}/`，
	 * **不是**賣場前台網址——前台走 `RewriteRules::SHOP_REWRITE_PREFIX`（`/profit-shop/{slug}/`）
	 * 並由 `ProfitShopRenderer` 接管渲染。
	 *
	 * 曾經開放為設定項（wp_options `power_shop_profit_settings.rewrite_slug`），後來移除：
	 * 全專案**沒有任何一處**組出或連到 powershop 的 CPT permalink（`grep get_permalink inc/`
	 * 三處命中全是商品），而 `ProfitShopRenderer::maybe_render` 只在 query var
	 * `profit_shop_slug` 有值時才渲染商品 grid——所以那條網址打開只會拿到佈景主題的
	 * single template + `post_content`，沒有商品、沒有價格、沒有加購。
	 * 讓站長設定一條「沒人連過去、內容半殘」的網址沒有任何正面價值。
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
			// show_in_rest = false：避免 author+ 經由 wp/v2/powershop 繞過 V2Api 權限檢查。
			// 自家 REST API 走 power-shop namespace（Phase 3 V2Api 處理）。
			'show_in_rest'       => false,
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
	 * 取得 rewrite slug（固定常數）
	 *
	 * 保留此 method 而非讓 caller 直接讀常數，是為了維持單一取值入口——
	 * caller 不需要知道它是設定值還是常數。
	 *
	 * @return string rewrite slug
	 */
	public static function get_rewrite_slug(): string {
		return self::DEFAULT_REWRITE_SLUG;
	}
}
