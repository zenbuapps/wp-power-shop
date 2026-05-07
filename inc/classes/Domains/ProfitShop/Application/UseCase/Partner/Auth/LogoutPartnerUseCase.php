<?php
/**
 * Partner 登出 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;

/**
 * Partner 登出 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3
 *
 * 行為：
 *   - idempotent：未知 token 也不拋（safe-by-default）
 *   - 清除 transient
 */
final class LogoutPartnerUseCase {

	/**
	 * 建構子
	 *
	 * @param PartnerTokenStore $tokens Token 儲存 Service
	 */
	public function __construct(
		private readonly PartnerTokenStore $tokens
	) {}

	/**
	 * 執行登出（撤銷 token）
	 *
	 * @param string $token 明文 token
	 *
	 * @return void
	 */
	public function execute( string $token ): void {
		$this->tokens->revoke( $token );
	}
}
