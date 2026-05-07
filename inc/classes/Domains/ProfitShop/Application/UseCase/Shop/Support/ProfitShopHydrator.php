<?php
/**
 * ProfitShop Input/Output Hydrator（UseCase 共用 helper）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidShopMode;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;

/**
 * 將 ProfitShopInput 轉成 ProfitShop 聚合根、ProfitShop 轉成 ProfitShopOutput 的共用工具
 *
 * 集中 Input/Output ↔ Entity 的對映邏輯，避免散落於每個 UseCase。
 */
final class ProfitShopHydrator {

	/**
	 * 從 ProfitShopInput 建立 ProfitShop 聚合根
	 *
	 * @param int             $id    要使用的 ID（建立時為 0）
	 * @param ProfitShopInput $input 輸入 DTO
	 *
	 * @return ProfitShop
	 */
	public static function from_input( int $id, ProfitShopInput $input ): ProfitShop {
		$mode = ShopMode::tryFrom( $input->mode );
		if ( null === $mode ) {
			// 非法 mode 字串應該 fail-fast 而非靜默改成 page，避免 admin 設定漂移
			throw new InvalidShopMode( "invalid_shop_mode:{$input->mode}" );
		}

		$items = [];
		foreach ( $input->items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = self::build_override_item( $row );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return new ProfitShop(
			id: $id,
			title: $input->title,
			slug: $input->slug,
			status: $input->status,
			mode: $mode,
			partner_term_id: $input->partner_term_id,
			rate: new ProfitRate( $input->rate ),
			items: $items,
			settings: $input->settings
		);
	}

	/**
	 * 將 ProfitShop 聚合根序列化為 ProfitShopOutput
	 *
	 * @param ProfitShop $shop       賣場聚合根
	 * @param string     $created_at 建立時間（可由 Repository 提供，預設空字串）
	 * @param string     $updated_at 最後更新時間（同上）
	 *
	 * @return ProfitShopOutput
	 */
	public static function to_output(
		ProfitShop $shop,
		string $created_at = '',
		string $updated_at = ''
	): ProfitShopOutput {
		$items = [];
		foreach ( $shop->items() as $item ) {
			$items[] = $item->to_array();
		}

		return new ProfitShopOutput(
			id: $shop->id,
			title: $shop->title,
			slug: $shop->slug,
			status: $shop->status(),
			mode: $shop->mode->value,
			partner_term_id: $shop->partner_term_id,
			rate: $shop->rate->value(),
			items: $items,
			settings: $shop->settings,
			created_at: $created_at,
			updated_at: $updated_at
		);
	}

	/**
	 * 從 array 建立 OverrideItem
	 *
	 * @param array<string, mixed> $row 單筆原始資料
	 *
	 * @return OverrideItem|null 不合法時回 null（caller 應跳過）
	 */
	private static function build_override_item( array $row ): ?OverrideItem {
		$product_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		if ( $product_id <= 0 ) {
			return null;
		}

		$override_payload = is_array( $row['override'] ?? null ) ? $row['override'] : [];
		$override         = self::build_price_override( $override_payload );

		$inflated_raw   = isset( $row['inflated_count'] ) ? (int) $row['inflated_count'] : 0;
		$inflated_count = new InflatedCount( $inflated_raw );

		$variations = [];
		$v_payload  = is_array( $row['variations'] ?? null ) ? $row['variations'] : [];
		foreach ( $v_payload as $vid => $v_row ) {
			$vid_int = (int) $vid;
			if ( $vid_int <= 0 || ! is_array( $v_row ) ) {
				continue;
			}
			$v_override            = is_array( $v_row['override'] ?? null ) ? $v_row['override'] : [];
			$variations[ $vid_int ] = self::build_price_override( $v_override );
		}

		return new OverrideItem(
			product_id: $product_id,
			override: $override,
			inflated_count: $inflated_count,
			variations: $variations
		);
	}

	/**
	 * 從 array 建立 PriceOverride
	 *
	 * @param array<string, mixed> $payload override 陣列
	 *
	 * @return PriceOverride
	 */
	private static function build_price_override( array $payload ): PriceOverride {
		$regular = self::nullable_string( $payload['regular_price'] ?? null );
		$sale    = self::nullable_string( $payload['sale_price'] ?? null );
		$signup  = self::nullable_string( $payload['signup_fee'] ?? null );
		return new PriceOverride( $regular, $sale, $signup );
	}

	/**
	 * 任意值 → nullable string；空字串視為 null
	 *
	 * @param mixed $value 來源值
	 *
	 * @return string|null
	 */
	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}
		$str = (string) $value;
		return '' === $str ? null : $str;
	}
}
