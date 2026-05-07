<?php
/**
 * RegeneratePartnerPasswordUseCase 單元測試（Phase 3-C 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2 + Phase 3-B observation 1
 *
 * 紅燈合約：
 *   class RegeneratePartnerPasswordUseCase {
 *     public function __construct(PartnerRepositoryInterface $partners);
 *     public function execute(int $partner_term_id): string; // 回新明文密碼（一次性顯示）
 *   }
 *
 * 行為：
 *   - 用 wp_generate_password(12) 產生隨機密碼（單元測試環境改用注入式 generator）
 *   - 透過 PartnerRepository::save($snap, $new_pw) 寫回（hash 由 repository 處理）
 *   - 回傳明文（讓 admin UI 一次性顯示，不存）
 *   - 未知 partner_term_id → 拋 PartnerNotFound
 *
 * 紅燈狀態：UseCase class 不存在；測試會 fail。
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\RegeneratePartnerPasswordUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * RegeneratePartnerPasswordUseCase 紅燈合約測試
 */
final class RegeneratePartnerPasswordUseCaseTest extends TestCase {

	private InMemoryPartnerRepository $partnerRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->partnerRepo = new InMemoryPartnerRepository();
	}

	/**
	 * happy：partner 存在 → 回新明文密碼，DB 內 hash 已更新
	 *
	 * @group happy
	 * @group security
	 */
	public function test_regenerate_returns_plaintext_and_persists_hash(): void {
		$snap = $this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$this->partnerRepo->save( $snap, 'old-password' );

		$old_hash = $this->partnerRepo->stored_password_hash( 5 );
		$this->assertSame( 'hashed::old-password', $old_hash );

		$useCase = new RegeneratePartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$new_plain = $useCase->execute( partner_term_id: 5 );

		$this->assertIsString( $new_plain );
		$this->assertGreaterThanOrEqual( 12, strlen( $new_plain ), '新密碼長度應 >= 12' );
		$this->assertNotSame( 'old-password', $new_plain );

		$new_hash = $this->partnerRepo->stored_password_hash( 5 );
		$this->assertSame( "hashed::{$new_plain}", $new_hash, 'DB hash 應已更新' );
		$this->assertNotSame( $old_hash, $new_hash, 'hash 應與舊的不同' );
	}

	/**
	 * security：兩次執行應產生不同密碼（隨機性）
	 *
	 * @group security
	 */
	public function test_each_execution_produces_unique_password(): void {
		$snap = $this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$this->partnerRepo->save( $snap, 'old' );

		$useCase = new RegeneratePartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$pws = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$pws[] = $useCase->execute( partner_term_id: 5 );
		}

		$this->assertCount( 5, array_unique( $pws ), '每次 regenerate 必須產生不同密碼' );
	}

	/**
	 * error：未知 partner → 拋 PartnerNotFound
	 *
	 * @group error
	 */
	public function test_unknown_partner_throws_partner_not_found(): void {
		$useCase = new RegeneratePartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$this->expectException( PartnerNotFound::class );
		$useCase->execute( partner_term_id: 9999 );
	}

	/**
	 * security：UseCase 回傳值為明文，但不應該也不能 echo / log 該明文
	 *
	 * 此測試僅斷言型別與長度，不能用 echo / error_log 等管道輸出（由 master 在綠燈時注意）。
	 *
	 * @group security
	 */
	public function test_return_value_is_plaintext_only_for_one_time_display(): void {
		$snap = $this->partnerRepo->seed( 5, 'Jerry', 'jerry' );
		$this->partnerRepo->save( $snap, 'old' );

		$useCase = new RegeneratePartnerPasswordUseCase( partnerRepo: $this->partnerRepo );

		$plain = $useCase->execute( partner_term_id: 5 );

		// 明文不應只是 hash 字串前綴
		$this->assertStringNotContainsString( 'hashed::', $plain );
		// 明文不應為 0 / 空
		$this->assertNotEmpty( $plain );
	}
}
