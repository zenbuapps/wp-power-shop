<?php
/**
 * 列出分潤夥伴 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Support\PartnerHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;

/**
 * 列出分潤夥伴 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2
 *
 * 回傳 PartnerOutput[]，**含明文密碼**（`PartnerOutput::$password`）。
 *
 * 🔴 明文只能回給 `manage_options`。承載本 UseCase 的 `GET /profit-partners`
 * 門檻只有 `manage_woocommerce`（比寫入側寬），因此 V2Api 在輸出前一律套
 * `V2Api::mask_partner_password_for_current_user()` 把非站長的 password 遮成 null。
 * **任何新增的、會回傳本 UseCase 結果的 endpoint 都必須照套**，否則明文外洩，
 * 而 phpcs / phpstan / PHPUnit 一個都不會紅。背景見 `PartnerOutput` 的 class docblock。
 */
final class ListPartners {

	/**
	 * 建構子
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner Repository
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo
	) {}

	/**
	 * 執行列表查詢
	 *
	 * @return PartnerOutput[]
	 */
	public function execute(): array {
		$partners = $this->partnerRepo->all();

		$out = [];
		foreach ( $partners as $partner ) {
			$out[] = PartnerHydrator::to_output( $partner );
		}
		return $out;
	}
}
