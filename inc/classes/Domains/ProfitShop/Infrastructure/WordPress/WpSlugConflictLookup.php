<?php
/**
 * Slug 衝突查找 WP 實作
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictLookupInterface;

/**
 * 透過 WP 全域物件 + DB 查詢的 SlugConflictLookup 實作
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 */
final class WpSlugConflictLookup implements SlugConflictLookupInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * WordPress 保留字（不完整列表，spec §6.11.1）
	 *
	 * @var array<string, string>
	 */
	private const WP_RESERVED = [
		'wp-admin'    => 'WordPress 後台',
		'wp-json'     => 'WordPress REST API',
		'wp-content'  => 'WordPress 內容目錄',
		'wp-includes' => 'WordPress 核心目錄',
		'feed'        => 'WordPress feed',
		'comments'    => 'WordPress 留言',
		'search'      => 'WordPress 搜尋',
		'author'      => 'WordPress 作者頁',
		'category'    => 'WordPress 分類頁',
		'tag'         => 'WordPress 標籤頁',
		'page'        => 'WordPress 頁面',
		'attachment'  => 'WordPress 附件',
		'embed'       => 'WordPress 嵌入',
		'trackback'   => 'WordPress Trackback',
	];

	/**
	 * 是否為 WordPress 保留字
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return string|null
	 */
	public function is_wp_reserved( string $slug ): ?string {
		return self::WP_RESERVED[ $slug ] ?? null;
	}

	/**
	 * 是否為 WooCommerce 核心 page slug
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return string|null
	 */
	public function is_wc_page_slug( string $slug ): ?string {
		$wc_pages = [
			'shop'       => \__( 'WooCommerce 商店頁', 'power_shop' ),
			'cart'       => \__( 'WooCommerce 購物車', 'power_shop' ),
			'checkout'   => \__( 'WooCommerce 結帳頁', 'power_shop' ),
			'my-account' => \__( 'WooCommerce 會員中心', 'power_shop' ),
		];

		// 也讀 WC 設定頁實際存放的 page slugs（防止使用者改了 slug）。
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $wc_page_key ) {
				$page_id = (int) \wc_get_page_id( $wc_page_key );
				if ( $page_id <= 0 ) {
					continue;
				}
				$post = \get_post( $page_id );
				if ( $post instanceof \WP_Post && $post->post_name === $slug ) {
					return $wc_pages[ $wc_page_key ] ?? \__( 'WooCommerce 頁面', 'power_shop' );
				}
			}
		}

		return $wc_pages[ $slug ] ?? null;
	}

	/**
	 * 是否與其他已註冊 CPT 的 rewrite slug 衝突
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return array{int, string}|null
	 */
	public function find_conflicting_cpt( string $slug ): ?array {
		$post_types = \get_post_types( [ 'public' => true ], 'objects' );
		foreach ( $post_types as $post_type ) {
			if ( ! is_object( $post_type ) ) {
				continue;
			}

			// 排除自家 CPT。
			if ( CptRegistrar::POST_TYPE === $post_type->name ) {
				continue;
			}

			$rewrite      = $post_type->rewrite ?? false;
			$rewrite_slug = '';
			if ( is_array( $rewrite ) && isset( $rewrite['slug'] ) ) {
				$rewrite_slug = (string) $rewrite['slug'];
			} elseif ( true === $rewrite ) {
				$rewrite_slug = (string) $post_type->name;
			}

			if ( '' === $rewrite_slug ) {
				continue;
			}

			if ( $rewrite_slug === $slug ) {
				$label = isset( $post_type->labels->singular_name )
				? (string) $post_type->labels->singular_name
				: (string) $post_type->name;
				return [ 0, $label ];
			}
		}

		return null;
	}

	/**
	 * 是否與既有 page slug（post_name）衝突
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return array{int, string}|null
	 */
	public function find_conflicting_page( string $slug ): ?array {
		global $wpdb;

		// 使用 prepare 防 SQL injection。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status IN ('publish', 'draft') LIMIT 1",
				'page',
				$slug
			)
		);

		if ( ! $row ) {
			return null;
		}

		return [ (int) $row->ID, (string) $row->post_title ];
	}

	/**
	 * 是否與其他自訂 rewrite rule 的 prefix 衝突
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return string|null
	 */
	public function find_conflicting_rewrite( string $slug ): ?string {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return null;
		}

		// 蒐集所有可能的 rewrite rules：
		// 1) extra_rules（呼叫 add_rewrite_rule(...,'bottom')）
		// 2) extra_rules_top（呼叫 add_rewrite_rule(...,'top')）
		// 3) flushed rewrite_rules option（已 flush 過的完整 rule set）
		$all_rules = [];
		if ( is_array( $wp_rewrite->extra_rules ?? null ) ) {
			$all_rules += $wp_rewrite->extra_rules;
		}
		if ( is_array( $wp_rewrite->extra_rules_top ?? null ) ) {
			$all_rules += $wp_rewrite->extra_rules_top;
		}
		$flushed = \get_option( 'rewrite_rules', [] );
		if ( is_array( $flushed ) ) {
			$all_rules += $flushed;
		}

		// 比對 rule 的 prefix 是否等於 slug。
		$prefix = '^' . preg_quote( $slug, '#' );
		$plain  = '^' . $slug;
		foreach ( $all_rules as $regex => $_target ) {
			if ( ! is_string( $regex ) ) {
				continue;
			}
			if (
				str_starts_with( $regex, $plain . '/' )
				|| str_starts_with( $regex, $prefix . '/' )
				|| $regex === $plain . '/?$'
				|| $regex === $prefix . '/?$'
				|| $regex === $plain
				|| $regex === $prefix
			) {
				return \__( '自訂 rewrite rule', 'power_shop' );
			}
		}

		return null;
	}
}
