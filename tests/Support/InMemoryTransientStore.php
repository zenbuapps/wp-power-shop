<?php
/**
 * 純記憶體 Transient Store（測試替身）
 *
 * 模擬 WP transient API（set_transient / get_transient / delete_transient）。
 * 提供 PartnerTokenStore 與 LoginRateLimiter 純單元測試使用。
 *
 * Phase 3-C 紅燈規範：
 *   PartnerTokenStore / LoginRateLimiter 必須允許注入 transient interface
 *   以便不啟 WP 也能驗證行為。具體 interface 由 master 在綠燈階段實作；
 *   本 fake 提供常見三方法（set/get/delete）+ TTL 過期模擬 + dump helper。
 */

declare(strict_types=1);

namespace Tests\Support;

/**
 * 模擬 WP transient 的純記憶體實作
 *
 * 行為：
 *   - set( $key, $value, $ttl )：寫入；ttl > 0 才設 expires_at
 *   - get( $key )：過期回 false（與 WP 一致）；不存在亦回 false
 *   - delete( $key )：清除
 *
 * 配合 FixedClock 可模擬時間流動驗證 TTL 過期。
 */
final class InMemoryTransientStore {

	/**
	 * 內部儲存
	 *
	 * @var array<string, array{value: mixed, expires_at: int}>
	 */
	private array $store = [];

	/**
	 * 注入時鐘（讀取現在時間）
	 *
	 * @var FixedClock
	 */
	private FixedClock $clock;

	/**
	 * 建構子
	 *
	 * @param FixedClock|null $clock 注入時鐘；null 則自建一個從 1_000_000_000 起算的固定時鐘
	 */
	public function __construct( ?FixedClock $clock = null ) {
		$this->clock = $clock ?? new FixedClock( 1_000_000_000 );
	}

	/**
	 * 取得時鐘（測試需手動推進時間時用）
	 */
	public function clock(): FixedClock {
		return $this->clock;
	}

	/**
	 * 寫入 transient
	 *
	 * @param string $key   transient key
	 * @param mixed  $value 任意 serialisable 值
	 * @param int    $ttl   TTL 秒數（0 = 永久；> 0 = 經過 ttl 秒後過期）
	 */
	public function set( string $key, mixed $value, int $ttl ): bool {
		$this->store[ $key ] = [
			'value'      => $value,
			'expires_at' => $ttl > 0 ? $this->clock->now() + $ttl : 0,
		];
		return true;
	}

	/**
	 * 讀取 transient（過期 → false；不存在 → false）
	 *
	 * @param string $key transient key
	 *
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		if ( ! isset( $this->store[ $key ] ) ) {
			return false;
		}

		$entry = $this->store[ $key ];
		if ( $entry['expires_at'] > 0 && $entry['expires_at'] <= $this->clock->now() ) {
			unset( $this->store[ $key ] );
			return false;
		}

		return $entry['value'];
	}

	/**
	 * 刪除 transient
	 *
	 * @param string $key transient key
	 */
	public function delete( string $key ): bool {
		$existed = isset( $this->store[ $key ] );
		unset( $this->store[ $key ] );
		return $existed;
	}

	// ========== 測試 helper ==========

	/**
	 * Dump 全部 key（含過期；用於驗證內容）
	 *
	 * @return array<string, array{value: mixed, expires_at: int}>
	 */
	public function dump(): array {
		return $this->store;
	}

	/**
	 * 取得指定 prefix 的所有 entry（驗證 token 不外洩明文時使用）
	 *
	 * @param string $prefix key prefix
	 *
	 * @return array<string, array{value: mixed, expires_at: int}>
	 */
	public function dump_with_prefix( string $prefix ): array {
		$result = [];
		foreach ( $this->store as $key => $entry ) {
			if ( str_starts_with( $key, $prefix ) ) {
				$result[ $key ] = $entry;
			}
		}
		return $result;
	}

	/**
	 * 強制把指定 key 的 expires_at 改寫（模擬時間到 / 強制過期）
	 *
	 * @param string $key        transient key
	 * @param int    $expires_at unix timestamp
	 */
	public function force_expires_at( string $key, int $expires_at ): void {
		if ( isset( $this->store[ $key ] ) ) {
			$this->store[ $key ]['expires_at'] = $expires_at;
		}
	}

	/**
	 * 清空所有 entry
	 */
	public function flush(): void {
		$this->store = [];
	}

	/**
	 * 計算目前 entry 數
	 */
	public function size(): int {
		return count( $this->store );
	}
}
