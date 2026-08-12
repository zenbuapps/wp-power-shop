<?php
/**
 * DuplicateShop UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1（POST profit-shops/{id}/duplicate）
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\DuplicateShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryProfitShopRepository;

/**
 * DuplicateShop UseCase 測試
 */
final class DuplicateShopTest extends TestCase {

	private InMemoryProfitShopRepository $shopRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->shopRepo = new InMemoryProfitShopRepository();
	}

	/**
	 * happy：複製賣場 → 新賣場有不同 ID + 不同 slug + 狀態為 draft
	 *
	 * @group happy
	 */
	public function test_duplicates_shop_with_new_id_and_draft_status(): void {
		$item = new OverrideItem(
			product_id: 100,
			override: new PriceOverride( '1000', '800', null ),
			inflated_count: new InflatedCount( 0 ),
			variations: []
		);
		$this->shopRepo->seed(
			new ProfitShop(
				id: 700,
				title: '原始賣場',
				slug: 'origin-shop',
				status: 'publish',
				mode: ShopMode::PAGE,
				partner_term_id: 5,
				rate: new ProfitRate( 15 ),
				items: [ $item ],
				settings: [ 'banner_url' => 'https://example.com/b.jpg' ]
			)
		);

		$useCase = new DuplicateShop( shopRepo: $this->shopRepo );

		$output = $useCase->execute( id: 700 );

		$this->assertNotSame( 700, $output->id, '複製出的賣場應有不同 ID' );
		$this->assertSame( 'draft', $output->status, '複製出的賣場初始狀態應為 draft' );
		$this->assertNotSame( 'origin-shop', $output->slug, 'slug 不能與原賣場相同' );
		$this->assertSame( 5, $output->partner_term_id );
		$this->assertSame( 15, $output->rate );
	}

	/**
	 * error：原始賣場不存在 → 拋出 ProfitShopNotFound
	 *
	 * @group error
	 */
	public function test_throws_not_found_when_source_shop_does_not_exist(): void {
		$useCase = new DuplicateShop( shopRepo: $this->shopRepo );

		$this->expectException( ProfitShopNotFound::class );
		$useCase->execute( id: 9999 );
	}

	/**
	 * edge：原賣場標題附 (Copy) 後綴
	 *
	 * @group edge
	 */
	public function test_duplicated_title_has_copy_suffix(): void {
		$this->shopRepo->seed(
			new ProfitShop(
				id: 701,
				title: '春季賣場',
				slug: 'spring-shop',
				status: 'publish',
				mode: ShopMode::PAGE,
				partner_term_id: 5,
				rate: new ProfitRate( 10 ),
				items: [],
				settings: []
			)
		);

		$useCase = new DuplicateShop( shopRepo: $this->shopRepo );

		$output = $useCase->execute( id: 701 );

		$this->assertStringContainsString( '春季賣場', $output->title, '複製賣場標題應包含原標題' );
		$this->assertNotSame( '春季賣場', $output->title, '複製賣場標題不應與原賣場完全相同' );
	}
}
