<?php
/**
 * Partner 認證 Service（Phase 3-A 骨架）
 *
 * @phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
 * @phpcs:disable Squiz.Commenting.FunctionComment.IncorrectTypeHint
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * 處理 Partner 登入 token 的簽發與驗證
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 / §8
 *
 * 預定責任（Phase 3-C 實作）：
 * - issue_token：以 Partner term_id 簽發 token，寫入 PartnerTokenStore
 * - verify_request：從 WP_REST_Request 抽 token，回傳對應 partner_id（無效則回 null）
 *
 * Phase 3-A 僅交付骨架；method body 拋 BadMethodCallException。
 */
final class PartnerAuthService {

	/**
	 * 為指定 Partner 簽發認證 token
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return string token 字串
	 */
	public function issue_token( int $term_id ): string {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-C' );
	}

	/**
	 * 驗證請求中的 Partner token
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return int|null 通過驗證時回傳 partner term_id；否則回傳 null
	 */
	public function verify_request( \WP_REST_Request $request ): ?int {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-C' );
	}
}
