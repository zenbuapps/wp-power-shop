<?php
/**
 * GetShop UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\GetShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryProfitShopRepository;

/**
 * GetShop UseCase 測試
 */
final class GetShopTest extends TestCase {

	private InMemoryProfitShopRepository $shopRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->shopRepo = new InMemoryProfitShopRepository();
	}

	/**
	 * happy：賣場存在 → 回傳 ProfitShopOutput
	 *
	 * @group happy
	 */
	public function test_returns_shop_output_when_id_exists(): void {
		$this->shopRepo->seed( $this->build_shop( 100 ) );

		$useCase = new GetShop( shopRepo: $this->shopRepo );

		$output = $useCase->execute( id: 100 );

		$this->assertInstanceOf( ProfitShopOutput::class, $output );
		$this->assertSame( 100, $output->id );
	}

	/**
	 * error：賣場不存在 → 拋出 ProfitShopNotFound
	 *
	 * @group error
	 */
	public function test_throws_not_found_when_id_does_not_exist(): void {
		$useCase = new GetShop( shopRepo: $this->shopRepo );

		$this->expectException( ProfitShopNotFound::class );
		$useCase->execute( id: 999 );
	}

	/**
	 * edge：id <= 0 → 視為找不到（拋 ProfitShopNotFound）
	 *
	 * @group edge
	 */
	public function test_throws_not_found_when_id_is_zero(): void {
		$useCase = new GetShop( shopRepo: $this->shopRepo );

		$this->expectException( ProfitShopNotFound::class );
		$useCase->execute( id: 0 );
	}

	private function build_shop( int $id ): ProfitShop {
		return new ProfitShop(
			id: $id,
			title: '測試賣場',
			slug: 'test-shop',
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: 5,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: []
		);
	}
}
