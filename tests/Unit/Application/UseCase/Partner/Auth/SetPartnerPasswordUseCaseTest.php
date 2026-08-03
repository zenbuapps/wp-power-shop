<?php
/**
 * SetPartnerPasswordUseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 *
 * 業務規則：
 *   - 站長指定的明文密碼必須先過 `PartnerPassword` VO（≥ 8 codepoint + ≥ 1 英文 + ≥ 1 數字）
 *   - 密碼寫入後 `password_changed_at` 必須被 bump（該夥伴所有舊 token 隨之失效）
 *   - partner 不存在 → PartnerNotFound，且**優先於**密碼強度檢查
 *     （否則弱密碼對不存在的 partner 回 422，等於洩漏「這個 id 不存在」以外的資訊）
 *
 * @group profit_shop
 * @group application
 * @group usecase
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\SetPartnerPasswordUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\WeakPassword;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * SetPartnerPasswordUseCase 測試
 */
final class SetPartnerPasswordUseCaseTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
		$this->partnerRepo->seed( 5, 'Jerry', 'jerry', 'jerry@example.com' );
		$this->partnerRepo->save( $this->partnerRepo->find_by_id( 5 ), 'old-pa55word' );
	}

	/**
	 * happy：合規密碼 → 寫入成功，舊密碼失效
	 *
	 * @group happy
	 * @group security
	 */
	public function test_sets_password_when_strong_enough(): void {
		$useCase = new SetPartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$useCase->execute( partner_term_id: 5, plain_password: 'NewPass456' );

		$this->assertTrue(
			$this->partnerRepo->verify_password( 5, 'NewPass456' ),
			'新密碼應可通過驗證'
		);
		$this->assertFalse(
			$this->partnerRepo->verify_password( 5, 'old-pa55word' ),
			'舊密碼應立即失效'
		);
	}

	/**
	 * happy：回傳 password_changed_at，且該值被實際寫入 repository
	 *
	 * 這個時間戳是舊 token 撤銷機制的依據（`PartnerTokenStore::verify` 比對
	 * `issued_at <= password_changed_at`）——沒 bump 的話，站長改了密碼但
	 * 夥伴目前的登入階段仍然有效，而且不會有任何徵兆。
	 *
	 * @group happy
	 * @group security
	 */
	public function test_returns_and_persists_password_changed_at(): void {
		$useCase = new SetPartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$returned = $useCase->execute( partner_term_id: 5, plain_password: 'NewPass456' );

		$this->assertGreaterThan( 0, $returned );
		$this->assertSame(
			$this->partnerRepo->get_password_changed_at( 5 ),
			$returned,
			'回傳值必須與 repository 實際存的 password_changed_at 一致'
		);
	}

	/**
	 * error：密碼過短 → WeakPassword，且**不**寫入任何變更
	 *
	 * @group error
	 * @group security
	 */
	public function test_rejects_too_short_password(): void {
		$useCase = new SetPartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$thrown = false;
		try {
			$useCase->execute( partner_term_id: 5, plain_password: 'Ab1' );
		} catch ( WeakPassword $e ) {
			$thrown = true;
			$this->assertNotEmpty( $e->getReasons(), 'WeakPassword 必須攜帶 reasons' );
		}

		$this->assertTrue( $thrown, '過短密碼應拋 WeakPassword' );
		$this->assertTrue(
			$this->partnerRepo->verify_password( 5, 'old-pa55word' ),
			'驗證失敗時不可動到既有密碼'
		);
	}

	/**
	 * error：純數字密碼（無英文）→ WeakPassword
	 *
	 * @group error
	 * @group security
	 */
	public function test_rejects_password_without_letter(): void {
		$useCase = new SetPartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$this->expectException( WeakPassword::class );
		$useCase->execute( partner_term_id: 5, plain_password: '123456789' );
	}

	/**
	 * error：純英文密碼（無數字）→ WeakPassword
	 *
	 * ⚠️ 這條同時記錄了一個現存的不一致：`CreatePartner` 走的是另一條路徑，
	 * **沒有**套 `PartnerPassword` VO，所以站長在「新增夥伴」頁仍可設純英文密碼。
	 * 見 specs/tech-debt.md。
	 *
	 * @group error
	 * @group security
	 */
	public function test_rejects_password_without_digit(): void {
		$useCase = new SetPartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$this->expectException( WeakPassword::class );
		$useCase->execute( partner_term_id: 5, plain_password: 'onlyletters' );
	}

	/**
	 * error：partner 不存在 → PartnerNotFound
	 *
	 * @group error
	 */
	public function test_throws_partner_not_found_when_id_invalid(): void {
		$useCase = new SetPartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( partner_term_id: 999, plain_password: 'NewPass456' );
	}

	/**
	 * error：partner 不存在時，PartnerNotFound 優先於 WeakPassword
	 *
	 * 順序若反過來，攻擊者可以用一組弱密碼掃 id：回 422 代表「id 存在」、
	 * 回 404 代表「不存在」，變成存在性 oracle。本端點雖然是 admin_only，
	 * 仍照 least-privilege 把順序鎖死。
	 *
	 * @group error
	 * @group security
	 */
	public function test_partner_not_found_takes_precedence_over_weak_password(): void {
		$useCase = new SetPartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( partner_term_id: 999, plain_password: 'x' );
	}
}
