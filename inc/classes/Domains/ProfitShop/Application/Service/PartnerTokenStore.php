<?php
/**
 * Partner Token 儲存 Service（Phase 3-A 骨架）
 *
 * @phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Partner 登入 token 的持久化（hash → partner_id, expires_at）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 / §8
 *
 * 預定責任（Phase 3-C 實作）：
 * - 後端可選 transient / 自訂表 / object cache
 * - set / get / delete 三個基本操作
 *
 * Phase 3-A 僅交付骨架；method body 拋 BadMethodCallException。
 */
final class PartnerTokenStore {

	/**
	 * 儲存 token
	 *
	 * @param string $hash       token hash（不可回推明文）
	 * @param int    $partner_id Partner term ID
	 * @param int    $expires_at unix timestamp（秒）
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return void
	 */
	public function set( string $hash, int $partner_id, int $expires_at ): void {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-C' );
	}

	/**
	 * 取得 token 對應的 partner 資訊
	 *
	 * @param string $hash token hash
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return array{partner_id: int, expires_at: int}|null 不存在時回傳 null
	 */
	public function get( string $hash ): ?array {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-C' );
	}

	/**
	 * 刪除 token
	 *
	 * @param string $hash token hash
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return void
	 */
	public function delete( string $hash ): void {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-C' );
	}
}
