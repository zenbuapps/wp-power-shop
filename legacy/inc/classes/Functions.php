<?php

declare(strict_types=1);

namespace J7\PowerShopV2;

use J7\PowerShop\Plugin;
use function _\find;

/**
 * Class Functions
 */
final class Functions {

	/**
	 * Register CPT
	 *
	 * @param string $label - the name of CPT
	 * @return void
	 */
	public static function register_cpt( $label ): void {

		$kebab = str_replace( ' ', '-', strtolower( $label ) );
		$snake = str_replace( ' ', '_', strtolower( $label ) );

		// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText
		$labels = [
			'name'                     => \esc_html__( $label, 'power-shop' ),
			'singular_name'            => \esc_html__( $label, 'power-shop' ),
			'add_new'                  => \esc_html__( 'Add new', 'power-shop' ),
			'add_new_item'             => \esc_html__( 'Add new item', 'power-shop' ),
			'edit_item'                => \esc_html__( 'Edit', 'power-shop' ),
			'new_item'                 => \esc_html__( 'New', 'power-shop' ),
			'view_item'                => \esc_html__( 'View', 'power-shop' ),
			'view_items'               => \esc_html__( 'View', 'power-shop' ),
			'search_items'             => \esc_html__( 'Search ' . $label, 'power-shop' ),
			'not_found'                => \esc_html__( 'Not Found', 'power-shop' ),
			'not_found_in_trash'       => \esc_html__( 'Not found in trash', 'power-shop' ),
			'parent_item_colon'        => \esc_html__( 'Parent item', 'power-shop' ),
			'all_items'                => \esc_html__( 'All', 'power-shop' ),
			'archives'                 => \esc_html__( $label . ' archives', 'power-shop' ),
			'attributes'               => \esc_html__( $label . ' attributes', 'power-shop' ),
			'insert_into_item'         => \esc_html__( 'Insert to this ' . $label, 'power-shop' ),
			'uploaded_to_this_item'    => \esc_html__( 'Uploaded to this ' . $label, 'power-shop' ),
			'featured_image'           => \esc_html__( 'Featured image', 'power-shop' ),
			'set_featured_image'       => \esc_html__( 'Set featured image', 'power-shop' ),
			'remove_featured_image'    => \esc_html__( 'Remove featured image', 'power-shop' ),
			'use_featured_image'       => \esc_html__( 'Use featured image', 'power-shop' ),
			'menu_name'                => \esc_html__( $label, 'power-shop' ),
			'filter_items_list'        => \esc_html__( 'Filter ' . $label . ' list', 'power-shop' ),
			'filter_by_date'           => \esc_html__( 'Filter by date', 'power-shop' ),
			'items_list_navigation'    => \esc_html__( $label . ' list navigation', 'power-shop' ),
			'items_list'               => \esc_html__( $label . ' list', 'power-shop' ),
			'item_published'           => \esc_html__( $label . ' published', 'power-shop' ),
			'item_published_privately' => \esc_html__( $label . ' published privately', 'power-shop' ),
			'item_reverted_to_draft'   => \esc_html__( $label . ' reverted to draft', 'power-shop' ),
			'item_scheduled'           => \esc_html__( $label . ' scheduled', 'power-shop' ),
			'item_updated'             => \esc_html__( $label . ' updated', 'power-shop' ),
		];
		// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText
		$args = [
			'label'                 => \esc_html__( $label, 'power-shop' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			'labels'                => $labels,
			'description'           => '',
			'public'                => true,
			'hierarchical'          => false,
			'exclude_from_search'   => true,
			'publicly_queryable'    => true,
			'show_ui'               => true,
			'show_in_nav_menus'     => true,
			'show_in_admin_bar'     => true,
			'show_in_rest'          => true,
			'can_export'            => true,
			'delete_with_user'      => true,
			'has_archive'           => false,
			'rest_base'             => '',
			'show_in_menu'          => true,
			'menu_position'         => 4,
			'menu_icon'             => 'dashicons-store',
			'capability_type'       => 'post',
			'supports'              => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'author' ],
			'taxonomies'            => [],
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'rewrite'               => [
				'with_front' => true,
			],
		];

		\register_post_type( $kebab, $args );
	}

	/**
	 * Add metabox
	 *
	 * @param array $args The metabox arguments.
	 * @return void
	 */
	public static function add_metabox( array $args ): void {
		\add_meta_box(
			$args['id'],
			__( $args['label'], 'power-shop' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			[ __CLASS__, 'render_metabox' ],
			Plugin::$kebab,
			'advanced',
			'default',
			[ 'id' => $args['id'] ]
		);
	}

	/**
	 * Renders the meta box.
	 *
	 * @param \WP_Post $post    The post object.
	 * @param array    $metabox The metabox arguments.
	 * @return void
	 */
	public static function render_metabox( $post, $metabox ): void {
		echo "<div id='{$metabox[ 'args' ][ 'id' ]}'></div>";
	}

	/**
	 * JSON Parse
	 *
	 * @param string    $stringfy    The JSON string.
	 * @param mixed     $default     The default value.
	 * @param bool|null $associative Whether to return associative array.
	 * @return mixed
	 */
	public static function json_parse( $stringfy, $default = [], $associative = null ) {
		$out_put = '';
		try {
			$out_put = json_decode( str_replace( '\\', '', $stringfy ), $associative ) ?? $default;
		} catch ( \Throwable $th ) {
			$out_put = $default;
		} finally {
			return $out_put; // phpcs:ignore Universal.CodeAnalysis.ConstructorDestructorReturn.ReturnValueFound
		}
	}

	/**
	 * Get products info
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	public static function get_products_info( int $post_id ): array {
		$shop_meta_string = \get_post_meta( $post_id, Plugin::$snake . '_meta', true ) ?? '[]';

		try {
			$shop_meta = ( json_decode( $shop_meta_string, true ) );
		} catch ( \Throwable $th ) {
			$shop_meta = [];
		}

		if ( ! is_array( $shop_meta ) ) {
			$shop_meta = [];
		}

		$products = array_map(
			[ __CLASS__, 'get_product_data' ],
			$shop_meta
		);

		$products_info = [
			'products' => $products,
			'meta'     => $shop_meta,
		];

		return $products_info;
	}

	/**
	 * Get product data from meta
	 *
	 * @param array $meta The product meta.
	 * @return array
	 */
	public static function get_product_data( array $meta ): array {
		$meta = (array) $meta ?? [];
		if ( empty( $meta['productId'] ) ) {
			return [];
		}

		$product = \wc_get_product( $meta['productId'] );
		if ( ! $product ) {
			return [];
		}

		$feature_image_id = $product->get_image_id();
		$attachment_ids   = [ $feature_image_id, ...$product->get_gallery_image_ids() ];
		$images           = [];
		foreach ( $attachment_ids as $attachment_id ) {
			$images[] = \wp_get_attachment_url( $attachment_id );
		}
		// format data
		$product_data                         = [];
		$product_data['id']                   = $meta['productId'];
		$product_data['type']                 = $product->get_type();
		$product_data['name']                 = $product->get_name();
		$product_data['description']          = \wpautop( $product->get_description() );
		$product_data['images']               = $images;
		$product_data['stock']                = [
			'manageStock'   => $product->get_manage_stock(),
			'stockQuantity' => $product->get_stock_quantity(),
			'stockStatus'   => $product->get_stock_status(),
		];
		$product_data['is_sold_individually'] = $product->is_sold_individually();
		$product_data['is_in_stock']          = $product->is_in_stock();
		$product_data['is_purchasable']       = $product->is_purchasable();
		$product_data['shortDescription']     = \wpautop( $product->get_short_description() );
		$product_data['sku']                  = $product->get_sku();
		$product_data['total_sales']          = $product->get_total_sales();
		$product_data['backorders']           = $product->get_backorders(); // "yes" | "no" | "notify"

		if ( 'simple' === $product->get_type() ) {
			// 價格備援取未稅原始價，沒生效中的特價時為 0，避免前台 Price 元件渲染出假特價（詳見 format_variations）
			$product_data['regularPrice']    = $meta['regularPrice'] ?? (float) $product->get_regular_price();
			$product_data['salesPrice']      = $meta['salesPrice'] ?? self::get_active_sale_price( $product );
			$product_data['extraBuyerCount'] = $meta['extraBuyerCount'] ?? null;
		}
		if ( 'variable' === $product->get_type() ) {
			// 不再要求 $meta['variations'] 必須存在
			// 賣場頁快取的 power_shop_meta 可能缺 variations（見 issue #76），
			// 此時仍要組出變體資料，價格改用 WooCommerce 現值當備援，否則前台無法選規格下單
			$variation_meta                       = $meta['variations'] ?? [];
			$product_data['variations']           = [];
			$product_data['variation_attributes'] = $product->get_variation_attributes();
			$attributes_arr                       = [];
			$attributes                           = $product?->get_attributes(); // get attributes object

			foreach ( $attributes as $key => $attribute ) {
				$option_ids   = $attribute->get_options(); // option_ids
				$slugs        = $attribute->get_slugs(); // option_slugs
				$attr_options = [];

				foreach ( $option_ids as $index => $option_id ) {
					$term           = \get_term_by( 'slug', $slugs[ $index ], $attribute->get_name() );
					$option_name    = $term ? $term->name : $slugs[ $index ];
					$attr_options[] = [
						'value' => urldecode( $slugs[ $index ] ),
						'name'  => urldecode( $option_name ),
					];
				}

				if ( $attribute instanceof \WC_Product_Attribute ) {
					$attributes_arr[] = [
						'name'     => \wc_attribute_label( $attribute->get_name() ),
						'slug'     => $attribute->get_name(),
						'options'  => $attr_options,
						'position' => $attribute->get_position(),
					];
				}

				if ( is_string( $key ) && is_string( $attribute ) ) {
					$attributes_arr[ urldecode( $key ) ] = $attribute;
				}
			}

			$product_data['attributes'] = $attributes_arr;

			foreach ( $product->get_available_variations() as $key => $variation ) {
				$variation_id      = $variation['variation_id'];
				$variation_product = \wc_get_product( $variation_id );
				if ( ! ( $variation_product instanceof \WC_Product ) ) {
					continue;
				}
				$theMeta                            = find( $variation_meta, [ 'variationId' => $variation_id ] ) ?? [];
				$product_data['variations'][ $key ] = $variation;
				// 備援價取未稅原始價，沒生效中的特價時為 0，避免假特價與含稅重複課稅（詳見 format_variations）
				$product_data['variations'][ $key ]['regularPrice']    = $theMeta['regularPrice'] ?? (float) $variation_product->get_regular_price();
				$product_data['variations'][ $key ]['salesPrice']      = $theMeta['salesPrice'] ?? self::get_active_sale_price( $variation_product );
				$product_data['variations'][ $key ]['stock']           = [
					'manageStock'   => $variation_product->get_manage_stock(),
					'stockQuantity' => $variation_product->get_stock_quantity(),
					'stockStatus'   => $variation_product->get_stock_status(),
				];
				$product_data['variations'][ $key ]['extraBuyerCount'] = $theMeta['extraBuyerCount'] ?? null;
			}
		}

		return $product_data;
	}

	/**
	 * 將 WooCommerce 可變商品的變體，格式化成 power_shop_meta 的 variations 結構
	 *
	 * 價格一律取未稅原始價，與編輯器寫入的格式對齊（編輯器用 Number(sale_price)，沒特價時為 0）。
	 * 不可改用 get_available_variations() 的 display_price / display_regular_price：
	 * display_price 是「現行有效價」，沒特價時會等於原價，前台 Price 元件會因此渲染出
	 * 「刪除線原價 + 同價紅字特價」的假特價；且該值經過 wc_get_price_to_display() 套用
	 * 稅務顯示設定，站台設為含稅時會被 Cart::price_refresh() 當未稅基價再課一次稅。
	 *
	 * @param \WC_Product $product 商品物件
	 * @return array<int, array{variationId: int, regularPrice: float, salesPrice: float}>
	 */
	public static function format_variations( \WC_Product $product ): array {
		if ( ! ( $product instanceof \WC_Product_Variable ) ) {
			return [];
		}

		$formatted_variations = [];
		foreach ( $product->get_available_variations() as $variation ) {
			$variation_product = \wc_get_product( $variation['variation_id'] );
			if ( ! ( $variation_product instanceof \WC_Product ) ) {
				continue;
			}
			$formatted_variations[] = [
				'variationId'  => $variation['variation_id'],
				'regularPrice' => (float) $variation_product->get_regular_price(),
				'salesPrice'   => self::get_active_sale_price( $variation_product ),
			];
		}

		return $formatted_variations;
	}

	/**
	 * 取得「目前實際生效」的特價，沒有生效中的特價時回傳 0
	 *
	 * 不可直接用 get_sale_price()：它回傳的是 _sale_price 欄位原值，
	 * 即使特價已排程但尚未開始（is_on_sale() 為 false）也會有值，
	 * 寫進 power_shop_meta 後會讓前台提早顯示特價，並被 Cart::price_refresh()
	 * 拿去 set_price()，造成尚未開賣的特價提前結帳。
	 *
	 * @param \WC_Product $product 商品或變體物件
	 * @return float
	 */
	public static function get_active_sale_price( \WC_Product $product ): float {
		return $product->is_on_sale() ? (float) $product->get_sale_price() : 0.0;
	}
}
