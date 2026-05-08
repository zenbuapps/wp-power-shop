<?php
/**
 * CreatePartner UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2、§6.3
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\CreatePartner;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * CreatePartner UseCase 測試
 */
final class CreatePartnerTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
	}

	/**
	 * happy：合法輸入 → 建立 partner，回傳 PartnerOutput 不含密碼
	 *
	 * @group happy
	 * @group security
	 */
	public function test_creates_partner_with_hashed_password(): void {
		$useCase = new CreatePartner( partnerRepo: $this->partnerRepo );

		$input = PartnerInput::from_array(
			[
				'name'          => 'Jerry',
				'slug'          => 'jerry',
				'contact_email' => 'jerry@example.com',
				'password'      => 'plain-pa55!',
			]
		);

		$output = $useCase->execute( $input );

		$this->assertInstanceOf( PartnerOutput::class, $output );
		$this->assertSame( 'Jerry', $output->name );
		$this->assertSame( 'jerry', $output->slug );

		// 驗證密碼已雜湊（fake 用 'hashed::' 前綴模擬）
		$hash = $this->partnerRepo->stored_password_hash( $output->id );
		$this->assertNotNull( $hash, '密碼必須被儲存' );
		$this->assertNotSame( 'plain-pa55!', $hash, '安全性違規：密碼必須雜湊' );
		$this->assertStringContainsString( 'hashed::', $hash );

		// PartnerOutput 不含 password
		$this->assertArrayNotHasKey( 'password', $output->to_array() );
	}

	/**
	 * error：slug 為保留字 → 拋 InvalidPartnerSlug（由 PartnerSlug VO 觸發）
	 *
	 * @group error
	 */
	public function test_throws_invalid_partner_slug_for_reserved_word(): void {
		$useCase = new CreatePartner( partnerRepo: $this->partnerRepo );

		$input = PartnerInput::from_array(
			[
				'name'     => 'X',
				'slug'     => 'admin',
				'password' => 'whatever',
			]
		);

		$this->expectException( InvalidPartnerSlug::class );
		$useCase->execute( $input );
	}

	/**
	 * BUG-1 BLOCKING-2：slug 已被佔用 → 拋 SlugConflictException，
	 * 且 conflict_kind 必為 'profit_partner'（與 SlugConflictDetector / taxonomy slug 一致）
	 *
	 * 雙審紀錄：BUG-1 反轉 conflict_kind 'partner' → 'profit_partner'，
	 * 防止與 ValidateSlugUseCase 偵測到的 partner term 衝突命名分裂。
	 *
	 * @group error
	 * @group bug_1
	 */
	public function test_slug_conflict_uses_unified_profit_partner_kind(): void {
		// 預先建立同名 partner，模擬 slug 被佔用
		$useCase  = new CreatePartner( partnerRepo: $this->partnerRepo );
		$existing = PartnerInput::from_array(
			[
				'name'     => 'Existing',
				'slug'     => 'duplicate-jerry',
				'password' => 'p',
			]
		);
		$useCase->execute( $existing );

		// 第二次同 slug 必拋 SlugConflictException
		$dup = PartnerInput::from_array(
			[
				'name'     => 'Another Jerry',
				'slug'     => 'duplicate-jerry',
				'password' => 'p',
			]
		);

		try {
			$useCase->execute( $dup );
			$this->fail( 'duplicate slug 必須拋 SlugConflictException' );
		} catch ( SlugConflictException $e ) {
			$conflicts = $e->getConflicts();
			$this->assertCount( 1, $conflicts );
			$this->assertSame(
				'profit_partner',
				$conflicts[0]->conflict_kind,
				"BUG-1 BLOCKING-2：partner slug 衝突 conflict_kind 必為 'profit_partner'，不可為 'partner'"
			);
			$this->assertSame( 'duplicate-jerry', $conflicts[0]->conflicting_slug );
		}
	}

	/**
	 * error：slug 為非法字元（含空白）→ 拋 InvalidPartnerSlug
	 *
	 * @group error
	 */
	public function test_throws_invalid_partner_slug_for_illegal_chars(): void {
		$useCase = new CreatePartner( partnerRepo: $this->partnerRepo );

		$input = PartnerInput::from_array(
			[
				'name'     => 'X',
				'slug'     => 'has space',
				'password' => 'p',
			]
		);

		$this->expectException( InvalidPartnerSlug::class );
		$useCase->execute( $input );
	}
}
