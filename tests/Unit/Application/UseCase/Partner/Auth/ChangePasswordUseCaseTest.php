<?php
/**
 * ChangePasswordUseCase 單元測試（Phase 6-A1 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 Partner 自助修密碼
 *
 * 紅燈合約：
 *   final class ChangePasswordUseCase {
 *     public function __construct(
 *       PartnerRepositoryInterface $partnerRepo,
 *       LoginRateLimiter           $limiter,
 *       ClockInterface             $clock,
 *     );
 *
 *     public function execute(ChangePasswordInput $input, ?string $ip = null): ChangePasswordOutput;
 *   }
 *
 * 流程（綠燈時 master 應實作）：
 *   1. 取出 partner（partner_term_id 由 caller 提供，鎖死 token 來源 → 永不從 query string）
 *      - 不存在 → PartnerNotFound
 *   2. rate-limit 用 pseudo-slug "pwchange:{term_id}" 走 LoginRateLimiter（與 login slug 維度隔離）
 *      - assert_not_blocked → 達門檻拋 TooManyAttempts
 *   3. 驗證 current_password 是否符合 termmeta hash
 *      - 錯 → record_failure( pseudo_slug, ip ) → 拋 InvalidCredentials
 *   4. 把 new_password 餵進 PartnerPassword VO
 *      - 弱 → 拋 WeakPassword（rate-limit 不動：格式錯不該消耗鎖定計數）
 *   5. PartnerRepository::save( $snap, $new_password )
 *      - save 內部會 update _partner_password + _partner_password_changed_at
 *   6. limiter::reset( pseudo_slug, ip ) 清計數器
 *   7. 回 ChangePasswordOutput{ success: true, password_changed_at: int }
 *
 * 預期紅燈：
 *   - Class ChangePasswordUseCase / ChangePasswordInput / ChangePasswordOutput / WeakPassword / PartnerPassword
 *     not found（已部分由其他測試紅燈帶出，這裡會在 ctor / execute 階段失敗）
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ChangePasswordInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ChangePasswordOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\LoginRateLimiter;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\ChangePasswordUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\WeakPassword;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;
use Tests\Support\FixedSaltProvider;
use Tests\Support\InMemoryTransientStore;
use Tests\Support\SpyEmailNotifier;
use Tests\Unit\Application\Fakes\InMemoryPartnerRepository;

/**
 * ChangePasswordUseCase 紅燈合約測試
 */
final class ChangePasswordUseCaseTest extends TestCase {

	private const PARTNER_ID = 5;
	private const PARTNER_SLUG = 'jerry';
	private const CURRENT_PASSWORD = 'old-pa55!';
	private const NEW_STRONG_PASSWORD = 'NewPass123';
	private const TEST_IP = '203.0.113.10';
	private const FIXED_TIME = 1_700_000_000;

	private InMemoryPartnerRepository $partnerRepo;
	private FixedClock $clock;
	private InMemoryTransientStore $transients;
	private SpyEmailNotifier $email;

	protected function setUp(): void {
		parent::setUp();
		$this->clock       = new FixedClock( self::FIXED_TIME );
		$this->partnerRepo = new InMemoryPartnerRepository( $this->clock );
		$this->transients  = new InMemoryTransientStore( $this->clock );
		$this->email       = new SpyEmailNotifier();

		// 預植入 partner 並設置原密碼（save 內部會把 password_changed_at 設為 clock->now()）
		$snap = $this->partnerRepo->seed(
			term_id: self::PARTNER_ID,
			name: 'Jerry',
			slug: self::PARTNER_SLUG,
			email: 'jerry@example.com'
		);
		$this->partnerRepo->save( $snap, self::CURRENT_PASSWORD );
	}

	/**
	 * Happy：current 正確 + new 強 → success=true，password_changed_at 為 clock 當下時間
	 *
	 * @group happy
	 */
	public function test_happy_returns_success_with_password_changed_at(): void {
		$useCase = $this->make_use_case();

		// 推進時鐘以驗證 password_changed_at 確實是「變更當下」而非 setUp 時的值
		$this->clock->advance( 60 );

		$output = $useCase->execute(
			new ChangePasswordInput(
				partner_term_id: self::PARTNER_ID,
				current_password: self::CURRENT_PASSWORD,
				new_password: self::NEW_STRONG_PASSWORD,
			),
			self::TEST_IP,
		);

		$this->assertInstanceOf( ChangePasswordOutput::class, $output );
		$this->assertTrue( $output->success );
		$this->assertSame( self::FIXED_TIME + 60, $output->password_changed_at );
	}

	/**
	 * Happy：成功後新密碼 verify true、舊密碼 verify false
	 *
	 * @group happy
	 * @group security
	 */
	public function test_happy_new_password_verifies_old_does_not(): void {
		$useCase = $this->make_use_case();

		$useCase->execute(
			new ChangePasswordInput(
				partner_term_id: self::PARTNER_ID,
				current_password: self::CURRENT_PASSWORD,
				new_password: self::NEW_STRONG_PASSWORD,
			),
			self::TEST_IP,
		);

		$this->assertTrue(
			$this->partnerRepo->verify_password( self::PARTNER_ID, self::NEW_STRONG_PASSWORD ),
			'新密碼應可通過 verify'
		);
		$this->assertFalse(
			$this->partnerRepo->verify_password( self::PARTNER_ID, self::CURRENT_PASSWORD ),
			'舊密碼不應再通過 verify'
		);
	}

	/**
	 * Happy：成功後 get_password_changed_at 回 clock 當下 timestamp（撤銷 token 所需）
	 *
	 * @group happy
	 * @group security
	 */
	public function test_happy_repo_password_changed_at_updated_to_now(): void {
		$useCase = $this->make_use_case();
		$this->clock->advance( 120 );

		$useCase->execute(
			new ChangePasswordInput(
				partner_term_id: self::PARTNER_ID,
				current_password: self::CURRENT_PASSWORD,
				new_password: self::NEW_STRONG_PASSWORD,
			),
			self::TEST_IP,
		);

		$changed_at = $this->partnerRepo->get_password_changed_at( self::PARTNER_ID );
		$this->assertNotNull( $changed_at );
		$this->assertSame( self::FIXED_TIME + 120, $changed_at );
	}

	/**
	 * Error：current 錯 → 拋 InvalidCredentials + pseudo-slug rate-limit 計數 +1
	 *
	 * @group error
	 * @group security
	 */
	public function test_wrong_current_password_throws_invalid_credentials_and_records_failure(): void {
		$useCase = $this->make_use_case();

		try {
			$useCase->execute(
				new ChangePasswordInput(
					partner_term_id: self::PARTNER_ID,
					current_password: 'wrong-current',
					new_password: self::NEW_STRONG_PASSWORD,
				),
				self::TEST_IP,
			);
			$this->fail( 'current_password 錯應拋 InvalidCredentials' );
		} catch ( InvalidCredentials ) {
			// expected
		}

		// rate-limit pseudo-slug 計數應為 1（pwchange:{id}）。
		// 用 prefix dump 驗證有 entry 寫入，避開硬鎖 key 結構。
		$entries = $this->transients->dump_with_prefix( 'ps_partner_login_fail_' );
		$this->assertNotEmpty( $entries, '失敗一次後應有 rate-limit transient 寫入' );

		// 同 IP 失敗計數也應寫入（雙維度策略）
		$ip_entries = $this->transients->dump_with_prefix( 'ps_partner_login_fail_ip_' );
		$this->assertNotEmpty( $ip_entries, '失敗一次後應有 IP 維度 transient 寫入' );
	}

	/**
	 * Error：new_password 弱 → 拋 WeakPassword，**rate-limit 不動**
	 *   原因：格式校驗錯不該消耗鎖定計數，否則使用者按錯 5 次格式就被鎖
	 *
	 * @group error
	 * @group security
	 */
	public function test_weak_new_password_throws_without_recording_rate_limit(): void {
		$useCase = $this->make_use_case();

		// pre-condition: transient 為空
		$this->assertSame( 0, $this->transients->size() );

		try {
			$useCase->execute(
				new ChangePasswordInput(
					partner_term_id: self::PARTNER_ID,
					current_password: self::CURRENT_PASSWORD,
					new_password: '123',  // too short + missing letter
				),
				self::TEST_IP,
			);
			$this->fail( '弱密碼應拋 WeakPassword' );
		} catch ( WeakPassword $e ) {
			$reasons = $e->getReasons();
			$this->assertContains( 'too_short', $reasons );
			$this->assertContains( 'missing_letter', $reasons );
		}

		// 弱密碼不應消耗 rate-limit 計數（任何維度）
		$this->assertSame(
			0,
			$this->transients->size(),
			'WeakPassword 不應寫入任何 rate-limit transient（格式錯誤不該消耗鎖定計數）'
		);
	}

	/**
	 * Security：5 次 current 錯 → 第 6 次（即使 current + new 都對）→ 拋 TooManyAttempts
	 *
	 * @group security
	 */
	public function test_too_many_failures_blocks_change_password(): void {
		$useCase = $this->make_use_case();

		// 失敗 5 次（用錯誤 current 觸發 record_failure）
		for ( $i = 0; $i < 5; $i++ ) {
			try {
				$useCase->execute(
					new ChangePasswordInput(
						partner_term_id: self::PARTNER_ID,
						current_password: 'wrong',
						new_password: self::NEW_STRONG_PASSWORD,
					),
					self::TEST_IP,
				);
			} catch ( \DomainException ) {
				// expected: InvalidCredentials
			}
		}

		// 第 6 次：current 對、new 強，仍應被擋（pseudo-slug 達門檻）
		$this->expectException( TooManyAttempts::class );
		$useCase->execute(
			new ChangePasswordInput(
				partner_term_id: self::PARTNER_ID,
				current_password: self::CURRENT_PASSWORD,
				new_password: self::NEW_STRONG_PASSWORD,
			),
			self::TEST_IP,
		);
	}

	/**
	 * Reset：4 次失敗 + 1 次成功 → rate-limit 清空，後續再失敗從 1 計起
	 *
	 * @group security
	 * @group edge
	 */
	public function test_successful_change_resets_rate_limit_counter(): void {
		$useCase = $this->make_use_case();

		// 失敗 4 次（不到門檻）
		for ( $i = 0; $i < 4; $i++ ) {
			try {
				$useCase->execute(
					new ChangePasswordInput(
						partner_term_id: self::PARTNER_ID,
						current_password: 'wrong',
						new_password: self::NEW_STRONG_PASSWORD,
					),
					self::TEST_IP,
				);
			} catch ( \DomainException ) {
				// expected
			}
		}

		// 第 5 次成功（current 對）
		$useCase->execute(
			new ChangePasswordInput(
				partner_term_id: self::PARTNER_ID,
				current_password: self::CURRENT_PASSWORD,
				new_password: self::NEW_STRONG_PASSWORD,
			),
			self::TEST_IP,
		);

		// 成功後 rate-limit 應清乾淨；接下來再失敗 4 次都不應觸發鎖定
		// （若上次計數沒 reset，再 1 次失敗就到 5 觸發鎖定）
		for ( $i = 0; $i < 4; $i++ ) {
			try {
				$useCase->execute(
					new ChangePasswordInput(
						partner_term_id: self::PARTNER_ID,
						current_password: 'wrong-again',
						new_password: 'NewPass456',
					),
					self::TEST_IP,
				);
			} catch ( InvalidCredentials ) {
				// expected, NOT TooManyAttempts
			} catch ( TooManyAttempts $e ) {
				$this->fail( '成功後應 reset rate-limit；第 ' . ( $i + 1 ) . ' 次失敗不應觸發鎖定，實際拋 TooManyAttempts' );
			}
		}

		$this->assertTrue( true, 'rate-limit 已 reset，第 5+5 次仍未鎖定' );
	}

	/**
	 * Security：pseudo-slug 隔離——pwchange 失敗不污染 partner login slug 維度
	 *
	 *   ChangePasswordUseCase 用 pwchange:{id}；PartnerAuthService::attempt_login 用 partner slug。
	 *   若 master 把兩個維度寫成共用 key，pwchange 失敗 5 次會把 jerry 的 login slug 鎖死。
	 *
	 *   驗證方式：pwchange 失敗 5 次後，看 transient 是否有 jerry 這個 slug 的 entry。
	 *
	 * @group security
	 * @group edge
	 */
	public function test_pwchange_pseudo_slug_does_not_pollute_login_slug_counter(): void {
		$useCase = $this->make_use_case();

		// pwchange 失敗 5 次（達門檻）
		for ( $i = 0; $i < 5; $i++ ) {
			try {
				$useCase->execute(
					new ChangePasswordInput(
						partner_term_id: self::PARTNER_ID,
						current_password: 'wrong',
						new_password: self::NEW_STRONG_PASSWORD,
					),
					self::TEST_IP,
				);
			} catch ( \DomainException ) {
				// expected
			}
		}

		// 直接讀 LoginRateLimiter 的 slug key 不可（private const），
		// 改驗 transient dump：不可有「key 等於 jerry partner slug」的 entry，
		// 但「key 含 pwchange:5（pseudo-slug）」的 entry 應存在。
		$dump = $this->transients->dump();

		$has_jerry_login_slug   = false;
		$has_pwchange_pseudo_5  = false;
		foreach ( $dump as $key => $_v ) {
			// LoginRateLimiter::KEY_PREFIX = 'ps_partner_login_fail_'
			// pwchange pseudo-slug 在 key 後綴；jerry 的 login slug 也會以該 prefix 為前綴
			if ( $key === 'ps_partner_login_fail_' . self::PARTNER_SLUG ) {
				$has_jerry_login_slug = true;
			}
			if ( false !== strpos( $key, 'pwchange:' . self::PARTNER_ID ) ) {
				$has_pwchange_pseudo_5 = true;
			}
		}

		$this->assertFalse(
			$has_jerry_login_slug,
			'pwchange 失敗不應在 login slug（jerry）維度留痕，否則 partner 連 login 都會被鎖'
		);
		$this->assertTrue(
			$has_pwchange_pseudo_5,
			'pwchange 失敗應在 pseudo-slug pwchange:' . self::PARTNER_ID . ' 維度留痕'
		);
	}

	/**
	 * Security：撤銷端到端——成功後 password_changed_at > 假設舊 token issued_at
	 *
	 *   驗證 PartnerTokenStore::verify 比對 changed_at 時，舊 token 會被識別為失效。
	 *
	 * @group security
	 */
	public function test_password_changed_at_strictly_greater_than_old_token_issued_at(): void {
		// 模擬 partner 在 t=FIXED_TIME 簽發舊 token（假設 issued_at = clock->now()）
		$old_token_issued_at = $this->clock->now();

		// 推進時鐘 100 秒，partner 來改密碼
		$this->clock->advance( 100 );

		$useCase = $this->make_use_case();
		$useCase->execute(
			new ChangePasswordInput(
				partner_term_id: self::PARTNER_ID,
				current_password: self::CURRENT_PASSWORD,
				new_password: self::NEW_STRONG_PASSWORD,
			),
			self::TEST_IP,
		);

		$new_changed_at = $this->partnerRepo->get_password_changed_at( self::PARTNER_ID );
		$this->assertNotNull( $new_changed_at );
		$this->assertGreaterThan(
			$old_token_issued_at,
			$new_changed_at,
			'改密後的 password_changed_at 必須嚴格大於舊 token 的 issued_at，PartnerTokenStore::verify 才能據此撤銷'
		);
	}

	/**
	 * Error：partner_term_id 不存在 → 拋 PartnerNotFound（在驗 current 之前先判存在性）
	 *
	 * @group error
	 */
	public function test_unknown_partner_term_id_throws_partner_not_found(): void {
		$useCase = $this->make_use_case();

		$this->expectException( PartnerNotFound::class );
		$useCase->execute(
			new ChangePasswordInput(
				partner_term_id: 99_999,
				current_password: 'whatever',
				new_password: self::NEW_STRONG_PASSWORD,
			),
			self::TEST_IP,
		);
	}

	/**
	 * Edge：IP=null → rate-limit 退化成只走 pseudo-slug 維度
	 *
	 * @group edge
	 * @group security
	 */
	public function test_ip_null_only_uses_slug_dimension(): void {
		$useCase = $this->make_use_case();

		// ip=null 失敗 1 次
		try {
			$useCase->execute(
				new ChangePasswordInput(
					partner_term_id: self::PARTNER_ID,
					current_password: 'wrong',
					new_password: self::NEW_STRONG_PASSWORD,
				),
				null,  // ip null
			);
		} catch ( InvalidCredentials ) {
			// expected
		}

		// 應只有 pseudo-slug 維度寫入；IP 維度不應寫入
		$slug_entries = $this->transients->dump_with_prefix( 'ps_partner_login_fail_' );
		$ip_only_entries = [];
		foreach ( $this->transients->dump() as $key => $entry ) {
			if ( str_starts_with( $key, 'ps_partner_login_fail_ip_' ) ) {
				$ip_only_entries[ $key ] = $entry;
			}
		}

		// pseudo-slug entry 應存在
		$has_pseudo_slug = false;
		foreach ( $slug_entries as $key => $_v ) {
			if ( false !== strpos( $key, 'pwchange:' . self::PARTNER_ID ) ) {
				$has_pseudo_slug = true;
				break;
			}
		}
		$this->assertTrue( $has_pseudo_slug, 'ip=null 時 pseudo-slug 維度仍應寫入' );

		// IP 維度應為空
		$this->assertEmpty(
			$ip_only_entries,
			'ip=null 時 IP 維度（ps_partner_login_fail_ip_）不應寫入任何 entry'
		);
	}

	/**
	 * Output DTO round-trip：確保 ChangePasswordOutput::to_array 與 from_array 對稱
	 *
	 * @group happy
	 */
	public function test_output_dto_round_trip(): void {
		$useCase = $this->make_use_case();
		$this->clock->advance( 30 );

		$output = $useCase->execute(
			new ChangePasswordInput(
				partner_term_id: self::PARTNER_ID,
				current_password: self::CURRENT_PASSWORD,
				new_password: self::NEW_STRONG_PASSWORD,
			),
			self::TEST_IP,
		);

		$arr  = $output->to_array();
		$back = ChangePasswordOutput::from_array( $arr );

		$this->assertSame( $output->success, $back->success );
		$this->assertSame( $output->password_changed_at, $back->password_changed_at );
	}

	// ========== helper ==========

	/**
	 * 建構 ChangePasswordUseCase（注入 fakes）
	 *
	 * 預期 master 在綠燈時定義的 ctor 簽章：
	 *   __construct(
	 *     PartnerRepositoryInterface $partnerRepo,
	 *     LoginRateLimiter $limiter,
	 *     ClockInterface $clock,
	 *   )
	 *
	 * 若 master 採用其他 DI shape（例如把 limiter 拆 transients/clock 直接注入），
	 * 此 helper 會在 ctor 階段紅燈失敗，提醒 master 修正。
	 */
	private function make_use_case(): ChangePasswordUseCase {
		$limiter = new LoginRateLimiter(
			transients: $this->transients,
			clock: $this->clock,
			email: $this->email,
			salt_provider: new FixedSaltProvider(),
			max_attempts: 5,
			window_seconds: 900,
		);

		return new ChangePasswordUseCase(
			partnerRepo: $this->partnerRepo,
			limiter: $limiter,
			clock: $this->clock,
		);
	}
}
