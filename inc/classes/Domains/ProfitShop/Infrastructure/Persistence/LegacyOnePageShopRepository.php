<?php
/**
 * 舊版「一頁商店」資料源 WP 實作
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Application\Service\LegacyShopRepositoryInterface;

/**
 * 從 'power-shop' CPT（舊版）讀取資料的 LegacyShopRepository 實作
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * 與新版 'powershop' CPT 區分：
 * - 舊版（legacy）：post_type = 'power-shop'（含連字號）
 * - 新版：post_type = 'powershop'（無連字號）
 */
final class LegacyOnePageShopRepository implements LegacyShopRepositoryInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 舊版 CPT 名稱
	 */
	public const LEGACY_POST_TYPE = 'power-shop';

	/**
	 * 列出所有可匯入的舊版一頁商店
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$posts = \get_posts(
			[
				'post_type'      => self::LEGACY_POST_TYPE,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		$out = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[] = $this->hydrate_from_post( $post );
		}
		return $out;
	}

	/**
	 * 依 ID 取得單筆舊版一頁商店
	 *
	 * @param int $id 舊版資料 ID
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( self::LEGACY_POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $this->hydrate_from_post( $post );
	}

	/**
	 * 從 WP_Post + post_meta 重建舊版資料 array
	 *
	 * @param \WP_Post $post 來源 post
	 *
	 * @return array<string, mixed>
	 */
	private function hydrate_from_post( \WP_Post $post ): array {
		$meta = \get_post_meta( $post->ID );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		// post_meta 預設為 array of array；攤平為單值。
		$flat = [];
		foreach ( $meta as $key => $values ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( is_array( $values ) && isset( $values[0] ) ) {
				$flat[ $key ] = $values[0];
			} else {
				$flat[ $key ] = $values;
			}
		}

		return [
			'id'    => (int) $post->ID,
			'title' => (string) $post->post_title,
			'slug'  => (string) $post->post_name,
			'meta'  => $flat,
		];
	}
}
