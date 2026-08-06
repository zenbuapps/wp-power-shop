<?php
/**
 * 結算狀態原子轉換（wc_order_itemmeta 條件式 UPDATE）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementStatusMutatorInterface;

/**
 * 以條件式 UPDATE 對 `_settlement_status` 做 compare-and-set
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5
 * 併發問題的完整說明見 `SettlementStatusMutatorInterface` 的 docblock。
 *
 * ## 🔴 繞過 `wc_update_order_item_meta()` 的代價：快取要自己清
 *
 * 本類別直接下 SQL，因此**不會**觸發 WooCommerce / WordPress 的 meta 快取失效，
 * 同一個請求後續任何 `wc_get_order_item_meta()` 都會讀到**轉換前的舊值**——
 * 症狀是「撥款成功但畫面/ 後續邏輯顯示還是 pending」，而且沒有任何錯誤訊息。
 * 因此 CAS 成功後必須手動清兩個地方（少清一個就會漏）：
 *
 * | 清除對象 | 為什麼 |
 * |---|---|
 * | `wp_cache_delete( $id, 'order_item_meta' )` | WP metadata API 的快取。群組名是 `{$meta_type}_meta`（`wp-includes/meta.php:145`），而 WC 的 meta type 是 `order_item`（`class-wc-order-item-data-store.php:93`）⇒ **`order_item_meta`** |
 * | `WC_Cache_Helper::invalidate_cache_group( 'object_' . $id )` | `wc_update_order_item_meta()` 自己也會清這個（`wc-order-item-functions.php:107`） |
 *
 * ⚠️ **群組名不是 `order_itemmeta`**（那是資料表後綴 `woocommerce_order_itemmeta`）。
 * 用錯群組名 `wp_cache_delete()` 會安靜地什麼都不做——它不會回報「這個群組不存在」。
 *
 * ## 狀態 meta 不存在時一律 fail-closed
 *
 * `OrderItemSettlementRepository::find_by_order_item()` 對缺少 `_settlement_status`
 * 的紀錄會**預設回 `pending`**，但 CAS 的 `WHERE meta_value = 'pending'` 對不存在的
 * 資料列一定是 0 rows ⇒ 這種紀錄永遠 CAS 失敗、被列入「已跳過」。
 *
 * 這是**刻意的**：要讓它成功就得補一個 INSERT，而「先查再插」同樣是 read-check-write，
 * 等於把剛剛消滅掉的競態換個地方長回來。少撥一筆款（站長會看到跳過原因並手動處理）
 * 遠優於重複撥款。正常資料一定有這個 meta（寫入 hook 與 Repository::save() 都會寫）。
 */
final class WpSettlementStatusMutator implements SettlementStatusMutatorInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 結算狀態 meta key（與 OrderItemSettlementRepository 對齊）
	 *
	 * @var string
	 */
	private const META_SETTLEMENT_STATUS = '_settlement_status';

	/**
	 * 撥款時間 meta key
	 *
	 * @var string
	 */
	private const META_SETTLED_AT = '_settled_at';

	/**
	 * 撥款操作者 meta key
	 *
	 * @var string
	 */
	private const META_SETTLED_BY = '_settled_by';

	/**
	 * WP metadata API 對 order_item 使用的快取群組
	 *
	 * 見 class docblock：是 `order_item_meta`，不是資料表後綴 `order_itemmeta`。
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'order_item_meta';

	/**
	 * 原子地把結算狀態從 $from 換成 $to
	 *
	 * @param int    $order_item_id 訂單品項 ID
	 * @param string $from          期望的當前狀態
	 * @param string $to            目標狀態
	 *
	 * @return bool true = 搶到並完成轉換
	 */
	public function compare_and_set( int $order_item_id, string $from, string $to ): bool {
		if ( $order_item_id <= 0 || $from === $to ) {
			// $from === $to 時 MySQL 會回報 0 affected rows（值沒變），
			// 那與「沒搶到」無法區分——直接拒絕這種無意義的呼叫。
			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->update(
			$wpdb->prefix . 'woocommerce_order_itemmeta',
			[ 'meta_value' => $to ],
			[
				'order_item_id' => $order_item_id,
				'meta_key'      => self::META_SETTLEMENT_STATUS,
				'meta_value'    => $from,
			],
			[ '%s' ],
			[ '%d', '%s', '%s' ]
		);
		// phpcs:enable

		// $wpdb->update() 失敗回 false、成功回受影響列數。
		// false 與 0 在此都代表「沒搶到」，但語意不同：前者是 DB 錯誤、後者是狀態已被改。
		// 兩者都必須回 false（fail-closed），不可把 DB 錯誤誤判成成功。
		if ( false === $affected || $affected < 1 ) {
			return false;
		}

		$this->flush_item_meta_cache( $order_item_id );

		return true;
	}

	/**
	 * 寫入撥款稽核欄位
	 *
	 * 這兩欄走 `wc_update_order_item_meta()`（會自行處理快取），不需要手動清。
	 *
	 * @param int $order_item_id 訂單品項 ID
	 * @param int $settled_at    撥款時間
	 * @param int $settled_by    操作者 user ID
	 *
	 * @return void
	 */
	public function mark_settled( int $order_item_id, int $settled_at, int $settled_by ): void {
		\wc_update_order_item_meta( $order_item_id, self::META_SETTLED_AT, $settled_at );
		\wc_update_order_item_meta( $order_item_id, self::META_SETTLED_BY, $settled_by );
	}

	/**
	 * 讀取當前結算狀態
	 *
	 * 直接下 SQL 而非 `wc_get_order_item_meta()`：CAS 失敗後需要的是**資料庫當下的值**，
	 * 走快取可能拿到同請求早先讀進來的舊值，讓回報給站長的「目前狀態為 X」是錯的。
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return string|null 狀態 meta 不存在時回 null
	 */
	public function current_status( int $order_item_id ): ?string {
		if ( $order_item_id <= 0 ) {
			return null;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE order_item_id = %d AND meta_key = %s
				LIMIT 1",
				$order_item_id,
				self::META_SETTLEMENT_STATUS
			)
		);
		// phpcs:enable

		return null === $value ? null : (string) $value;
	}

	/**
	 * 清除該 order item 的 meta 快取（兩個群組都要清，見 class docblock）
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return void
	 */
	private function flush_item_meta_cache( int $order_item_id ): void {
		\wp_cache_delete( $order_item_id, self::CACHE_GROUP );

		if ( class_exists( \WC_Cache_Helper::class ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'object_' . $order_item_id );
		}
	}
}
