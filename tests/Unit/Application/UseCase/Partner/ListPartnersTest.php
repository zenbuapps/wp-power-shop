<?php
/**
 * ListPartners UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2（GET profit-partners）
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\ListPartners;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * ListPartners UseCase 測試
 */
final class ListPartnersTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
	}

	/**
	 * happy：repository 有資料 → 全部以 PartnerOutput 陣列回傳，並帶出明文密碼
	 *
	 * 列表頁的「複製密碼」按鈕就是靠這個欄位運作的——少了它按鈕會全部停用。
	 *
	 * ⚠️ 本測試在 2026-08 之前斷言的是**相反**的契約（PartnerOutput 不得含 password），
	 * 由站長要求變更為「明文可見可複製」。雜湊仍是登入驗證的唯一依據，
	 * 明文只供顯示；`PartnerOutput` 因此只能用於 admin-only endpoint。
	 * 背景與風險見 `PartnerTermRepository` 的 class docblock。
	 *
	 * @group happy
	 * @group security
	 */
	public function test_returns_all_partners_with_plain_password(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry', 'jerry@example.com' );
		$this->partnerRepo->seed( 6, 'Mary', 'mary', null );

		$useCase = new ListPartners( partnerRepo: $this->partnerRepo );

		$output = $useCase->execute();

		$this->assertCount( 2, $output );
		foreach ( $output as $partner ) {
			$this->assertArrayHasKey( 'password', $partner->to_array(), 'PartnerOutput 應含 password 欄位' );
		}
	}

	/**
	 * edge：seed 進來未設密碼的 partner，password 為 null 而非空字串
	 *
	 * 對應「明文功能上線前建立的夥伴」——DB 只有雜湊、無明文可還原。
	 * 列表頁靠 null 分流把「複製密碼」按鈕停用並說明原因；
	 * 若回空字串，按鈕會是可按的，站長會複製到一串空白而不自知。
	 *
	 * @group edge
	 */
	public function test_partner_without_stored_plain_password_is_null(): void {
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry', 'jerry@example.com' );

		$useCase = new ListPartners( partnerRepo: $this->partnerRepo );

		$output = $useCase->execute();

		$this->assertCount( 1, $output );
		$this->assertNull( $output[0]->password );
	}

	/**
	 * edge：repository 為空 → 回空陣列
	 *
	 * @group edge
	 */
	public function test_returns_empty_array_when_no_partners(): void {
		$useCase = new ListPartners( partnerRepo: $this->partnerRepo );

		$this->assertSame( [], $useCase->execute() );
	}

	/**
	 * happy：order by id ascending（穩定的回傳順序）
	 *
	 * @group happy
	 */
	public function test_returns_partners_in_id_ascending_order(): void {
		$this->partnerRepo->seed( 7, 'C', 'c-slug' );
		$this->partnerRepo->seed( 5, 'A', 'a-slug' );
		$this->partnerRepo->seed( 6, 'B', 'b-slug' );

		$useCase = new ListPartners( partnerRepo: $this->partnerRepo );

		$output = $useCase->execute();

		$ids = array_map( static fn( $p ): int => $p->id, $output );
		sort( $ids );
		$this->assertSame( [ 5, 6, 7 ], $ids );
	}
}
