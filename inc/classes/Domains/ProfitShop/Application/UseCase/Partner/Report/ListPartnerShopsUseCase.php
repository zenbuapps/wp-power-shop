<?php
/**
 * 列出 partner 報表可篩選的賣場清單
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementSummaryProviderInterface;

/**
 * 取得該 partner 曾產生過業績的賣場清單（供報表的賣場篩選器）
 *
 * 🔴 `$partner_term_id` **必須**由 caller（V2Api 的 permission_callback 從 token 解出）傳入，
 * 與 KPI / Trend / Settlements 三個 UseCase 同一條紀律：**永遠不得從 query string 取**。
 * 這裡多一層強調是因為本清單決定「使用者能篩哪些賣場」，若可由外部指定 partner_term_id，
 * 等於把「別人有哪些賣場」變成可列舉的資訊。
 *
 * ⚠️ 清單來源與「為什麼不查 powershop CPT」的完整推導見
 * `SettlementSummaryProviderInterface::shops_for_partner()`。
 */
final class ListPartnerShopsUseCase {

	/**
	 * 建構子
	 *
	 * @param SettlementSummaryProviderInterface $summary 結算摘要 provider
	 */
	public function __construct(
		private readonly SettlementSummaryProviderInterface $summary
	) {}

	/**
	 * 執行查詢
	 *
	 * @param int $partner_term_id partner term ID（來自 token，非 query string）
	 *
	 * @return array<int, array{shop_id: int, shop_name: string}>
	 */
	public function execute( int $partner_term_id ): array {
		if ( $partner_term_id <= 0 ) {
			return [];
		}

		return $this->summary->shops_for_partner( $partner_term_id );
	}
}
