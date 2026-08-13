<?php
/**
 * ImportLegacyShop UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7（POST profit-migration/import）
 *
 * 重要規則（OQ-4 自決）：
 *   舊版「一頁商店」沒有 partner 概念，匯入時必須由 admin 指定 partner_term_id；
 *   缺 partner_term_id → 拋 LegacyShopNotImportable( reason='partner_term_missing' )。
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group migration
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Migration;

use J7\PowerShop\Domains\ProfitShop\Application\Service\ProductLookupInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\ImportLegacyShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\LegacyShopNotImportable;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryLegacyShopRepository;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;
use Tests\Unit\Application\Fakes\InMemoryProfitShopRepository;

/**
 * ImportLegacyShop UseCase 測試
 */
final class ImportLegacyShopTest extends TestCase {

	private InMemoryLegacyShopRepository $legacyRepo;
	private InMemoryProfitShopRepository $shopRepo;
	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->legacyRepo  = new InMemoryLegacyShopRepository();
		$this->shopRepo    = new InMemoryProfitShopRepository();
		$this->partnerRepo = new InMemoryPartnerRepository();
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry' );

		$this->legacyRepo->seed(
			[
				'id'    => 11,
				'title' => '舊版賣場 A',
				'meta'  => [
					'banner_url' => 'https://example.com/a.jpg',
					'btn_color'  => '#1677ff',
					'items'      => [ [ 'product_id' => 100, 'price' => '999' ] ],
				],
			]
		);
	}

	/**
	 * happy：legacy 存在 + partner_term_id 合法 → 建立新賣場
	 *
	 * @group happy
	 */
	public function test_imports_legacy_shop_with_valid_partner(): void {
		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		$output = $useCase->execute( legacy_id: 11, partner_term_id: 5 );

		$this->assertSame( 5, $output->partner_term_id );
		$this->assertSame( 'page', $output->mode, 'spec §4.7：匯入後預設 mode=page' );
		$this->assertSame(
			1,
			$this->shopRepo->count(),
			'spec §4.7：不刪除舊版，只新增'
		);
	}

	/**
	 * error：缺 partner_term_id → 拋 LegacyShopNotImportable
	 *
	 * @group error
	 */
	public function test_throws_legacy_shop_not_importable_when_partner_term_id_missing(): void {
		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		try {
			$useCase->execute( legacy_id: 11, partner_term_id: 0 );
			$this->fail( '預期拋出 LegacyShopNotImportable' );
		} catch ( LegacyShopNotImportable $e ) {
			$this->assertSame( 'partner_term_missing', $e->getReason() );
		}
	}

	/**
	 * error：legacy id 不存在 → 拋 LegacyShopNotImportable( reason='legacy_not_found' )
	 *
	 * @group error
	 */
	public function test_throws_legacy_shop_not_importable_when_legacy_id_invalid(): void {
		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		$this->expectException( LegacyShopNotImportable::class );
		$useCase->execute( legacy_id: 9999, partner_term_id: 5 );
	}

	/**
	 * error：partner_term_id 指向不存在的 partner → 拋 PartnerNotFound
	 *
	 * @group error
	 */
	public function test_throws_partner_not_found_when_partner_term_id_invalid(): void {
		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( legacy_id: 11, partner_term_id: 9999 );
	}

	// ====================================================================
	//  Rule: 商品必須被搬過去（本功能上線後最久的一個缺陷）
	// ====================================================================

	/**
	 * 預植入一筆「帶真實 legacy 商品資料」的舊版賣場
	 *
	 * ⚠️ 這個 fixture 是本檔案先前**缺少**的東西。原本的 setUp 只放了假的
	 * `meta.items` key（legacy 根本不用這個 key），所以「商品有沒有被搬過去」
	 * 這件事完全沒有被任何測試碰到——items 寫死成空陣列也一路全綠。
	 *
	 * @return void
	 */
	private function seed_legacy_with_products(): void {
		$this->legacyRepo->seed(
			[
				'id'    => 22,
				'title' => '有商品的舊版賣場',
				'slug'  => 'old-shop',
				'meta'  => [
					'power_shop_meta'            => json_encode(
						[
							[
								'productId'       => 83,
								'productType'     => 'simple',
								'regularPrice'    => 20,
								'salesPrice'      => 18,
								'extraBuyerCount' => 3,
							],
							[
								'productId'    => 69,
								'productType'  => 'simple',
								'regularPrice' => 90,
								'salesPrice'   => 0,
							],
						]
					),
					'power_shop_settings'        => json_encode( [ 'btnColor' => '#ff0000' ] ),
					'power_shop_report_password' => 'MWMwMDllMjYwMg==',
					'_edit_lock'                 => '1785405506:1',
				],
			]
		);
	}

	/**
	 * 🔴 happy：legacy 的商品必須被帶進新賣場（含價格覆寫與灌水人數）
	 *
	 * @group happy
	 */
	public function test_imports_legacy_products_into_the_new_shop(): void {
		$this->seed_legacy_with_products();

		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		$output = $useCase->execute( legacy_id: 22, partner_term_id: 5 );

		$this->assertCount( 2, $output->items, '舊版的 2 個商品都必須被帶入' );

		$this->assertSame( 83, $output->items[0]['product_id'] );
		$this->assertSame( '20', $output->items[0]['override']['regular_price'] );
		$this->assertSame( '18', $output->items[0]['override']['sale_price'] );
		$this->assertSame( 3, $output->items[0]['inflated_count'] );

		$this->assertSame( 69, $output->items[1]['product_id'] );
		$this->assertSame( '90', $output->items[1]['override']['regular_price'] );
		$this->assertNull(
			$output->items[1]['override']['sale_price'],
			'legacy 的 salesPrice=0 代表「沒有特價」，轉成 "0" 會讓商品變 0 元'
		);
	}

	/**
	 * 🔴 security：settings 只帶白名單欄位，舊版報表密碼不得被複製進新賣場
	 *
	 * @group security
	 */
	public function test_import_does_not_copy_report_password_into_settings(): void {
		$this->seed_legacy_with_products();

		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		$output = $useCase->execute( legacy_id: 22, partner_term_id: 5 );

		$this->assertSame( '#ff0000', $output->settings['btnColor'] ?? null );
		$this->assertArrayNotHasKey( 'power_shop_report_password', $output->settings );
		$this->assertArrayNotHasKey( '_edit_lock', $output->settings );
		$this->assertArrayNotHasKey( 'power_shop_meta', $output->settings );
	}

	/**
	 * happy：分潤比例由呼叫端指定（legacy 沒有分潤概念）
	 *
	 * @group happy
	 */
	public function test_import_applies_the_given_rate(): void {
		$this->seed_legacy_with_products();

		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		$output = $useCase->execute( legacy_id: 22, partner_term_id: 5, rate: 15 );

		$this->assertSame( 15, $output->rate );
	}

	/**
	 * happy：未指定 rate 時維持 0（向後相容既有呼叫端）
	 *
	 * @group happy
	 */
	public function test_import_defaults_rate_to_zero(): void {
		$this->seed_legacy_with_products();

		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		$this->assertSame( 0, $useCase->execute( legacy_id: 22, partner_term_id: 5 )->rate );
	}

	// ====================================================================
	//  Rule: slug 衝突 / 重複匯入
	// ====================================================================

	/**
	 * 🔴 error：slug 已被佔用 → 拋 SlugConflictException（不得靜默建出第二個同 slug 賣場）
	 *
	 * 沒有這道檢查時，同一筆 legacy 可以被無限次匯入，每次都產生一個 slug 相同的賣場，
	 * 而走正常建立路徑（CreateShop）的同一個 slug 卻會被 409 擋下——雙標準。
	 *
	 * @group error
	 */
	public function test_throws_slug_conflict_when_slug_already_taken(): void {
		$this->seed_legacy_with_products();

		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			slugDetector: $this->conflicting_detector(),
		);

		$this->expectException( SlugConflictException::class );
		$useCase->execute( legacy_id: 22, partner_term_id: 5 );
	}

	/**
	 * error：slug 衝突時不得寫入任何資料
	 *
	 * @group error
	 */
	public function test_slug_conflict_does_not_persist_anything(): void {
		$this->seed_legacy_with_products();

		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			slugDetector: $this->conflicting_detector(),
		);

		try {
			$useCase->execute( legacy_id: 22, partner_term_id: 5 );
		} catch ( SlugConflictException $e ) {
			// 預期
		}

		$this->assertSame( 0, $this->shopRepo->count() );
	}

	// ====================================================================
	//  Rule: 已刪除的商品跳過而非整筆失敗
	// ====================================================================

	/**
	 * happy：legacy 引用了已被刪除的商品 → 跳過該筆並回報，其餘照常匯入
	 *
	 * @group happy
	 */
	public function test_skips_deleted_products_and_reports_them(): void {
		$this->seed_legacy_with_products();

		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			productLookup: $this->product_lookup_allowing( [ 83 ] ),
		);

		$output = $useCase->execute( legacy_id: 22, partner_term_id: 5 );

		$this->assertCount( 1, $output->items );
		$this->assertSame( 83, $output->items[0]['product_id'] );
		$this->assertSame(
			[ 69 ],
			$useCase->skipped_product_ids(),
			'被跳過的商品必須回報給呼叫端，讓 UI 顯性告知站長'
		);
	}

	/**
	 * edge：未注入 ProductLookup 時不做過濾（維持既有呼叫端行為）
	 *
	 * @group edge
	 */
	public function test_does_not_filter_products_without_lookup(): void {
		$this->seed_legacy_with_products();

		$useCase = new ImportLegacyShop(
			legacyRepo: $this->legacyRepo,
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
		);

		$output = $useCase->execute( legacy_id: 22, partner_term_id: 5 );

		$this->assertCount( 2, $output->items );
		$this->assertSame( [], $useCase->skipped_product_ids() );
	}

	/**
	 * 產生一個「一律回報衝突」的 SlugConflictDetector 替身
	 *
	 * @return SlugConflictDetectorInterface
	 */
	private function conflicting_detector(): SlugConflictDetectorInterface {
		return new class() implements SlugConflictDetectorInterface {
			/**
			 * 一律回報一筆 powershop 衝突
			 *
			 * @param string $slug    待檢查 slug
			 * @param string $context 檢查情境
			 *
			 * @return array<int, SlugConflict>
			 */
			public function detect( string $slug, string $context ): array {
				return [
					new SlugConflict(
						conflict_kind: 'powershop',
						conflicting_slug: $slug,
						conflicting_id: 999,
						conflicting_label: '既有賣場'
					),
				];
			}
		};
	}

	/**
	 * 產生一個只認得指定商品 ID 的 ProductLookup 替身
	 *
	 * @param array<int, int> $existing 存在的商品 ID
	 *
	 * @return ProductLookupInterface
	 */
	private function product_lookup_allowing( array $existing ): ProductLookupInterface {
		return new class( $existing ) implements ProductLookupInterface {
			/**
			 * 建構子
			 *
			 * @param array<int, int> $existing 存在的商品 ID
			 */
			public function __construct( private readonly array $existing ) {}

			/**
			 * 商品是否存在
			 *
			 * @param int $product_id 商品 ID
			 *
			 * @return bool
			 */
			public function exists( int $product_id ): bool {
				return in_array( $product_id, $this->existing, true );
			}

			/**
			 * Variation 是否屬於該商品
			 *
			 * @param int $product_id   父商品 ID
			 * @param int $variation_id Variation ID
			 *
			 * @return bool
			 */
			public function has_variation( int $product_id, int $variation_id ): bool {
				return true;
			}
		};
	}
}
