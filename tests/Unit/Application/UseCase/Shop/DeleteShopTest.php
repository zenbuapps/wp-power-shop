<?php
/**
 * DeleteShop UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\DeleteShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryProfitShopRepository;

/**
 * DeleteShop UseCase 測試
 */
final class DeleteShopTest extends TestCase {

	private InMemoryProfitShopRepository $shopRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->shopRepo = new InMemoryProfitShopRepository();
	}

	/**
	 * happy：賣場存在 → 呼叫 delete 並從 repo 消失
	 *
	 * @group happy
	 */
	public function test_deletes_existing_shop(): void {
		$this->shopRepo->seed( $this->build_shop( 300 ) );

		$useCase = new DeleteShop( shopRepo: $this->shopRepo );

		$useCase->execute( id: 300 );

		$this->assertNull( $this->shopRepo->find( 300 ), '刪除後 find() 應回 null' );
	}

	/**
	 * error：賣場不存在 → 拋出 ProfitShopNotFound
	 *
	 * @group error
	 */
	public function test_throws_not_found_when_shop_does_not_exist(): void {
		$useCase = new DeleteShop( shopRepo: $this->shopRepo );

		$this->expectException( ProfitShopNotFound::class );
		$useCase->execute( id: 9999 );
	}

	/**
	 * edge：id <= 0 → 拋出 ProfitShopNotFound
	 *
	 * @group edge
	 */
	public function test_throws_not_found_when_id_is_zero(): void {
		$useCase = new DeleteShop( shopRepo: $this->shopRepo );

		$this->expectException( ProfitShopNotFound::class );
		$useCase->execute( id: 0 );
	}

	private function build_shop( int $id ): ProfitShop {
		return new ProfitShop(
			id: $id,
			title: '即將被刪',
			slug: 'doomed',
			status: 'draft',
			mode: ShopMode::PAGE,
			partner_term_id: 5,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: []
		);
	}
}
