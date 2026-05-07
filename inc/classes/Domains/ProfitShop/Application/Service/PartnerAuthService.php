<?php
/**
 * Partner 認證 Service
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;

/**
 * Partner 登入認證流程
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§6.4、§8
 *
 * 流程：
 *   1. LoginRateLimiter::assert_not_blocked($slug)：被鎖時拋 TooManyAttempts，
 *      不繼續查 partner（縮減 timing oracle 表面）
 *   2. PartnerRepository::find_by_slug($slug)：找不到 → 拋 InvalidCredentials
 *      （統一錯誤訊息以避免帳號列舉攻擊）
 *   3. PartnerRepository::verify_password()：失敗 → record_failure → 拋 InvalidCredentials
 *   4. 成功 → reset → 回 PartnerSnapshot
 */
final class PartnerAuthService {

	/**
	 * 建構子
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner Repository
	 * @param LoginRateLimiter           $limiter     登入速率限制
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo,
		private readonly LoginRateLimiter $limiter
	) {}

	/**
	 * 嘗試登入
	 *
	 * @param string $slug           Partner slug
	 * @param string $plain_password 明文密碼
	 *
	 * @return PartnerSnapshot 登入成功的 Partner 資訊
	 *
	 * @throws TooManyAttempts 登入嘗試次數過多時.
	 * @throws InvalidCredentials Slug 不存在或密碼錯誤時（統一錯誤）.
	 *
	 * @phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
	 */
	public function attempt_login( string $slug, string $plain_password ): PartnerSnapshot {
		// 1. 先檢查是否已被鎖定（攻擊面短路）
		$this->limiter->assert_not_blocked( $slug );

		// 2. 取得 partner（不存在 → 統一回 InvalidCredentials）
		$partner = $this->partnerRepo->find_by_slug( $slug );
		if ( null === $partner ) {
			// 注意：不 record_failure（避免攻擊者透過已知不存在的 slug 間接猜測存在的 slug
			// 是否會 trigger rate-limit；但若加上 record_failure 反而能阻擋探測——
			// 此處選擇「不 record」以維持「未知 slug 與密碼錯」行為一致，避免 timing oracle）。
			throw new InvalidCredentials();
		}

		// 3. 驗證密碼
		if ( ! $this->partnerRepo->verify_password( $partner->term_id, $plain_password ) ) {
			$this->limiter->record_failure( $slug );
			throw new InvalidCredentials();
		}

		// 4. 成功，重置計數
		$this->limiter->reset( $slug );

		return $partner;
	}
}
