<?php
/**
 * 後台結算查詢（跨 partner）WP 實作
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementQueryProviderInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;

/**
 * 後台結算頁的 wc_order_itemmeta 聚合查詢（admin-only，跨 partner）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3、§6.6（HPOS）、§6.8（SQL 注入）
 *
 * ## 為何獨立於 `WpSettlementSummaryProvider` 而不是加方法進去
 *
 * 那個類別的每個 public 方法開頭都有 `if ( $partner_term_id <= 0 ) return empty;`——
 * 「partner 範圍鎖死」是它的**類別級不變量**。把「partner 可為空」的 admin 查詢塞進同一個
 * 類別，等於在那個不變量上開一個洞：日後只要有人在 partner 端點誤用了 admin 方法，
 * 跨 partner 越權就成立，而型別檢查與既有測試**一條都不會紅**。
 *
 * 兩者共用的是 **SQL 模式**（HPOS 雙路徑 / `$wpdb->prepare` / 狀態白名單 / bcadd 精度），
 * 不是同一個類別。這是刻意的重複，換到的是結構上的隔離保證。
 *
 * ## 安全保證（與 `WpSettlementSummaryProvider` 同一套）
 *
 * - 所有使用者輸入一律 `$wpdb->prepare` placeholder，不拼接
 * - `shop_ids` / `partner_term_ids` 強制 `intval` 後只留正整數，以 `%d` 注入
 * - `statuses` 走 `SettlementRecord` 常數白名單，非白名單值 → 該筆條件視為 0 命中
 * - date range 以 `gmdate('Y-m-d H:i:s')` 序列化後 `%s` 注入，並 clamp 到合理範圍
 * - 排除 cancelled / failed / trash / auto-draft 訂單，避免無效訂單污染撥款金額
 *
 * ## 金額精度
 *
 * 全部以 `bcadd(..., 2)` 產生十進位字串。這些數字會直接變成轉給夥伴的金額，
 * float 累加的分位誤差只會在對帳時才被發現。
 */
final class WpAdminSettlementQuery implements SettlementQueryProviderInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Order itemmeta keys（與 OrderItemSettlementRepository 對齊）
	 */
	private const META_SHOP_ID           = '_profit_shop_id';
	private const META_PARTNER_TERM_ID   = '_profit_partner_term_id';
	private const META_RATE              = '_profit_rate';
	private const META_ACTUAL_PRICE      = '_actual_price';
	private const META_PROFIT_AMOUNT     = '_profit_amount';
	private const META_SETTLEMENT_STATUS = '_settlement_status';
	private const META_SETTLED_AT        = '_settled_at';

	/**
	 * 排除於結算查詢的訂單狀態（與 OrderItemSettlementRepository::EXCLUDED_ORDER_STATUSES 對齊）
	 *
	 * 同時涵蓋 `wc-` prefix 與 raw 兩種寫法（HPOS 不一定帶 prefix）。
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
	 * Settlement status 白名單（防 SQL injection）
	 *
	 * @var string[]
	 */
	private const VALID_SETTLEMENT_STATUSES = [
		SettlementRecord::STATUS_PENDING,
		SettlementRecord::STATUS_PAID,
		SettlementRecord::STATUS_REFUNDED,
		SettlementRecord::STATUS_CANCELLED,
	];

	/**
	 * 9999-12-31 23:59:59 UTC，timestamp 上限
	 *
	 * @var int
	 */
	private const MAX_TIMESTAMP = 253402300799;

	/**
	 * 🔴 唯一被計入結算的訂單型別
	 *
	 * 訂閱商品結帳後，同一組 `_profit_*` meta 會**同時**存在於主訂單與
	 * `shop_subscription` 物件的 line item 上，而訂閱物件的狀態
	 * （`wc-active` / `wc-on-hold`）**不在** `EXCLUDED_ORDER_STATUSES` 內
	 * ⇒ 不限定型別的話，訂閱商品的每一筆分潤都會被算兩次。
	 *
	 * 症狀是「金額剛好是兩倍」，**沒有任何錯誤訊息**——這種缺陷只有在對帳時
	 * 才會被發現，而那時錢可能已經多付出去了。
	 *
	 * 值刻意取自 `OrderItemSettlementRepository::ORDER_POST_TYPE` 而不是各自寫死字串：
	 * 這是安全過濾條件，四個查詢類別（本類 / Repository / SummaryProvider）任一處漂移
	 * 都會讓那條路徑的金額變兩倍，而漂移本身不會有任何徵兆。
	 *
	 * @var string
	 */
	private const ORDER_TYPE = OrderItemSettlementRepository::ORDER_POST_TYPE;

	/**
	 * 賣場已被刪除時的佔位文字（規格 `query-settlements.feature` 逐字斷言）
	 *
	 * @var string
	 */
	public const DELETED_SHOP_LABEL = '[已刪除賣場]';

	/**
	 * 分潤夥伴已被刪除時的佔位文字（規格逐字斷言；用「夥伴」不用「KOL」）
	 *
	 * @var string
	 */
	public const DELETED_PARTNER_LABEL = '[已停用夥伴]';

	/**
	 * 依過濾條件取得結算列表（含分頁）
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function find_settlements( SettlementFilterInput $filter ): array {
		if ( self::is_impossible_filter( $filter ) ) {
			return [];
		}

		global $wpdb;

		$is_hpos                                 = self::is_hpos_enabled();
		$date_col                                = self::date_column( $is_hpos );
		[ $join_orders, $status_col, $type_col ] = self::order_join( $is_hpos );
		[ $where_sql, $where_args ]              = self::build_where( $filter, $is_hpos );

		$per_page = max( 1, $filter->per_page );
		$offset   = ( max( 1, $filter->page ) - 1 ) * $per_page;

		$excluded_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		$sql = "SELECT
				oi.order_item_id,
				oi.order_id,
				oi.order_item_name AS product_name,
				oim_shop.meta_value AS shop_id,
				oim_partner.meta_value AS partner_term_id,
				oim_amt.meta_value AS profit_amount,
				oim_actual.meta_value AS actual_price,
				oim_st.meta_value AS settlement_status,
				oim_rate.meta_value AS rate,
				oim_settled.meta_value AS settled_at,
				{$date_col} AS order_date
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_shop
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim_shop.order_item_id
			{$join_orders}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_partner
				ON oim_partner.order_item_id = oim_shop.order_item_id AND oim_partner.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_amt
				ON oim_amt.order_item_id = oim_shop.order_item_id AND oim_amt.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_actual
				ON oim_actual.order_item_id = oim_shop.order_item_id AND oim_actual.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_st
				ON oim_st.order_item_id = oim_shop.order_item_id AND oim_st.meta_key = %s
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_rate
				ON oim_rate.order_item_id = oim_shop.order_item_id AND oim_rate.meta_key = %s
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_settled
				ON oim_settled.order_item_id = oim_shop.order_item_id AND oim_settled.meta_key = %s
			WHERE oim_shop.meta_key = %s
				AND {$type_col} = %s
				AND {$status_col} NOT IN ({$excluded_placeholders})
				{$where_sql}
			ORDER BY oi.order_item_id DESC
			LIMIT %d OFFSET %d";

		$args = array_merge(
			[
				self::META_PARTNER_TERM_ID,
				self::META_PROFIT_AMOUNT,
				self::META_ACTUAL_PRICE,
				self::META_SETTLEMENT_STATUS,
				self::META_RATE,
				self::META_SETTLED_AT,
				self::META_SHOP_ID,
				self::ORDER_TYPE,
			],
			self::EXCLUDED_ORDER_STATUSES,
			$where_args,
			[ $per_page, $offset ]
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), \ARRAY_A );
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$shop_names    = self::resolve_shop_names( array_map( static fn( $r ): int => (int) ( $r['shop_id'] ?? 0 ), $rows ) );
		$partner_names = self::resolve_partner_names( array_map( static fn( $r ): int => (int) ( $r['partner_term_id'] ?? 0 ), $rows ) );

		$out = [];
		foreach ( $rows as $row ) {
			$settled_at_raw  = $row['settled_at'] ?? '';
			$shop_id         = (int) ( $row['shop_id'] ?? 0 );
			$partner_term_id = (int) ( $row['partner_term_id'] ?? 0 );
			$rate            = (int) ( $row['rate'] ?? 0 );

			$out[] = [
				'order_item_id'   => (int) ( $row['order_item_id'] ?? 0 ),
				'order_id'        => (int) ( $row['order_id'] ?? 0 ),
				'product_name'    => (string) ( $row['product_name'] ?? '' ),
				'partner_term_id' => $partner_term_id,
				'partner_name'    => $partner_names[ $partner_term_id ] ?? self::DELETED_PARTNER_LABEL,
				'shop_id'         => $shop_id,
				'shop_name'       => $shop_names[ $shop_id ] ?? self::DELETED_SHOP_LABEL,
				'actual_price'    => self::format_money( (string) ( $row['actual_price'] ?? '0' ) ),
				'profit_amount'   => self::format_money( (string) ( $row['profit_amount'] ?? '0' ) ),
				'rate'            => $rate,
				// 🔴 賣場在結帳當下已被刪除時，寫入 hook 會刻意寫下完整歸屬 meta 但
				// rate = 0 / amount = 0.00（而不是不寫），好讓這筆錢仍看得見、可人工補救。
				// 這些紀錄**絕不可**被過濾掉——那等於讓一筆該付的錢從系統中消失。
				// 前端據此標紅提示「賣場已刪除，需人工設定比例」。
				'needs_manual_rate' => 0 === $rate,
				'status'          => (string) ( $row['settlement_status'] ?? '' ),
				'settled_at'      => ( '' === $settled_at_raw || null === $settled_at_raw ) ? null : (int) $settled_at_raw,
				'order_date'      => (string) ( $row['order_date'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * 依過濾條件取得符合的 order_id 清單（去重）
	 *
	 * 供「訂單列表依分潤賣場過濾」使用：WooCommerce REST 的 `orders` 端點無法依
	 * order item meta 篩選，因此前端先向本端點取回 order_id 陣列，再當成
	 * `wc-rest` 的 `include` filter 送出。
	 *
	 * ⚠️ `include` 有長度上限（過長的 query string 會被伺服器截斷或拒絕），
	 * 因此這裡硬性 cap，並由呼叫端誠實回報是否被截斷——
	 * 靜默截斷會讓站長以為「這個賣場只有這幾張訂單」。
	 *
	 * @param SettlementFilterInput $filter 過濾條件（page / per_page 會被忽略）
	 * @param int                   $limit  最多回傳幾筆
	 *
	 * @return int[] order_id 陣列（新到舊）
	 */
	public function find_order_ids( SettlementFilterInput $filter, int $limit ): array {
		if ( self::is_impossible_filter( $filter ) || $limit <= 0 ) {
			return [];
		}

		global $wpdb;

		$is_hpos                                 = self::is_hpos_enabled();
		[ $join_orders, $status_col, $type_col ] = self::order_join( $is_hpos );
		[ $where_sql, $where_args ]              = self::build_where( $filter, $is_hpos );

		$excluded_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		$sql = "SELECT DISTINCT oi.order_id
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_shop
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim_shop.order_item_id
			{$join_orders}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_partner
				ON oim_partner.order_item_id = oim_shop.order_item_id AND oim_partner.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_st
				ON oim_st.order_item_id = oim_shop.order_item_id AND oim_st.meta_key = %s
			WHERE oim_shop.meta_key = %s
				AND {$type_col} = %s
				AND {$status_col} NOT IN ({$excluded_placeholders})
				{$where_sql}
			ORDER BY oi.order_id DESC
			LIMIT %d";

		$args = array_merge(
			[
				self::META_PARTNER_TERM_ID,
				self::META_SETTLEMENT_STATUS,
				self::META_SHOP_ID,
				self::ORDER_TYPE,
			],
			self::EXCLUDED_ORDER_STATUSES,
			$where_args,
			[ $limit ]
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_values( array_unique( array_map( 'intval', $rows ) ) );
	}

	/**
	 * 依過濾條件取得符合的**訂單**總數（去重後，忽略 limit）
	 *
	 * 供 order-ids 端點判斷是否被 `$limit` 截斷。
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return int
	 */
	public function count_order_ids( SettlementFilterInput $filter ): int {
		if ( self::is_impossible_filter( $filter ) ) {
			return 0;
		}

		global $wpdb;

		$is_hpos                                 = self::is_hpos_enabled();
		[ $join_orders, $status_col, $type_col ] = self::order_join( $is_hpos );
		[ $where_sql, $where_args ]              = self::build_where( $filter, $is_hpos );

		$excluded_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		$sql = "SELECT COUNT(DISTINCT oi.order_id)
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_shop
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim_shop.order_item_id
			{$join_orders}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_partner
				ON oim_partner.order_item_id = oim_shop.order_item_id AND oim_partner.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_st
				ON oim_st.order_item_id = oim_shop.order_item_id AND oim_st.meta_key = %s
			WHERE oim_shop.meta_key = %s
				AND {$type_col} = %s
				AND {$status_col} NOT IN ({$excluded_placeholders})
				{$where_sql}";

		$args = array_merge(
			[
				self::META_PARTNER_TERM_ID,
				self::META_SETTLEMENT_STATUS,
				self::META_SHOP_ID,
				self::ORDER_TYPE,
			],
			self::EXCLUDED_ORDER_STATUSES,
			$where_args
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable

		return null === $total ? 0 : (int) $total;
	}

	/**
	 * 依過濾條件取得符合的總筆數
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return int
	 */
	public function count_settlements( SettlementFilterInput $filter ): int {
		if ( self::is_impossible_filter( $filter ) ) {
			return 0;
		}

		global $wpdb;

		$is_hpos                                 = self::is_hpos_enabled();
		[ $join_orders, $status_col, $type_col ] = self::order_join( $is_hpos );
		[ $where_sql, $where_args ]              = self::build_where( $filter, $is_hpos );

		$excluded_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		$sql = "SELECT COUNT(DISTINCT oi.order_item_id)
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_shop
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim_shop.order_item_id
			{$join_orders}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_partner
				ON oim_partner.order_item_id = oim_shop.order_item_id AND oim_partner.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_st
				ON oim_st.order_item_id = oim_shop.order_item_id AND oim_st.meta_key = %s
			WHERE oim_shop.meta_key = %s
				AND {$type_col} = %s
				AND {$status_col} NOT IN ({$excluded_placeholders})
				{$where_sql}";

		$args = array_merge(
			[
				self::META_PARTNER_TERM_ID,
				self::META_SETTLEMENT_STATUS,
				self::META_SHOP_ID,
				self::ORDER_TYPE,
			],
			self::EXCLUDED_ORDER_STATUSES,
			$where_args
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable

		return null === $total ? 0 : (int) $total;
	}

	/**
	 * 依過濾條件取得各狀態的金額與筆數彙總
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return array<string, mixed>
	 */
	public function summarize_settlements( SettlementFilterInput $filter ): array {
		$summary = self::empty_summary();

		if ( self::is_impossible_filter( $filter ) ) {
			return $summary;
		}

		global $wpdb;

		$is_hpos                                 = self::is_hpos_enabled();
		[ $join_orders, $status_col, $type_col ] = self::order_join( $is_hpos );
		[ $where_sql, $where_args ]              = self::build_where( $filter, $is_hpos );

		$excluded_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		$sql = "SELECT
				oim_st.meta_value AS settlement_status,
				COUNT(DISTINCT oi.order_item_id) AS cnt,
				SUM(CAST(oim_amt.meta_value AS DECIMAL(20,4))) AS profit_sum,
				SUM(CAST(oim_actual.meta_value AS DECIMAL(20,4))) AS sales_sum
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_shop
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim_shop.order_item_id
			{$join_orders}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_partner
				ON oim_partner.order_item_id = oim_shop.order_item_id AND oim_partner.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_amt
				ON oim_amt.order_item_id = oim_shop.order_item_id AND oim_amt.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_actual
				ON oim_actual.order_item_id = oim_shop.order_item_id AND oim_actual.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_st
				ON oim_st.order_item_id = oim_shop.order_item_id AND oim_st.meta_key = %s
			WHERE oim_shop.meta_key = %s
				AND {$type_col} = %s
				AND {$status_col} NOT IN ({$excluded_placeholders})
				{$where_sql}
			GROUP BY oim_st.meta_value";

		$args = array_merge(
			[
				self::META_PARTNER_TERM_ID,
				self::META_PROFIT_AMOUNT,
				self::META_ACTUAL_PRICE,
				self::META_SETTLEMENT_STATUS,
				self::META_SHOP_ID,
				self::ORDER_TYPE,
			],
			self::EXCLUDED_ORDER_STATUSES,
			$where_args
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), \ARRAY_A );
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return $summary;
		}

		foreach ( $rows as $row ) {
			$status = (string) ( $row['settlement_status'] ?? '' );
			$profit = self::format_money( (string) ( $row['profit_sum'] ?? '0' ) );
			$sales  = self::format_money( (string) ( $row['sales_sum'] ?? '0' ) );
			$count  = (int) ( $row['cnt'] ?? 0 );

			$summary['total_sales'] = bcadd( $summary['total_sales'], $sales, 2 );
			$summary['total_count'] += $count;

			switch ( $status ) {
				case SettlementRecord::STATUS_PENDING:
					$summary['pending_amount'] = bcadd( $summary['pending_amount'], $profit, 2 );
					$summary['pending_count'] += $count;
					break;
				case SettlementRecord::STATUS_PAID:
					$summary['paid_amount'] = bcadd( $summary['paid_amount'], $profit, 2 );
					$summary['paid_count'] += $count;
					break;
				case SettlementRecord::STATUS_REFUNDED:
					$summary['refunded_amount'] = bcadd( $summary['refunded_amount'], $profit, 2 );
					$summary['refunded_count'] += $count;
					break;
				case SettlementRecord::STATUS_CANCELLED:
					$summary['cancelled_amount'] = bcadd( $summary['cancelled_amount'], $profit, 2 );
					$summary['cancelled_count'] += $count;
					break;
			}
		}

		return $summary;
	}

	// ============================================================
	// 共用 helper
	// ============================================================

	/**
	 * 預設（全 0）摘要形狀
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_summary(): array {
		return [
			'pending_amount'   => '0.00',
			'pending_count'    => 0,
			'paid_amount'      => '0.00',
			'paid_count'       => 0,
			'refunded_amount'  => '0.00',
			'refunded_count'   => 0,
			'cancelled_amount' => '0.00',
			'cancelled_count'  => 0,
			'total_sales'      => '0.00',
			'total_count'      => 0,
		];
	}

	/**
	 * 判斷過濾條件是否必定 0 命中（省一次 DB 往返）
	 *
	 * 目前唯一情況：caller 指定了 statuses，但全部被白名單清掉——
	 * 此時**不可**當成「不限狀態」照查，那會把使用者的過濾條件靜默放寬成全部。
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return bool
	 */
	private static function is_impossible_filter( SettlementFilterInput $filter ): bool {
		return ! empty( $filter->statuses ) && empty( self::sanitize_statuses( $filter->statuses ) );
	}

	/**
	 * 組出訂單表 JOIN 子句、狀態欄位名與**型別欄位名**（HPOS 雙路徑）
	 *
	 * 型別欄位是第三個回傳值而不是內嵌進 JOIN，是為了讓每個查詢都必須顯式寫出
	 * `AND {$type_col} = %s`——漏掉會是編譯不出錯、測試也可能不紅的靜默雙倍計算。
	 *
	 * @param bool $is_hpos 是否啟用 HPOS
	 *
	 * @return array{0: string, 1: string, 2: string} [JOIN 子句, 狀態欄位, 型別欄位]
	 */
	private static function order_join( bool $is_hpos ): array {
		global $wpdb;

		if ( $is_hpos ) {
			return [ "INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id", 'o.status', 'o.type' ];
		}
		return [ "INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id", 'o.post_status', 'o.post_type' ];
	}

	/**
	 * 批次解析賣場名稱（已刪除 / 已丟到垃圾桶者回佔位文字）
	 *
	 * 以 `_prime_post_caches()` 一次查完，避免逐列 `get_post()` 造成 N+1。
	 *
	 * @param int[] $shop_ids 賣場 ID（可含重複與 0）
	 *
	 * @return array<int, string> shop_id => 名稱（找不到的鍵不存在，由呼叫端填佔位文字）
	 */
	private static function resolve_shop_names( array $shop_ids ): array {
		$ids = self::positive_ints( $shop_ids );
		if ( empty( $ids ) ) {
			return [];
		}

		$ids = array_values( array_unique( $ids ) );
		\_prime_post_caches( $ids, false, false );

		$names = [];
		foreach ( $ids as $id ) {
			$post = \get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( CptRegistrar::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
				continue;
			}
			$names[ $id ] = (string) $post->post_title;
		}
		return $names;
	}

	/**
	 * 批次解析分潤夥伴名稱（已刪除者回佔位文字）
	 *
	 * @param int[] $partner_term_ids Partner term ID（可含重複與 0）
	 *
	 * @return array<int, string> term_id => 名稱（找不到的鍵不存在）
	 */
	private static function resolve_partner_names( array $partner_term_ids ): array {
		$ids = self::positive_ints( $partner_term_ids );
		if ( empty( $ids ) ) {
			return [];
		}

		$terms = \get_terms(
			[
				'taxonomy'   => TaxonomyRegistrar::TAXONOMY,
				'include'    => array_values( array_unique( $ids ) ),
				'hide_empty' => false,
			]
		);

		if ( \is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$names = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$names[ (int) $term->term_id ] = (string) $term->name;
			}
		}
		return $names;
	}

	/**
	 * 訂單建立日期欄位名（HPOS 雙路徑）
	 *
	 * @param bool $is_hpos 是否啟用 HPOS
	 *
	 * @return string
	 */
	private static function date_column( bool $is_hpos ): string {
		return $is_hpos ? 'o.date_created_gmt' : 'o.post_date_gmt';
	}

	/**
	 * 建構額外 WHERE 條件（partner / shop / status / date range）
	 *
	 * 全部走 prepared placeholder；ID 類一律 intval 後只留正整數。
	 *
	 * @param SettlementFilterInput $filter  過濾條件
	 * @param bool                  $is_hpos 是否啟用 HPOS
	 *
	 * @return array{0: string, 1: array<int, mixed>} [額外 SQL, 額外 args]
	 */
	private static function build_where( SettlementFilterInput $filter, bool $is_hpos ): array {
		$parts = [];
		$args  = [];

		$partner_ids = self::positive_ints( $filter->partner_term_ids );
		if ( ! empty( $partner_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $partner_ids ), '%d' ) );
			$parts[]      = "AND oim_partner.meta_value IN ({$placeholders})";
			$args         = array_merge( $args, $partner_ids );
		}

		$shop_ids = self::positive_ints( $filter->shop_ids );
		if ( ! empty( $shop_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $shop_ids ), '%d' ) );
			$parts[]      = "AND oim_shop.meta_value IN ({$placeholders})";
			$args         = array_merge( $args, $shop_ids );
		}

		$statuses = self::sanitize_statuses( $filter->statuses );
		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$parts[]      = "AND oim_st.meta_value IN ({$placeholders})";
			$args         = array_merge( $args, $statuses );
		}

		$date_col = self::date_column( $is_hpos );

		if ( null !== $filter->date_start ) {
			$parts[] = "AND {$date_col} >= %s";
			$args[]  = self::format_datetime( $filter->date_start );
		}
		if ( null !== $filter->date_end ) {
			$parts[] = "AND {$date_col} <= %s";
			$args[]  = self::format_datetime( $filter->date_end );
		}

		return [ implode( ' ', $parts ), $args ];
	}

	/**
	 * 把任意陣列正規化成正整數清單
	 *
	 * @param array<int, mixed> $raw 原始陣列
	 *
	 * @return int[]
	 */
	private static function positive_ints( array $raw ): array {
		return array_values(
			array_filter(
				array_map( 'intval', $raw ),
				static fn( int $id ): bool => $id > 0
			)
		);
	}

	/**
	 * Settlement status 白名單過濾
	 *
	 * @param array<int, mixed> $statuses 候選 status
	 *
	 * @return string[]
	 */
	private static function sanitize_statuses( array $statuses ): array {
		$out = [];
		foreach ( $statuses as $s ) {
			if ( is_string( $s ) && in_array( $s, self::VALID_SETTLEMENT_STATUSES, true ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/**
	 * 把 unix timestamp 安全序列化成 SQL DATETIME 字串
	 *
	 * @param int $timestamp unix timestamp
	 *
	 * @return string
	 */
	private static function format_datetime( int $timestamp ): string {
		if ( $timestamp < 0 ) {
			$timestamp = 0;
		}
		if ( $timestamp > self::MAX_TIMESTAMP ) {
			$timestamp = self::MAX_TIMESTAMP;
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * 將數值字串格式化為兩位小數（非數字 fail-safe 成 '0.00'）
	 *
	 * @param string $value 候選數值字串
	 *
	 * @return string
	 */
	private static function format_money( string $value ): string {
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '0.00';
		}
		return bcadd( $value, '0', 2 );
	}

	/**
	 * 判斷當前環境是否啟用 HPOS
	 *
	 * @return bool
	 */
	private static function is_hpos_enabled(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
