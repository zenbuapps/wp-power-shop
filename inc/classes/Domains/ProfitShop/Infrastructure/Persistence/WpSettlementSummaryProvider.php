<?php
/**
 * Settlement Summary Provider WP 實作（Phase 3-D 真實 SQL 聚合）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementSummaryProviderInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;

/**
 * Partner Report 用的 WP 真實聚合實作
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3、§5、§6.6、§7、§8
 *
 * SQL 策略：
 *   - 以 wc_order_itemmeta JOIN 自身（多次 alias）取出 partner / amount / status / actual_price / shop_id
 *   - INNER JOIN wc_orders（HPOS）或 wp_posts（legacy）取得訂單狀態與日期
 *   - WHERE partner_term_id = %d（鎖死在 prepared statement 第一條件）
 *   - 訂單狀態 NOT IN EXCLUDED_ORDER_STATUSES（過濾 cancelled/failed/trash/auto-draft）
 *   - settlement status filter 走白名單比對（VALID_SETTLEMENT_STATUSES）
 *   - shop_ids 強制 intval cast（絕不允許字串進 SQL）
 *   - date range 以 DATETIME 字串 + prepare %s 注入
 *
 * 安全保證：
 *   - partner_term_id 永遠以 %d placeholder 注入；<= 0 直接 short-circuit
 *   - shop_ids 全部 intval 後僅過濾正整數
 *   - statuses 白名單（VALID_SETTLEMENT_STATUSES）非白名單 → 視為 0 命中
 *   - date 以 gmdate('Y-m-d H:i:s', timestamp) 序列化後 prepare
 *   - 全程 $wpdb->prepare，不拼接使用者輸入
 *
 * 金額精度：使用 bcadd / bcsub / number_format 保持兩位小數字串。
 */
final class WpSettlementSummaryProvider implements SettlementSummaryProviderInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Order itemmeta keys（與 OrderItemSettlementRepository 對齊）
	 */
	private const META_PARTNER_TERM_ID   = '_profit_partner_term_id';
	private const META_SHOP_ID           = '_profit_shop_id';
	private const META_PROFIT_AMOUNT     = '_profit_amount';
	private const META_SETTLEMENT_STATUS = '_settlement_status';
	private const META_ACTUAL_PRICE      = '_actual_price';

	/**
	 * 排除於聚合的訂單狀態（與 OrderItemSettlementRepository::EXCLUDED_ORDER_STATUSES 對齊）
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
	 * Trend granularity DATE_FORMAT 對映（hard-coded，不接受外部輸入）
	 *
	 * @var array<string, string>
	 */
	private const TREND_FORMAT_MAP = [
		'day'   => '%Y-%m-%d',
		'week'  => '%Y-%u',
		'month' => '%Y-%m',
	];

	/**
	 * 預設 KPI 形狀（partner_term_id 不合法 / 無命中 / 全 0 時使用）
	 *
	 * @return array<string, string>
	 */
	private static function empty_kpi(): array {
		return [
			'total_sales'     => '0.00',
			'profit_pending'  => '0.00',
			'profit_paid'     => '0.00',
			'profit_refunded' => '0.00',
			'period_start'    => '',
			'period_end'      => '',
		];
	}

	/**
	 * 取得指定 partner 的 KPI 概要
	 *
	 * @param int            $partner_term_id partner term ID（鎖死）
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<string, mixed>
	 */
	public function summary_for_partner( int $partner_term_id, FilterCriteria $criteria ): array {
		// IDOR 防禦 short-circuit：partner_term_id <= 0 一律回空 KPI
		if ( $partner_term_id <= 0 ) {
			return self::empty_kpi();
		}

		$status_whitelist = self::sanitize_status_filter( $criteria->statuses );

		// 若 caller 帶了 statuses 但白名單清掉後變空 → 應視為 0 命中
		if ( ! empty( $criteria->statuses ) && empty( $status_whitelist ) ) {
			return self::empty_kpi();
		}

		global $wpdb;

		// 共用 is_hpos 一次計算傳入 helper，避免 hot path 多次呼叫
		// class_exists + WC OrderUtil（reviewer wordpress MAJOR-1）.
		$is_hpos                                = self::is_hpos_enabled();
		[ $where_extra_sql, $where_extra_args ] = self::build_extra_where( $criteria, $status_whitelist, $is_hpos );

		// 主 SQL：以 partner meta 為錨點 JOIN 出每筆 settlement 的 amount / status / actual_price
		// 再 GROUP BY status 拿到 profit_paid / profit_pending / profit_refunded
		// 再單獨算 total_sales（actual_price 加總）
		$sql_join_orders = $is_hpos
		? "INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id"
		: "INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id";

		$order_status_col = $is_hpos ? 'o.status' : 'o.post_status';

		$status_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		$sql = "SELECT
				oim_st.meta_value AS settlement_status,
				SUM(CAST(oim_amt.meta_value AS DECIMAL(20,4))) AS profit_sum,
				SUM(CAST(oim_actual.meta_value AS DECIMAL(20,4))) AS sales_sum
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_partner
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim_partner.order_item_id
			{$sql_join_orders}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_amt
				ON oim_amt.order_item_id = oim_partner.order_item_id
				AND oim_amt.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_st
				ON oim_st.order_item_id = oim_partner.order_item_id
				AND oim_st.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_actual
				ON oim_actual.order_item_id = oim_partner.order_item_id
				AND oim_actual.meta_key = %s
			WHERE oim_partner.meta_key = %s
				AND oim_partner.meta_value = %d
				AND {$order_status_col} NOT IN ({$status_placeholders})
				{$where_extra_sql}
			GROUP BY oim_st.meta_value";

		$args = array_merge(
			[
				self::META_PROFIT_AMOUNT,
				self::META_SETTLEMENT_STATUS,
				self::META_ACTUAL_PRICE,
				self::META_PARTNER_TERM_ID,
				$partner_term_id,
			],
			self::EXCLUDED_ORDER_STATUSES,
			$where_extra_args
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), \ARRAY_A );
		// phpcs:enable

		$kpi = self::empty_kpi();
		if ( ! is_array( $rows ) ) {
			return $kpi;
		}

		$total_sales = '0.00';
		foreach ( $rows as $row ) {
			$status     = (string) ( $row['settlement_status'] ?? '' );
			$profit_sum = self::format_money( (string) ( $row['profit_sum'] ?? '0' ) );
			$sales_sum  = self::format_money( (string) ( $row['sales_sum'] ?? '0' ) );

			$total_sales = bcadd( $total_sales, $sales_sum, 2 );

			switch ( $status ) {
				case SettlementRecord::STATUS_PAID:
					$kpi['profit_paid'] = bcadd( $kpi['profit_paid'], $profit_sum, 2 );
					break;
				case SettlementRecord::STATUS_PENDING:
					$kpi['profit_pending'] = bcadd( $kpi['profit_pending'], $profit_sum, 2 );
					break;
				case SettlementRecord::STATUS_REFUNDED:
					$kpi['profit_refunded'] = bcadd( $kpi['profit_refunded'], $profit_sum, 2 );
					break;
				case SettlementRecord::STATUS_CANCELLED:
					// cancelled 不計入任何分桶；total_sales 仍包含（actual_price 已成交過）
					break;
			}
		}

		$kpi['total_sales'] = $total_sales;

		// period_start / period_end：若 caller 給了 date range，回傳 normalized Y-m-d
		if ( null !== $criteria->date_start ) {
			$kpi['period_start'] = gmdate( 'Y-m-d', $criteria->date_start );
		}
		if ( null !== $criteria->date_end ) {
			$kpi['period_end'] = gmdate( 'Y-m-d', $criteria->date_end );
		}

		return $kpi;
	}

	/**
	 * 取得指定 partner 的時間序列
	 *
	 * 注意：本 query 假設每個 order_item 只有一筆 _settlement_status meta（INNER JOIN 1:1）.
	 * 若未來 settlement 流程改為一個 line item 對多筆狀態記錄，COUNT(DISTINCT order_item_id)
	 * 會雙重計算——需改為 COUNT(DISTINCT (order_item_id, settlement_id)) 或調整 schema.
	 *
	 * @param int            $partner_term_id partner term ID（鎖死）
	 * @param FilterCriteria $criteria        過濾條件
	 * @param string         $interval        粒度（day/week/month）
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function trend_for_partner( int $partner_term_id, FilterCriteria $criteria, string $interval ): array {
		if ( $partner_term_id <= 0 ) {
			return [];
		}

		// granularity 白名單（不接受 user input 進 SQL）
		$date_format = self::TREND_FORMAT_MAP[ $interval ] ?? self::TREND_FORMAT_MAP['day'];

		$status_whitelist = self::sanitize_status_filter( $criteria->statuses );
		if ( ! empty( $criteria->statuses ) && empty( $status_whitelist ) ) {
			return [];
		}

		global $wpdb;

		$is_hpos                                = self::is_hpos_enabled();
		[ $where_extra_sql, $where_extra_args ] = self::build_extra_where( $criteria, $status_whitelist, $is_hpos );

		$sql_join_orders = $is_hpos
		? "INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id"
		: "INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id";

		$order_status_col = $is_hpos ? 'o.status' : 'o.post_status';
		$order_date_col   = $is_hpos ? 'o.date_created_gmt' : 'o.post_date_gmt';

		$status_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		// date_format 來自 hard-coded TREND_FORMAT_MAP，安全可直接內插
		$sql = "SELECT
				DATE_FORMAT({$order_date_col}, %s) AS bucket,
				COUNT(DISTINCT oi.order_item_id) AS cnt,
				SUM(CAST(oim_amt.meta_value AS DECIMAL(20,4))) AS profit_sum,
				SUM(CAST(oim_actual.meta_value AS DECIMAL(20,4))) AS sales_sum
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_partner
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim_partner.order_item_id
			{$sql_join_orders}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_amt
				ON oim_amt.order_item_id = oim_partner.order_item_id
				AND oim_amt.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_st
				ON oim_st.order_item_id = oim_partner.order_item_id
				AND oim_st.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_actual
				ON oim_actual.order_item_id = oim_partner.order_item_id
				AND oim_actual.meta_key = %s
			WHERE oim_partner.meta_key = %s
				AND oim_partner.meta_value = %d
				AND {$order_status_col} NOT IN ({$status_placeholders})
				{$where_extra_sql}
			GROUP BY bucket
			ORDER BY bucket ASC";

		$args = array_merge(
			[
				$date_format,
				self::META_PROFIT_AMOUNT,
				self::META_SETTLEMENT_STATUS,
				self::META_ACTUAL_PRICE,
				self::META_PARTNER_TERM_ID,
				$partner_term_id,
			],
			self::EXCLUDED_ORDER_STATUSES,
			$where_extra_args
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), \ARRAY_A );
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$series = [];
		foreach ( $rows as $row ) {
			$series[] = [
				'date'   => (string) ( $row['bucket'] ?? '' ),
				'count'  => (int) ( $row['cnt'] ?? 0 ),
				'profit' => self::format_money( (string) ( $row['profit_sum'] ?? '0' ) ),
				'total'  => self::format_money( (string) ( $row['sales_sum'] ?? '0' ) ),
			];
		}
		return $series;
	}

	/**
	 * 取得指定 partner 的 settlement list（含分頁）
	 *
	 * @param int            $partner_term_id partner term ID（鎖死）
	 * @param FilterCriteria $criteria        過濾條件
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_partner( int $partner_term_id, FilterCriteria $criteria ): array {
		if ( $partner_term_id <= 0 ) {
			return [];
		}

		$status_whitelist = self::sanitize_status_filter( $criteria->statuses );
		if ( ! empty( $criteria->statuses ) && empty( $status_whitelist ) ) {
			return [];
		}

		global $wpdb;

		$is_hpos                                = self::is_hpos_enabled();
		[ $where_extra_sql, $where_extra_args ] = self::build_extra_where( $criteria, $status_whitelist, $is_hpos );

		$sql_join_orders = $is_hpos
		? "INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id"
		: "INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id";

		$order_status_col = $is_hpos ? 'o.status' : 'o.post_status';

		$status_placeholders = implode( ',', array_fill( 0, count( self::EXCLUDED_ORDER_STATUSES ), '%s' ) );

		$per_page = max( 1, $criteria->per_page );
		$page     = max( 1, $criteria->page );
		$offset   = ( $page - 1 ) * $per_page;

		$sql = "SELECT
				oi.order_item_id,
				oi.order_id,
				oim_partner.meta_value AS partner_term_id,
				oim_shop.meta_value AS shop_id,
				oim_amt.meta_value AS profit_amount,
				oim_st.meta_value AS settlement_status,
				oim_actual.meta_value AS actual_price
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_partner
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim_partner.order_item_id
			{$sql_join_orders}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_amt
				ON oim_amt.order_item_id = oim_partner.order_item_id
				AND oim_amt.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_st
				ON oim_st.order_item_id = oim_partner.order_item_id
				AND oim_st.meta_key = %s
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_actual
				ON oim_actual.order_item_id = oim_partner.order_item_id
				AND oim_actual.meta_key = %s
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_shop
				ON oim_shop.order_item_id = oim_partner.order_item_id
				AND oim_shop.meta_key = %s
			WHERE oim_partner.meta_key = %s
				AND oim_partner.meta_value = %d
				AND {$order_status_col} NOT IN ({$status_placeholders})
				{$where_extra_sql}
			ORDER BY oi.order_item_id DESC
			LIMIT %d OFFSET %d";

		$args = array_merge(
			[
				self::META_PROFIT_AMOUNT,
				self::META_SETTLEMENT_STATUS,
				self::META_ACTUAL_PRICE,
				self::META_SHOP_ID,
				self::META_PARTNER_TERM_ID,
				$partner_term_id,
			],
			self::EXCLUDED_ORDER_STATUSES,
			$where_extra_args,
			[ $per_page, $offset ]
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), \ARRAY_A );
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$list = [];
		foreach ( $rows as $row ) {
			$list[] = [
				'order_item_id'   => (int) ( $row['order_item_id'] ?? 0 ),
				'order_id'        => (int) ( $row['order_id'] ?? 0 ),
				'partner_term_id' => (int) ( $row['partner_term_id'] ?? 0 ),
				'shop_id'         => (int) ( $row['shop_id'] ?? 0 ),
				'profit_amount'   => self::format_money( (string) ( $row['profit_amount'] ?? '0' ) ),
				'actual_price'    => self::format_money( (string) ( $row['actual_price'] ?? '0' ) ),
				'status'          => (string) ( $row['settlement_status'] ?? '' ),
			];
		}
		return $list;
	}

	// ============================================================
	// 共用 helper
	// ============================================================

	/**
	 * 建構額外 WHERE 條件（statuses / shop_ids / date range）
	 *
	 * 全部使用 prepared placeholders；shop_ids 強制 intval cast。
	 *
	 * @param FilterCriteria $criteria         過濾條件
	 * @param string[]       $status_whitelist 已通過白名單的 settlement status
	 * @param bool           $is_hpos          是否 HPOS（由 caller 一次計算傳入，避免 hot path
	 *                                         多次呼叫 OrderUtil；reviewer wordpress MAJOR-1）
	 *
	 * @return array{0: string, 1: array<int, mixed>} [extra SQL, extra args]
	 */
	private static function build_extra_where( FilterCriteria $criteria, array $status_whitelist, bool $is_hpos ): array {
		$sql_parts = [];
		$args      = [];

		// settlement status filter
		if ( ! empty( $status_whitelist ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $status_whitelist ), '%s' ) );
			$sql_parts[]  = "AND oim_st.meta_value IN ({$placeholders})";
			$args         = array_merge( $args, $status_whitelist );
		}

		// shop_ids filter（intval cast 後僅取正整數，整數安全直接內插）
		$shop_ids_clean = array_values(
			array_filter(
				array_map( 'intval', $criteria->shop_ids ),
				static fn( int $id ): bool => $id > 0
			)
		);
		if ( ! empty( $shop_ids_clean ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $shop_ids_clean ), '%d' ) );
			// 需要額外 JOIN shop_id 才能過濾——以 EXISTS subquery 或額外 alias 處理。
			// 為簡化，再 JOIN 一條 shop alias（若 caller 沒指定 shop_ids 就跳過）
			global $wpdb;
			$sql_parts[] = "AND EXISTS (
				SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_shop_filter
				WHERE oim_shop_filter.order_item_id = oim_partner.order_item_id
					AND oim_shop_filter.meta_key = %s
					AND oim_shop_filter.meta_value IN ({$placeholders})
			)";
			$args[]      = self::META_SHOP_ID;
			foreach ( $shop_ids_clean as $sid ) {
				$args[] = $sid;
			}
		}

		// date_start / date_end 過濾（注入合法 DATETIME 字串）
		$date_col = $is_hpos ? 'o.date_created_gmt' : 'o.post_date_gmt';

		if ( null !== $criteria->date_start ) {
			$sql_parts[] = "AND {$date_col} >= %s";
			$args[]      = self::format_datetime( $criteria->date_start );
		}
		if ( null !== $criteria->date_end ) {
			$sql_parts[] = "AND {$date_col} <= %s";
			$args[]      = self::format_datetime( $criteria->date_end );
		}

		return [ implode( ' ', $sql_parts ), $args ];
	}

	/**
	 * Settlement status 白名單過濾（防 SQL injection）
	 *
	 * @param string[] $statuses 候選 status
	 *
	 * @return string[] 通過白名單者
	 */
	private static function sanitize_status_filter( array $statuses ): array {
		$out = [];
		foreach ( $statuses as $s ) {
			if ( is_string( $s ) && in_array( $s, self::VALID_SETTLEMENT_STATUSES, true ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/**
	 * 9999-12-31 23:59:59 UTC，作為 timestamp 上限避免 gmdate 對極端值
	 * （如 PHP_INT_MAX）回傳意義不明字串（reviewer LOW-T5-2）.
	 */
	private const MAX_TIMESTAMP = 253402300799;

	/**
	 * 將 unix timestamp 安全地序列化成 SQL DATETIME 字串
	 *
	 * @param int $timestamp unix timestamp
	 *
	 * @return string 格式 Y-m-d H:i:s
	 */
	private static function format_datetime( int $timestamp ): string {
		// 限制在 unix 合理範圍，避免 PHP_INT_MAX 之類極端值造成 gmdate 行為怪異
		if ( $timestamp < 0 ) {
			$timestamp = 0;
		}
		if ( $timestamp > self::MAX_TIMESTAMP ) {
			$timestamp = self::MAX_TIMESTAMP;
		}
		// gmdate 對於極大的 timestamp（如 PHP_INT_MAX）會回傳意義不明字串，
		// 但仍然是受 prepare 保護的字串，不會構成 SQL injection；查詢結果為 0 命中是預期行為。
		$out = gmdate( 'Y-m-d H:i:s', $timestamp );
		return false === $out ? '1970-01-01 00:00:00' : $out;
	}

	/**
	 * 將數值字串格式化為兩位小數
	 *
	 * 對非數字輸入採 fail-fast：直接回 '0.00'，避免 bcadd 在某些 PHP 版本對非數字字串
	 * 拋警告或回不可預期值（reviewer wordpress MAJOR-2）.
	 *
	 * @param string $value 候選數值字串（可能來自 SUM(...) 結果）
	 *
	 * @return string 兩位小數字串
	 */
	private static function format_money( string $value ): string {
		if ( '' === $value ) {
			return '0.00';
		}
		if ( ! is_numeric( $value ) ) {
			return '0.00';
		}
		// bcadd 同時可標準化精度
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
