<?php
/**
 * ListShops UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.2、§4.1
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\ListShops;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryProfitShopRepository;

/**
 * ListShops UseCase 測試
 */
final class ListShopsTest extends TestCase {

	private InMemoryProfitShopRepository $shopRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->shopRepo = new InMemoryProfitShopRepository();
	}

	/**
	 * happy：repository 中有多筆賣場 → 全部以 ProfitShopOutput 陣列回傳
	 *
	 * @group happy
	 */
	public function test_returns_all_shops_when_no_filter(): void {
		$this->shopRepo->seed( $this->build_shop( 1001, 'A 賣場', 5 ) );
		$this->shopRepo->seed( $this->build_shop( 1002, 'B 賣場', 5 ) );
		$this->shopRepo->seed( $this->build_shop( 1003, 'C 賣場', 6 ) );

		$useCase = new ListShops( shopRepo: $this->shopRepo );

		$output = $useCase->execute();

		$this->assertCount( 3, $output, '應回傳全部 3 筆賣場' );
	}

	/**
	 * happy：依 partner_term_id 過濾 → 只回該 partner 旗下的賣場
	 *
	 * @group happy
	 */
	public function test_filters_by_partner_term_id(): void {
		$this->shopRepo->seed( $this->build_shop( 2001, 'Jerry 賣場 A', 5 ) );
		$this->shopRepo->seed( $this->build_shop( 2002, 'Jerry 賣場 B', 5 ) );
		$this->shopRepo->seed( $this->build_shop( 2003, '其他 KOL', 6 ) );

		$useCase = new ListShops( shopRepo: $this->shopRepo );

		$output = $useCase->execute( partner_term_id: 5 );

		$this->assertCount( 2, $output );
		foreach ( $output as $shop ) {
			$this->assertSame( 5, $shop->partner_term_id );
		}
	}

	/**
	 * edge：repository 為空 → 回傳空陣列（不該拋例外）
	 *
	 * @group edge
	 */
	public function test_returns_empty_array_when_no_shops(): void {
		$useCase = new ListShops( shopRepo: $this->shopRepo );

		$output = $useCase->execute();

		$this->assertSame( [], $output );
	}

	/**
	 * 建立測試用 ProfitShop
	 */
	private function build_shop( int $id, string $title, int $partner_term_id ): ProfitShop {
		return new ProfitShop(
			id: $id,
			title: $title,
			slug: 'shop-' . $id,
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: $partner_term_id,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: []
		);
	}
}
