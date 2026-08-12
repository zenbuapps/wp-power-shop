<?php
/**
 * Slug 衝突偵測介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;

/**
 * Slug 衝突偵測抽象
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 *
 * UseCase 透過此介面注入 SlugConflictDetector，方便單元測試以 fake 替換。
 */
interface SlugConflictDetectorInterface {

	/**
	 * 偵測指定 slug 在指定 context 下的衝突
	 *
	 * @param string $slug    待檢查 slug
	 * @param string $context 檢查情境（machine code）
	 *
	 * @return SlugConflict[] 空陣列代表無衝突
	 */
	public function detect( string $slug, string $context ): array;
}
