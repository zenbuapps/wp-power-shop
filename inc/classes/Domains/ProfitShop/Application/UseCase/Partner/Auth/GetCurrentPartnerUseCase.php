<?php
/**
 * 取得目前登入 Partner UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;

/**
 * 由 token 反查 Partner 資訊（partner-auth/me）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3
 *
 * 行為：
 *   - token 無效 / 過期 → 拋 InvalidCredentials（401）
 *   - token 命中但 partner 已被刪 → 拋 InvalidCredentials（防殘留 token）
 */
final class GetCurrentPartnerUseCase {

	/**
	 * 建構子
	 *
	 * @param PartnerTokenStore          $tokens   Token 儲存
	 * @param PartnerRepositoryInterface $partners Partner Repository
	 */
	public function __construct(
		private readonly PartnerTokenStore $tokens,
		private readonly PartnerRepositoryInterface $partners
	) {}

	/**
	 * 取得目前 token 對應的 Partner
	 *
	 * @param string $token 明文 token
	 *
	 * @return PartnerSnapshot
	 *
	 * @throws InvalidCredentials Token 無效 / 過期 / partner 已被刪.
	 */
	public function execute( string $token ): PartnerSnapshot {
		$partner_term_id = $this->tokens->verify( $token );
		if ( null === $partner_term_id ) {
			throw new InvalidCredentials();
		}

		$snapshot = $this->partners->find_by_id( $partner_term_id );
		if ( null === $snapshot ) {
			throw new InvalidCredentials();
		}

		return $snapshot;
	}
}
