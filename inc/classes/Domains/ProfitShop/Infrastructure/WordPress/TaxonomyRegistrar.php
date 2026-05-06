<?php
/**
 * 分潤夥伴 Taxonomy 註冊器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

/**
 * 註冊 profit_partner taxonomy
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4
 *
 * - 不可公開 archive（後台 UI 走 React SPA，前台報表透過自訂 rewrite）。
 * - 不註冊 admin menu。
 */
final class TaxonomyRegistrar {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Taxonomy 識別字串
	 */
	public const TAXONOMY = 'profit_partner';

	/**
	 * Constructor
	 *
	 * 掛在 init priority 11，與 CptRegistrar 同步。
	 */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register' ], 11 );
	}

	/**
	 * 註冊 profit_partner taxonomy
	 *
	 * @return void
	 */
	public static function register(): void {
		$labels = [
			'name'                       => \__( '分潤夥伴', 'power_shop' ),
			'singular_name'              => \__( '分潤夥伴', 'power_shop' ),
			'menu_name'                  => \__( '分潤夥伴', 'power_shop' ),
			'all_items'                  => \__( '所有分潤夥伴', 'power_shop' ),
			'edit_item'                  => \__( '編輯分潤夥伴', 'power_shop' ),
			'view_item'                  => \__( '檢視分潤夥伴', 'power_shop' ),
			'update_item'                => \__( '更新分潤夥伴', 'power_shop' ),
			'add_new_item'               => \__( '新增分潤夥伴', 'power_shop' ),
			'new_item_name'              => \__( '新分潤夥伴名稱', 'power_shop' ),
			'search_items'               => \__( '搜尋分潤夥伴', 'power_shop' ),
			'not_found'                  => \__( '找不到任何分潤夥伴', 'power_shop' ),
			'no_terms'                   => \__( '尚無分潤夥伴', 'power_shop' ),
			'items_list'                 => \__( '分潤夥伴列表', 'power_shop' ),
			'items_list_navigation'      => \__( '分潤夥伴列表導覽', 'power_shop' ),
			'separate_items_with_commas' => \__( '以逗號分隔多個分潤夥伴', 'power_shop' ),
		];

		$args = [
			'labels'            => $labels,
			'public'            => false,
			'publicly_queryable' => false,
			'show_ui'           => false, // 後台走 React SPA。
			'show_in_menu'      => false,
			'show_in_rest'      => true,
			'show_admin_column' => false,
			'hierarchical'      => false,
			'rewrite'           => false,
		];

		\register_taxonomy( self::TAXONOMY, [ CptRegistrar::POST_TYPE ], $args );
	}
}
