<?php
/**
 * 結算稽核紀錄 Repository（partner termmeta 實作）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditLogInterface;

/**
 * 以 partner termmeta `_partner_settled_log` 保存撥款 / 沖回稽核紀錄
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5、§7.5
 *
 * 儲存形式：單一 termmeta，值是 JSON 陣列字串。
 *
 * ## 三個必須照做的細節
 *
 * 1. **`wp_slash()` 後才寫入**。`update_term_meta()` 內部會對值跑一次 `wp_unslash()`，
 *    不先 slash 的話 JSON 裡的 `"` 會被吃掉，整串變成無法 decode 的壞字串——
 *    下一次 `read()` 只會拿到空陣列，**先前所有稽核紀錄靜默消失**。
 *    這與 `PartnerTermRepository` 寫明文密碼是同一條紀律（`profit-shop.rule.md` §6）。
 *
 * 2. **`JSON_UNESCAPED_UNICODE`**。紀錄裡沒有中文欄位，但保持與
 *    `CptProfitShopRepository` 一致，避免日後有人加入備註欄位時變成 `\uXXXX` 亂碼。
 *
 * 3. **超量截斷保留「最新」而非「最舊」**。稽核紀錄只增不減，一個高流量賣場跑幾年
 *    會讓這個 termmeta 膨脹到幾 MB，而 termmeta 每次讀 term 都會被載入。
 *    截斷時保留尾端（最近的 N 筆）——對帳看的永遠是最近的紀錄。
 *
 * ⚠️ 這裡沒有跨請求的鎖。兩個管理員在同一瞬間撥款不同 partner 不會互相影響（不同 term）；
 * 撥款**同一個** partner 時理論上可能後寫的覆蓋先寫的（read-modify-write）。
 * 實務機率極低，且遺失的是稽核紀錄而非狀態本身（狀態在 order itemmeta，各自獨立寫入，
 * 不會遺失）。要根治需要自訂資料表，屬 schema 變更，不在本次範圍。
 */
final class PartnerSettledLogRepository implements SettlementAuditLogInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Termmeta key（spec §7.5 指定）
	 *
	 * @var string
	 */
	public const META_KEY = '_partner_settled_log';

	/**
	 * 單一 partner 保留的稽核紀錄上限
	 *
	 * @var int
	 */
	private const MAX_ENTRIES = 2000;

	/**
	 * 追加一筆稽核紀錄
	 *
	 * @param int                  $partner_term_id Partner term ID
	 * @param array<string, mixed> $entry           稽核項目
	 *
	 * @return void
	 */
	public function append( int $partner_term_id, array $entry ): void {
		if ( $partner_term_id <= 0 ) {
			return;
		}

		$entries   = $this->read( $partner_term_id );
		$entries[] = $entry;

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			// 保留最近的 MAX_ENTRIES 筆（見 class docblock 細節 3）。
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		$json = \wp_json_encode( $entries, JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return;
		}

		// wp_slash 必須：update_term_meta 內部會 wp_unslash（見 class docblock 細節 1）。
		\update_term_meta( $partner_term_id, self::META_KEY, \wp_slash( $json ) );
	}

	/**
	 * 讀取全部稽核紀錄
	 *
	 * 壞資料（非 JSON / 非陣列）一律回空陣列——稽核紀錄是唯讀展示用途，
	 * 為它拋例外會讓整個撥款流程掛掉，代價遠大於少顯示一段歷史。
	 *
	 * @param int $partner_term_id Partner term ID
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read( int $partner_term_id ): array {
		if ( $partner_term_id <= 0 ) {
			return [];
		}

		$raw = \get_term_meta( $partner_term_id, self::META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$out = [];
		foreach ( $decoded as $entry ) {
			if ( is_array( $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}
}
