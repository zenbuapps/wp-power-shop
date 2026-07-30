<?php
/**
 * 商品覆寫項目（OverrideItem 子實體）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Entity;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\VariationOverride;

/**
 * 一個分潤賣場內的單一商品覆寫設定
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.3
 *
 * 欄位：
 * - product_id：商品 ID（不可變）
 * - override：商品自身價格覆寫（可變）
 * - inflated_count：灌水銷量（可變）
 * - variations：variation_id => VariationOverride 的對照（可變）
 *
 * variations 的 value 自 Phase 9 起由 PriceOverride 升級為 VariationOverride
 * （= PriceOverride + InflatedCount），讓變體也能各自設定灌水銷量。
 * get_variation_override() 的簽章刻意維持回傳 ?PriceOverride 並改為委派，
 * 使 PriceCalculator / CartPriceOverrideHook 等既有取價路徑零改動。
 */
final class OverrideItem {

	/**
	 * Variation 覆寫對照表
	 *
	 * @var array<int, VariationOverride>
	 */
	public array $variations;

	/**
	 * 建構子
	 *
	 * @param int                           $product_id     商品 ID
	 * @param PriceOverride                 $override       商品自身的價格覆寫
	 * @param InflatedCount                 $inflated_count 灌水銷量
	 * @param array<int, VariationOverride> $variations     variation_id => VariationOverride 對照表
	 *
	 * @throws \InvalidArgumentException 當 $variations 內含非 VariationOverride 的值時拋出
	 */
	public function __construct(
		public readonly int $product_id,
		public PriceOverride $override,
		public InflatedCount $inflated_count,
		array $variations = []
	) {
		// PHP 無法對 array 的 value 型別做原生檢查。若讓 PriceOverride（升級前的舊型別）
		// 混進來，get_variation_override() 取 ->override 只會得到 null + 一則 Warning，
		// 結果是變體覆寫價「靜默失效退回原價」＝商家損失。故在此 fail-fast。
		$this->variations = [];
		foreach ( $variations as $variation_id => $variation ) {
			// @phpstan-ignore-next-line 執行期防護：docblock 型別無法在 runtime 強制
			if ( ! $variation instanceof VariationOverride ) {
				throw new \InvalidArgumentException(
					sprintf(
						'OverrideItem::$variations 的每個值都必須是 VariationOverride，variation_id %s 收到 %s',
						(string) $variation_id,
						get_debug_type( $variation )
					)
				);
			}
			$this->variations[ (int) $variation_id ] = $variation;
		}
	}

	/**
	 * 設定指定 variation 的價格覆寫；既有則覆寫
	 *
	 * 第三參數為 null 時保留該 variation 既有的灌水銷量（不存在則以 0 建立），
	 * 讓既有 2 參數呼叫端維持原本語意。
	 *
	 * @param int                $variation_id   Variation 商品 ID
	 * @param PriceOverride      $override       價格覆寫
	 * @param InflatedCount|null $inflated_count 灌水銷量；null 表示沿用既有值
	 *
	 * @return void
	 */
	public function set_variation_override(
		int $variation_id,
		PriceOverride $override,
		?InflatedCount $inflated_count = null
	): void {
		$resolved_count = $inflated_count
		?? ( $this->variations[ $variation_id ]->inflated_count ?? new InflatedCount( 0 ) );

		$this->variations[ $variation_id ] = new VariationOverride( $override, $resolved_count );
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
	 * 簽章刻意維持回傳 ?PriceOverride（而非 ?VariationOverride）：PriceCalculator 與
	 * CartPriceOverrideHook 都只需要價格，維持原簽章可讓兩者零改動。
	 *
	 * @param int $variation_id Variation 商品 ID
	 *
	 * @return PriceOverride|null
	 */
	public function get_variation_override( int $variation_id ): ?PriceOverride {
		return ( $this->variations[ $variation_id ] ?? null )?->override;
	}

	/**
	 * 取得指定 variation 的灌水銷量；不存在時回傳 null
	 *
	 * @param int $variation_id Variation 商品 ID
	 *
	 * @return InflatedCount|null
	 */
	public function get_variation_inflated_count( int $variation_id ): ?InflatedCount {
		return ( $this->variations[ $variation_id ] ?? null )?->inflated_count;
	}

	/**
	 * 序列化為陣列（符合 spec §2.3 結構）
	 *
	 * @return array{
	 *   product_id: int,
	 *   inflated_count: int,
	 *   override: array{regular_price: string|null, sale_price: string|null, signup_fee: string|null},
	 *   variations: array<int, array{override: array{regular_price: string|null, sale_price: string|null, signup_fee: string|null}, inflated_count: int}>
	 * }
	 */
	public function to_array(): array {
		$variations = [];
		foreach ( $this->variations as $vid => $variation ) {
			$variations[ $vid ] = $variation->to_array();
		}

		return [
			'product_id'     => $this->product_id,
			'inflated_count' => $this->inflated_count->value(),
			'override'       => $this->override->to_array(),
			'variations'     => $variations,
		];
	}
}
