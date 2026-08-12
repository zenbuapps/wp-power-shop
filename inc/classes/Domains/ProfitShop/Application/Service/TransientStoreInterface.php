<?php
/**
 * Transient 抽象介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Transient 存取抽象（Port）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3
 *
 * Application Service（PartnerTokenStore / LoginRateLimiter）依賴此介面，
 * Production 由 WpTransientStore 包 set_transient/get_transient/delete_transient，
 * Test 由 InMemoryTransientStore 取代以避免依賴 WP runtime。
 *
 * 行為與 WP transient API 一致：
 * - get：找不到或已過期 → 回 false（不丟例外）
 * - set：ttl=0 視為永久；ttl>0 為相對秒數
 * - delete：清除 key（idempotent）
 */
interface TransientStoreInterface {

	/**
	 * 寫入 transient
	 *
	 * @param string $key   transient key
	 * @param mixed  $value 任意 serialisable 值
	 * @param int    $ttl   TTL 秒數（0 = 永久）
	 *
	 * @return bool 成功為 true
	 */
	public function set( string $key, mixed $value, int $ttl ): bool;

	/**
	 * 讀取 transient（不存在 / 過期 → false）
	 *
	 * @param string $key transient key
	 *
	 * @return mixed
	 */
	public function get( string $key ): mixed;

	/**
	 * 刪除 transient
	 *
	 * @param string $key transient key
	 *
	 * @return bool 成功為 true（含原本不存在的情況）
	 */
	public function delete( string $key ): bool;
}
