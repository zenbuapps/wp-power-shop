<?php
/**
 * 登入速率限制 Service（Phase 3-A 骨架）
 *
 * @phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
 * @phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;

/**
 * Partner 登入速率限制（per-slug 計數器 + 窗口）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 / §8
 *
 * 預定責任（Phase 3-C 實作）：
 * - assert_allowed：超出閾值即拋 TooManyAttempts
 * - record_failure：紀錄一次失敗
 * - reset：登入成功後重置
 *
 * Phase 3-A 僅交付骨架；method body 拋 BadMethodCallException。
 */
final class LoginRateLimiter {

	/**
	 * 確認指定 slug 的登入嘗試仍在允許範圍
	 *
	 * @param string $slug Partner slug
	 *
	 * @throws TooManyAttempts          當超出嘗試次數時
	 * @throws \BadMethodCallException  Phase 3-A 尚未實作
	 *
	 * @return void
	 */
	public function assert_allowed( string $slug ): void {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-C' );
	}

	/**
	 * 紀錄一次失敗嘗試
	 *
	 * @param string $slug Partner slug
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return void
	 */
	public function record_failure( string $slug ): void {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-C' );
	}

	/**
	 * 重置指定 slug 的失敗次數（登入成功後呼叫）
	 *
	 * @param string $slug Partner slug
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return void
	 */
	public function reset( string $slug ): void {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-C' );
	}
}
