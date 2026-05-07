<?php
/**
 * WP Transient 適配器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\TransientStoreInterface;

/**
 * WordPress transient API 的 production 適配器
 *
 * 實作 TransientStoreInterface 直接 wrap WP 的三個全域函式。
 * 測試端由 Tests\Support\InMemoryTransientStore 取代以避免依賴 WP runtime。
 */
final class WpTransientStore implements TransientStoreInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 寫入 transient
	 *
	 * @param string $key   transient key
	 * @param mixed  $value 任意 serialisable 值
	 * @param int    $ttl   TTL 秒數（0 = 永久）
	 *
	 * @return bool
	 */
	public function set( string $key, mixed $value, int $ttl ): bool {
		return (bool) \set_transient( $key, $value, $ttl );
	}

	/**
	 * 讀取 transient
	 *
	 * @param string $key transient key
	 *
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		return \get_transient( $key );
	}

	/**
	 * 刪除 transient
	 *
	 * @param string $key transient key
	 *
	 * @return bool
	 */
	public function delete( string $key ): bool {
		return (bool) \delete_transient( $key );
	}
}
