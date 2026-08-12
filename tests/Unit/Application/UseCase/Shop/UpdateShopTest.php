<?php
/**
 * UpdateShop UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1、§6.11
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\UpdateShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\FakeItemValidator;
use Tests\Unit\Application\Fakes\FakeSlugConflictDetector;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;
use Tests\Unit\Application\Fakes\InMemoryProfitShopRepository;

/**
 * UpdateShop UseCase 測試
 */
final class UpdateShopTest extends TestCase {

	private InMemoryProfitShopRepository $shopRepo;
	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->shopRepo    = new InMemoryProfitShopRepository();
		$this->partnerRepo = new InMemoryPartnerRepository();
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$this->shopRepo->seed(
			new ProfitShop(
				id: 200,
				title: '舊標題',
				slug: 'old-slug',
				status: 'draft',
				mode: ShopMode::PAGE,
				partner_term_id: 5,
				rate: new ProfitRate( 10 ),
				items: [],
				settings: []
			)
		);
	}

	/**
	 * happy：更新標題 + rate → 回傳更新後的 ProfitShopOutput
	 *
	 * @group happy
	 */
	public function test_updates_title_and_rate(): void {
		$useCase = new UpdateShop(
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			itemValidator: new FakeItemValidator( 'accept' ),
			slugDetector: new FakeSlugConflictDetector(),
		);

		$input = ProfitShopInput::from_array(
			[
				'id'              => 200,
				'title'           => '新標題',
				'slug'            => 'old-slug',
				'status'          => 'publish',
				'mode'            => 'page',
				'partner_term_id' => 5,
				'rate'            => 25,
				'items'           => [],
				'settings'        => [],
			]
		);

		$output = $useCase->execute( id: 200, input: $input );

		$this->assertSame( '新標題', $output->title );
		$this->assertSame( 25, $output->rate );
		$this->assertSame( 'publish', $output->status );
	}

	/**
	 * error：賣場不存在 → 拋出 ProfitShopNotFound
	 *
	 * @group error
	 */
	public function test_throws_not_found_when_shop_id_does_not_exist(): void {
		$useCase = new UpdateShop(
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			itemValidator: new FakeItemValidator( 'accept' ),
			slugDetector: new FakeSlugConflictDetector(),
		);

		$input = ProfitShopInput::from_array(
			[
				'id'              => 9999,
				'title'           => 'X',
				'slug'            => 'x',
				'status'          => 'publish',
				'mode'            => 'page',
				'partner_term_id' => 5,
				'rate'            => 10,
				'items'           => [],
				'settings'        => [],
			]
		);

		$this->expectException( ProfitShopNotFound::class );
		$useCase->execute( id: 9999, input: $input );
	}

	/**
	 * error：切換 partner 但新 partner 不存在 → 拋出 PartnerNotFound
	 *
	 * @group error
	 */
	public function test_throws_partner_not_found_when_new_partner_does_not_exist(): void {
		$useCase = new UpdateShop(
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			itemValidator: new FakeItemValidator( 'accept' ),
			slugDetector: new FakeSlugConflictDetector(),
		);

		$input = ProfitShopInput::from_array(
			[
				'id'              => 200,
				'title'           => '新標題',
				'slug'            => 'old-slug',
				'status'          => 'draft',
				'mode'            => 'page',
				'partner_term_id' => 9999,
				'rate'            => 10,
				'items'           => [],
				'settings'        => [],
			]
		);

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( id: 200, input: $input );
	}

	/**
	 * error：新 slug 與其他資源衝突 → 拋出 SlugConflictException
	 *
	 * @group error
	 */
	public function test_throws_slug_conflict_when_new_slug_collides(): void {
		$detector = new FakeSlugConflictDetector(
			[ new SlugConflict( 'page', 'about', 7, '關於我們' ) ]
		);

		$useCase = new UpdateShop(
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			itemValidator: new FakeItemValidator( 'accept' ),
			slugDetector: $detector,
		);

		$input = ProfitShopInput::from_array(
			[
				'id'              => 200,
				'title'           => '更名測試',
				'slug'            => 'about',
				'status'          => 'publish',
				'mode'            => 'page',
				'partner_term_id' => 5,
				'rate'            => 10,
				'items'           => [],
				'settings'        => [],
			]
		);

		$this->expectException( SlugConflictException::class );
		$useCase->execute( id: 200, input: $input );
	}
}
