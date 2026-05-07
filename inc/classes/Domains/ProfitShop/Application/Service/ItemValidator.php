<?php
/**
 * 商品項目驗證 Service（Phase 3-A 骨架）
 *
 * @phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * 驗證 ProfitShopInput::$items 的內容是否合法
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.1
 *
 * 預定責任（Phase 3-B 實作）：
 * - 商品存在性檢查（call ProductRepository）
 * - variation 歸屬檢查（variation_id 必須屬於 product_id）
 * - 價格格式合法性
 *
 * Phase 3-A 僅交付骨架；method body 拋 BadMethodCallException。
 */
final class ItemValidator {

	/**
	 * 驗證商品項目陣列
	 *
	 * @param array<int, array<string, mixed>> $items 商品覆寫項目原始陣列
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return void
	 */
	public function validate( array $items ): void {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-B' );
	}
}
