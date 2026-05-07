<?php
/**
 * CreateShop UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1、§6.11
 * 對應實作（待 Phase 3-B Green 階段建立）：
 *   - inc/classes/Domains/ProfitShop/Application/UseCase/Shop/CreateShop.php
 *
 * 驗證重點：
 * - happy：partner 存在、items 合法、slug 不衝突 → 寫入並回傳 ProfitShopOutput
 * - error：partner_term_id 不存在 → throw PartnerNotFound
 * - error：item product_id 不存在 → throw ProductNotFound（透過 ItemValidator）
 * - error：slug 衝突 → throw SlugConflictException
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\CreateShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\FakeItemValidator;
use Tests\Unit\Application\Fakes\FakeSlugConflictDetector;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;
use Tests\Unit\Application\Fakes\InMemoryProfitShopRepository;

/**
 * CreateShop UseCase 測試
 */
final class CreateShopTest extends TestCase {

	private InMemoryProfitShopRepository $shopRepo;
	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->shopRepo    = new InMemoryProfitShopRepository();
		$this->partnerRepo = new InMemoryPartnerRepository();
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry', 'jerry@example.com' );
	}

	/**
	 * happy：partner 存在、items 合法、slug 不衝突 → 賣場成功建立
	 *
	 * @group happy
	 */
	public function test_creates_shop_when_input_is_valid(): void {
		$useCase = new CreateShop(
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			itemValidator: new FakeItemValidator( 'accept' ),
			slugDetector: new FakeSlugConflictDetector(),
		);

		$input = ProfitShopInput::from_array(
			[
				'title'           => '夏季活動賣場',
				'slug'            => 'summer-sale',
				'status'          => 'publish',
				'mode'            => 'page',
				'partner_term_id' => 5,
				'rate'            => 10,
				'items'           => [
					[
						'product_id'     => 100,
						'inflated_count' => 0,
						'override'       => [ 'regular_price' => '999', 'sale_price' => '799', 'signup_fee' => null ],
					],
				],
				'settings'        => [],
			]
		);

		$output = $useCase->execute( $input );

		$this->assertInstanceOf( ProfitShopOutput::class, $output );
		$this->assertSame( '夏季活動賣場', $output->title );
		$this->assertSame( 'summer-sale', $output->slug );
		$this->assertSame( 5, $output->partner_term_id );
		$this->assertSame( 1, $this->shopRepo->count(), '應寫入 1 筆賣場' );
	}

	/**
	 * error：partner_term_id 不存在 → 拋出 PartnerNotFound
	 *
	 * @group error
	 */
	public function test_throws_partner_not_found_when_partner_term_id_invalid(): void {
		$useCase = new CreateShop(
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			itemValidator: new FakeItemValidator( 'accept' ),
			slugDetector: new FakeSlugConflictDetector(),
		);

		$input = ProfitShopInput::from_array(
			[
				'title'           => '不存在的 partner',
				'slug'            => 'ghost-shop',
				'status'          => 'draft',
				'mode'            => 'page',
				'partner_term_id' => 9999,
				'rate'            => 10,
				'items'           => [],
				'settings'        => [],
			]
		);

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( $input );
	}

	/**
	 * error：item product_id 無效 → ItemValidator 拋出 ProductNotFound
	 *
	 * @group error
	 */
	public function test_throws_product_not_found_when_item_product_invalid(): void {
		$useCase = new CreateShop(
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			itemValidator: new FakeItemValidator( 'reject_product' ),
			slugDetector: new FakeSlugConflictDetector(),
		);

		$input = ProfitShopInput::from_array(
			[
				'title'           => '測試',
				'slug'            => 'test-shop',
				'status'          => 'draft',
				'mode'            => 'page',
				'partner_term_id' => 5,
				'rate'            => 10,
				'items'           => [
					[ 'product_id' => 99999, 'inflated_count' => 0, 'override' => [] ],
				],
				'settings'        => [],
			]
		);

		$this->expectException( ProductNotFound::class );
		$useCase->execute( $input );
	}

	/**
	 * error：slug 與既有資源衝突 → 拋出 SlugConflictException 並攜帶 conflicts
	 *
	 * @group error
	 */
	public function test_throws_slug_conflict_when_slug_duplicates_existing_resource(): void {
		$detector = new FakeSlugConflictDetector(
			[
				new SlugConflict( 'product', 'shop', 42, 'WooCommerce 商店頁' ),
			]
		);

		$useCase = new CreateShop(
			shopRepo: $this->shopRepo,
			partnerRepo: $this->partnerRepo,
			itemValidator: new FakeItemValidator( 'accept' ),
			slugDetector: $detector,
		);

		$input = ProfitShopInput::from_array(
			[
				'title'           => '衝突賣場',
				'slug'            => 'shop',
				'status'          => 'draft',
				'mode'            => 'page',
				'partner_term_id' => 5,
				'rate'            => 10,
				'items'           => [],
				'settings'        => [],
			]
		);

		try {
			$useCase->execute( $input );
			$this->fail( '預期拋出 SlugConflictException 但沒有' );
		} catch ( SlugConflictException $e ) {
			$conflicts = $e->getConflicts();
			$this->assertCount( 1, $conflicts );
			$this->assertSame( 'product', $conflicts[0]->conflict_kind );
		}
	}
}
