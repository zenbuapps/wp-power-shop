<?php
/**
 * PartnerRepositoryInterface get_password_changed_at() 契約測試（Phase 3-D Task T-1 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4 / §6 + Phase 3-D 預告 #1
 *
 * 背景：
 *   PartnerTermRepository 已在 save() 寫入 _partner_password_changed_at termmeta（line 35, 118），
 *   但 PartnerRepositoryInterface 缺對應的 getter。
 *   Phase 3-D Task T-2（PartnerTokenStore 撤銷邏輯）需要這個 getter 來比對 token issued_at，
 *   一旦密碼被變更，舊 token 必須失效。
 *
 * 紅燈預期：
 *   - PartnerRepositoryInterface 尚未宣告 get_password_changed_at()
 *   - InMemoryPartnerRepository fake 也尚未實作
 *   - ReflectionClass::hasMethod() 會回 false → 第一個測試紅燈
 *   - 後兩個測試在 anonymous class implements interface 時會 Fatal error
 *     （class contains 1 abstract method），測試框架報為 PHP error → 紅燈
 *
 * 預期合約：
 *   - 簽名：get_password_changed_at(int $term_id): ?int
 *   - 從未設定 → null
 *   - 已設定 unix timestamp → 回傳該 int
 *   - meta_value 為空字串（邊界）→ null（而非 0，避免讓 caller 把 0 誤判為「epoch 起點」）
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Repository;

use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * PartnerRepositoryInterface::get_password_changed_at() 契約測試
 */
final class PartnerRepositoryGetPasswordChangedAtContractTest extends TestCase {

	/**
	 * Interface 必須宣告 get_password_changed_at 方法
	 *
	 * 紅燈訊息預期：Method get_password_changed_at does not exist on PartnerRepositoryInterface
	 *
	 * @group happy
	 */
	public function test_interface_declares_get_password_changed_at_method(): void {
		$reflection = new \ReflectionClass( PartnerRepositoryInterface::class );

		$this->assertTrue(
			$reflection->hasMethod( 'get_password_changed_at' ),
			'PartnerRepositoryInterface 必須宣告 get_password_changed_at 方法以支援 token 撤銷比對'
		);

		$method = $reflection->getMethod( 'get_password_changed_at' );

		// 唯一一個參數
		$params = $method->getParameters();
		$this->assertCount( 1, $params, 'get_password_changed_at() 應只接受一個參數（term_id）' );

		// 參數型別為 int
		$param_type = $params[0]->getType();
		$this->assertNotNull( $param_type, 'get_password_changed_at() 第一個參數必須宣告型別' );
		$this->assertInstanceOf( \ReflectionNamedType::class, $param_type );
		$this->assertSame( 'int', $param_type->getName(), 'get_password_changed_at() 第一個參數型別必須為 int' );

		// 回傳型別為 ?int（nullable int）
		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type, 'get_password_changed_at() 必須宣告回傳型別' );
		$this->assertInstanceOf( \ReflectionNamedType::class, $return_type );
		$this->assertSame( 'int', $return_type->getName(), 'get_password_changed_at() 回傳基礎型別必須為 int' );
		$this->assertTrue( $return_type->allowsNull(), 'get_password_changed_at() 必須允許回傳 null（從未設定情境）' );
	}

	/**
	 * Fake repository 從未設定密碼變更時間 → 回 null
	 *
	 * 紅燈預期：anonymous class implements PartnerRepositoryInterface 會缺實作 abstract method，
	 * PHP 會 fatal error，測試 runner 報 Error / Fatal error。
	 *
	 * @group happy
	 */
	public function test_get_password_changed_at_returns_null_when_never_set(): void {
		$repo = $this->make_fake_repo_with_changed_at( null );

		$this->assertNull(
			$repo->get_password_changed_at( 9001 ),
			'從未設定 _partner_password_changed_at 的 partner 應回 null'
		);
	}

	/**
	 * Fake repository 已設定 timestamp → 回該 int
	 *
	 * 紅燈預期：同上，缺實作 abstract method 會 fatal。
	 *
	 * @group happy
	 */
	public function test_get_password_changed_at_returns_unix_timestamp_when_set(): void {
		$repo = $this->make_fake_repo_with_changed_at( 1700000000 );

		$this->assertSame(
			1700000000,
			$repo->get_password_changed_at( 9001 ),
			'已設定 _partner_password_changed_at 應原值回傳 unix timestamp'
		);
	}

	/**
	 * 邊界：termmeta 值是空字串時應回 null（而非 0）
	 *
	 * 規範意圖：caller 如果用 (int) cast 會把空字串變 0，0 又是 epoch 起點，
	 * 會讓「token issued_at >= password_changed_at」永遠成立，等於撤銷邏輯失效。
	 * 因此 Repository 必須在底層就把空字串/未設定統一回 null。
	 *
	 * 紅燈預期：同上，缺實作 abstract method 會 fatal。
	 *
	 * @group edge
	 * @group security
	 */
	public function test_get_password_changed_at_returns_null_when_meta_value_is_empty_string(): void {
		$repo = $this->make_fake_repo_with_changed_at_raw( '' );

		$this->assertNull(
			$repo->get_password_changed_at( 9001 ),
			'meta_value 為空字串時應回 null（不可回 0，否則 token 撤銷邏輯會失效）'
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立 anonymous class fake，僅控制 get_password_changed_at 回傳值（int|null）
	 *
	 * @param int|null $changed_at 預期 get_password_changed_at 該回傳的值
	 */
	private function make_fake_repo_with_changed_at( ?int $changed_at ): PartnerRepositoryInterface {
		return new class( $changed_at ) implements PartnerRepositoryInterface {
			public function __construct( private readonly ?int $changed_at ) {}

			public function find_by_slug( string $slug ): ?PartnerSnapshot {
				return null;
			}
			public function find_by_id( int $term_id ): ?PartnerSnapshot {
				return null;
			}
			public function save( PartnerSnapshot $partner, ?string $plain_password = null ): int {
				return 0;
			}
			public function is_in_use( int $term_id ): bool {
				return false;
			}
			public function verify_password( int $term_id, string $plain_password ): bool {
				return false;
			}
			public function delete( int $term_id ): void {}
			public function all(): array {
				return [];
			}
			public function get_password_changed_at( int $term_id ): ?int {
				return $this->changed_at;
			}
		};
	}

	/**
	 * 建立 anonymous class fake，模擬 termmeta 原始值為任意 mixed（例如空字串）
	 *
	 * 用途：模擬 wp_term_meta 回傳空字串的邊界，驗證 fake 仍能回 null。
	 * 實作應在內部把空字串視為 null。
	 *
	 * @param mixed $raw_meta_value 模擬 get_term_meta 的原始回傳
	 */
	private function make_fake_repo_with_changed_at_raw( mixed $raw_meta_value ): PartnerRepositoryInterface {
		return new class( $raw_meta_value ) implements PartnerRepositoryInterface {
			public function __construct( private readonly mixed $raw_meta_value ) {}

			public function find_by_slug( string $slug ): ?PartnerSnapshot {
				return null;
			}
			public function find_by_id( int $term_id ): ?PartnerSnapshot {
				return null;
			}
			public function save( PartnerSnapshot $partner, ?string $plain_password = null ): int {
				return 0;
			}
			public function is_in_use( int $term_id ): bool {
				return false;
			}
			public function verify_password( int $term_id, string $plain_password ): bool {
				return false;
			}
			public function delete( int $term_id ): void {}
			public function all(): array {
				return [];
			}
			public function get_password_changed_at( int $term_id ): ?int {
				// 模擬 production：空字串視為未設定。
				if ( '' === $this->raw_meta_value || null === $this->raw_meta_value ) {
					return null;
				}
				return (int) $this->raw_meta_value;
			}
		};
	}
}
