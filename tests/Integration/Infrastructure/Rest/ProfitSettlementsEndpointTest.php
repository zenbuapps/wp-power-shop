<?php
/**
 * profit-settlements 六個 endpoint 的整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3、§5.5、§6.1、§6.9、§7.5
 * 對應實作：
 *   - inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php（6 個 route + callbacks）
 *   - inc/classes/Domains/ProfitShop/Application/UseCase/Settlement/*
 *   - inc/classes/Domains/ProfitShop/Infrastructure/Persistence/WpAdminSettlementQuery.php
 *   - inc/classes/Domains/ProfitShop/Infrastructure/Persistence/PartnerSettledLogRepository.php
 *
 * @group profit_shop
 * @group rest
 * @group settlement
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\ListSettlementOrderIdsUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerSettledLogRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;
use Tests\Integration\TestCase;

/**
 * profit-settlements endpoint 整合測試
 */
final class ProfitSettlementsEndpointTest extends TestCase {

	private const SHOP_ID = 9001;

	/**
	 * 測試用 partner term ID
	 *
	 * @var int
	 */
	private int $partner_term_id = 0;

	/**
	 * 設定
	 */
	public function set_up(): void {
		parent::set_up();

		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: '結算測試夥伴',
				slug: new PartnerSlug( 'settlement-test-partner' ),
				contact_email: null
			),
			'pw12345678'
		);
	}

	// ========== fixture helpers ==========

	/**
	 * 建立一張含分潤 line item 的訂單，回傳 order_item_id
	 *
	 * @param string   $status          結算狀態
	 * @param string   $profit_amount   分潤金額
	 * @param string   $actual_price    成交價
	 * @param int|null $partner_term_id 覆寫 partner（預設用本測試的 partner）
	 * @param string   $order_status    訂單狀態
	 * @param string   $product_name    商品名稱（用於 CSV 注入測試）
	 * @param int|null $shop_id         覆寫賣場 ID（預設用本測試的常數）
	 *
	 * @return int order_item_id
	 */
	private function seed_settlement(
		string $status = 'pending',
		string $profit_amount = '100.00',
		string $actual_price = '500.00',
		?int $partner_term_id = null,
		string $order_status = 'processing',
		string $product_name = '結算測試商品',
		?int $shop_id = null
	): int {
		$product = $this->createSimpleProduct(
			[
				'name'          => $product_name,
				'regular_price' => $actual_price,
			]
		);

		$order = \wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_total( (float) $actual_price );
		$order->add_item( $item );
		$order->set_status( $order_status );
		$order->save();

		$item_id = $item->get_id();
		$this->assertGreaterThan( 0, $item_id, 'fixture 必須成功建立 order item' );

		\wc_update_order_item_meta( $item_id, '_profit_shop_id', $shop_id ?? self::SHOP_ID );
		\wc_update_order_item_meta( $item_id, '_profit_partner_term_id', $partner_term_id ?? $this->partner_term_id );
		\wc_update_order_item_meta( $item_id, '_profit_rate', 20 );
		\wc_update_order_item_meta( $item_id, '_actual_price', $actual_price );
		\wc_update_order_item_meta( $item_id, '_profit_amount', $profit_amount );
		\wc_update_order_item_meta( $item_id, '_settlement_status', $status );

		return $item_id;
	}

	/**
	 * 在一個 `shop_subscription` 物件上建立帶相同分潤 meta 的 line item
	 *
	 * 模擬訂閱商品結帳後 `_profit_*` 同時落在主訂單與訂閱物件上的真實情況。
	 * 直接寫 posts / order_items 表而非用 WCS API——WooCommerce Subscriptions
	 * 未必在測試環境啟用，但「訂單型別必須被過濾」這條規則與該外掛是否啟用無關。
	 *
	 * @param string $status        結算狀態
	 * @param string $profit_amount 分潤金額
	 *
	 * @return int order_item_id
	 */
	private function seed_settlement_on_subscription( string $status = 'pending', string $profit_amount = '100.00' ): int {
		global $wpdb;

		$subscription_id = self::factory()->post->create(
			[
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-active',
			]
		);

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_order_items',
			[
				'order_item_name' => '訂閱物件上的品項',
				'order_item_type' => 'line_item',
				'order_id'        => $subscription_id,
			],
			[ '%s', '%s', '%d' ]
		);
		$item_id = (int) $wpdb->insert_id;

		\wc_update_order_item_meta( $item_id, '_profit_shop_id', self::SHOP_ID );
		\wc_update_order_item_meta( $item_id, '_profit_partner_term_id', $this->partner_term_id );
		\wc_update_order_item_meta( $item_id, '_profit_rate', 20 );
		\wc_update_order_item_meta( $item_id, '_actual_price', '500.00' );
		\wc_update_order_item_meta( $item_id, '_profit_amount', $profit_amount );
		\wc_update_order_item_meta( $item_id, '_settlement_status', $status );

		return $item_id;
	}

	/**
	 * 切換為 administrator 並回傳 user id
	 *
	 * @return int
	 */
	private function login_admin(): int {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
		return $admin_id;
	}

	/**
	 * 切換為 shop_manager（有 manage_woocommerce、無 manage_options）
	 *
	 * @return int
	 */
	private function login_shop_manager(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		\wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * 發出帶 admin nonce 的 POST 請求
	 *
	 * @param string               $route 路由
	 * @param array<string, mixed> $body  JSON body
	 *
	 * @return \WP_REST_Response
	 */
	private function post( string $route, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( $body ) );

		return \rest_do_request( $request );
	}

	/**
	 * 發出 GET 請求
	 *
	 * @param string               $route  路由
	 * @param array<string, mixed> $params query params
	 *
	 * @return \WP_REST_Response
	 */
	private function get( string $route, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );

		return \rest_do_request( $request );
	}

	// ========== 路由註冊 ==========

	/**
	 * smoke：七個 route 皆已註冊
	 *
	 * @group smoke
	 */
	public function test_all_seven_routes_are_registered(): void {
		$routes = \rest_get_server()->get_routes();

		foreach (
			[
				'/power-shop/profit-settlements',
				'/power-shop/profit-settlements/summary',
				'/power-shop/profit-settlements/order-ids',
				'/power-shop/profit-settlements/mark-paid',
				'/power-shop/profit-settlements/mark-cancelled',
				'/power-shop/profit-settlements/reverse-paid',
				'/power-shop/profit-settlements/export',
			] as $route
		) {
			$this->assertArrayHasKey( $route, $routes, "spec §4.3：{$route} 應註冊" );
		}
	}

	// ========== 權限 ==========

	/**
	 * security：未登入者不可讀取結算列表
	 *
	 * @group security
	 */
	public function test_guest_cannot_read_settlements(): void {
		\wp_set_current_user( 0 );

		$response = $this->get( '/power-shop/profit-settlements' );

		$this->assertContains( $response->get_status(), [ 401, 403 ], '未登入必須被擋下' );
	}

	/**
	 * security：一般訂閱者不可讀取結算列表
	 *
	 * @group security
	 */
	public function test_subscriber_cannot_read_settlements(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->get( '/power-shop/profit-settlements' );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * 🔴 security：shop_manager 連**讀**都不行（整個結算頁收在 manage_options）
	 *
	 * 規格 query-settlements.feature 與 export-settlements-csv.feature 都明文斷言
	 * 「僅具商店管理權限」→ 403，讀寫皆然。結算頁緊鄰不可逆的金流動作，
	 * 讓 shop_manager 看得到金額卻按不了按鈕只會讓人繞路（截圖、口頭轉述金額），
	 * 整頁同一個邊界才一致。
	 *
	 * @group security
	 */
	public function test_shop_manager_is_denied_on_every_settlement_endpoint(): void {
		$item_id = $this->seed_settlement();
		$this->login_shop_manager();

		foreach ( [ 'profit-settlements', 'profit-settlements/summary', 'profit-settlements/order-ids' ] as $route ) {
			$read = $this->get( "/power-shop/{$route}" );
			$this->assertContains( $read->get_status(), [ 401, 403 ], "shop_manager 不該能讀取 {$route}" );

			// WP 的 rest_forbidden 回應本來就有 data（內含 status），所以不能只斷言「沒有 data」；
			// 真正要確認的是裡面沒有任何結算欄位。
			$body = (string) \wp_json_encode( $read->get_data() );
			foreach ( [ 'items', 'profit_amount', 'pending_amount', 'order_ids' ] as $leak ) {
				$this->assertStringNotContainsString( $leak, $body, "403 回應不得洩漏 {$leak}" );
			}
		}

		$export = $this->post( '/power-shop/profit-settlements/export', [] );
		$this->assertContains( $export->get_status(), [ 401, 403 ], 'shop_manager 不該能匯出' );

		foreach ( [ 'mark-paid', 'mark-cancelled', 'reverse-paid' ] as $action ) {
			$write = $this->post(
				"/power-shop/profit-settlements/{$action}",
				[ 'order_item_ids' => [ $item_id ] ]
			);
			$this->assertContains(
				$write->get_status(),
				[ 401, 403 ],
				"shop_manager 不該能執行 {$action}"
			);
		}

		// 狀態必須完全沒被動到
		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', 'pending' );
	}

	/**
	 * 🔴 security：admin 但缺少 nonce 時撥款被拒（CSRF 防禦）
	 *
	 * @group security
	 */
	public function test_mark_paid_without_nonce_is_rejected(): void {
		$item_id = $this->seed_settlement();
		$this->login_admin();

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-settlements/mark-paid' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( [ 'order_item_ids' => [ $item_id ] ] ) );

		$response = \rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', 'pending' );
	}

	// ========== GET list / summary ==========

	/**
	 * happy：列表回 {code, data} 且含分頁中介資料
	 *
	 * @group happy
	 */
	public function test_list_returns_wrapped_payload_with_pagination(): void {
		$this->seed_settlement();
		$this->seed_settlement();
		$this->login_admin();

		$response = $this->get( '/power-shop/profit-settlements' );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'success', $body['code'] ?? null, 'admin endpoint 一律包 {code, data}' );
		$this->assertArrayHasKey( 'data', $body );
		$this->assertCount( 2, $body['data']['items'] );
		$this->assertSame( 2, $body['data']['total'] );
		$this->assertSame( 1, $body['data']['page'] );
		$this->assertSame( 20, $body['data']['per_page'] );

		$first = $body['data']['items'][0];
		foreach (
			[
				'order_item_id',
				'order_id',
				'product_name',
				'partner_term_id',
				'partner_name',
				'shop_id',
				'shop_name',
				'actual_price',
				'profit_amount',
				'status',
			] as $key
		) {
			$this->assertArrayHasKey( $key, $first, "列表項目應含 {$key}" );
		}
	}

	/**
	 * happy：依 status 過濾
	 *
	 * @group happy
	 */
	public function test_list_filters_by_status(): void {
		$this->seed_settlement( 'pending' );
		$paid_id = $this->seed_settlement( 'paid' );
		$this->login_admin();

		$body = $this->get( '/power-shop/profit-settlements', [ 'status' => 'paid' ] )->get_data();

		$this->assertCount( 1, $body['data']['items'] );
		$this->assertSame( $paid_id, $body['data']['items'][0]['order_item_id'] );
	}

	/**
	 * happy：依 partner_id 過濾
	 *
	 * @group happy
	 */
	public function test_list_filters_by_partner(): void {
		$other_partner = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: '另一位夥伴',
				slug: new PartnerSlug( 'other-partner' ),
				contact_email: null
			),
			'pw12345678'
		);

		$mine = $this->seed_settlement();
		$this->seed_settlement( 'pending', '100.00', '500.00', $other_partner );
		$this->login_admin();

		$body = $this->get(
			'/power-shop/profit-settlements',
			[ 'partner_id' => $this->partner_term_id ]
		)->get_data();

		$this->assertCount( 1, $body['data']['items'] );
		$this->assertSame( $mine, $body['data']['items'][0]['order_item_id'] );
	}

	/**
	 * 🔴 security：不在白名單的 status 回 400，**不得**退化成「不過濾」
	 *
	 * 這條是實作過程中真的踩到的缺陷：DTO 原本靜默丟棄不合法的 status，
	 * 導致過濾條件被清空後整個查詢退化成「列出全部」——站長會看到所有品項
	 * 卻以為自己正在看某個篩選子集。回 0 筆同樣有害（會被讀成「沒有待撥款的」），
	 * 所以正解是明確報錯。
	 *
	 * @group security
	 */
	public function test_unknown_status_filter_is_rejected_not_silently_widened(): void {
		$this->seed_settlement( 'pending' );
		$this->seed_settlement( 'paid' );
		$this->login_admin();

		$response = $this->get(
			'/power-shop/profit-settlements',
			[ 'status' => "paid' OR 1=1 --" ]
		);

		$this->assertSame( 400, $response->get_status(), '非法 status 必須明確報錯' );

		$body = $response->get_data();
		$this->assertSame( 'validation_failed', $body['code'] ?? null );
		$this->assertArrayNotHasKey( 'items', (array) ( $body['data'] ?? [] ), '不得回傳任何結算資料' );
	}

	/**
	 * security：摘要端點對非法 status 同樣拒絕（兩個端點不得行為分歧）
	 *
	 * @group security
	 */
	public function test_summary_also_rejects_unknown_status(): void {
		$this->seed_settlement( 'pending' );
		$this->login_admin();

		$response = $this->get( '/power-shop/profit-settlements/summary', [ 'status' => 'shipped' ] );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * security：已取消 / 失敗的訂單其 line item 不列入結算
	 *
	 * @group security
	 */
	public function test_cancelled_orders_are_excluded(): void {
		$this->seed_settlement( 'pending', '100.00', '500.00', null, 'processing' );
		$this->seed_settlement( 'pending', '999.00', '500.00', null, 'cancelled' );
		$this->login_admin();

		$body = $this->get( '/power-shop/profit-settlements' )->get_data();

		$this->assertCount( 1, $body['data']['items'], '已取消訂單不該污染結算金額' );
		$this->assertSame( '100.00', $body['data']['items'][0]['profit_amount'] );
	}

	/**
	 * happy：摘要卡回各狀態的金額與筆數
	 *
	 * @group happy
	 */
	public function test_summary_returns_amounts_and_counts(): void {
		$this->seed_settlement( 'pending', '100.00' );
		$this->seed_settlement( 'pending', '50.50' );
		$this->seed_settlement( 'paid', '200.00' );
		$this->seed_settlement( 'refunded', '30.00' );
		$this->login_admin();

		$body = $this->get( '/power-shop/profit-settlements/summary' )->get_data();
		$data = $body['data'];

		$this->assertSame( 'success', $body['code'] );
		$this->assertSame( '150.50', $data['pending_amount'] );
		$this->assertSame( 2, $data['pending_count'] );
		$this->assertSame( '200.00', $data['paid_amount'] );
		$this->assertSame( 1, $data['paid_count'] );
		$this->assertSame( '30.00', $data['refunded_amount'] );
		$this->assertSame( 1, $data['refunded_count'] );
		$this->assertSame( 4, $data['total_count'] );
	}

	/**
	 * happy：摘要跟隨過濾條件（與列表一致）
	 *
	 * @group happy
	 */
	public function test_summary_respects_filter(): void {
		$this->seed_settlement( 'pending', '100.00' );
		$this->seed_settlement( 'paid', '200.00' );
		$this->login_admin();

		$data = $this->get(
			'/power-shop/profit-settlements/summary',
			[ 'status' => 'pending' ]
		)->get_data()['data'];

		$this->assertSame( '100.00', $data['pending_amount'] );
		$this->assertSame( '0.00', $data['paid_amount'], '被過濾掉的狀態不該出現在摘要' );
	}

	// ========== mark-paid ==========

	/**
	 * happy：撥款寫入狀態、時間、操作者與稽核紀錄
	 *
	 * @group happy
	 */
	public function test_mark_paid_updates_meta_and_writes_audit_log(): void {
		$item_id  = $this->seed_settlement( 'pending', '250.00' );
		$admin_id = $this->login_admin();

		$response = $this->post(
			'/power-shop/profit-settlements/mark-paid',
			[ 'order_item_ids' => [ $item_id ] ]
		);
		$body = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'success', $body['code'] );
		$this->assertSame( [ $item_id ], $body['data']['updated_ids'] );
		$this->assertSame( '250.00', $body['data']['total_amount'] );

		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', 'paid' );
		$this->assertSame(
			(string) $admin_id,
			\wc_get_order_item_meta( $item_id, '_settled_by', true ),
			'必須記錄是誰撥的款'
		);
		$this->assertNotEmpty( \wc_get_order_item_meta( $item_id, '_settled_at', true ) );

		$log = PartnerSettledLogRepository::instance()->read( $this->partner_term_id );
		$this->assertCount( 1, $log, 'spec §5.5：批次撥款必須寫入 _partner_settled_log' );
		$this->assertSame( 'settle', $log[0]['type'] );
		$this->assertSame( '250.00', $log[0]['total'] );
		$this->assertSame( 1, $log[0]['item_count'] );
		$this->assertSame( $admin_id, $log[0]['by'] );
	}

	/**
	 * 🔴 security：重複撥款不會重複計入，且被跳過的 id 明確回報
	 *
	 * 模擬兩位管理員同時按下撥款：第二次必須完整回報「這些沒有重複計入」。
	 *
	 * @group security
	 */
	public function test_concurrent_mark_paid_does_not_double_count(): void {
		$item_id = $this->seed_settlement( 'pending', '250.00' );
		$this->login_admin();

		$first  = $this->post( '/power-shop/profit-settlements/mark-paid', [ 'order_item_ids' => [ $item_id ] ] );
		$second = $this->post( '/power-shop/profit-settlements/mark-paid', [ 'order_item_ids' => [ $item_id ] ] );

		$this->assertSame( [ $item_id ], $first->get_data()['data']['updated_ids'] );

		$second_data = $second->get_data()['data'];
		$this->assertSame( 200, $second->get_status(), '重複操作不是錯誤，是「已處理過」' );
		$this->assertSame( [], $second_data['updated_ids'] );
		$this->assertSame( '0.00', $second_data['total_amount'] );
		$this->assertArrayHasKey( (string) $item_id, $second_data['skipped'], '被跳過的 id 必須明確列出' );

		$log = PartnerSettledLogRepository::instance()->read( $this->partner_term_id );
		$this->assertCount( 1, $log, '稽核紀錄不得因重複操作而多一筆' );
	}

	/**
	 * error：空清單 → 400
	 *
	 * @group error
	 */
	public function test_mark_paid_with_empty_list_returns_400(): void {
		$this->login_admin();

		$response = $this->post( '/power-shop/profit-settlements/mark-paid', [ 'order_item_ids' => [] ] );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * security：超過批次上限 → 400（DoS 防護）
	 *
	 * @group security
	 */
	public function test_mark_paid_over_batch_limit_returns_400(): void {
		$this->login_admin();

		$response = $this->post(
			'/power-shop/profit-settlements/mark-paid',
			[ 'order_item_ids' => range( 1, 501 ) ]
		);

		$this->assertSame( 400, $response->get_status() );
	}

	// ========== mark-cancelled / reverse-paid ==========

	/**
	 * happy：取消 pending 品項
	 *
	 * @group happy
	 */
	public function test_mark_cancelled_updates_pending_records(): void {
		$item_id = $this->seed_settlement( 'pending' );
		$this->login_admin();

		$response = $this->post(
			'/power-shop/profit-settlements/mark-cancelled',
			[ 'order_item_ids' => [ $item_id ] ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', 'cancelled' );
		$this->assertSame( '', \wc_get_order_item_meta( $item_id, '_settled_at', true ), '取消不該寫撥款時間' );
	}

	/**
	 * 🔴 security：reverse-paid 對非 paid 狀態一律拒絕
	 *
	 * pending 被誤沖回會產生 refund_after_paid 稽核紀錄，站長下次撥款照此扣抵 → 少付夥伴。
	 *
	 * @group security
	 */
	public function test_reverse_paid_rejects_non_paid_records(): void {
		$pending_id   = $this->seed_settlement( 'pending' );
		$cancelled_id = $this->seed_settlement( 'cancelled' );
		$this->login_admin();

		$response = $this->post(
			'/power-shop/profit-settlements/reverse-paid',
			[ 'order_item_ids' => [ $pending_id, $cancelled_id ] ]
		);
		$data = $response->get_data()['data'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $data['updated_ids'] );
		$this->assertArrayHasKey( (string) $pending_id, $data['skipped'] );
		$this->assertArrayHasKey( (string) $cancelled_id, $data['skipped'] );

		$this->assertOrderItemMetaEquals( $pending_id, '_settlement_status', 'pending' );
		$this->assertSame(
			[],
			PartnerSettledLogRepository::instance()->read( $this->partner_term_id ),
			'被拒絕的沖回不得留下任何稽核紀錄'
		);
	}

	/**
	 * happy：reverse-paid 對 paid 品項成功並留下 refund_after_paid 稽核
	 *
	 * @group happy
	 */
	public function test_reverse_paid_succeeds_for_paid_records(): void {
		$item_id = $this->seed_settlement( 'paid', '180.00' );
		$this->login_admin();

		$response = $this->post(
			'/power-shop/profit-settlements/reverse-paid',
			[ 'order_item_ids' => [ $item_id ] ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ $item_id ], $response->get_data()['data']['updated_ids'] );
		$this->assertOrderItemMetaEquals( $item_id, '_settlement_status', 'refunded' );

		$log = PartnerSettledLogRepository::instance()->read( $this->partner_term_id );
		$this->assertCount( 1, $log );
		$this->assertSame( 'refund_after_paid', $log[0]['type'], 'spec §7.5 指定的稽核型別' );
		$this->assertSame( '180.00', $log[0]['total'] );
	}

	/**
	 * edge：混合批次只處理符合狀態者，其餘明確回報
	 *
	 * @group edge
	 */
	public function test_mixed_batch_reports_both_updated_and_skipped(): void {
		$pending_id = $this->seed_settlement( 'pending' );
		$paid_id    = $this->seed_settlement( 'paid' );
		$this->login_admin();

		$data = $this->post(
			'/power-shop/profit-settlements/mark-paid',
			[ 'order_item_ids' => [ $pending_id, $paid_id ] ]
		)->get_data()['data'];

		$this->assertSame( [ $pending_id ], $data['updated_ids'] );
		$this->assertSame( 1, $data['updated_count'] );
		$this->assertSame( 1, $data['skipped_count'] );
		$this->assertArrayHasKey( (string) $paid_id, $data['skipped'] );
	}

	// ========== export ==========

	/**
	 * happy：匯出回 CSV 全文（含 BOM 與表頭）與檔名
	 *
	 * @group happy
	 */
	public function test_export_returns_csv_with_bom_and_header(): void {
		$this->seed_settlement( 'pending', '100.00', '500.00', null, 'processing', '一般商品' );
		$this->login_admin();

		$response = $this->post( '/power-shop/profit-settlements/export', [] );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'success', $body['code'] );

		$data = $body['data'];
		$csv  = (string) base64_decode( $data['content_base64'], true );

		$this->assertStringStartsWith( "\u{FEFF}", $csv, 'Excel 需要 BOM 才不會把中文開成亂碼' );
		$this->assertStringContainsString( '訂單編號', $csv );
		$this->assertStringContainsString( '一般商品', $csv );
		$this->assertSame( 1, $data['row_count'] );
		$this->assertStringEndsWith( '.csv', $data['filename'] );
	}

	/**
	 * 🔴 security：CSV 公式注入被中和（端到端，商品名稱來自使用者輸入）
	 *
	 * @group security
	 */
	public function test_export_neutralises_formula_injection_end_to_end(): void {
		$this->seed_settlement(
			'pending',
			'100.00',
			'500.00',
			null,
			'processing',
			'=HYPERLINK("https://evil.tld","對帳明細")'
		);
		$this->login_admin();

		$data    = $this->post( '/power-shop/profit-settlements/export', [] )->get_data()['data'];
		$content = (string) base64_decode( $data['content_base64'], true );

		$this->assertStringContainsString( "'=HYPERLINK", $content, '危險前綴必須被單引號中和' );
		$this->assertStringNotContainsString( ',=HYPERLINK', $content, '不得有未中和的公式欄位' );
	}

	/**
	 * security：匯出回應不得被快取（內容含全站分潤金額）
	 *
	 * @group security
	 */
	public function test_export_response_is_not_cacheable(): void {
		$this->seed_settlement();
		$this->login_admin();

		$headers = $this->post( '/power-shop/profit-settlements/export', [] )->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}

	/**
	 * happy：匯出遵循過濾條件
	 *
	 * @group happy
	 */
	public function test_export_respects_filter(): void {
		$this->seed_settlement( 'pending', '100.00', '500.00', null, 'processing', '待撥款商品' );
		$this->seed_settlement( 'paid', '200.00', '500.00', null, 'processing', '已撥款商品' );
		$this->login_admin();

		$data = $this->post(
			'/power-shop/profit-settlements/export',
			[ 'status' => 'paid' ]
		)->get_data()['data'];

		$csv = (string) base64_decode( $data['content_base64'], true );

		$this->assertSame( 1, $data['row_count'] );
		$this->assertStringContainsString( '已撥款商品', $csv );
		$this->assertStringNotContainsString( '待撥款商品', $csv );
	}

	/**
	 * security：未授權者不可匯出
	 *
	 * @group security
	 */
	public function test_guest_cannot_export(): void {
		$this->seed_settlement();
		\wp_set_current_user( 0 );

		$response = $this->post( '/power-shop/profit-settlements/export', [] );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	// ========== 訂單型別過濾（訂閱物件雙重計算） ==========

	/**
	 * 🔴 security：訂閱物件上的品項不得被計入列表（否則金額 ×2）
	 *
	 * 訂閱商品結帳後，同一組 `_profit_*` meta 會**同時**存在於主訂單與
	 * `shop_subscription` 物件的 line item 上，而訂閱物件的狀態（`wc-active` /
	 * `wc-on-hold`）不在被排除的訂單狀態清單內。不限定訂單型別的話，
	 * 每一筆分潤都會被算兩次——症狀是「金額剛好是兩倍」，沒有任何錯誤訊息。
	 *
	 * @group security
	 */
	public function test_subscription_line_items_are_excluded_from_list(): void {
		$item_id = $this->seed_settlement( 'pending', '48.00' );
		$this->seed_settlement_on_subscription( 'pending', '48.00' );
		$this->login_admin();

		$body = $this->get( '/power-shop/profit-settlements' )->get_data();

		$this->assertCount( 1, $body['data']['items'], '訂閱物件上的品項不得讓同一筆分潤出現兩次' );
		$this->assertSame( $item_id, $body['data']['items'][0]['order_item_id'] );
		$this->assertSame( 1, $body['data']['total'] );
	}

	/**
	 * 🔴 security：摘要同樣排除訂閱物件的品項
	 *
	 * @group security
	 */
	public function test_subscription_line_items_are_excluded_from_summary(): void {
		$this->seed_settlement( 'pending', '48.00' );
		$this->seed_settlement_on_subscription( 'pending', '48.00' );
		$this->login_admin();

		$data = $this->get( '/power-shop/profit-settlements/summary' )->get_data()['data'];

		$this->assertSame( '48.00', $data['pending_amount'], '金額不得變成兩倍' );
		$this->assertSame( 1, $data['pending_count'] );
	}

	/**
	 * 🔴 security：匯出同樣排除訂閱物件的品項
	 *
	 * @group security
	 */
	public function test_subscription_line_items_are_excluded_from_export(): void {
		$this->seed_settlement( 'pending', '48.00' );
		$this->seed_settlement_on_subscription( 'pending', '48.00' );
		$this->login_admin();

		$data = $this->post( '/power-shop/profit-settlements/export', [] )->get_data()['data'];

		$this->assertSame( 1, $data['row_count'] );
	}

	// ========== 已刪除的賣場 / 夥伴 ==========

	/**
	 * 🔴 happy：賣場已刪除時以佔位文字呈現，金額照舊（那筆錢仍然要付給人）
	 *
	 * @group happy
	 */
	public function test_deleted_shop_shows_placeholder_and_keeps_amount(): void {
		$shop_id = self::factory()->post->create(
			[
				'post_type'   => CptRegistrar::POST_TYPE,
				'post_title'  => '夏季賣場',
				'post_status' => 'publish',
			]
		);
		$item_id = $this->seed_settlement( 'pending', '48.00', '240.00', null, 'processing', '筆記型電腦', $shop_id );

		\wp_delete_post( $shop_id, true );
		$this->login_admin();

		$item = $this->get( '/power-shop/profit-settlements' )->get_data()['data']['items'][0];

		$this->assertSame( $item_id, $item['order_item_id'] );
		$this->assertSame( '[已刪除賣場]', $item['shop_name'] );
		$this->assertSame( '48.00', $item['profit_amount'], '金額已凍結，不因賣場被刪而消失' );
	}

	/**
	 * 🔴 happy：夥伴已刪除時以佔位文字呈現，該筆仍出現在列表中
	 *
	 * @group happy
	 */
	public function test_deleted_partner_shows_placeholder_and_row_survives(): void {
		$item_id = $this->seed_settlement();

		\wp_delete_term( $this->partner_term_id, TaxonomyRegistrar::TAXONOMY );
		$this->login_admin();

		$items = $this->get( '/power-shop/profit-settlements' )->get_data()['data']['items'];

		$this->assertCount( 1, $items, '夥伴被刪不可讓整列消失' );
		$this->assertSame( $item_id, $items[0]['order_item_id'] );
		$this->assertSame( '[已停用夥伴]', $items[0]['partner_name'] );
	}

	/**
	 * happy：賣場與夥伴都存在時顯示真實名稱
	 *
	 * @group happy
	 */
	public function test_existing_shop_and_partner_show_real_names(): void {
		$shop_id = self::factory()->post->create(
			[
				'post_type'   => CptRegistrar::POST_TYPE,
				'post_title'  => '夏季賣場',
				'post_status' => 'publish',
			]
		);
		$this->seed_settlement( 'pending', '48.00', '240.00', null, 'processing', '筆記型電腦', $shop_id );
		$this->login_admin();

		$item = $this->get( '/power-shop/profit-settlements' )->get_data()['data']['items'][0];

		$this->assertSame( '夏季賣場', $item['shop_name'] );
		$this->assertSame( '結算測試夥伴', $item['partner_name'] );
	}

	// ========== _profit_rate = 0 ==========

	/**
	 * 🔴 happy：分潤比例為 0 的紀錄要被標示為待人工處理，**且絕不可被過濾掉**
	 *
	 * 結帳當下賣場已被刪除時，寫入 hook 會刻意寫下完整歸屬 meta 但
	 * rate = 0 / amount = 0.00（而不是不寫）。過濾掉這種紀錄等於讓一筆
	 * 該付的錢從系統中消失。
	 *
	 * @group happy
	 */
	public function test_zero_rate_records_are_flagged_not_filtered_out(): void {
		$item_id = $this->seed_settlement( 'pending', '0.00' );
		\wc_update_order_item_meta( $item_id, '_profit_rate', 0 );
		$this->login_admin();

		$items = $this->get( '/power-shop/profit-settlements' )->get_data()['data']['items'];

		$this->assertCount( 1, $items, 'rate=0 / amount=0.00 的紀錄不得被過濾掉' );
		$this->assertSame( $item_id, $items[0]['order_item_id'] );
		$this->assertSame( 0, $items[0]['rate'] );
		$this->assertTrue( $items[0]['needs_manual_rate'], '需帶有「需人工設定比例」的標記' );
	}

	/**
	 * happy：正常比例的紀錄不會被誤標為待人工處理
	 *
	 * @group happy
	 */
	public function test_normal_rate_records_are_not_flagged(): void {
		$this->seed_settlement();
		$this->login_admin();

		$item = $this->get( '/power-shop/profit-settlements' )->get_data()['data']['items'][0];

		$this->assertSame( 20, $item['rate'] );
		$this->assertFalse( $item['needs_manual_rate'] );
	}

	// ========== order-ids（D-6） ==========

	/**
	 * happy：依賣場取得 order_id 清單
	 *
	 * @group happy
	 */
	public function test_order_ids_returns_matching_orders(): void {
		$item_id  = $this->seed_settlement();
		$order_id = (int) \wc_get_order_id_by_order_item_id( $item_id );
		$this->login_admin();

		$data = $this->get(
			'/power-shop/profit-settlements/order-ids',
			[ 'shop_id' => self::SHOP_ID ]
		)->get_data()['data'];

		$this->assertSame( [ $order_id ], $data['order_ids'] );
		$this->assertSame( 1, $data['total'] );
		$this->assertFalse( $data['truncated'] );
	}

	/**
	 * 🔴 security：超過上限時截斷並誠實回報
	 *
	 * 靜默截斷會讓站長直接得出「這個賣場只有這幾張訂單」的錯誤結論。
	 *
	 * @group security
	 */
	public function test_order_ids_reports_truncation(): void {
		$this->seed_settlement();
		$this->seed_settlement();
		$this->seed_settlement();
		$this->login_admin();

		$data = $this->get(
			'/power-shop/profit-settlements/order-ids',
			[ 'limit' => 2 ]
		)->get_data()['data'];

		$this->assertCount( 2, $data['order_ids'] );
		$this->assertSame( 3, $data['total'], 'total 必須是未受 limit 影響的真實總數' );
		$this->assertTrue( $data['truncated'] );
		$this->assertSame( 2, $data['limit'] );
	}

	/**
	 * security：limit 只能變小、不可用來繞過上限
	 *
	 * @group security
	 */
	public function test_order_ids_limit_cannot_exceed_cap(): void {
		$this->seed_settlement();
		$this->login_admin();

		$data = $this->get(
			'/power-shop/profit-settlements/order-ids',
			[ 'limit' => 999999 ]
		)->get_data()['data'];

		$this->assertSame( ListSettlementOrderIdsUseCase::DEFAULT_LIMIT, $data['limit'] );
	}

	/**
	 * security：未授權者不可取得 order_id 清單
	 *
	 * @group security
	 */
	public function test_guest_cannot_read_order_ids(): void {
		$this->seed_settlement();
		\wp_set_current_user( 0 );

		$response = $this->get( '/power-shop/profit-settlements/order-ids' );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}
}
