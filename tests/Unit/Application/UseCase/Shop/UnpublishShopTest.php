<?php
/**
 * UnpublishShop UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\UnpublishShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryProfitShopRepository;

/**
 * UnpublishShop UseCase 測試
 */
final class UnpublishShopTest extends TestCase {

	private InMemoryProfitShopRepository $shopRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->shopRepo = new InMemoryProfitShopRepository();
	}

	/**
	 * happy：publish → draft
	 *
	 * @group happy
	 */
	public function test_unpublishes_published_shop(): void {
		$this->shopRepo->seed( $this->build_shop( 500, 'publish' ) );

		$useCase = new UnpublishShop( shopRepo: $this->shopRepo );

		$output = $useCase->execute( id: 500 );

		$this->assertSame( 'draft', $output->status );
	}

	/**
	 * happy：已是 draft → 幂等
	 *
	 * @group happy
	 */
	public function test_unpublishing_draft_shop_is_idempotent(): void {
		$this->shopRepo->seed( $this->build_shop( 501, 'draft' ) );

		$useCase = new UnpublishShop( shopRepo: $this->shopRepo );

		$output = $useCase->execute( id: 501 );

		$this->assertSame( 'draft', $output->status );
	}

	/**
	 * error：賣場不存在 → 拋出 ProfitShopNotFound
	 *
	 * @group error
	 */
	public function test_throws_not_found_when_shop_does_not_exist(): void {
		$useCase = new UnpublishShop( shopRepo: $this->shopRepo );

		$this->expectException( ProfitShopNotFound::class );
		$useCase->execute( id: 9999 );
	}

	private function build_shop( int $id, string $status ): ProfitShop {
		return new ProfitShop(
			id: $id,
			title: '已發佈賣場',
			slug: 'pub-shop',
			status: $status,
			mode: ShopMode::PAGE,
			partner_term_id: 5,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: []
		);
	}
}
