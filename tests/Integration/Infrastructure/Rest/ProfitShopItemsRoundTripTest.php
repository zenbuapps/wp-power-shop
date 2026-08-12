<?php
/**
 * ProfitShop items round-trip IT（BUG-A 契約回歸鎖 + save-shop-item-overrides.feature 全 7 Rule）
 *
 * 對應規格：
 *   - specs/plan/2026-07-29-profit-shop-product-selector-plan.md §0、§1-6、§測試策略
 *   - specs/features/profit-shop/save-shop-item-overrides.feature（全 7 Rule）
 *
 * 驗證 V2Api PUT/GET /profit-shops/{id} 端點 + Application UseCase（UpdateShop /
 * CreateShop）+ ProfitShopHydrator + Infrastructure Repository 全鏈路的 items
 * 巢狀契約，走真實 WP REST dispatch（\rest_do_request）。
 *
 * PHP 產品程式碼本次變更 = 0，本檔是 characterization test：
 *   - 正向 round-trip（對應 feature Rule「後置」4 條）鎖住既有正確持久化行為
 *   - BUG-A 回歸鎖（扁平 price_override / variation_id 不應被寫入）鎖住既有
 *     「Hydrator 只讀巢狀 override / variations，忽略未知扁平欄位」行為
 *   - 負向案例（對應 feature Rule「前置」3 條）鎖住既有的驗證 / 例外對映
 *
 * @group profit_shop
 * @group rest
 * @group application
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use Tests\Integration\TestCase;

/**
 * PUT/GET /profit-shops/{id} items 巢狀契約 round-trip IT
 */
final class ProfitShopItemsRoundTripTest extends TestCase {

	/** @var int */
	private int $partner_term_id;

	/** @var int */
	private int $product_id_a;

	/** @var int */
	private int $product_id_b;

	public function set_up(): void {
		parent::set_up();

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Round Trip Partner',
				slug: new PartnerSlug( 'round-trip-partner' ),
				contact_email: 'rt@example.com'
			),
			'pa55word!'
		);

		$product_a          = $this->createSimpleProduct( [ 'name' => 'MacBook Pro', 'regular_price' => '999' ] );
		$this->product_id_a = $product_a->get_id();

		$product_b          = $this->createSimpleProduct( [ 'name' => 'iPhone 15', 'regular_price' => '888' ] );
		$this->product_id_b = $product_b->get_id();
	}

	// ========== Rule: 後置（持久化）- 價格覆寫必須完整寫入且可原樣讀回 ==========

	/**
	 * happy：PUT 巢狀 items（含 override 三欄 + inflated_count）→ GET 原樣還原
	 *
	 * @test
	 * @group happy
	 */
	public function test_put_nested_items_round_trips_through_get(): void {
		$shop_id = $this->create_shop_via_api( [], 'items-rt-shop' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-shop',
			[
				[
					'product_id'     => $this->product_id_a,
					'inflated_count' => 30,
					'override'       => [
						'regular_price' => '50000',
						'sale_price'    => '49900',
						'signup_fee'    => null,
					],
				],
			]
		);

		// ---- 防空斷言：先確認 PUT 成功且真的寫入 1 筆，才驗欄位值 ----
		$this->assertSame( 200, $response->get_status() );
		$put_items = $response->get_data()['data']['items'] ?? [];
		$this->assertCount( 1, $put_items, 'PUT 回應應已包含寫入的 1 筆 item（防空斷言）' );

		$after = $this->get_shop( $shop_id );
		$this->assertSame( 200, $after['status'] );
		$get_items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $get_items, 'GET 重新查詢應還原 1 筆 item（防空斷言）' );

		$item = $get_items[0];
		$this->assertSame( $this->product_id_a, $item['product_id'] );
		$this->assertSame( 30, $item['inflated_count'] );
		$this->assertSame( '50000', $item['override']['regular_price'] );
		$this->assertSame( '49900', $item['override']['sale_price'] );
		$this->assertNull( $item['override']['signup_fee'] );
	}

	// ========== Rule: 後置（持久化）- 未提供的價格欄位必須保存為 null 代表沿用原價 ==========

	/**
	 * edge：只設定 sale_price → regular_price 應為 null
	 *
	 * @test
	 * @group edge
	 */
	public function test_only_sale_price_set_leaves_regular_price_null(): void {
		$shop_id = $this->create_shop_via_api( [], 'items-rt-sale-only' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-sale-only',
			[
				[
					'product_id' => $this->product_id_a,
					'override'   => [
						'regular_price' => null,
						'sale_price'    => '49900',
						'signup_fee'    => null,
					],
				],
			]
		);
		$this->assertSame( 200, $response->get_status() );

		$after     = $this->get_shop( $shop_id );
		$get_items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $get_items, '防空斷言：GET 應還原 1 筆 item' );

		$this->assertNull( $get_items[0]['override']['regular_price'] );
		$this->assertSame( '49900', $get_items[0]['override']['sale_price'] );
	}

	/**
	 * edge：兩個價格欄位皆留空 → 三欄全為 null（完全沿用原價）
	 *
	 * @test
	 * @group edge
	 */
	public function test_both_price_fields_empty_persists_as_null(): void {
		$shop_id = $this->create_shop_via_api( [], 'items-rt-empty' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-empty',
			[
				[
					'product_id'     => $this->product_id_b,
					'inflated_count' => 0,
				],
			]
		);
		$this->assertSame( 200, $response->get_status() );

		$after     = $this->get_shop( $shop_id );
		$get_items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $get_items, '防空斷言：GET 應還原 1 筆 item' );

		$this->assertNull( $get_items[0]['override']['regular_price'] );
		$this->assertNull( $get_items[0]['override']['sale_price'] );
		$this->assertNull( $get_items[0]['override']['signup_fee'] );
	}

	// ========== BUG-A 回歸鎖：扁平 items 契約必須被完全忽略 ==========

	/**
	 * edge：【最重要】PUT 扁平 items（price_override / variation_id 而非巢狀 override /
	 * variations）→ GET 斷言價格未被寫入
	 *
	 * 對應 plan §0：BUG-A 是純前端問題，PHP 端本來就忽略扁平欄位；本測試把這個
	 * 「忽略扁平欄位」的既有行為鎖在 REST 層，防止未來有人「順手」讓後端也讀懂
	 * 扁平欄位（那樣反而會讓舊分支 / 舊快取的前端流量產生非預期寫入）。
	 *
	 * @test
	 * @group edge
	 * @group smoke
	 * @group bug_a_regression
	 */
	public function test_put_flat_price_override_is_ignored_and_get_reflects_null(): void {
		$shop_id = $this->create_shop_via_api( [], 'items-rt-flat' );

		$request = new \WP_REST_Request( 'PUT', '/power-shop/profit-shops/' . $shop_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'title'           => '扁平契約測試賣場',
					'slug'            => 'items-rt-flat',
					'status'          => 'draft',
					'mode'            => 'page',
					'partner_term_id' => $this->partner_term_id,
					'rate'            => 10,
					'items'           => [
						[
							'product_id'     => $this->product_id_a,
							// 舊版前端扁平契約（BUG-A 成因），Hydrator 應完全忽略這兩個鍵：
							'price_override' => 50000,
							'variation_id'   => 999,
							'inflated_count' => 5,
						],
					],
					'settings'        => [],
				]
			)
		);
		$response = \rest_do_request( $request );

		// ---- 防空斷言：先確認 PUT 成功且真的寫入了 1 筆 item ----
		$this->assertSame( 200, $response->get_status() );
		$put_items = $response->get_data()['data']['items'] ?? [];
		$this->assertCount( 1, $put_items, 'PUT 回應應包含 1 筆 item（product_id 本身仍應正確寫入）' );
		$this->assertSame( $this->product_id_a, $put_items[0]['product_id'] );

		$after     = $this->get_shop( $shop_id );
		$get_items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $get_items, '防空斷言：GET 應還原 1 筆 item' );

		$item = $get_items[0];
		$this->assertSame( $this->product_id_a, $item['product_id'] );
		$this->assertSame( 5, $item['inflated_count'], 'inflated_count 是巢狀合法欄位，應正常寫入' );
		$this->assertNull( $item['override']['regular_price'], 'BUG-A 回歸鎖：扁平 price_override 不應被寫入 regular_price' );
		$this->assertNull( $item['override']['sale_price'], 'BUG-A 回歸鎖：扁平 price_override 不應被寫入 sale_price' );
		$this->assertSame( [], $item['variations'], 'BUG-A 回歸鎖：扁平 variation_id 不應被寫入 variations' );
	}

	// ========== Rule: 後置（持久化）- 儲存必須以整體覆寫語意處理 items ==========

	/**
	 * happy：未包含在送出 items 中的商品應自賣場移除（整體覆寫語意，非局部合併）
	 *
	 * @test
	 * @group happy
	 */
	public function test_update_with_fewer_items_removes_missing_products(): void {
		$shop_id = $this->create_shop_via_api(
			[
				[
					'product_id'     => $this->product_id_a,
					'inflated_count' => 30,
				],
				[
					'product_id'     => $this->product_id_b,
					'inflated_count' => 10,
				],
			],
			'items-rt-overwrite'
		);

		// 防空斷言：先確認建立時真的有 2 筆，才進行覆寫並驗證移除行為
		$before = $this->get_shop( $shop_id );
		$this->assertCount( 2, $before['data']['items'] ?? [], '前提：建立時應有 2 筆 item' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-overwrite',
			[
				[
					'product_id'     => $this->product_id_a,
					'inflated_count' => 30,
				],
			]
		);
		$this->assertSame( 200, $response->get_status() );

		$after       = $this->get_shop( $shop_id );
		$after_items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $after_items, '未包含在送出 items 中的商品應被移除，只剩 1 筆' );
		$this->assertSame( $this->product_id_a, $after_items[0]['product_id'] );
	}

	// ========== Rule: 後置（回應）- items 每筆必須包含契約定義的完整欄位 ==========

	/**
	 * happy：GET 回應中每筆 item 必須包含 product_id / inflated_count / override /
	 * variations，override 必須包含 regular_price / sale_price / signup_fee
	 *
	 * @test
	 * @group happy
	 */
	public function test_get_response_items_contain_full_contract_fields(): void {
		$shop_id = $this->create_shop_via_api(
			[
				[
					'product_id'     => $this->product_id_a,
					'inflated_count' => 1,
					'override'       => [
						'regular_price' => '100',
						'sale_price'    => null,
						'signup_fee'    => null,
					],
				],
			],
			'items-rt-fields'
		);

		$after = $this->get_shop( $shop_id );
		$items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $items, '防空斷言：應有 1 筆 item 才有欄位可驗' );

		$item = $items[0];
		foreach ( [ 'product_id', 'inflated_count', 'override', 'variations' ] as $field ) {
			$this->assertArrayHasKey( $field, $item, "item 應包含欄位 {$field}" );
		}
		$this->assertIsArray( $item['override'] );
		foreach ( [ 'regular_price', 'sale_price', 'signup_fee' ] as $field ) {
			$this->assertArrayHasKey( $field, $item['override'], "override 應包含欄位 {$field}" );
		}
	}

	// ========== Rule: 前置（參數）- override 各價格欄位若提供必須為非負十進位字串 ==========

	/**
	 * error：regular_price 為負數字串 "-100" → 400 validation_failed
	 *
	 * @test
	 * @group error
	 */
	public function test_negative_regular_price_returns_400_validation_failed(): void {
		$shop_id = $this->create_shop_via_api( [], 'items-rt-negative' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-negative',
			[
				[
					'product_id' => $this->product_id_a,
					'override'   => [
						'regular_price' => '-100',
						'sale_price'    => null,
						'signup_fee'    => null,
					],
				],
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['code'] ?? null );
	}

	/**
	 * error：regular_price 為非數字字串 "abc" → 400 validation_failed
	 *
	 * @test
	 * @group error
	 */
	public function test_non_numeric_regular_price_returns_400_validation_failed(): void {
		$shop_id = $this->create_shop_via_api( [], 'items-rt-nonnumeric' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-nonnumeric',
			[
				[
					'product_id' => $this->product_id_a,
					'override'   => [
						'regular_price' => 'abc',
						'sale_price'    => null,
						'signup_fee'    => null,
					],
				],
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['code'] ?? null );
	}

	// ========== Rule: 前置（參數）- sale_price 不得高於 regular_price ==========

	/**
	 * error：sale_price 高於 regular_price → 400 validation_failed
	 *
	 * @test
	 * @group error
	 */
	public function test_sale_price_greater_than_regular_price_returns_400_validation_failed(): void {
		$shop_id = $this->create_shop_via_api( [], 'items-rt-sale-gt-regular' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-sale-gt-regular',
			[
				[
					'product_id' => $this->product_id_a,
					'override'   => [
						'regular_price' => '50000',
						'sale_price'    => '60000',
						'signup_fee'    => null,
					],
				],
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['code'] ?? null );
	}

	// ========== Rule: 前置（參數）- product_id 必須對應存在的商品 ==========

	/**
	 * error：不存在的 product_id → 404 not_found
	 *
	 * @test
	 * @group error
	 */
	public function test_nonexistent_product_id_returns_404_not_found(): void {
		$shop_id        = $this->create_shop_via_api( [], 'items-rt-missing-product' );
		$nonexistent_id = 999999;

		$response = $this->put_items(
			$shop_id,
			'items-rt-missing-product',
			[
				[
					'product_id' => $nonexistent_id,
					'override'   => [
						'regular_price' => '50000',
						'sale_price'    => null,
						'signup_fee'    => null,
					],
				],
			]
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['code'] ?? null );
	}

	// ========== Phase 9：變體層覆寫（價格 + 灌水購買人數）契約 ==========

	/**
	 * happy：PUT 巢狀 variations（override 三欄 + inflated_count）→ GET 原樣還原
	 *
	 * 這是 Phase 9 契約擴充的主鎖：variations[vid] 的形狀是
	 * `{ override: {...}, inflated_count: int }`，而非只有 override。
	 *
	 * @test
	 * @group happy
	 */
	public function test_put_variation_overrides_round_trip_through_get(): void {
		[ $variable_id, $variation_ids ] = $this->create_variable_product();
		$shop_id                         = $this->create_shop_via_api( [], 'items-rt-variation' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-variation',
			[
				[
					'product_id'     => $variable_id,
					'inflated_count' => 3,
					'override'       => [
						'regular_price' => '1000',
						'sale_price'    => null,
						'signup_fee'    => null,
					],
					'variations'     => [
						(string) $variation_ids[0] => [
							'override'       => [
								'regular_price' => '900',
								'sale_price'    => '850',
								'signup_fee'    => null,
							],
							'inflated_count' => 27,
						],
						(string) $variation_ids[1] => [
							'override'       => [
								'regular_price' => null,
								'sale_price'    => null,
								'signup_fee'    => null,
							],
							'inflated_count' => 5,
						],
					],
				],
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$after     = $this->get_shop( $shop_id );
		$get_items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $get_items, '防空斷言：GET 應還原 1 筆 item' );

		$variations = $get_items[0]['variations'] ?? [];
		$this->assertCount( 2, $variations, '防空斷言：應還原 2 筆 variation' );

		$v0 = $variations[ $variation_ids[0] ] ?? null;
		$this->assertIsArray( $v0, "variation {$variation_ids[0]} 應存在於回應中" );
		$this->assertSame( '900', $v0['override']['regular_price'] );
		$this->assertSame( '850', $v0['override']['sale_price'] );
		$this->assertSame( 27, $v0['inflated_count'], '變體層 inflated_count 必須完整 round-trip' );

		// 只設灌水人數、不設價格：override 三欄維持 null（沿用原價），inflated_count 仍寫入
		$v1 = $variations[ $variation_ids[1] ] ?? null;
		$this->assertIsArray( $v1, "variation {$variation_ids[1]} 應存在於回應中" );
		$this->assertNull( $v1['override']['regular_price'] );
		$this->assertNull( $v1['override']['sale_price'] );
		$this->assertSame( 5, $v1['inflated_count'] );
	}

	/**
	 * edge：variations 只給 override、不給 inflated_count → 讀回為 0（不是 null，也不報錯）
	 *
	 * 對應 R-7 的「舊契約前端」情境：舊分支 / 舊快取仍可能送出無 inflated_count 的
	 * variations，後端必須接住。
	 *
	 * @test
	 * @group edge
	 * @group smoke
	 */
	public function test_variation_without_inflated_count_defaults_to_zero(): void {
		[ $variable_id, $variation_ids ] = $this->create_variable_product();
		$shop_id                         = $this->create_shop_via_api( [], 'items-rt-var-legacy' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-var-legacy',
			[
				[
					'product_id' => $variable_id,
					'variations' => [
						(string) $variation_ids[0] => [
							'override' => [
								'regular_price' => '900',
								'sale_price'    => null,
								'signup_fee'    => null,
							],
						],
					],
				],
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$after     = $this->get_shop( $shop_id );
		$get_items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $get_items, '防空斷言：GET 應還原 1 筆 item' );

		$v0 = $get_items[0]['variations'][ $variation_ids[0] ] ?? null;
		$this->assertIsArray( $v0, '防空斷言：variation 應存在' );
		$this->assertSame( '900', $v0['override']['regular_price'], '價格必須照常寫入' );
		$this->assertSame( 0, $v0['inflated_count'], '未提供 inflated_count 應讀回 0' );
	}

	/**
	 * error：變體層 sale_price 高於 regular_price → 400 validation_failed
	 *
	 * 後端 PriceOverride VO 對每個變體的 override 都會走同一條驗證，
	 * 不因前端 Form rule 已擋就放寬（繞過前端直打 API 一樣被擋）。
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_variation_sale_price_greater_than_regular_returns_400(): void {
		[ $variable_id, $variation_ids ] = $this->create_variable_product();
		$shop_id                         = $this->create_shop_via_api( [], 'items-rt-var-sale-gt' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-var-sale-gt',
			[
				[
					'product_id' => $variable_id,
					'variations' => [
						(string) $variation_ids[0] => [
							'override'       => [
								'regular_price' => '500',
								'sale_price'    => '900',
								'signup_fee'    => null,
							],
							'inflated_count' => 0,
						],
					],
				],
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['code'] ?? null );
	}

	/**
	 * error：variation 不屬於該 product → 400 validation_failed（InvalidVariation）
	 *
	 * ItemValidator::validate() 的 has_variation 檢查；鎖住「拿 A 商品的變體 ID
	 * 掛到 B 商品上」會被擋下。
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_variation_not_belonging_to_product_returns_400(): void {
		[ , $variation_ids ] = $this->create_variable_product();
		$shop_id             = $this->create_shop_via_api( [], 'items-rt-var-mismatch' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-var-mismatch',
			[
				[
					// simple 商品掛上另一個 variable 商品的 variation ID
					'product_id' => $this->product_id_a,
					'variations' => [
						(string) $variation_ids[0] => [
							'override'       => [
								'regular_price' => '900',
								'sale_price'    => null,
								'signup_fee'    => null,
							],
							'inflated_count' => 0,
						],
					],
				],
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['code'] ?? null );
	}

	/**
	 * edge：變體層 inflated_count 為負數 → sanitize 為 0（不是 400）
	 *
	 * 與商品層一致：InflatedCount VO 採容錯設計，負數修正為 0 而非拋例外。
	 *
	 * @test
	 * @group edge
	 */
	public function test_negative_variation_inflated_count_is_sanitized_to_zero(): void {
		[ $variable_id, $variation_ids ] = $this->create_variable_product();
		$shop_id                         = $this->create_shop_via_api( [], 'items-rt-var-negative' );

		$response = $this->put_items(
			$shop_id,
			'items-rt-var-negative',
			[
				[
					'product_id' => $variable_id,
					'variations' => [
						(string) $variation_ids[0] => [
							'override'       => [
								'regular_price' => '900',
								'sale_price'    => null,
								'signup_fee'    => null,
							],
							'inflated_count' => -12,
						],
					],
				],
			]
		);

		$this->assertSame( 200, $response->get_status(), '負數應被容錯 sanitize，而非 400' );

		$after     = $this->get_shop( $shop_id );
		$get_items = $after['data']['items'] ?? [];
		$this->assertCount( 1, $get_items, '防空斷言：GET 應還原 1 筆 item' );

		$v0 = $get_items[0]['variations'][ $variation_ids[0] ] ?? null;
		$this->assertIsArray( $v0, '防空斷言：variation 應存在' );
		$this->assertSame( 0, $v0['inflated_count'], '負數應被 sanitize 為 0' );
	}

	// ========== Rule: 後置（持久化）- items 的排列順序即賣場前台的商品顯示順序 ==========

	/**
	 * happy：items 陣列順序原樣保留（前台 template 依此順序渲染商品）
	 *
	 * 賣場編輯頁的商品表格支援拖曳排序，排序結果就是直接改變 items 陣列的排列。
	 * 這條鎖住「送什麼順序、讀回來就是什麼順序」——若哪天 Hydrator / Repository
	 * 改用 product_id 當 key 或中途 sort，拖曳排序會靜默失效（存檔成功但順序跑掉）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_items_order_is_preserved_through_round_trip(): void {
		$product_c  = $this->createSimpleProduct( [ 'name' => 'iPad Air', 'regular_price' => '777' ] );
		$product_id_c = $product_c->get_id();

		$shop_id = $this->create_shop_via_api( [], 'items-rt-order' );

		$desired_order = [ $this->product_id_b, $product_id_c, $this->product_id_a ];

		$response = $this->put_items(
			$shop_id,
			'items-rt-order',
			array_map(
				static fn( int $pid ): array => [
					'product_id'     => $pid,
					'inflated_count' => 0,
					'override'       => [
						'regular_price' => null,
						'sale_price'    => null,
						'signup_fee'    => null,
					],
				],
				$desired_order
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$after = $this->get_shop( $shop_id );
		$this->assertSame( 200, $after['status'] );

		$get_items = $after['data']['items'] ?? [];
		$this->assertCount( 3, $get_items, 'GET 應還原 3 筆 item（防空斷言）' );

		$actual_order = array_map( static fn( array $item ): int => (int) $item['product_id'], $get_items );
		$this->assertSame( $desired_order, $actual_order, 'items 讀回的順序必須與送出時完全一致' );
	}

	/**
	 * happy：重新排序後再存一次，新順序取代舊順序（拖曳排序的直接契約）
	 *
	 * @test
	 * @group happy
	 */
	public function test_reordered_items_persist_the_new_order(): void {
		$shop_id = $this->create_shop_via_api( [], 'items-rt-reorder' );

		$make_items = static fn( array $ids ): array => array_map(
			static fn( int $pid ): array => [
				'product_id'     => $pid,
				'inflated_count' => 0,
				'override'       => [
					'regular_price' => null,
					'sale_price'    => null,
					'signup_fee'    => null,
				],
			],
			$ids
		);

		$first_order = [ $this->product_id_a, $this->product_id_b ];
		$this->assertSame( 200, $this->put_items( $shop_id, 'items-rt-reorder', $make_items( $first_order ) )->get_status() );

		$before = $this->get_shop( $shop_id );
		$this->assertSame(
			$first_order,
			array_map( static fn( array $item ): int => (int) $item['product_id'], $before['data']['items'] ?? [] ),
			'測試前置：初始順序應為 [A, B]'
		);

		// 使用者在後台把 B 拖到 A 前面
		$swapped_order = [ $this->product_id_b, $this->product_id_a ];
		$this->assertSame( 200, $this->put_items( $shop_id, 'items-rt-reorder', $make_items( $swapped_order ) )->get_status() );

		$after = $this->get_shop( $shop_id );
		$this->assertSame( 200, $after['status'] );
		$this->assertSame(
			$swapped_order,
			array_map( static fn( array $item ): int => (int) $item['product_id'], $after['data']['items'] ?? [] ),
			'重新排序後讀回的順序必須是新順序 [B, A]'
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立含 2 個變體的 variable 商品
	 *
	 * @return array{0: int, 1: array<int, int>} [parent product id, variation ids]
	 */
	private function create_variable_product(): array {
		$variable = $this->createVariableProduct(
			[
				'name'       => '可變測試商品',
				'variations' => [
					[ 'regular_price' => '1000' ],
					[ 'regular_price' => '1200' ],
				],
			]
		);

		$variation_ids = array_values( array_map( 'intval', $variable->get_children() ) );

		// 防空斷言：後續所有變體測試都建立在「真的有 2 個變體」這個前提上
		$this->assertCount( 2, $variation_ids, '測試前置：可變商品應有 2 個變體' );

		return [ (int) $variable->get_id(), $variation_ids ];
	}

	/**
	 * 透過 POST /profit-shops 建立賣場，回傳新 ID
	 *
	 * @param array<int, array<string, mixed>> $items items 原始陣列
	 * @param string                            $slug  賣場 slug
	 */
	private function create_shop_via_api( array $items, string $slug ): int {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-shops' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'title'           => 'Items Round Trip 測試賣場',
					'slug'            => $slug,
					'status'          => 'draft',
					'mode'            => 'page',
					'partner_term_id' => $this->partner_term_id,
					'rate'            => 10,
					'items'           => $items,
					'settings'        => [],
				]
			)
		);

		$response = \rest_do_request( $request );
		$body     = $response->get_data();

		// 防空斷言：測試前置必須先確認建立成功，否則後續 PUT/GET 都是建立在錯誤前提上。
		$this->assertSame( 201, $response->get_status(), '測試前置：建立賣場應成功' );

		return (int) ( $body['data']['id'] ?? 0 );
	}

	/**
	 * 透過 PUT /profit-shops/{id} 覆寫 items（其它欄位維持固定的合法值，slug 與建立時相同避免誤觸衝突）
	 *
	 * @param int                               $shop_id 賣場 ID
	 * @param string                            $slug    賣場 slug（必須與建立時相同）
	 * @param array<int, array<string, mixed>>  $items   items 原始陣列
	 */
	private function put_items( int $shop_id, string $slug, array $items ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'PUT', '/power-shop/profit-shops/' . $shop_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'title'           => 'Items Round Trip 測試賣場',
					'slug'            => $slug,
					'status'          => 'draft',
					'mode'            => 'page',
					'partner_term_id' => $this->partner_term_id,
					'rate'            => 10,
					'items'           => $items,
					'settings'        => [],
				]
			)
		);

		return \rest_do_request( $request );
	}

	/**
	 * 透過 GET /profit-shops/{id} 取得賣場，回傳 status + 已剝掉 {code,data} 外層的 ProfitShopOutput 陣列
	 *
	 * @param int $shop_id 賣場 ID
	 *
	 * @return array{status: int, data: array<string, mixed>}
	 */
	private function get_shop( int $shop_id ): array {
		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-shops/' . $shop_id );
		$response = \rest_do_request( $request );
		$body     = $response->get_data();

		return [
			'status' => $response->get_status(),
			'data'   => ( is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : [],
		];
	}
}
