<?php
/**
 * Slug 衝突偵測 Service（Phase 3-A 骨架）
 *
 * @phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;

/**
 * 偵測賣場 slug 是否與既有資源衝突
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.1
 *
 * 預定責任（Phase 3-B 實作）：
 * - 檢查既有 ProfitShop CPT 的 post_name
 * - 檢查 WC product 的 post_name
 * - 檢查 WP page 的 post_name
 * - 回傳 SlugConflict[]（空陣列代表無衝突）
 *
 * Phase 3-A 僅交付骨架；method body 拋 BadMethodCallException。
 */
final class SlugConflictDetector {

	/**
	 * 偵測指定 slug 在指定 context 下的衝突
	 *
	 * @param string $slug    待檢查 slug
	 * @param string $context 檢查情境（machine code）
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return SlugConflict[]
	 */
	public function detect( string $slug, string $context ): array {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-B' );
	}
}
