<?php
/**
 * 重新產生 Partner 密碼 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;

/**
 * 重新產生 Partner 密碼 UseCase（admin only）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2、Phase 3-B observation 1
 *
 * 行為：
 *   - 用 wp_generate_password(12) 產隨機密碼
 *   - PartnerRepository::save($snap, $new_pw) 寫回（hash 由 repository 處理）
 *   - 回明文一次性顯示給 admin（呼叫端絕不可 echo / log 該明文）
 *   - 未知 partner_term_id → 拋 PartnerNotFound
 */
final class RegeneratePartnerPasswordUseCase {

	private const PASSWORD_LENGTH = 12;

	/**
	 * 建構子
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner Repository
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo
	) {}

	/**
	 * 重新產生密碼
	 *
	 * @param int $partner_term_id Partner term ID
	 *
	 * @return string 新明文密碼（一次性，呼叫端應立即顯示且不持久化）
	 *
	 * @throws PartnerNotFound Partner 不存在時
	 */
	public function execute( int $partner_term_id ): string {
		$existing = $this->partnerRepo->find_by_id( $partner_term_id );
		if ( null === $existing ) {
			throw new PartnerNotFound( "找不到 partner term {$partner_term_id}" );
		}

		$new_password = \wp_generate_password( self::PASSWORD_LENGTH, true, false );

		// PartnerRepository::save 會把第二參數 wp_hash_password 寫進 termmeta，僅明文不持久。
		$this->partnerRepo->save( $existing, $new_password );

		return $new_password;
	}
}
