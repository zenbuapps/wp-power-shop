<?php
/**
 * Partner 自助修密碼 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ChangePasswordInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ChangePasswordOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ClockInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\LoginRateLimiter;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerPassword;

/**
 * Partner 自助修密碼 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3
 *
 * 流程（嚴格順序，違反 = 退回重做）：
 *   1. pseudo-slug = "pwchange:{partner_term_id}" → assert_not_blocked
 *      （與 partner login slug 維度隔離；防 pwchange 失敗污染 login slug）
 *   2. find_by_id → null 拋 PartnerNotFound（不 record_failure，避免 rate-limit 被 unknown id 噪音消耗）
 *   3. verify_password → false：record_failure(pseudo_slug, ip) → 拋 InvalidCredentials
 *   4. new PartnerPassword(new_password) → 弱拋 WeakPassword（rate-limit 不動，
 *      避免使用者按錯 5 次格式就被鎖）
 *   5. PartnerRepository::save($snap, $new_password)
 *      （save 內部 update _partner_password_changed_at termmeta）
 *   6. reset(pseudo_slug, ip) 清雙維度計數
 *   7. audit log（不含明文密碼 / hash / token）
 *   8. return ChangePasswordOutput{ success: true, password_changed_at }
 *
 * 沒有 catch：Domain Exception 透傳給 V2Api callback，由 ExceptionMapper 對映。
 */
final class ChangePasswordUseCase {

	/**
	 * Pseudo-slug 前綴（與 partner login slug 維度隔離；測試鎖死字面值）
	 *
	 * @var string
	 */
	private const PWCHANGE_PSEUDO_SLUG_PREFIX = 'pwchange:';

	/**
	 * 建構子
	 *
	 * 採 nominal interface 注入（DIP）：
	 *   - PartnerRepositoryInterface（查 partner / verify / save）
	 *   - LoginRateLimiter（具體類，與 PartnerAuthService 一致；含雙維度 slug + IP）
	 *   - ClockInterface（提供 now(): int，用以填 password_changed_at）
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner repository
	 * @param LoginRateLimiter           $limiter     Rate limiter（與 login 共用，但 slug 維度走 pseudo-slug）
	 * @param ClockInterface             $clock       時鐘
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo,
		private readonly LoginRateLimiter $limiter,
		private readonly ClockInterface $clock
	) {}

	/**
	 * 執行 partner 自助修密碼
	 *
	 * @param ChangePasswordInput $input 輸入（含 partner_term_id + current_password + new_password）
	 * @param string|null         $ip    Client IP（per-IP rate-limit；無效 / null 退化成只走 pseudo-slug 維度）
	 *
	 * @return ChangePasswordOutput 成功回 success=true + 變更後的 password_changed_at
	 *
	 * @throws PartnerNotFound    當 partner_term_id 不存在.
	 * @throws InvalidCredentials 當 current_password 錯（已記錄 rate-limit 失敗）.
	 *
	 * 透傳例外（由內部呼叫拋出，不在此函式 throw new）：
	 *   - \J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts
	 *     由 LoginRateLimiter::assert_not_blocked 雙維度（pseudo-slug + IP）達門檻時拋.
	 *   - \J7\PowerShop\Domains\ProfitShop\Domain\Exception\WeakPassword
	 *     由 PartnerPassword constructor 弱密碼時拋（rate-limit 不動）.
	 */
	public function execute( ChangePasswordInput $input, ?string $ip = null ): ChangePasswordOutput {
		$pseudo_slug = self::PWCHANGE_PSEUDO_SLUG_PREFIX . $input->partner_term_id;

		// 1. rate-limit 雙維度檢查（任一達門檻 → TooManyAttempts）
		$this->limiter->assert_not_blocked( $pseudo_slug, $ip );

		// 2. 取出 partner（不存在不 record_failure，避免 unknown id 消耗計數）
		$partner = $this->partnerRepo->find_by_id( $input->partner_term_id );
		if ( null === $partner ) {
			throw new PartnerNotFound();
		}

		// 3. 驗證 current_password（錯 → 雙維度 record_failure → InvalidCredentials）
		$ok = $this->partnerRepo->verify_password( $input->partner_term_id, $input->current_password );
		if ( ! $ok ) {
			$this->limiter->record_failure( $pseudo_slug, $ip );
			throw new InvalidCredentials();
		}

		// 4. 驗證 new_password 強度（弱 → WeakPassword；rate-limit 不動，避免格式錯誤消耗計數）
		new PartnerPassword( $input->new_password );

		// 5. 寫入新密碼（save 內部會 update _partner_password_changed_at termmeta）
		$this->partnerRepo->save( $partner, $input->new_password );

		// 6. 成功 → 清 rate-limit 雙維度
		$this->limiter->reset( $pseudo_slug, $ip );

		// 7. 取得變更後的 password_changed_at（用 repository 反查，與 token 撤銷比對的真值一致）
		$changed_at = $this->partnerRepo->get_password_changed_at( $input->partner_term_id );
		if ( null === $changed_at ) {
			// fallback：若 repository 因任何原因未寫入，用 clock 當下時間補位（不阻擋成功路徑）
			$changed_at = $this->clock->now();
		}

		// 8. audit log：絕不包含明文密碼 / hash / token；過濾換行 / tab 防 log injection
		\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[power-shop][audit] partner-password-change partner_term_id=%d timestamp=%d',
				$input->partner_term_id,
				$changed_at
			)
		);

		return new ChangePasswordOutput(
			success: true,
			password_changed_at: $changed_at,
		);
	}
}
