<?php
/**
 * 站長指定 Partner 密碼 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerPassword;

/**
 * 站長為 Partner 指定一組密碼（admin only）
 *
 * 與姊妹 UseCase 的分工：
 *
 * | UseCase | 密碼來源 | 使用時機 |
 * |---------|----------|----------|
 * | `RegeneratePartnerPasswordUseCase` | `wp_generate_password(12)` 系統隨機 | 夥伴忘記密碼 / 疑似外洩，站長一鍵重設強密碼 |
 * | **本 UseCase** | 站長指定 | 站長要主動把一組自己知道的密碼交給夥伴 |
 * | `ChangePasswordUseCase` | partner 指定 | 夥伴自助改密碼（需驗 current_password） |
 *
 * 行為：
 *   - 明文密碼先過 `PartnerPassword` VO（≥ 8 codepoint + ≥ 1 英文 + ≥ 1 數字），
 *     不合格拋 `WeakPassword`（→ 422 weak_password + data.reasons[]）
 *   - `PartnerRepository::save($snap, $new_pw)` 寫回，hash 與 `password_changed_at`
 *     由 repository 處理；後者一 bump，該 partner 所有舊 token 立即失效
 *     （`PartnerTokenStore::verify` 比對 `issued_at <= password_changed_at`）
 *   - 未知 partner_term_id → 拋 `PartnerNotFound`
 *
 * ⚠️ 不回傳密碼：明文由呼叫端（站長）自己輸入，本來就已經知道，
 * 沒有像 regenerate 那樣「系統產生完必須顯示一次」的需求。
 * 回傳明文只會多一個沒必要的洩漏面（response body 進 log / proxy cache）。
 */
final class SetPartnerPasswordUseCase {

	/**
	 * 建構子
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner Repository
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo
	) {}

	/**
	 * 指定密碼
	 *
	 * 例外（兩者都是 \DomainException，由 ExceptionMapper 對映）：
	 *  - `PartnerNotFound` → 404，partner 不存在
	 *  - `WeakPassword` → 422 weak_password + data.reasons[]，密碼強度不足
	 *
	 * @param int    $partner_term_id Partner term ID
	 * @param string $plain_password  站長指定的明文密碼
	 *
	 * @return int 密碼變更時間（unix timestamp）
	 *
	 * @throws PartnerNotFound Partner 不存在時（優先於強度檢查，避免變成存在性 oracle）
	 */
	public function execute( int $partner_term_id, string $plain_password ): int {
		$existing = $this->partnerRepo->find_by_id( $partner_term_id );
		if ( null === $existing ) {
			throw new PartnerNotFound( "找不到 partner term {$partner_term_id}" );
		}

		// 強度驗證必須在 find_by_id 之後、save 之前：
		// 先確認 partner 存在，弱密碼才不會對「不存在的 partner」回 422 而洩漏存在性。
		$password = new PartnerPassword( $plain_password );

		$this->partnerRepo->save( $existing, $password->value() );

		$changed_at = $this->partnerRepo->get_password_changed_at( $partner_term_id );

		// save() 必定寫入 password_changed_at；讀不到代表 persistence 異常。
		return $changed_at ?? time();
	}
}
