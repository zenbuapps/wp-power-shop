<?php
/**
 * In-Memory ProfitShopRepository（測試替身）
 *
 * 提供 ProfitShopRepositoryInterface 的純記憶體實作，
 * 讓 Application 層 UseCase 的單元測試不需依賴 WP / WC。
 *
 * 同時提供 seed() / all() 便於測試 setup。
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Fakes;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 純記憶體 ProfitShop Repository
 *
 * @internal Phase 3-B 測試用替身
 */
final class InMemoryProfitShopRepository implements ProfitShopRepositoryInterface {

	/**
	 * 內部儲存：id => ProfitShop
	 *
	 * @var array<int, ProfitShop>
	 */
	private array $shops = [];

	/**
	 * 自增 ID 來源
	 *
	 * @var int
	 */
	private int $next_id = 1000;

	/**
	 * 強制下一個 save 必須丟出特定例外（給錯誤路徑測試用）
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $force_save_exception = null;

	/**
	 * @param int $id 賣場 ID
	 */
	public function find( int $id ): ?ProfitShop {
		return $this->shops[ $id ] ?? null;
	}

	/**
	 * @param string $slug 賣場 slug
	 */
	public function find_by_slug( string $slug ): ?ProfitShop {
		if ( '' === $slug ) {
			return null;
		}

		foreach ( $this->shops as $shop ) {
			if ( $shop->slug === $slug ) {
				return $shop;
			}
		}

		return null;
	}

	/**
	 * @param ProfitShop $shop 賣場聚合根
	 */
	public function save( ProfitShop $shop ): int {
		if ( null !== $this->force_save_exception ) {
			$exception                  = $this->force_save_exception;
			$this->force_save_exception = null;
			throw $exception;
		}

		$id = $shop->id > 0 ? $shop->id : ++$this->next_id;

		// 由於 ProfitShop::$id 為 readonly，新建時需 clone 出帶 id 的版本。
		if ( $shop->id === $id ) {
			$persisted = $shop;
		} else {
			$persisted = new ProfitShop(
				id: $id,
				title: $shop->title,
				slug: $shop->slug,
				status: $shop->status(),
				mode: $shop->mode,
				partner_term_id: $shop->partner_term_id,
				rate: $shop->rate,
				items: $shop->items(),
				settings: $shop->settings,
			);
		}

		$this->shops[ $id ] = $persisted;
		return $id;
	}

	/**
	 * @param int $id 賣場 ID
	 */
	public function delete( int $id ): void {
		unset( $this->shops[ $id ] );
	}

	/**
	 * @param int $term_id Partner term ID
	 *
	 * @return ProfitShop[]
	 */
	public function find_by_partner( int $term_id ): array {
		return array_values(
			array_filter(
				$this->shops,
				static fn( ProfitShop $shop ): bool => $shop->partner_term_id === $term_id
			)
		);
	}

	// ========== 測試專用 helper ==========

	/**
	 * 預植入賣場（覆寫既有 id；不走 save 流程）
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 */
	public function seed( ProfitShop $shop ): void {
		$this->shops[ $shop->id ] = $shop;
	}

	/**
	 * 取得所有賣場（list-shape）
	 *
	 * 對應 master 將要新增到 interface 上的 all() 方法。
	 *
	 * @return ProfitShop[]
	 */
	public function all(): array {
		return array_values( $this->shops );
	}

	/**
	 * 強制下一次 save 拋出指定例外
	 */
	public function force_save_exception( \Throwable $e ): void {
		$this->force_save_exception = $e;
	}

	/**
	 * 計算內部紀錄筆數（用於 delete 驗證）
	 */
	public function count(): int {
		return count( $this->shops );
	}
}
