<?php
/**
 * 分潤賣場 Repository（CPT 實作）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;

/**
 * 以 powershop CPT + post_meta 持久化分潤賣場聚合根
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.2、§2.3、§10
 *
 * post_meta 欄位（spec §2.2）：
 * - _profit_shop_mode
 * - _profit_partner_term_id
 * - _profit_rate
 * - _profit_shop_items（JSON 字串）
 * - _profit_shop_settings（JSON 字串）
 */
final class CptProfitShopRepository implements ProfitShopRepositoryInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Post meta keys
	 */
	private const META_MODE      = '_profit_shop_mode';
	private const META_PARTNER   = '_profit_partner_term_id';
	private const META_RATE      = '_profit_rate';
	private const META_ITEMS     = '_profit_shop_items';
	private const META_SETTINGS  = '_profit_shop_settings';

	/**
	 * 依 ID 取得賣場
	 *
	 * @param int $id 賣場 ID
	 *
	 * @return ProfitShop|null 找不到或非 powershop CPT 時回傳 null
	 */
	public function find( int $id ): ?ProfitShop {
		$post = \get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( CptRegistrar::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $this->hydrate_from_post( $post );
	}

	/**
	 * 儲存賣場（新增或更新）
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 *
	 * @return int 賣場 ID
	 *
	 * @throws \RuntimeException 當 wp_insert_post / wp_update_post 失敗時拋出
	 */
	public function save( ProfitShop $shop ): int {
		$post_data = [
			'post_type'    => CptRegistrar::POST_TYPE,
			'post_title'   => $shop->title,
			'post_name'    => $shop->slug,
			'post_status'  => $shop->status,
		];

		if ( 0 === $shop->id ) {
			$post_id = \wp_insert_post( $post_data, true );
		} else {
			$post_data['ID'] = $shop->id;
			$post_id         = \wp_update_post( $post_data, true );
		}

		if ( \is_wp_error( $post_id ) ) {
			throw new \RuntimeException(
				'儲存分潤賣場失敗：' . $post_id->get_error_message()
			);
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			throw new \RuntimeException( '儲存分潤賣場失敗：未取得有效 post id' );
		}

		$this->persist_meta( $post_id, $shop );

		return $post_id;
	}

	/**
	 * 刪除賣場（trash，不真刪）
	 *
	 * @param int $id 賣場 ID
	 *
	 * @return void
	 */
	public function delete( int $id ): void {
		\wp_trash_post( $id );
	}

	/**
	 * 找出某 partner 旗下的所有賣場
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return ProfitShop[]
	 */
	public function find_by_partner( int $term_id ): array {
		$query = new \WP_Query(
			[
				'post_type'      => CptRegistrar::POST_TYPE,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDB.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'     => self::META_PARTNER,
						'value'   => $term_id,
						'compare' => '=',
					],
				],
			]
		);

		$shops = [];
		foreach ( $query->posts as $post_id ) {
			$shop = $this->find( (int) $post_id );
			if ( null !== $shop ) {
				$shops[] = $shop;
			}
		}

		return $shops;
	}

	/**
	 * 從 WP_Post + post_meta 重建 ProfitShop 聚合根
	 *
	 * @param \WP_Post $post 來源 post
	 *
	 * @return ProfitShop
	 */
	private function hydrate_from_post( \WP_Post $post ): ProfitShop {
		$mode_raw      = (string) \get_post_meta( $post->ID, self::META_MODE, true );
		$partner_id    = (int) \get_post_meta( $post->ID, self::META_PARTNER, true );
		$rate_raw      = (int) \get_post_meta( $post->ID, self::META_RATE, true );
		$items_raw     = (string) \get_post_meta( $post->ID, self::META_ITEMS, true );
		$settings_raw  = (string) \get_post_meta( $post->ID, self::META_SETTINGS, true );

		$mode = ShopMode::tryFrom( $mode_raw ) ?? ShopMode::PAGE;

		// rate 容錯：0-100 範圍外退回 0，避免重建聚合根失敗。
		$rate_value = ( $rate_raw < 0 || $rate_raw > 100 ) ? 0 : $rate_raw;

		$items    = $this->decode_items( $items_raw );
		$settings = $this->decode_settings( $settings_raw );

		return new ProfitShop(
			id: $post->ID,
			title: $post->post_title,
			slug: $post->post_name,
			status: $post->post_status,
			mode: $mode,
			partner_term_id: $partner_id,
			rate: new ProfitRate( $rate_value ),
			items: $items,
			settings: $settings
		);
	}

	/**
	 * 將聚合根的 5 個 meta 寫回 post_meta
	 *
	 * @param int        $post_id post id
	 * @param ProfitShop $shop    賣場聚合根
	 *
	 * @return void
	 */
	private function persist_meta( int $post_id, ProfitShop $shop ): void {
		\update_post_meta( $post_id, self::META_MODE, $shop->mode->value );
		\update_post_meta( $post_id, self::META_PARTNER, $shop->partner_term_id );
		\update_post_meta( $post_id, self::META_RATE, $shop->rate->value() );

		$items_payload = array_map(
			static fn( OverrideItem $item ): array => $item->to_array(),
			$shop->items()
		);

		// wp_slash 防止 update_post_meta stripslash 後反序列化失敗。
		$items_json    = (string) \wp_json_encode( $items_payload );
		$settings_json = (string) \wp_json_encode( $shop->settings );

		\update_post_meta( $post_id, self::META_ITEMS, \wp_slash( $items_json ) );
		\update_post_meta( $post_id, self::META_SETTINGS, \wp_slash( $settings_json ) );
	}

	/**
	 * 將 _profit_shop_items JSON 字串反序列化為 OverrideItem 陣列
	 *
	 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.3
	 *
	 * @param string $json JSON 字串（可能為空字串）
	 *
	 * @return OverrideItem[]
	 */
	private function decode_items( string $json ): array {
		if ( '' === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$items = [];
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = $this->build_override_item( $row );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * 從 array 重建 OverrideItem
	 *
	 * @param array<string, mixed> $row 單筆商品 array（已 json_decode）
	 *
	 * @return OverrideItem|null 資料不合法時回傳 null（caller 應跳過）
	 */
	private function build_override_item( array $row ): ?OverrideItem {
		$product_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		if ( $product_id <= 0 ) {
			return null;
		}

		$override_payload = is_array( $row['override'] ?? null ) ? $row['override'] : [];
		$override         = $this->build_price_override( $override_payload );

		$inflated_raw   = isset( $row['inflated_count'] ) ? (int) $row['inflated_count'] : 0;
		$inflated_count = new InflatedCount( $inflated_raw );

		$variations         = [];
		$variations_payload = is_array( $row['variations'] ?? null ) ? $row['variations'] : [];

		foreach ( $variations_payload as $variation_id => $variation_row ) {
			if ( ! is_array( $variation_row ) ) {
				continue;
			}

			$vid = (int) $variation_id;
			if ( $vid <= 0 ) {
				continue;
			}

			$v_override_payload = is_array( $variation_row['override'] ?? null )
			? $variation_row['override']
			: [];

			$variations[ $vid ] = $this->build_price_override( $v_override_payload );
		}

		return new OverrideItem(
			product_id: $product_id,
			override: $override,
			inflated_count: $inflated_count,
			variations: $variations
		);
	}

	/**
	 * 從 array 重建 PriceOverride
	 *
	 * @param array<string, mixed> $payload override 陣列（regular_price/sale_price/signup_fee）
	 *
	 * @return PriceOverride
	 */
	private function build_price_override( array $payload ): PriceOverride {
		$regular_price = $this->normalize_nullable_string( $payload['regular_price'] ?? null );
		$sale_price    = $this->normalize_nullable_string( $payload['sale_price'] ?? null );
		$signup_fee    = $this->normalize_nullable_string( $payload['signup_fee'] ?? null );

		return new PriceOverride( $regular_price, $sale_price, $signup_fee );
	}

	/**
	 * 將任意值正規化為 nullable string；空字串視為 null
	 *
	 * @param mixed $value 來源值
	 *
	 * @return string|null
	 */
	private function normalize_nullable_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}
		$str = (string) $value;
		return '' === $str ? null : $str;
	}

	/**
	 * 將 _profit_shop_settings JSON 字串反序列化為 array
	 *
	 * @param string $json JSON 字串（可能為空字串）
	 *
	 * @return array<string, mixed>
	 */
	private function decode_settings( string $json ): array {
		if ( '' === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
