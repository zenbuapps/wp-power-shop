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
	 *
	 * 註：_override_regular_price / _override_sale_price / _override_signup_fee 為 write-only，
	 * 由 `save_to_item()`（結帳建立 line item 時）填入，本 Repository 不直接讀回 Entity
	 * （spec §2.5）——它們是給站長 / 對帳查核用的快照，不參與任何計算。
	 *
	 * 🔴 **全部維持 private**：meta key 的字面值是本 Repository 獨佔的知識。外部若需要
	 * 只改寫其中某一個欄位（`SettlementReversalHook` 的兩個情境），一律透過下方的
	 * `update_profit_amount()` / `flag_refund_after_paid()` 這類**具名的窄寫入方法**，
	 * 不是把常數開成 public 讓呼叫端自己 `wc_update_order_item_meta()`——後者會讓
	 * 「寫哪個 key」與「怎麼寫」散到兩個檔案，而那正是本專案反覆吃虧的漂移來源。
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
	 * 「已撥款後才發生退款」旗標的 meta key
	 *
	 * 對應 `SettlementRecord::$is_refund_after_paid`（spec §5.3 / §7.9）。
	 *
	 * ═══ 為什麼一定要持久化，只有 audit log 不夠 ═══
	 *
	 * 這種品項的 `_settlement_status` **仍然是 `paid`**（我方刻意不自動改——錢已經匯出去了，
	 * 要不要向夥伴追討是業務決策）。少了這個旗標，結算頁**完全無從分辨**它與一筆正常的
	 * 已撥款品項有何不同：站長只能靠 `error_log` / WC logger 去翻，而那是他不會主動看的地方。
	 *
	 * ⚠️ 在本欄位存在之前，`SettlementRecord::$is_refund_after_paid` 是一個
	 *    **設得起來但存不下去**的旗標——`save()` 沒寫它、`find_by_order_item()` 讀回恆 false，
	 *    全程沒有任何錯誤訊息。讀寫必須成套，任一邊漏掉就會回到那個狀態。
	 *
	 * ⚠️ 底線前綴不可省略：WooCommerce 的 `get_formatted_meta_data()` 預設濾掉底線開頭的
	 *    meta，所以它不會出現在顧客看得到的訂單頁與訂單 email。
	 *
	 * ⚠️ 既有資料沒有這個 key。`find_by_order_item()` 對缺鍵一律正規化為 `false`
	 *    （同 Phase 9 `inflated_count` 缺鍵讀為 0 的做法），**不需要 migration**。
	 *
	 * @var string
	 */
	private const META_REFUND_AFTER_PAID = '_profit_refund_after_paid';

	/**
	 * 唯一納入結算查詢的訂單型別
	 *
	 * 🔴 少了這道過濾，**訂閱商品的分潤金額會直接翻倍**。
	 *
	 * 成因：訂閱商品結帳後，`_profit_*` 這組 line item meta 會**同時存在於**
	 * parent order（`shop_order`）與 `shop_subscription` 兩筆記錄上；而
	 * `shop_subscription` 的狀態（`wc-active` / `wc-on-hold` / `wc-pending-cancel`）
	 * **完全不在** EXCLUDED_ORDER_STATUSES 內 ⇒ 兩筆都會被撈進來、金額 ×2。
	 *
	 * ⚠️ 這個缺陷在 2026-08 之前不發作，只因為**沒有任何程式碼會寫入這些 meta**
	 *    （`OrderLineItemSettlementHook` 是第一個寫入端）；結帳寫入一上線就會立刻引爆。
	 *
	 * HPOS 用 `wc_orders.type`、legacy 用 `posts.post_type`，兩邊值都是 `shop_order`。
	 *
	 * 🔴 **public 是刻意的**：`WpSettlementSummaryProvider`（夥伴報表的 KPI / trend /
	 * settlements 三段 SQL）必須用**完全相同**的值過濾，否則會出現「後台結算頁只算一次、
	 * 夥伴自己的報表卻算兩次」這種兩邊各自都『正常運作』的分歧。共用同一個常數讓那種
	 * 漂移在結構上不可能發生——這也是為什麼**不要**在別的類別裡另外寫一份 `'shop_order'`。
	 *
	 * @var string
	 */
	public const ORDER_POST_TYPE = 'shop_order';

	/**
	 * 排除於結算查詢的訂單狀態清單
	 *
	 * Cancelled / failed / trash / auto-draft 訂單對結算金額無意義，必須過濾掉，
	 * 否則 partner / shop 的結算金額會被「無效訂單」污染。
	 *
	 * 注意：保留 'wc-pending'（spec §6.6 視為合法的「待付款待結算」狀態）。
	 *
	 * 同時涵蓋 wc- prefix 與 raw（HPOS 不一定帶 prefix）兩種寫法，
	 * 確保 HPOS on/off 兩套 SQL 都能正確過濾。
	 *
	 * @var string[]
	 */
	private const EXCLUDED_ORDER_STATUSES = [
		'trash',
		'wc-trash',
		'wc-failed',
		'wc-cancelled',
		'failed',
		'cancelled',
		'auto-draft',
	];

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

		/*
		* 「已撥款後退款」旗標。既有資料沒有這個 key，缺鍵時 `wc_get_order_item_meta()`
		* 回空字串 ⇒ `wc_string_to_bool('')` 為 false，等同「不需要 migration」的正規化。
		* 用 `wc_string_to_bool()` 而非 `'1' === $raw`：它同時吃 `1` / `'1'` / `'yes'` / `true`，
		* 對「別的地方用 WooCommerce 慣例寫進來」有容錯，且不會把任何字串誤判成 true。
		*/
		$is_refund_after_paid = \wc_string_to_bool(
			(string) \wc_get_order_item_meta( $order_item_id, self::META_REFUND_AFTER_PAID, true )
		);

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
				is_refund_after_paid: $is_refund_after_paid
			);
		} catch ( \DomainException $e ) {
			// 資料毀損（如 status 不在 VALID_STATUSES、rate 異常等）：
			// 不要 crash 整個請求，但必須留下 ERROR 級別 log 讓營運可發現。
			// 僅 catch DomainException——\Error/\TypeError 等真正的 fatal 應炸出去由 PHP 處理。
			\J7\WpUtils\Classes\WC::logger(
				sprintf(
					'OrderItemSettlement hydrate failed for order_item_id=%d: %s',
					$order_item_id,
					$e->getMessage()
				),
				'error',
				[
					'order_item_id' => $order_item_id,
					'shop_id'       => $shop_id,
				],
				'profit_shop'
			);
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

		/*
		* 「已撥款後退款」旗標：false 時**刪除** meta 而不是寫 '0'。
		*
		* 兩個理由：(1) 與上面 settled_at / settled_by 的 null ⇒ delete 一致；
		* (2) 絕大多數結算紀錄這個旗標都是 false，寫 '0' 等於替每一筆訂單品項憑空多一列
		*     order itemmeta。缺鍵由 `find_by_order_item()` 正規化為 false，語意完全相同。
		*/
		if ( $record->is_refund_after_paid ) {
			\wc_update_order_item_meta( $item_id, self::META_REFUND_AFTER_PAID, 1 );
		} else {
			\wc_delete_order_item_meta( $item_id, self::META_REFUND_AFTER_PAID );
		}

		// 確保 override 三欄存在（write-only，未必每次都需要更新）—— 略過：
		// 由上游 OrderHooks 在第一次寫入時填入；本 Repository 不負責這三欄的 lifecycle。
	}

	/**
	 * 只改寫 `_profit_amount` 一個欄位（部分退款重算的唯一寫入出口）
	 *
	 * ═══ 🔴 為什麼不能直接用 `save()` ═══
	 *
	 * `save()` 會把 `_settlement_status` / `_settled_at` / `_settled_by` 一起用**呼叫端讀取
	 * 當下的舊值**寫回去。部分退款發生在顧客端，而站長可能正在結算頁按撥款——
	 * 若在那個瞬間 `save()` 把狀態推回 `pending`，那筆已經撥出去的款會重新出現在待撥款清單，
	 * 站長再撥一次 ⇒ **重複撥款**，而且沒有任何錯誤訊息。這正是 D-4 引入 CAS 要消滅的場景。
	 *
	 * ⇒ 需要「只動一個欄位」時一律用這種**具名的窄寫入方法**，不要把 meta key 開成 public
	 *   讓呼叫端自己組 `wc_update_order_item_meta()`。
	 *
	 * ⚠️ 本方法**不做任何狀態檢查**——它只忠實寫入一個欄位。呼叫端負責先確認狀態
	 *   （`SettlementReversalHook` 用 `SettlementStatusMutatorInterface::current_status()`
	 *    直接讀 DB 再確認一次）。
	 *
	 * @param int    $order_item_id 訂單品項 ID
	 * @param string $amount        新的分潤金額（十進位字串，呼叫端已收斂到站台貨幣小數位）
	 *
	 * @return void
	 */
	public function update_profit_amount( int $order_item_id, string $amount ): void {
		if ( $order_item_id <= 0 ) {
			return;
		}

		\wc_update_order_item_meta( $order_item_id, self::META_PROFIT_AMOUNT, $amount );
	}

	/**
	 * 標記「已撥款後才發生退款」（只寫這一個欄位，不動狀態）
	 *
	 * 這種品項的 `_settlement_status` **維持 `paid`** —— 錢已經匯給夥伴了，要不要向夥伴
	 * 追討是業務決策（spec §7.9），程式無權決定。本旗標的用途是讓結算頁能把它**標示出來**
	 * 讓站長人工處理；少了它，結算頁完全無從分辨它與一筆正常已撥款品項的差異。
	 *
	 * 同樣**不走 `save()`**，理由與 `update_profit_amount()` 完全相同（見該方法 docblock）。
	 *
	 * ⚠️ 冪等：重複標記同一筆只是重寫相同的值，不會累積。
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return void
	 */
	public function flag_refund_after_paid( int $order_item_id ): void {
		if ( $order_item_id <= 0 ) {
			return;
		}

		\wc_update_order_item_meta( $order_item_id, self::META_REFUND_AFTER_PAID, 1 );
	}

	/**
	 * 把結算快照寫進**尚未儲存**的 WC_Order_Item（結帳建立 line item 時的唯一寫入出口）
	 *
	 * ═══ 為什麼需要與 save() 並存的第二條寫入路徑 ═══
	 *
	 * `save()` 走 `wc_update_order_item_meta()`，前提是 order item **已經有 ID**。
	 * 但結帳時唯一同時拿得到「cart item 的 _profit_* meta」與「對應 line item」的擴充點是
	 * `woocommerce_checkout_create_order_line_item`（WooCommerce 10.1.4：
	 * includes/class-wc-checkout.php:571），而它觸發時 `$order->add_item( $item )` **還沒執行**
	 * （下一行才執行），`$item->get_id()` 恆為 0 ⇒ `wc_update_order_item_meta()` 無處可寫。
	 *
	 * 因此這條路徑改用 `WC_Order_Item::add_meta_data()`——WooCommerce 自己在同一個方法內
	 * 也是這樣做的（`$item->set_backorder_meta()`），meta 會在 `$order->save()` 時一併落地。
	 *
	 * 🔴 這個方法存在的**真正理由是收斂 meta key**：cart 端與 order 端的命名不同
	 * （`_profit_price_override` → `_actual_price`、`_profit_regular_price_override`
	 * → `_override_regular_price`…），把 key 名稱留在 Repository 內，呼叫端就不可能寫錯對映。
	 * 對映表見 `OrderLineItemSettlementHook` 的 class docblock。
	 *
	 * ⚠️ 本方法**忽略** `$record->order_item_id`（此刻必為 0，item 尚未儲存）——
	 *    真正的 ID 由 WooCommerce 在 `$order->save()` 時決定。
	 *
	 * ⚠️ `_settled_at` / `_settled_by` 為 null 時**不寫入**，與 `save()` 的「null ⇒ delete」
	 *    等價：`find_by_order_item()` 對缺鍵與空字串都還原成 null。
	 *
	 * 🔴 `add_meta_data( ..., true )` 的 `$unique = true` **不可省略**。
	 * Store API 的 `update_line_items_from_cart()`（src/StoreApi/Utilities/OrderController.php:786）
	 * 在 cart hash 變動時會 `remove_order_items( 'line_item' )` 後重建，同一張草稿單可能觸發多次。
	 * 少了 unique 旗標會累積重複 meta，而 `WpSettlementSummaryProvider` 的三段 SQL 都假設
	 * `_settlement_status` 是 **1:1 INNER JOIN**（該檔 :226 自己註明）
	 * ⇒ 重複 meta 會讓夥伴分潤金額**直接翻倍**。
	 *
	 * 🔴 本方法**不可**加進 `SettlementRepositoryInterface`：Domain 層不得出現
	 * `WC_Order_Item_Product` 型別，加了會讓 `tests/Unit/**`（不啟 WP）直接 fatal。
	 *
	 * @param \WC_Order_Item_Product $item                尚未儲存的 order line item
	 * @param SettlementRecord       $record              結算快照（order_item_id 會被忽略）
	 * @param string                 $override_regular    覆寫原價；'' 表示未覆寫
	 * @param string                 $override_sale       覆寫特價；'' 表示無特價
	 * @param string                 $override_signup     覆寫註冊費（訂閱專用）；'' 表示未覆寫
	 *
	 * @return void
	 */
	public function save_to_item(
		\WC_Order_Item_Product $item,
		SettlementRecord $record,
		string $override_regular,
		string $override_sale,
		string $override_signup
	): void {
		$item->add_meta_data( self::META_SHOP_ID, $record->shop_id, true );
		$item->add_meta_data( self::META_PARTNER_TERM_ID, $record->partner_term_id, true );
		$item->add_meta_data( self::META_RATE, $record->rate->value(), true );
		$item->add_meta_data( self::META_OVERRIDE_REGULAR, $override_regular, true );
		$item->add_meta_data( self::META_OVERRIDE_SALE, $override_sale, true );
		$item->add_meta_data( self::META_OVERRIDE_SIGNUP, $override_signup, true );
		$item->add_meta_data( self::META_ACTUAL_PRICE, $record->actual_price, true );
		$item->add_meta_data( self::META_PROFIT_AMOUNT, $record->profit_amount, true );
		$item->add_meta_data( self::META_SETTLEMENT_STATUS, $record->status, true );

		if ( null !== $record->settled_at ) {
			$item->add_meta_data( self::META_SETTLED_AT, $record->settled_at, true );
		}

		if ( null !== $record->settled_by ) {
			$item->add_meta_data( self::META_SETTLED_BY, $record->settled_by, true );
		}
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
	 * 排除已 cancelled/failed/trash/auto-draft 訂單的 line item，避免 partner / shop
	 * 結算金額被無效訂單污染。實作上動態判斷 HPOS 開關：
	 *
	 * - HPOS off：JOIN wp_posts ON wp_posts.ID = order_items.order_id
	 * - HPOS on ：JOIN {wp_orders} ON wp_orders.id = order_items.order_id
	 *
	 * 兩種路徑都使用 $wpdb->prepare + placeholder，不拼 SQL 字串。
	 * Phase 2 僅實作 LIMIT/OFFSET + status 排除；date 過濾留待 Phase 3。
	 *
	 * 🔴 兩條路徑都必須**同時**過濾訂單型別（`ORDER_POST_TYPE`）與訂單狀態。
	 *    只濾狀態會把 `shop_subscription` 一起撈進來，讓訂閱商品的分潤金額翻倍——
	 *    完整推導見 `ORDER_POST_TYPE` 常數的 docblock。
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

		$is_hpos = self::is_hpos_enabled();

		// 動態組 placeholder：N 個排除狀態 → '%s,%s,...,%s'
		$status_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		if ( $is_hpos ) {
			$sql = "SELECT oim.order_item_id
				FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
				INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
				WHERE oim.meta_key = %s AND oim.meta_value = %s
				AND o.type = %s
				AND o.status NOT IN ({$status_placeholders})
				ORDER BY oim.order_item_id DESC
				LIMIT %d OFFSET %d";
		} else {
			$sql = "SELECT oim.order_item_id
				FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
				INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
				WHERE oim.meta_key = %s AND oim.meta_value = %s
				AND p.post_type = %s
				AND p.post_status NOT IN ({$status_placeholders})
				ORDER BY oim.order_item_id DESC
				LIMIT %d OFFSET %d";
		}

		$args = array_merge(
			[ $meta_key, (string) $meta_value, self::ORDER_POST_TYPE ],
			self::EXCLUDED_ORDER_STATUSES,
			[ $per_page, $offset ]
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare( $sql, $args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( 'intval', $rows );
	}

	/**
	 * 判斷當前環境是否啟用 HPOS（custom orders table）
	 *
	 * @return bool 啟用 HPOS 回傳 true，否則 false
	 */
	private static function is_hpos_enabled(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
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
