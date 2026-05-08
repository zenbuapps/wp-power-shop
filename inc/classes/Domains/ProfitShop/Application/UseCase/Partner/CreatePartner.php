<?php
/**
 * 建立分潤夥伴 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerOutput;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Support\PartnerHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;

/**
 * 建立分潤夥伴 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.2、§6.3
 */
final class CreatePartner {

	/**
	 * 建構子
	 *
	 * @param PartnerRepositoryInterface $partnerRepo Partner Repository
	 */
	public function __construct(
		private readonly PartnerRepositoryInterface $partnerRepo
	) {}

	/**
	 * 執行建立
	 *
	 * @param PartnerInput $input Partner 輸入 DTO
	 *
	 * @return PartnerOutput 建立後輸出 DTO（不含密碼）
	 *
	 * @throws InvalidPartnerSlug 當 slug 不合法（PartnerSlug VO 觸發）
	 * @throws PartnerNotFound    當建立後找不到（理論上不應發生）
	 */
	public function execute( PartnerInput $input ): PartnerOutput {
		$slug = new PartnerSlug( $input->slug ); // 觸發 InvalidPartnerSlug

		// 預先檢查 slug 是否已被佔用，回 409 slug_conflict（避免 wp_insert_term 拋 term_exists 走 500）。
		// conflict_kind 與 SlugConflictDetector / WpSlugConflictLookup 統一為 'profit_partner'
		// （與 taxonomy slug 一致；BUG-1 雙審 BLOCKING-2 修正：統一 partner conflict 命名）。
		if ( null !== $this->partnerRepo->find_by_slug( $slug->value() ) ) {
			throw new SlugConflictException(
				[
					new SlugConflict(
						conflict_kind: 'profit_partner',
						conflicting_slug: $slug->value(),
						conflicting_id: null,
						conflicting_label: '已存在同名 Partner'
					),
				]
			);
		}

		$snapshot = new PartnerSnapshot(
			term_id: 0,
			name: $input->name,
			slug: $slug,
			contact_email: $input->contact_email
		);

		$term_id = $this->partnerRepo->save( $snapshot, $input->password );

		$persisted = $this->partnerRepo->find_by_id( $term_id );
		if ( null === $persisted ) {
			throw new PartnerNotFound( "建立後找不到 partner term {$term_id}" );
		}

		return PartnerHydrator::to_output( $persisted );
	}
}
