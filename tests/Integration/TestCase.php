<?php
/**
 * Power Shop 整合測試基礎類別
 * 所有 Power Shop 整合測試應繼承此類別，提供共用 helper methods。
 */

declare( strict_types=1 );

namespace Tests\Integration;

/**
 * 整合測試基礎類別（共用 helper / fixture / 自訂斷言）
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * 最後發生的錯誤（用於驗證操作是否失敗）
	 *
	 * @var \Throwable|null
	 */
	protected ?\Throwable $lastError = null;

	/**
	 * 查詢結果（用於驗證 Query 操作的回傳值）
	 *
	 * @var mixed
	 */
	protected mixed $queryResult = null;

	/**
	 * ID 映射表（用戶名稱 → 用戶 ID 等）
	 *
	 * @var array<string, int>
	 */
	protected array $ids = [];

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->lastError   = null;
		$this->queryResult = null;
		$this->ids         = [];
	}

	/**
	 * 清理（每個測試後執行）
	 *
	 * 1. 清空 _profit_* post_meta（ProfitShop 相關 meta）
	 * 2. 清除 transients
	 * 3. 清除 wc_order_itemmeta
	 */
	public function tear_down(): void {
		$this->clean_profit_meta();
		$this->clean_transients();
		$this->clean_wc_order_itemmeta();
		parent::tear_down();
	}

	// ========== 清理 helper ==========

	/**
	 * 清除所有 _profit_* 開頭的 post_meta（ProfitShop 相關）
	 */
	protected function clean_profit_meta(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_profit\\_%'" ); // phpcs:ignore
	}

	/**
	 * 清除所有 transients（包含 site transients）
	 */
	protected function clean_transients(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" ); // phpcs:ignore
		\wp_cache_flush();
	}

	/**
	 * 清除 wc_order_itemmeta
	 */
	protected function clean_wc_order_itemmeta(): void {
		global $wpdb;
		$table = "{$wpdb->prefix}woocommerce_order_itemmeta";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
		}
	}

	// ========== Fixture helper ==========

	/**
	 * 建立 simple WooCommerce 商品
	 *
	 * @param array<string, mixed> $args 覆蓋預設值（name / regular_price / sale_price / stock_quantity / status / sku ...）
	 * @return \WC_Product_Simple 已儲存的商品
	 */
	protected function createSimpleProduct( array $args = [] ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( (string) ( $args['name'] ?? '測試商品' ) );
		$product->set_status( (string) ( $args['status'] ?? 'publish' ) );
		$product->set_regular_price( (string) ( $args['regular_price'] ?? '100' ) );

		if ( isset( $args['sale_price'] ) ) {
			$product->set_sale_price( (string) $args['sale_price'] );
		}
		if ( isset( $args['sku'] ) ) {
			$product->set_sku( (string) $args['sku'] );
		}
		if ( isset( $args['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $args['stock_quantity'] );
		}
		if ( isset( $args['description'] ) ) {
			$product->set_description( (string) $args['description'] );
		}

		$product->save();
		return $product;
	}

	/**
	 * 建立 variable WooCommerce 商品（含基本屬性 + 可選 variations）
	 *
	 * @param array<string, mixed> $args
	 *   - name: 商品名稱
	 *   - status: 商品狀態
	 *   - variations: array<int, array{regular_price?: string, sale_price?: string, sku?: string, attributes?: array<string, string>}>
	 * @return \WC_Product_Variable 已儲存的可變商品
	 */
	protected function createVariableProduct( array $args = [] ): \WC_Product_Variable {
		$product = new \WC_Product_Variable();
		$product->set_name( (string) ( $args['name'] ?? '測試可變商品' ) );
		$product->set_status( (string) ( $args['status'] ?? 'publish' ) );
		$product->save();

		$variations = (array) ( $args['variations'] ?? [] );
		foreach ( $variations as $vargs ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( (string) ( $vargs['regular_price'] ?? '100' ) );
			if ( isset( $vargs['sale_price'] ) ) {
				$variation->set_sale_price( (string) $vargs['sale_price'] );
			}
			if ( isset( $vargs['sku'] ) ) {
				$variation->set_sku( (string) $vargs['sku'] );
			}
			if ( isset( $vargs['attributes'] ) && is_array( $vargs['attributes'] ) ) {
				$variation->set_attributes( $vargs['attributes'] );
			}
			$variation->save();
		}

		// 重新載入確保 children 同步
		return new \WC_Product_Variable( $product->get_id() );
	}

	/**
	 * 建立 subscription 訂閱商品（需要 WooCommerce Subscriptions 啟用，否則退回 simple）
	 *
	 * @param array<string, mixed> $args
	 *   - name: 商品名稱
	 *   - regular_price: 定期費用
	 *   - period: day/week/month/year
	 *   - period_interval: 週期間隔
	 * @return \WC_Product 已儲存的訂閱商品（或退回 simple product）
	 */
	protected function createSubscriptionProduct( array $args = [] ): \WC_Product {
		if ( ! class_exists( '\WC_Product_Subscription' ) ) {
			// Subscriptions plugin 未啟用時，退回 simple product
			return $this->createSimpleProduct( $args );
		}

		/** @var \WC_Product $product */
		$product = new \WC_Product_Subscription();
		$product->set_name( (string) ( $args['name'] ?? '測試訂閱商品' ) );
		$product->set_status( (string) ( $args['status'] ?? 'publish' ) );
		$product->set_regular_price( (string) ( $args['regular_price'] ?? '100' ) );
		$product->save();

		// 訂閱專屬 meta（Subscriptions 用 product meta 儲存）
		\update_post_meta( $product->get_id(), '_subscription_price', (string) ( $args['regular_price'] ?? '100' ) );
		\update_post_meta( $product->get_id(), '_subscription_period', (string) ( $args['period'] ?? 'month' ) );
		\update_post_meta( $product->get_id(), '_subscription_period_interval', (string) ( $args['period_interval'] ?? '1' ) );

		return $product;
	}

	/**
	 * 建立 ProfitShop（一頁賣場）
	 *
	 * 此 helper 為 P0 stub；實際 Domain 完成後（P1）將替換為真正的 ProfitShop 建立邏輯。
	 *
	 * @param array<string, mixed> $args 一頁賣場參數
	 * @return int 一頁賣場 ID（目前為 stub，回傳 0）
	 *
	 * @phpstan-ignore-next-line
	 */
	protected function createProfitShop( array $args = [] ): int {
		// TODO(P1): 等 Domain\ProfitShop 完成後，改為實際建立邏輯
		return 0;
	}

	// ========== 自訂斷言 ==========

	/**
	 * 斷言 wc_order_itemmeta 中某筆 meta 的值符合預期
	 *
	 * @param int    $order_item_id WooCommerce 訂單項目 ID
	 * @param string $key           meta key
	 * @param mixed  $expected      期望值
	 */
	protected function assertOrderItemMetaEquals( int $order_item_id, string $key, mixed $expected ): void {
		$actual = \wc_get_order_item_meta( $order_item_id, $key, true );
		$this->assertSame(
			$expected,
			$actual,
			sprintf( '訂單項目 %d 的 meta "%s" 預期為 %s，實際為 %s', $order_item_id, $key, var_export( $expected, true ), var_export( $actual, true ) )
		);
	}

	/**
	 * 斷言操作成功（$this->lastError 應為 null）
	 */
	protected function assert_operation_succeeded(): void {
		$this->assertNull(
			$this->lastError,
			sprintf( '預期操作成功，但發生錯誤：%s', $this->lastError?->getMessage() )
		);
	}

	/**
	 * 斷言操作失敗（$this->lastError 不應為 null）
	 */
	protected function assert_operation_failed(): void {
		$this->assertNotNull( $this->lastError, '預期操作失敗，但沒有發生錯誤' );
	}
}
