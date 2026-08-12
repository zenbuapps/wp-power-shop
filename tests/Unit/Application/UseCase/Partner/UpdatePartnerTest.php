<?php
/**
 * UpdatePartner UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerInput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\UpdatePartner;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * UpdatePartner UseCase 測試
 */
final class UpdatePartnerTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry', 'old@example.com' );
		// 預先 save 過密碼以便驗證「password=null 時不變更」
		$this->partnerRepo->save(
			$this->partnerRepo->find_by_id( 5 ),
			'old-password'
		);
	}

	/**
	 * happy：更新 name + email，password 為 null → 不改密碼
	 *
	 * @group happy
	 */
	public function test_updates_name_and_email_without_changing_password(): void {
		$useCase = new UpdatePartner( partnerRepo: $this->partnerRepo );

		$input = PartnerInput::from_array(
			[
				'id'            => 5,
				'name'          => 'Jerry V2',
				'slug'          => 'jerry',
				'contact_email' => 'new@example.com',
				'password'      => null,
			]
		);

		$output = $useCase->execute( id: 5, input: $input );

		$this->assertSame( 'Jerry V2', $output->name );
		$this->assertSame( 'new@example.com', $output->contact_email );

		// 密碼未變
		$this->assertTrue(
			$this->partnerRepo->verify_password( 5, 'old-password' ),
			'password=null 時應保留原密碼'
		);
	}

	/**
	 * happy：提供新密碼 → 密碼被覆寫
	 *
	 * @group happy
	 * @group security
	 */
	public function test_updates_password_when_provided(): void {
		$useCase = new UpdatePartner( partnerRepo: $this->partnerRepo );

		$input = PartnerInput::from_array(
			[
				'id'            => 5,
				'name'          => 'Jerry',
				'slug'          => 'jerry',
				'contact_email' => 'old@example.com',
				'password'      => 'new-secret',
			]
		);

		$useCase->execute( id: 5, input: $input );

		$this->assertTrue( $this->partnerRepo->verify_password( 5, 'new-secret' ) );
		$this->assertFalse( $this->partnerRepo->verify_password( 5, 'old-password' ) );
	}

	/**
	 * error：partner 不存在 → 拋 PartnerNotFound
	 *
	 * @group error
	 */
	public function test_throws_partner_not_found_when_id_invalid(): void {
		$useCase = new UpdatePartner( partnerRepo: $this->partnerRepo );

		$input = PartnerInput::from_array(
			[
				'id'   => 9999,
				'name' => 'X',
				'slug' => 'x',
			]
		);

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( id: 9999, input: $input );
	}
}
