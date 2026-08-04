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
	 * 將 PartnerSnapshot 轉成 PartnerOutput
	 *
	 * ⚠️ 產出的 `PartnerOutput` **含明文密碼**，只能回給 admin-only endpoint。
	 * partner-facing endpoint 請自行列舉欄位輸出，不要呼叫這個方法——
	 * 理由見 `PartnerOutput` 的 class docblock。
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
			created_at: $created_at,
			image_id: $partner->image_id,
			image_url: $partner->image_url,
			report_url: $partner->report_url,
			password: $partner->password
		);
	}
}
