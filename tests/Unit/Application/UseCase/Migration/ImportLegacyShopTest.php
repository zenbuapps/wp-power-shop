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

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\ImportLegacyShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\LegacyShopNotImportable;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
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
}
