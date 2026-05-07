<?php
/**
 * 商品快照提供者 Service（Phase 3-A 骨架）
 *
 * @phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\ProductSnapshot;

/**
 * 從 WC 端取得商品快照（含 variation）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §8
 *
 * 預定責任（Phase 3-B 實作）：
 * - 接 wc_get_product()
 * - 將 WC_Product / WC_Product_Variation 映射為 Domain Snapshot
 * - 隔離 Domain Service 不依賴 WC
 *
 * Phase 3-A 僅交付骨架；method body 拋 BadMethodCallException。
 */
final class ProductSnapshotProvider {

	/**
	 * 取得指定商品（含可選 variation）的快照
	 *
	 * @param int      $product_id   商品 ID
	 * @param int|null $variation_id Variation ID（可為 null）
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return ProductSnapshot
	 */
	public function snapshot_for( int $product_id, ?int $variation_id ): ProductSnapshot {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-B' );
	}
}
