<?php
/**
 * 結算記錄 Repository（wc_order_itemmeta 實作）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\SettlementRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;

/**
 * 以 wc_order_itemmeta 持久化 line item 級別的結算紀錄
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5、§5、§6.6（HPOS 相容）
 *
 * order_itemmeta key 列表（spec §2.5）：
 * - _profit_shop_id
 * - _profit_partner_term_id
 * - _profit_rate
 * - _override_regular_price（write-only，本 Repo 不直接讀取進 Entity）
 * - _override_sale_price（write-only）
 * - _override_signup_fee（write-only）
 * - _actual_price
 * - _profit_amount
 * - _settlement_status
 * - _settled_at
 * - _settled_by
 *
 * Phase 2 採基本可運作版：以 SQL 取得符合條件的 order_item_id 列表，
 * 再對每筆呼叫 find_by_order_item() 重建 Entity。完整 SQL 過濾（date/status/shop_ids）
 * 與分頁優化留待 Phase 3 調優。
 */
final class OrderItemSettlementRepository implements SettlementRepositoryInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Order itemmeta keys
	 */
	private const META_SHOP_ID           = '_profit_shop_id';
	private const META_PARTNER_TERM_ID   = '_profit_partner_term_id';
	private const META_RATE              = '_profit_rate';
	private const META_OVERRIDE_REGULAR  = '_override_regular_price';
	private const META_OVERRIDE_SALE     = '_override_sale_price';
	private const META_OVERRIDE_SIGNUP   = '_override_signup_fee';
	private const META_ACTUAL_PRICE      = '_actual_price';
	private const META_PROFIT_AMOUNT     = '_profit_amount';
	private const META_SETTLEMENT_STATUS = '_settlement_status';
	private const META_SETTLED_AT        = '_settled_at';
	private const META_SETTLED_BY        = '_settled_by';

	/**
	 * 依 order_item_id 取得結算記錄
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return SettlementRecord|null 找不到時回傳 null
	 */
	public function find_by_order_item( int $order_item_id ): ?SettlementRecord {
		if ( $order_item_id <= 0 ) {
			return null;
		}

		$shop_id = (int) \wc_get_order_item_meta( $order_item_id, self::META_SHOP_ID, true );

		// shop_id 不存在表示這筆 line item 不屬於分潤賣場。
		if ( $shop_id <= 0 ) {
			return null;
		}

		$partner_term_id = (int) \wc_get_order_item_meta( $order_item_id, self::META_PARTNER_TERM_ID, true );
		$rate_raw        = (int) \wc_get_order_item_meta( $order_item_id, self::META_RATE, true );
		$actual_price    = (string) \wc_get_order_item_meta( $order_item_id, self::META_ACTUAL_PRICE, true );
		$profit_amount   = (string) \wc_get_order_item_meta( $order_item_id, self::META_PROFIT_AMOUNT, true );
		$status_raw      = (string) \wc_get_order_item_meta( $order_item_id, self::META_SETTLEMENT_STATUS, true );

		$status = '' === $status_raw ? SettlementRecord::STATUS_PENDING : $status_raw;

		$settled_at_raw = \wc_get_order_item_meta( $order_item_id, self::META_SETTLED_AT, true );
		$settled_by_raw = \wc_get_order_item_meta( $order_item_id, self::META_SETTLED_BY, true );

		$settled_at = ( '' === $settled_at_raw || null === $settled_at_raw ) ? null : (int) $settled_at_raw;
		$settled_by = ( '' === $settled_by_raw || null === $settled_by_raw ) ? null : (int) $settled_by_raw;

		// rate 容錯：0-100 範圍外退回 0。
		$rate_value = ( $rate_raw < 0 || $rate_raw > 100 ) ? 0 : $rate_raw;

		try {
			return new SettlementRecord(
				order_item_id: $order_item_id,
				shop_id: $shop_id,
				partner_term_id: $partner_term_id,
				rate: new ProfitRate( $rate_value ),
				actual_price: $actual_price,
				profit_amount: $profit_amount,
				status: $status,
				settled_at: $settled_at,
				settled_by: $settled_by,
				is_refund_after_paid: false
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * 儲存結算記錄（新增或更新）
	 *
	 * 使用 wc_update_order_item_meta（不直接 SQL）以保持 WC 內部一致性。
	 *
	 * @param SettlementRecord $record 結算記錄
	 *
	 * @return void
	 */
	public function save( SettlementRecord $record ): void {
		$item_id = $record->order_item_id;

		\wc_update_order_item_meta( $item_id, self::META_SHOP_ID, $record->shop_id );
		\wc_update_order_item_meta( $item_id, self::META_PARTNER_TERM_ID, $record->partner_term_id );
		\wc_update_order_item_meta( $item_id, self::META_RATE, $record->rate->value() );
		\wc_update_order_item_meta( $item_id, self::META_ACTUAL_PRICE, $record->actual_price );
		\wc_update_order_item_meta( $item_id, self::META_PROFIT_AMOUNT, $record->profit_amount );
		\wc_update_order_item_meta( $item_id, self::META_SETTLEMENT_STATUS, $record->status );

		if ( null === $record->settled_at ) {
			\wc_delete_order_item_meta( $item_id, self::META_SETTLED_AT );
		} else {
			\wc_update_order_item_meta( $item_id, self::META_SETTLED_AT, $record->settled_at );
		}

		if ( null === $record->settled_by ) {
			\wc_delete_order_item_meta( $item_id, self::META_SETTLED_BY );
		} else {
			\wc_update_order_item_meta( $item_id, self::META_SETTLED_BY, $record->settled_by );
		}

		// 確保 override 三欄存在（write-only，未必每次都需要更新）—— 略過：
		// 由上游 OrderHooks 在第一次寫入時填入；本 Repository 不負責這三欄的 lifecycle。
	}

	/**
	 * 找出某 partner 旗下的結算記錄
	 *
	 * @param int            $term_id  Partner term ID
	 * @param FilterCriteria $criteria 過濾條件
	 *
	 * @return SettlementRecord[]
	 */
	public function find_by_partner( int $term_id, FilterCriteria $criteria ): array {
		$ids = $this->query_order_item_ids( self::META_PARTNER_TERM_ID, $term_id, $criteria );

		return $this->hydrate_records( $ids );
	}

	/**
	 * 找出某賣場的結算記錄
	 *
	 * @param int            $shop_id  賣場 ID
	 * @param FilterCriteria $criteria 過濾條件
	 *
	 * @return SettlementRecord[]
	 */
	public function find_by_shop( int $shop_id, FilterCriteria $criteria ): array {
		$ids = $this->query_order_item_ids( self::META_SHOP_ID, $shop_id, $criteria );

		return $this->hydrate_records( $ids );
	}

	/**
	 * 透過 wc_order_itemmeta 取得符合單一 meta_key/meta_value 的 order_item_id 列表
	 *
	 * Phase 2 僅實作 LIMIT/OFFSET，date/status 過濾留待 Phase 3。
	 *
	 * @param string         $meta_key   meta key（_profit_partner_term_id 或 _profit_shop_id）
	 * @param int            $meta_value meta 值
	 * @param FilterCriteria $criteria   過濾條件（目前僅讀取 page/per_page）
	 *
	 * @return int[] order_item_id 陣列
	 */
	private function query_order_item_ids( string $meta_key, int $meta_value, FilterCriteria $criteria ): array {
		global $wpdb;

		$per_page = max( 1, $criteria->per_page );
		$page     = max( 1, $criteria->page );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE meta_key = %s AND meta_value = %s
				ORDER BY order_item_id DESC
				LIMIT %d OFFSET %d",
				$meta_key,
				(string) $meta_value,
				$per_page,
				$offset
			)
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( 'intval', $rows );
	}

	/**
	 * 將 order_item_id 陣列重建為 SettlementRecord 陣列
	 *
	 * @param int[] $ids order_item_id 列表
	 *
	 * @return SettlementRecord[]
	 */
	private function hydrate_records( array $ids ): array {
		$records = [];
		foreach ( $ids as $id ) {
			$record = $this->find_by_order_item( $id );
			if ( null !== $record ) {
				$records[] = $record;
			}
		}
		return $records;
	}
}
