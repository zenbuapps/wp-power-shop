<?php
/**
 * Slug 衝突偵測 Service
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;

/**
 * 偵測賣場 slug 是否與既有資源衝突
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 *
 * 透過 SlugConflictLookupInterface 解耦 WP / WC 依賴，
 * 單元測試可注入 anonymous class implements SlugConflictLookupInterface 模擬五類衝突。
 *
 * Test 對應：
 *   tests/Unit/Application/Service/SlugConflictDetectorTest.php
 */
final class SlugConflictDetector implements SlugConflictDetectorInterface {

	/**
	 * 建構子
	 *
	 * 採 SlugConflictLookupInterface 注入（型別安全）：
	 *   - 生產實作：WpSlugConflictLookup（呼叫 WP / WC 函式）
	 *   - 單元測試：anonymous class implements SlugConflictLookupInterface
	 *
	 * @param SlugConflictLookupInterface $lookup Slug 衝突查找抽象
	 */
	public function __construct(
		private readonly SlugConflictLookupInterface $lookup
	) {}

	/**
	 * 偵測指定 slug 在指定 context 下的衝突
	 *
	 * @param string $slug    待檢查 slug
	 * @param string $context 檢查情境（machine code，例如 'profit_shop_slug'）
	 *
	 * @return SlugConflict[] 空陣列代表無衝突
	 */
	public function detect( string $slug, string $context ): array {
		$conflicts = [];

		$wp_label = $this->lookup->is_wp_reserved( $slug );
		if ( null !== $wp_label ) {
			$conflicts[] = new SlugConflict(
				conflict_kind: 'wp_reserved',
				conflicting_slug: $slug,
				conflicting_id: null,
				conflicting_label: $wp_label
			);
		}

		$wc_label = $this->lookup->is_wc_page_slug( $slug );
		if ( null !== $wc_label ) {
			$conflicts[] = new SlugConflict(
				conflict_kind: 'wc_page',
				conflicting_slug: $slug,
				conflicting_id: null,
				conflicting_label: $wc_label
			);
		}

		$cpt = $this->lookup->find_conflicting_cpt( $slug );
		if ( null !== $cpt ) {
			$conflicts[] = new SlugConflict(
				conflict_kind: 'cpt',
				conflicting_slug: $slug,
				conflicting_id: (int) ( $cpt[0] ?? 0 ),
				conflicting_label: (string) ( $cpt[1] ?? '' )
			);
		}

		$page = $this->lookup->find_conflicting_page( $slug );
		if ( null !== $page ) {
			$conflicts[] = new SlugConflict(
				conflict_kind: 'page',
				conflicting_slug: $slug,
				conflicting_id: (int) ( $page[0] ?? 0 ),
				conflicting_label: (string) ( $page[1] ?? '' )
			);
		}

		$rewrite = $this->lookup->find_conflicting_rewrite( $slug );
		if ( null !== $rewrite ) {
			$conflicts[] = new SlugConflict(
				conflict_kind: 'rewrite',
				conflicting_slug: $slug,
				conflicting_id: null,
				conflicting_label: $rewrite
			);
		}

		// BUG-1 補洞：既有 powershop CPT slug（spec §6.11 第 3 類）
		$powershop = $this->lookup->find_conflicting_powershop_slug( $slug );
		if ( null !== $powershop ) {
			$conflicts[] = new SlugConflict(
				conflict_kind: 'powershop',
				conflicting_slug: $slug,
				conflicting_id: (int) ( $powershop[0] ?? 0 ),
				conflicting_label: (string) ( $powershop[1] ?? '' )
			);
		}

		// BUG-1 副作用補洞：既有 profit_partner term slug（spec §6.11 第 4 類）
		$partner = $this->lookup->find_conflicting_partner_term_slug( $slug );
		if ( null !== $partner ) {
			$conflicts[] = new SlugConflict(
				conflict_kind: 'profit_partner',
				conflicting_slug: $slug,
				conflicting_id: (int) ( $partner[0] ?? 0 ),
				conflicting_label: (string) ( $partner[1] ?? '' )
			);
		}

		return $conflicts;
	}
}
