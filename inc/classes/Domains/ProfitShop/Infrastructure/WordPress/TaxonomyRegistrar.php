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
 * - 顯式註冊敏感 termmeta 為 protected（show_in_rest=false + auth_callback=false），
 *   避免任何路徑（包含 termmeta REST endpoint）對外洩漏 _partner_password 等敏感資料。
 */
final class TaxonomyRegistrar {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Taxonomy 識別字串
	 */
	public const TAXONOMY = 'profit_partner';

	/**
	 * 必須隱藏於 REST 的敏感 termmeta key 清單
	 *
	 * 任一 key 都不可暴露給 wp/v2/profit_partner 的 meta 欄位，
	 * 也不可由外部 client 透過 update_term_meta REST 寫入。
	 */
	private const PROTECTED_META_KEYS = [
		'_partner_password',
		'_partner_contact_email',
		'_partner_password_changed_at',
	];

	/**
	 * Constructor
	 *
	 * 掛在 init priority 11，與 CptRegistrar 同步。
	 */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register' ], 11 );
		\add_action( 'init', [ __CLASS__, 'register_protected_meta' ], 11 );
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

	/**
	 * 顯式將敏感 termmeta 註冊為 protected
	 *
	 * 雖然底線前綴 termmeta 預設不會被 register_meta 自動暴露，但 profit_partner
	 * taxonomy 的 show_in_rest=true 仍存在風險（自訂 endpoint、第三方 plugin 介入等）。
	 * 此處明示：
	 * - show_in_rest = false：不對外露出。
	 * - auth_callback = '__return_false'：拒絕任何 update/add/delete meta 的權限檢查，
	 *   實際寫入只會走自家 PartnerTermRepository（直接呼叫 update_term_meta，
	 *   不走 cap_check）。
	 *
	 * @return void
	 */
	public static function register_protected_meta(): void {
		foreach ( self::PROTECTED_META_KEYS as $meta_key ) {
			\register_meta(
				'term',
				$meta_key,
				[
					'object_subtype' => self::TAXONOMY,
					'type'           => 'string',
					'single'         => true,
					'show_in_rest'   => false,
					'auth_callback'  => '__return_false',
				]
			);
		}
	}
}
