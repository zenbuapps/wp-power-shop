<?php
/**
 * Partner 登入 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerLoginInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerLoginOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerAuthService;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;

/**
 * Partner 登入 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 / §7
 *
 * 流程：
 *   1. PartnerAuthService::attempt_login() 取得 PartnerSnapshot
 *      （失敗時拋 InvalidCredentials / TooManyAttempts，由 V2Api 對映 401/429）
 *   2. PartnerTokenStore::issue() 簽發 token
 *   3. 組裝 PartnerLoginOutput
 */
final class LoginPartnerUseCase {

	/**
	 * 建構子
	 *
	 * @param PartnerAuthService $auth   Partner 認證 Service
	 * @param PartnerTokenStore  $tokens Token 儲存 Service
	 */
	public function __construct(
		private readonly PartnerAuthService $auth,
		private readonly PartnerTokenStore $tokens
	) {}

	/**
	 * 執行登入
	 *
	 * @param PartnerLoginInput $input 登入輸入
	 *
	 * @return PartnerLoginOutput 含 token 的登入回應
	 */
	public function execute( PartnerLoginInput $input ): PartnerLoginOutput {
		$snapshot = $this->auth->attempt_login( $input->slug, $input->password );

		$issued = $this->tokens->issue( $snapshot->term_id );

		return new PartnerLoginOutput(
			token: $issued['token'],
			expires_at: (string) $issued['expires_at'],
			partner_id: $snapshot->term_id,
			partner_name: $snapshot->name,
		);
	}
}
