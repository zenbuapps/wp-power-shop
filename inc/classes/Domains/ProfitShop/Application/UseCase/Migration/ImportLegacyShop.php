<?php
/**
 * 匯入舊版一頁商店為分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\LegacyShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\LegacyShopNotImportable;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;

/**
 * 匯入舊版一頁商店為分潤賣場 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * 業務規則（OQ-4）：
 *   舊版「一頁商店」沒有 partner 概念，匯入時必須由 admin 指定 partner_term_id；
 *   缺 partner_term_id → 拋 LegacyShopNotImportable( reason='partner_term_missing' )
 *
 * 匯入後的賣場：
 * - mode 預設 page
 * - status 預設 draft（避免立即上線）
 * - title / slug 沿用原資料
 * - 不刪除舊版資料（只新增）
 */
final class ImportLegacyShop {

	/**
	 * 建構子
	 *
	 * @param LegacyShopRepositoryInterface $legacyRepo  Legacy 資料源
	 * @param ProfitShopRepositoryInterface $shopRepo    Shop Repository
	 * @param PartnerRepositoryInterface    $partnerRepo Partner Repository
	 */
	public function __construct(
		private readonly LegacyShopRepositoryInterface $legacyRepo,
		private readonly ProfitShopRepositoryInterface $shopRepo,
		private readonly PartnerRepositoryInterface $partnerRepo
	) {}

	/**
	 * 執行匯入
	 *
	 * @param int $legacy_id       舊版資料 ID
	 * @param int $partner_term_id 指定的 Partner term ID
	 *
	 * @return ProfitShopOutput 匯入後新建的 ProfitShop
	 *
	 * @throws LegacyShopNotImportable 當 partner_term_id <= 0 或 legacy 不存在
	 * @throws PartnerNotFound         當 partner 不存在
	 * @throws ProfitShopNotFound      當寫入後查無資料
	 */
	public function execute( int $legacy_id, int $partner_term_id ): ProfitShopOutput {
		if ( $partner_term_id <= 0 ) {
			throw new LegacyShopNotImportable( 'partner_term_missing' );
		}

		$legacy = $this->legacyRepo->find( $legacy_id );
		if ( null === $legacy ) {
			throw new LegacyShopNotImportable( 'legacy_not_found' );
		}

		if ( null === $this->partnerRepo->find_by_id( $partner_term_id ) ) {
			throw new PartnerNotFound( "Partner term {$partner_term_id} 不存在" );
		}

		$title    = (string) ( $legacy['title'] ?? '舊版匯入賣場' );
		$slug_raw = (string) ( $legacy['slug'] ?? '' );
		$slug     = '' === $slug_raw ? 'legacy-import-' . $legacy_id : $slug_raw;
		$meta     = is_array( $legacy['meta'] ?? null ) ? $legacy['meta'] : [];

		$shop = new ProfitShop(
			id: 0,
			title: $title,
			slug: $slug,
			status: 'draft',
			mode: ShopMode::PAGE,
			partner_term_id: $partner_term_id,
			rate: new ProfitRate( 0 ),
			items: [],
			settings: $meta
		);

		$new_id    = $this->shopRepo->save( $shop );
		$persisted = $this->shopRepo->find( $new_id );
		if ( null === $persisted ) {
			throw new ProfitShopNotFound( "匯入後找不到新賣場 ID {$new_id}" );
		}

		return ProfitShopHydrator::to_output( $persisted );
	}
}
