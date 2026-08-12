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
 * 回傳 PartnerOutput[]（不含密碼）。
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
