<?php
/**
 * In-Memory PartnerRepository（測試替身）
 *
 * 提供 PartnerRepositoryInterface 的純記憶體實作。
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Fakes;

use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;

/**
 * 純記憶體 Partner Repository
 *
 * @internal Phase 3-B 測試用替身
 */
final class InMemoryPartnerRepository implements PartnerRepositoryInterface {

	/**
	 * 內部儲存：term_id => PartnerSnapshot
	 *
	 * @var array<int, PartnerSnapshot>
	 */
	private array $partners = [];

	/**
	 * term_id => 雜湊密碼（測試用以驗證雜湊呼叫；不真做 wp_hash_password）
	 *
	 * @var array<int, string>
	 */
	private array $passwords = [];

	/**
	 * term_id => 是否仍掛在賣場
	 *
	 * @var array<int, bool>
	 */
	private array $in_use_flags = [];

	/**
	 * 自增 ID
	 *
	 * @var int
	 */
	private int $next_id = 5000;

	/**
	 * @param string $slug Partner slug 字串
	 */
	public function find_by_slug( string $slug ): ?PartnerSnapshot {
		foreach ( $this->partners as $snapshot ) {
			if ( $snapshot->slug->value() === $slug ) {
				return $snapshot;
			}
		}
		return null;
	}

	/**
	 * @param int $term_id Partner term ID
	 */
	public function find_by_id( int $term_id ): ?PartnerSnapshot {
		return $this->partners[ $term_id ] ?? null;
	}

	/**
	 * @param PartnerSnapshot $partner        Partner 資訊
	 * @param string|null     $plain_password 明文密碼
	 */
	public function save( PartnerSnapshot $partner, ?string $plain_password = null ): int {
		$term_id = $partner->term_id > 0 ? $partner->term_id : ++$this->next_id;

		$this->partners[ $term_id ] = new PartnerSnapshot(
			term_id: $term_id,
			name: $partner->name,
			slug: $partner->slug,
			contact_email: $partner->contact_email,
		);

		if ( null !== $plain_password ) {
			$this->passwords[ $term_id ] = 'hashed::' . $plain_password;
		}

		return $term_id;
	}

	/**
	 * @param int $term_id Partner term ID
	 */
	public function is_in_use( int $term_id ): bool {
		return $this->in_use_flags[ $term_id ] ?? false;
	}

	/**
	 * @param int    $term_id        Partner term ID
	 * @param string $plain_password 明文密碼
	 */
	public function verify_password( int $term_id, string $plain_password ): bool {
		$expected = $this->passwords[ $term_id ] ?? null;
		if ( null === $expected ) {
			return false;
		}
		return $expected === 'hashed::' . $plain_password;
	}

	/**
	 * @param int $term_id Partner term ID
	 */
	public function delete( int $term_id ): void {
		unset( $this->partners[ $term_id ], $this->passwords[ $term_id ], $this->in_use_flags[ $term_id ] );
	}

	// ========== 測試專用 helper ==========

	/**
	 * 預植入 partner（指定 term_id；覆寫既有）
	 */
	public function seed( int $term_id, string $name, string $slug, ?string $email = null ): PartnerSnapshot {
		$snapshot                   = new PartnerSnapshot(
			term_id: $term_id,
			name: $name,
			slug: new PartnerSlug( $slug ),
			contact_email: $email,
		);
		$this->partners[ $term_id ] = $snapshot;
		return $snapshot;
	}

	/**
	 * 標記某 partner 仍掛在賣場上（讓 is_in_use 回 true）
	 */
	public function mark_in_use( int $term_id, bool $in_use = true ): void {
		$this->in_use_flags[ $term_id ] = $in_use;
	}

	/**
	 * 取得所有 partner（測試用 listing）
	 *
	 * @return PartnerSnapshot[]
	 */
	public function all(): array {
		return array_values( $this->partners );
	}

	/**
	 * 取得密碼雜湊（用於驗證密碼有被寫入）
	 */
	public function stored_password_hash( int $term_id ): ?string {
		return $this->passwords[ $term_id ] ?? null;
	}

	/**
	 * 計算筆數
	 */
	public function count(): int {
		return count( $this->partners );
	}
}
