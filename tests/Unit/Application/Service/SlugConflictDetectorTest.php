<?php
/**
 * SlugConflictDetector Service 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 *
 * 五類衝突來源（按嚴重度）：
 *   1. WP 保留字（wp-admin、feed、search、author、category、tag、page 等）
 *   2. WooCommerce 核心 page slugs（shop、cart、checkout、my-account 等）
 *   3. 其他已註冊 CPT rewrite slug
 *   4. 既有 page slugs（撈所有 publish/draft `page` 的 post_name）
 *   5. 其他自訂 rewrite rules 的 prefix
 *
 * Green 階段 master 必須：
 *   - 建立 SlugConflictLookupInterface（提供五類查找方法）
 *   - 將 SlugConflictDetector 重構為可注入該介面（避免單元測試啟 WP）
 *
 * @group profit_shop
 * @group application
 * @group service
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetector;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictLookupInterface;
use PHPUnit\Framework\TestCase;

/**
 * SlugConflictDetector 行為合約測試
 *
 * 預期 master 在 Green 階段建立的 SlugConflictLookupInterface 至少需提供：
 *   - is_wp_reserved( string $slug ): ?string         // 命中時回 label
 *   - is_wc_page_slug( string $slug ): ?string        // 命中時回 label
 *   - find_conflicting_cpt( string $slug ): ?array    // [post_type, label]
 *   - find_conflicting_page( string $slug ): ?array   // [id, label]
 *   - find_conflicting_rewrite( string $slug ): ?string
 */
final class SlugConflictDetectorTest extends TestCase {

	/**
	 * 衝突類型 1：WP 保留字
	 *
	 * @group error
	 */
	public function test_detects_wp_reserved_word_conflict(): void {
		$lookup   = $this->makeLookup( wp_reserved: [ 'feed' => 'WordPress 保留字 feed' ] );
		$detector = new SlugConflictDetector( lookup: $lookup );

		$conflicts = $detector->detect( 'feed', 'profit_shop_slug' );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'wp_reserved', $conflicts[0]->conflict_kind );
	}

	/**
	 * 衝突類型 2：WooCommerce 核心 page slug
	 *
	 * @group error
	 */
	public function test_detects_wc_page_slug_conflict(): void {
		$lookup   = $this->makeLookup( wc_pages: [ 'shop' => 'WooCommerce 商店頁' ] );
		$detector = new SlugConflictDetector( lookup: $lookup );

		$conflicts = $detector->detect( 'shop', 'profit_shop_slug' );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'wc_page', $conflicts[0]->conflict_kind );
		$this->assertSame( 'WooCommerce 商店頁', $conflicts[0]->conflicting_label );
	}

	/**
	 * 衝突類型 3：其他已註冊 CPT rewrite slug
	 *
	 * @group error
	 */
	public function test_detects_cpt_rewrite_conflict(): void {
		$lookup   = $this->makeLookup( cpts: [ 'event' => [ 0, '活動 CPT' ] ] );
		$detector = new SlugConflictDetector( lookup: $lookup );

		$conflicts = $detector->detect( 'event', 'profit_shop_slug' );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'cpt', $conflicts[0]->conflict_kind );
	}

	/**
	 * 衝突類型 4：既有 page slug（post_name）
	 *
	 * @group error
	 */
	public function test_detects_page_slug_conflict(): void {
		$lookup   = $this->makeLookup( pages: [ 'about' => [ 42, '關於我們' ] ] );
		$detector = new SlugConflictDetector( lookup: $lookup );

		$conflicts = $detector->detect( 'about', 'profit_shop_slug' );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'page', $conflicts[0]->conflict_kind );
		$this->assertSame( 42, $conflicts[0]->conflicting_id );
	}

	/**
	 * 衝突類型 5：其他自訂 rewrite rules 的 prefix
	 *
	 * @group error
	 */
	public function test_detects_custom_rewrite_rule_conflict(): void {
		$lookup   = $this->makeLookup( rewrites: [ 'newsletter' => '電子報路徑' ] );
		$detector = new SlugConflictDetector( lookup: $lookup );

		$conflicts = $detector->detect( 'newsletter', 'profit_shop_slug' );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'rewrite', $conflicts[0]->conflict_kind );
	}

	/**
	 * happy：slug 完全乾淨 → 回空陣列
	 *
	 * @group happy
	 */
	public function test_returns_empty_when_no_conflict(): void {
		$lookup   = $this->makeLookup();
		$detector = new SlugConflictDetector( lookup: $lookup );

		$this->assertSame( [], $detector->detect( 'unique-slug', 'profit_shop_slug' ) );
	}

	/**
	 * 衝突類型 6（BUG-1 補洞）：既有 powershop CPT slug
	 *
	 * @group error
	 * @group bug_1
	 */
	public function test_detects_existing_powershop_slug_conflict(): void {
		$lookup   = $this->makeLookup( powershops: [ 'summer-sale' => [ 123, 'Profit Shop 賣場「夏季活動」' ] ] );
		$detector = new SlugConflictDetector( lookup: $lookup );

		$conflicts = $detector->detect( 'summer-sale', 'profit_shop_slug' );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'powershop', $conflicts[0]->conflict_kind );
		$this->assertSame( 123, $conflicts[0]->conflicting_id );
		$this->assertSame( 'Profit Shop 賣場「夏季活動」', $conflicts[0]->conflicting_label );
	}

	/**
	 * 衝突類型 7（BUG-1 副作用補洞）：既有 profit_partner term slug
	 *
	 * @group error
	 * @group bug_1
	 */
	public function test_detects_existing_profit_partner_term_slug_conflict(): void {
		$lookup   = $this->makeLookup( partner_terms: [ 'jerry' => [ 7, '分潤夥伴「Jerry」' ] ] );
		$detector = new SlugConflictDetector( lookup: $lookup );

		$conflicts = $detector->detect( 'jerry', 'profit_shop_slug' );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'profit_partner', $conflicts[0]->conflict_kind );
		$this->assertSame( 7, $conflicts[0]->conflicting_id );
	}

	/**
	 * 動態建立 SlugConflictLookup 替身（avoid fatal during file include）
	 *
	 * @param array<string, string>             $wp_reserved   slug => label
	 * @param array<string, string>             $wc_pages      slug => label
	 * @param array<string, array{int, string}> $cpts          slug => [post_type_id_placeholder, label]
	 * @param array<string, array{int, string}> $pages         slug => [post_id, label]
	 * @param array<string, string>             $rewrites      slug => label
	 * @param array<string, array{int, string}> $powershops    slug => [post_id, label]（BUG-1 補洞）
	 * @param array<string, array{int, string}> $partner_terms slug => [term_id, label]（BUG-1 副作用補洞）
	 */
	private function makeLookup(
		array $wp_reserved = [],
		array $wc_pages = [],
		array $cpts = [],
		array $pages = [],
		array $rewrites = [],
		array $powershops = [],
		array $partner_terms = []
	): SlugConflictLookupInterface {
		return new class( $wp_reserved, $wc_pages, $cpts, $pages, $rewrites, $powershops, $partner_terms ) implements SlugConflictLookupInterface {

			public function __construct(
				private readonly array $wp_reserved,
				private readonly array $wc_pages,
				private readonly array $cpts,
				private readonly array $pages,
				private readonly array $rewrites,
				private readonly array $powershops,
				private readonly array $partner_terms
			) {}

			public function is_wp_reserved( string $slug ): ?string {
				return $this->wp_reserved[ $slug ] ?? null;
			}

			public function is_wc_page_slug( string $slug ): ?string {
				return $this->wc_pages[ $slug ] ?? null;
			}

			public function find_conflicting_cpt( string $slug ): ?array {
				return $this->cpts[ $slug ] ?? null;
			}

			public function find_conflicting_page( string $slug ): ?array {
				return $this->pages[ $slug ] ?? null;
			}

			public function find_conflicting_rewrite( string $slug ): ?string {
				return $this->rewrites[ $slug ] ?? null;
			}

			public function find_conflicting_powershop_slug( string $slug ): ?array {
				return $this->powershops[ $slug ] ?? null;
			}

			public function find_conflicting_partner_term_slug( string $slug ): ?array {
				return $this->partner_terms[ $slug ] ?? null;
			}
		};
	}
}
