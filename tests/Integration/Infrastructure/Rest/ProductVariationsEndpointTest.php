<?php
/**
 * 自訂商品變體端點整合測試（`GET /power-shop/product-variations/{id}`）
 *
 * 對應規格：specs/features/profit-shop/save-variation-overrides.feature
 *          Rule: 前置（載入）- 變體清單必須完整載入，不得有筆數上限
 *
 * 對應實作：
 *   inc/classes/.../Persistence/WpProductVariationQuery.php
 *   inc/classes/.../Rest/V2Api.php::get_product_variations_with_id_callback()
 *
 * ═══ 這支端點存在的唯一理由：WooCommerce REST 有 100 筆硬上限 ═══
 *
 * `wc/v3/products/{id}/variations` 的 `per_page` 上限寫死在
 * `WC_REST_Controller::get_collection_params()`（`'maximum' => 100`）。
 * 賣場的變體層價格覆寫是**逐一 variation_id** 設定的，第 101 個之後的變體
 * 站長在編輯頁根本點不到 ⇒ 那些 SKU 在賣場裡永遠只能賣原價（原 TD-16）。
 *
 * 🔴 本檔最重要的一條是 `test_returns_all_variations_beyond_wc_rest_limit`：
 *    它刻意建 **105** 個變體。少於 101 的 fixture 測不出任何東西——
 *    舊的 wc-rest 路徑在 100 個以內同樣會全部回傳。
 *
 * @group profit_shop
 * @group rest
 * @group infrastructure
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use Tests\Integration\TestCase;

/**
 * 自訂變體端點整合測試
 */
final class ProductVariationsEndpointTest extends TestCase {

	/**
	 * WooCommerce REST 的 per_page 硬上限（本端點必須突破它）
	 */
	private const WC_REST_HARD_LIMIT = 100;

	/**
	 * 發出請求並取回 data 區塊
	 *
	 * @param int $product_id 商品 ID
	 *
	 * @return array<string, mixed>
	 */
	private function fetch( int $product_id ): array {
		$request  = new \WP_REST_Request( 'GET', "/power-shop/product-variations/{$product_id}" );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), '端點應回 200' );

		$body = (array) $response->get_data();

		return (array) ( $body['data'] ?? [] );
	}

	// ====================================================================
	//  A. 突破 100 筆上限（本端點存在的理由）
	// ====================================================================

	/**
	 * 🔴 超過 100 個變體時必須全部回傳
	 *
	 * @test
	 * @group happy
	 */
	public function test_returns_all_variations_beyond_wc_rest_limit(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$total   = self::WC_REST_HARD_LIMIT + 5;
		$product = $this->make_product_with_variations( $total );

		$data = $this->fetch( (int) $product->get_id() );

		$this->assertSame( $total, $data['total'], '總數必須是實際變體數，不得被截斷成 100' );
		$this->assertCount( $total, (array) $data['items'] );
		$this->assertGreaterThan(
			self::WC_REST_HARD_LIMIT,
			count( (array) $data['items'] ),
			'回傳筆數沒有超過 100 ⇒ 這支端點沒有解決它要解決的問題'
		);
	}

	/**
	 * 回傳的變體 ID 與商品的 children 逐一對得上（不是回了 105 筆重複的）
	 *
	 * 少了這條，「回 105 筆」可以靠任何方式湊出來，包含把同一筆重複 105 次。
	 *
	 * @test
	 * @group happy
	 */
	public function test_returned_ids_match_product_children_exactly(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$product  = $this->make_product_with_variations( self::WC_REST_HARD_LIMIT + 5 );
		$expected = array_map( 'intval', $product->get_children() );

		$data = $this->fetch( (int) $product->get_id() );
		$got  = array_map( static fn( $row ) => (int) $row['id'], (array) $data['items'] );

		sort( $expected );
		sort( $got );

		$this->assertSame( $expected, $got );
	}

	// ====================================================================
	//  B. 欄位契約（前端 VariationOverrideEditor 真正讀到的那幾個）
	// ====================================================================

	/**
	 * 🔴 前端會讀的每一個欄位都必須存在
	 *
	 * 少了任何一個，畫面上對應那一欄會變成空白 / `—`，而**不會有任何錯誤訊息**。
	 *
	 * @test
	 * @group happy
	 */
	public function test_row_shape_matches_frontend_contract(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$product = $this->make_variable_with_attributes(
			[ 'color' => [ 'red', 'blue' ] ],
			[ [ 'color' => 'red' ] ]
		);

		$data = $this->fetch( (int) $product->get_id() );
		$row  = (array) ( ( (array) $data['items'] )[0] ?? [] );

		foreach ( [ 'id', 'sku', 'attributes', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'low_stock_amount' ] as $key ) {
			$this->assertArrayHasKey( $key, $row, "缺少前端會讀的欄位 {$key}" );
		}

		$this->assertSame( 'SHAPE-001', $row['sku'] );
		$this->assertSame( '500', $row['regular_price'] );
		$this->assertSame( '450', $row['sale_price'] );

		$this->assertIsArray( $row['attributes'] );
		$this->assertArrayHasKey( 'name', (array) $row['attributes'][0] );
		$this->assertArrayHasKey( 'option', (array) $row['attributes'][0] );
		$this->assertSame( 'red', $row['attributes'][0]['option'] );
	}

	/**
	 * 🔴 「沒設低庫存門檻」必須是 null，不可是 0
	 *
	 * WooCommerce 對未設定回**空字串**，而前端 `ProductStock` 收到後會
	 * `Number('')` → `0` ⇒ **每一個變體都被判定為低庫存**、整欄變成警告色，
	 * 而資料其實完全正常。
	 *
	 * @test
	 * @group edge
	 */
	public function test_unset_low_stock_amount_is_null_not_zero(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$product = $this->createVariableProduct(
			[
				'name'       => '低庫存門檻商品',
				'variations' => [ [ 'regular_price' => '100' ] ],
			]
		);

		$row = (array) ( ( (array) $this->fetch( (int) $product->get_id() )['items'] )[0] ?? [] );

		$this->assertNull( $row['low_stock_amount'], '未設定的低庫存門檻必須是 null' );
		$this->assertNotSame( 0, $row['low_stock_amount'] );
	}

	/**
	 * 「任意（Any）」屬性必須照實回傳空字串，不得被濾掉
	 *
	 * 濾掉那一列會讓表格上少一個屬性，站長會以為資料壞了。
	 *
	 * @test
	 * @group edge
	 */
	public function test_any_attribute_is_returned_as_empty_string(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$product = $this->make_variable_with_attributes(
			[
				'color' => [ 'red', 'blue' ],
				'size'  => [ 'l', 'm' ],
			],
			[
				[
					'color' => 'red',
					'size'  => '',
				],
			]
		);

		$row     = (array) ( ( (array) $this->fetch( (int) $product->get_id() )['items'] )[0] ?? [] );
		$options = array_map( static fn( $a ) => $a['option'], (array) $row['attributes'] );

		$this->assertCount( 2, (array) $row['attributes'], 'Any 屬性那一列不得被濾掉' );
		$this->assertContains( '', $options );
	}

	// ====================================================================
	//  C. 降級與權限
	// ====================================================================

	/**
	 * 非可變商品回空清單而非錯誤
	 *
	 * 前端只在 `isVariable()` 為真時才展開，這條是縱深防禦：
	 * 就算有人手打網址，也應該是空清單而不是 500。
	 *
	 * @test
	 * @group edge
	 */
	public function test_simple_product_returns_empty_list(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$product = $this->createSimpleProduct( [ 'name' => '單純商品', 'regular_price' => '100' ] );

		$data = $this->fetch( (int) $product->get_id() );

		$this->assertSame( [], (array) $data['items'] );
		$this->assertSame( 0, $data['total'] );
	}

	/**
	 * 不存在的商品 ID 回空清單而非錯誤
	 *
	 * @test
	 * @group edge
	 */
	public function test_unknown_product_returns_empty_list(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$data = $this->fetch( 999999999 );

		$this->assertSame( [], (array) $data['items'] );
	}

	/**
	 * 🔴 未登入者不得讀取
	 *
	 * 變體清單含 SKU 與成本相關的價格欄位，是後台資料。
	 * 這支端點與其他 admin endpoint 走同一個預設 permission callback，
	 * 本條確保它沒有被誤設成 `__return_true`。
	 *
	 * @test
	 * @group security
	 */
	public function test_guest_is_rejected(): void {
		\wp_set_current_user( 0 );

		$product = $this->createVariableProduct(
			[
				'name'       => '權限測試商品',
				'variations' => [ [ 'regular_price' => '100' ] ],
			]
		);

		$response = \rest_do_request(
			new \WP_REST_Request( 'GET', '/power-shop/product-variations/' . $product->get_id() )
		);

		$this->assertNotSame( 200, $response->get_status(), '未登入者不得讀到後台的變體資料' );
	}

	// ====================================================================
	//  Helpers
	// ====================================================================

	/**
	 * 建一個「父商品有登記屬性」的可變商品
	 *
	 * 🔴 共用的 `createVariableProduct()` 不會在父商品上登記屬性，而
	 * `WC_Product_Variation_Data_Store_CPT::update_attributes()` **只會寫入
	 * 父商品已登記的那些屬性**——沒登記的一律被靜默丟棄。
	 *
	 * 少了這個 helper，變體的 `attributes` 會全部存不進去，
	 * 而測試會以「端點沒回傳屬性」的形式紅燈，看起來像端點寫錯。
	 *
	 * @param array<string, string[]>            $attribute_options 父商品屬性 `{ 名稱: 可選值[] }`
	 * @param array<int, array<string, string>>  $variation_attrs   每個變體的屬性值
	 *
	 * @return \WC_Product_Variable
	 */
	private function make_variable_with_attributes( array $attribute_options, array $variation_attrs ): \WC_Product_Variable {
		$product = new \WC_Product_Variable();
		$product->set_name( '屬性測試商品' );
		$product->set_status( 'publish' );

		$attributes = [];
		foreach ( $attribute_options as $name => $options ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( (string) $name );
			$attribute->set_options( $options );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$product->set_attributes( $attributes );
		$product->save();

		foreach ( $variation_attrs as $index => $attrs ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( '500' );
			$variation->set_sale_price( '450' );
			$variation->set_sku( sprintf( 'SHAPE-%03d', $index + 1 ) );
			$variation->set_attributes( $attrs );
			$variation->save();
		}

		return new \WC_Product_Variable( $product->get_id() );
	}

	/**
	 * 建一個帶 N 個變體的可變商品
	 *
	 * @param int $count 變體數量
	 *
	 * @return \WC_Product_Variable
	 */
	private function make_product_with_variations( int $count ): \WC_Product_Variable {
		$variations = [];

		for ( $i = 1; $i <= $count; $i++ ) {
			$variations[] = [
				'regular_price' => (string) ( 100 + $i ),
				'sku'           => sprintf( 'BULK-%03d', $i ),
				'attributes'    => [ 'no' => (string) $i ],
			];
		}

		return $this->createVariableProduct(
			[
				'name'       => '大量變體商品',
				'variations' => $variations,
			]
		);
	}
}
