<?php
/**
 * 商品覆寫項目（OverrideItem 子實體）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Entity;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;

/**
 * 一個分潤賣場內的單一商品覆寫設定
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.3
 *
 * 欄位：
 * - product_id：商品 ID（不可變）
 * - override：商品自身價格覆寫（可變）
 * - inflated_count：灌水銷量（可變）
 * - variations：variation_id => PriceOverride 的對照（可變）
 */
final class OverrideItem {

	/**
	 * Variation 覆寫對照表
	 *
	 * @var array<int, PriceOverride>
	 */
	public array $variations;

	/**
	 * 建構子
	 *
	 * @param int                       $product_id     商品 ID
	 * @param PriceOverride             $override       商品自身的價格覆寫
	 * @param InflatedCount             $inflated_count 灌水銷量
	 * @param array<int, PriceOverride> $variations     variation_id => PriceOverride 對照表
	 */
	public function __construct(
		public readonly int $product_id,
		public PriceOverride $override,
		public InflatedCount $inflated_count,
		array $variations = []
	) {
		$this->variations = $variations;
	}

	/**
	 * 設定指定 variation 的價格覆寫；既有則覆寫
	 *
	 * @param int           $variation_id Variation 商品 ID
	 * @param PriceOverride $override     價格覆寫
	 *
	 * @return void
	 */
	public function set_variation_override( int $variation_id, PriceOverride $override ): void {
		$this->variations[ $variation_id ] = $override;
	}

	/**
	 * 移除指定 variation 的價格覆寫；不存在時靜默略過
	 *
	 * @param int $variation_id Variation 商品 ID
	 *
	 * @return void
	 */
	public function remove_variation_override( int $variation_id ): void {
		unset( $this->variations[ $variation_id ] );
	}

	/**
	 * 取得指定 variation 的價格覆寫；不存在時回傳 null
	 *
	 * @param int $variation_id Variation 商品 ID
	 *
	 * @return PriceOverride|null
	 */
	public function get_variation_override( int $variation_id ): ?PriceOverride {
		return $this->variations[ $variation_id ] ?? null;
	}

	/**
	 * 序列化為陣列（符合 spec §2.3 結構）
	 *
	 * @return array{
	 *   product_id: int,
	 *   inflated_count: int,
	 *   override: array{regular_price: string|null, sale_price: string|null, signup_fee: string|null},
	 *   variations: array<int, array{override: array{regular_price: string|null, sale_price: string|null, signup_fee: string|null}}>
	 * }
	 */
	public function to_array(): array {
		$variations = [];
		foreach ( $this->variations as $vid => $override ) {
			$variations[ $vid ] = [ 'override' => $override->to_array() ];
		}

		return [
			'product_id'     => $this->product_id,
			'inflated_count' => $this->inflated_count->value(),
			'override'       => $this->override->to_array(),
			'variations'     => $variations,
		];
	}
}
