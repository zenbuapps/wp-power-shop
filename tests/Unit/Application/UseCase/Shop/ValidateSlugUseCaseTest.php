<?php
/**
 * ValidateSlug UseCase 單元測試（Phase 3-E T-9 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 * 對應實作（待 Green 階段建立）：
 *   - inc/classes/Domains/ProfitShop/Application/UseCase/Shop/ValidateSlugUseCase.php
 *   - inc/classes/Domains/ProfitShop/Application/DTO/SlugValidationOutput.php
 *
 * 驗證重點：
 * - happy：unique slug → available=true、conflicts=[]
 * - error：撞 WordPress 保留字 / powershop CPT / profit_partner term → available=false 並回對應 conflict type
 * - error：多類同時撞 → conflicts 全收
 * - error：空字串 / 含特殊字元 → 拋 InvalidPartnerSlug（格式驗證先行）
 *
 * 需求 conflict type 命名（讓前端可讀）：
 *   - 'wordpress_reserved'  WP 保留字
 *   - 'wc_page'             WooCommerce 核心 page slug
 *   - 'powershop'           既有 powershop CPT slug
 *   - 'profit_partner'      既有 profit_partner term slug
 *   - 'rewrite'             自訂 rewrite rule prefix
 *
 * 注意：本檔在 Green 階段尚未實作前會因為 class not found 全數紅燈。
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group phase_3e
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SlugValidationOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\ValidateSlugUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\FakeSlugConflictDetector;

/**
 * ValidateSlug UseCase 測試
 */
final class ValidateSlugUseCaseTest extends TestCase {

	/**
	 * happy：完全沒有衝突的 slug → available=true
	 *
	 * @group happy
	 */
	public function test_returns_available_when_no_conflicts(): void {
		$useCase = new ValidateSlugUseCase(
			slugDetector: new FakeSlugConflictDetector( [] )
		);

		$output = $useCase->execute( 'totally-unique-slug' );

		$this->assertInstanceOf( SlugValidationOutput::class, $output );
		$this->assertTrue( $output->is_available(), '沒有衝突時 is_available 應為 true' );
		$this->assertSame( [], $output->conflicts, '沒有衝突時 conflicts 應為空陣列' );
	}

	/**
	 * error：撞 WordPress 保留字 → available=false、conflicts 含 wordpress_reserved
	 *
	 * @group error
	 */
	public function test_returns_unavailable_when_wordpress_reserved(): void {
		$detector = new FakeSlugConflictDetector(
			[
				new SlugConflict( 'wordpress_reserved', 'admin', null, 'WordPress 系統保留字' ),
			]
		);

		$useCase = new ValidateSlugUseCase( slugDetector: $detector );
		$output  = $useCase->execute( 'admin' );

		$this->assertFalse( $output->is_available(), '撞保留字時 is_available 應為 false' );
		$this->assertCount( 1, $output->conflicts );
		$this->assertSame( 'wordpress_reserved', $output->conflicts[0]->conflict_kind );
		$this->assertSame( 'admin', $output->conflicts[0]->conflicting_slug );
	}

	/**
	 * error：撞既有 powershop CPT slug → conflicts 含 'powershop' kind
	 *
	 * @group error
	 */
	public function test_returns_unavailable_when_powershop_cpt_conflict(): void {
		$detector = new FakeSlugConflictDetector(
			[
				new SlugConflict( 'powershop', 'summer-sale', 123, 'powershop CPT「夏季活動」' ),
			]
		);

		$useCase = new ValidateSlugUseCase( slugDetector: $detector );
		$output  = $useCase->execute( 'summer-sale' );

		$this->assertFalse( $output->is_available() );
		$this->assertCount( 1, $output->conflicts );
		$this->assertSame( 'powershop', $output->conflicts[0]->conflict_kind );
		$this->assertSame( 123, $output->conflicts[0]->conflicting_id );
	}

	/**
	 * error：撞既有 profit_partner term slug → conflicts 含 'profit_partner' kind
	 *
	 * @group error
	 */
	public function test_returns_unavailable_when_partner_term_conflict(): void {
		$detector = new FakeSlugConflictDetector(
			[
				new SlugConflict( 'profit_partner', 'jerry', 7, 'profit_partner term「Jerry」' ),
			]
		);

		$useCase = new ValidateSlugUseCase( slugDetector: $detector );
		$output  = $useCase->execute( 'jerry' );

		$this->assertFalse( $output->is_available() );
		$this->assertCount( 1, $output->conflicts );
		$this->assertSame( 'profit_partner', $output->conflicts[0]->conflict_kind );
		$this->assertSame( 7, $output->conflicts[0]->conflicting_id );
	}

	/**
	 * error：同時撞多類資源 → conflicts 必須全部回傳，不可截斷
	 *
	 * @group error
	 * @group edge
	 */
	public function test_returns_unavailable_when_multiple_conflicts(): void {
		$detector = new FakeSlugConflictDetector(
			[
				new SlugConflict( 'wordpress_reserved', 'admin', null, 'WP 系統保留字' ),
				new SlugConflict( 'wc_page', 'admin', null, 'WooCommerce 我的帳戶頁' ),
				new SlugConflict( 'powershop', 'admin', 99, 'powershop CPT「測試」' ),
			]
		);

		$useCase = new ValidateSlugUseCase( slugDetector: $detector );
		$output  = $useCase->execute( 'admin' );

		$this->assertFalse( $output->is_available() );
		$this->assertCount( 3, $output->conflicts, '多類衝突應全部回傳，不可截斷' );

		$kinds = array_map( static fn( SlugConflict $c ): string => $c->conflict_kind, $output->conflicts );
		$this->assertContains( 'wordpress_reserved', $kinds );
		$this->assertContains( 'wc_page', $kinds );
		$this->assertContains( 'powershop', $kinds );
	}

	/**
	 * error：含特殊字元（非 [a-z0-9_-]）→ 拋 InvalidPartnerSlug
	 *
	 * 不可走 detector：格式錯的 slug 連 detector 都不該到，先做格式守門。
	 *
	 * @group error
	 * @group edge
	 */
	public function test_throws_invalid_partner_slug_when_slug_format_invalid(): void {
		$useCase = new ValidateSlugUseCase(
			slugDetector: new FakeSlugConflictDetector( [] )
		);

		$this->expectException( InvalidPartnerSlug::class );
		$useCase->execute( 'foo bar!' );
	}

	/**
	 * error：空字串 → 拋 InvalidPartnerSlug
	 *
	 * @group error
	 * @group edge
	 */
	public function test_throws_invalid_partner_slug_when_slug_empty(): void {
		$useCase = new ValidateSlugUseCase(
			slugDetector: new FakeSlugConflictDetector( [] )
		);

		$this->expectException( InvalidPartnerSlug::class );
		$useCase->execute( '' );
	}

	/**
	 * 安全/邊緣：超長 slug（> 60 字元）→ 拋 InvalidPartnerSlug
	 *
	 * 與 PartnerSlug ValueObject 對齊（1-60 字元）
	 *
	 * @group edge
	 */
	public function test_throws_invalid_partner_slug_when_slug_too_long(): void {
		$useCase = new ValidateSlugUseCase(
			slugDetector: new FakeSlugConflictDetector( [] )
		);

		$this->expectException( InvalidPartnerSlug::class );
		$useCase->execute( str_repeat( 'a', 61 ) );
	}

	/**
	 * SlugValidationOutput::to_array() 結構符合 spec response 結構
	 *
	 * @group happy
	 */
	public function test_output_to_array_matches_response_shape(): void {
		$detector = new FakeSlugConflictDetector(
			[
				new SlugConflict( 'wordpress_reserved', 'admin', null, 'WP 保留字' ),
			]
		);

		$useCase = new ValidateSlugUseCase( slugDetector: $detector );
		$output  = $useCase->execute( 'admin' );
		$array   = $output->to_array();

		$this->assertArrayHasKey( 'available', $array );
		$this->assertArrayHasKey( 'conflicts', $array );
		$this->assertIsBool( $array['available'] );
		$this->assertIsArray( $array['conflicts'] );
		$this->assertFalse( $array['available'] );
		$this->assertCount( 1, $array['conflicts'] );

		$first = $array['conflicts'][0];
		$this->assertIsArray( $first );
		$this->assertArrayHasKey( 'conflict_kind', $first );
		$this->assertArrayHasKey( 'conflicting_slug', $first );
		$this->assertArrayHasKey( 'conflicting_label', $first );
	}
}
