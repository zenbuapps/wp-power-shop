<?php
/**
 * Slug 衝突查找介面（spec §6.11 五類）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Slug 衝突查找抽象
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 *
 * 五類衝突來源：
 * 1. WordPress 保留字
 * 2. WooCommerce 核心 page slugs
 * 3. 其他已註冊 CPT rewrite slug
 * 4. 既有 page slugs
 * 5. 自訂 rewrite rules prefix
 *
 * 命中時回傳人類可讀 label（或含 id 的對照陣列），無命中時回 null。
 */
interface SlugConflictLookupInterface {

	/**
	 * 是否為 WordPress 保留字
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return string|null 命中時回 label，否則回 null
	 */
	public function is_wp_reserved( string $slug ): ?string;

	/**
	 * 是否為 WooCommerce 核心 page slug（shop / cart / checkout / my-account ...）
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return string|null 命中時回 label，否則回 null
	 */
	public function is_wc_page_slug( string $slug ): ?string;

	/**
	 * 是否與其他已註冊 CPT 的 rewrite slug 衝突
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return array{int, string}|null 命中時回 [post_type_or_id_placeholder, label]，否則回 null
	 */
	public function find_conflicting_cpt( string $slug ): ?array;

	/**
	 * 是否與既有 page slug（post_name）衝突
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return array{int, string}|null 命中時回 [post_id, label]，否則回 null
	 */
	public function find_conflicting_page( string $slug ): ?array;

	/**
	 * 是否與其他自訂 rewrite rule 的 prefix 衝突
	 *
	 * @param string $slug 候選 slug
	 *
	 * @return string|null 命中時回 label，否則回 null
	 */
	public function find_conflicting_rewrite( string $slug ): ?string;
}
