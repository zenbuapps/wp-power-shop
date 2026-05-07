<?php
/**
 * Partner DTO Hydrator
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Support;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerOutput;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;

/**
 * Partner Snapshot ↔ Output 對映工具
 */
final class PartnerHydrator {

	/**
	 * 將 PartnerSnapshot 轉成 PartnerOutput（不含密碼）
	 *
	 * @param PartnerSnapshot $partner    Partner Snapshot
	 * @param string          $created_at 建立時間（可選）
	 *
	 * @return PartnerOutput
	 */
	public static function to_output(
		PartnerSnapshot $partner,
		string $created_at = ''
	): PartnerOutput {
		return new PartnerOutput(
			id: $partner->term_id,
			name: $partner->name,
			slug: $partner->slug->value(),
			contact_email: $partner->contact_email,
			created_at: $created_at
		);
	}
}
