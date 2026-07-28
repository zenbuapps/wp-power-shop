<?php
/**
 * issue #76 回歸測試（單元層）— 真的 require 受測的專案程式碼，配最小 WP/WC stub，不需要 WordPress。
 *
 * 用法：php tests/php/issue-76-unit.php
 * 離開碼 0 = 全過，1 = 有失敗。
 *
 * 守護的行為：
 * - variable 商品缺 variations 時，handle_shop_meta() 必須補回（issue #76 主症狀）
 * - 沒特價的變體 salesPrice 必須為 0，否則前台 Price 元件會渲染假特價
 * - 補 variations 時必須保留該筆其他自訂欄位（團購設定等）
 * - WooCommerce 也沒變體時不得寫 DB；修好後重跑不得重複寫入
 * - 商品被刪除時不得 fatal
 */

namespace {
	$PLUGIN = dirname( __DIR__, 2 );

	$GLOBALS['__wc_products']    = [];
	$GLOBALS['__meta_writes']    = [];
	$GLOBALS['__assert_pass']    = 0;
	$GLOBALS['__assert_fail']    = 0;

	class WC_Product {
		public $id;
		public $type          = 'simple';
		public $regular_price = '';
		public $sale_price    = '';
		public $children      = [];
		public function __construct( $id, $type = 'simple', $regular = '', $sale = '' ) {
			$this->id            = $id;
			$this->type          = $type;
			$this->regular_price = $regular;
			$this->sale_price    = $sale;
		}
		public function get_id() { return $this->id; }
		public function get_type() { return $this->type; }
		public function get_regular_price() { return $this->regular_price; }
		public function get_sale_price() { return $this->sale_price; }
	}

	class WC_Product_Variable extends WC_Product {
		public $available = [];
		public function __construct( $id, $available = [] ) {
			parent::__construct( $id, 'variable' );
			$this->available = $available;
		}
		public function get_available_variations() { return $this->available; }
	}

	function wc_get_product( $id ) {
		return $GLOBALS['__wc_products'][ (int) $id ] ?? false;
	}

	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['__meta_writes'][] = [ 'post_id' => $post_id, 'key' => $key, 'value' => $value ];
		return true;
	}

	function add_shortcode( $tag, $cb ) {}
	function sanitize_text_field( $s ) { return $s; }
	function wp_unslash( $s ) { return $s; }
	function wp_json_encode( $v ) { return json_encode( $v ); }

	function ok( $label, $cond, $detail = '' ) {
		if ( $cond ) {
			$GLOBALS['__assert_pass']++;
			printf( "  PASS  %s%s\n", $label, $detail ? " ($detail)" : '' );
		} else {
			$GLOBALS['__assert_fail']++;
			printf( "  FAIL  %s%s\n", $label, $detail ? " ($detail)" : '' );
		}
	}
}

namespace J7\PowerShop {
	class Plugin {
		public static $snake   = 'power_shop';
		public static $kebab   = 'power-shop';
		public static $url     = 'http://example.test';
		public static $dir     = '/tmp';
		public static $version = '3.0.23';
	}
}

namespace J7\WpUtils\Traits {
	trait SingletonTrait {
		public static $instance = null;
		public static function instance() { return static::$instance ??= new static(); }
	}
}

namespace _ {
	function find( $collection, $predicate ) {
		foreach ( (array) $collection as $item ) {
			$hit = true;
			foreach ( $predicate as $k => $v ) {
				if ( ! isset( $item[ $k ] ) || $item[ $k ] != $v ) { // phpcs:ignore
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				return $item;
			}
		}
		return null;
	}
}

namespace {
	require_once $PLUGIN . '/legacy/inc/classes/Functions.php';
	require_once $PLUGIN . '/legacy/inc/classes/ShortCode.php';

	use J7\PowerShopV2\Functions;
	use J7\PowerShopV2\ShortCode;

	/** 建立可變商品：501 有特價 500→450，502 無特價 800 */
	function seed_variable( $pid = 123 ) {
		$GLOBALS['__wc_products'][501] = new WC_Product( 501, 'variation', '500', '450' );
		$GLOBALS['__wc_products'][502] = new WC_Product( 502, 'variation', '800', '' );
		$GLOBALS['__wc_products'][ $pid ] = new WC_Product_Variable(
			$pid,
			[
				[ 'variation_id' => 501, 'display_regular_price' => 500.0, 'display_price' => 450.0 ],
				[ 'variation_id' => 502, 'display_regular_price' => 800.0, 'display_price' => 800.0 ],
			]
		);
	}

	function call_handle_shop_meta( array $shop_meta ): array {
		$ref    = new ReflectionClass( ShortCode::class );
		$sc     = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'handle_shop_meta' );
		$method->setAccessible( true );
		return $method->invoke( $sc, $shop_meta );
	}

	$GLOBALS['post']     = (object) [ 'ID' => 9 ];
	$broken_meta_factory = fn() => [ [ 'productId' => 123, 'productType' => 'variable' ] ];

	echo "\n=== T1 Functions::format_variations() 真實呼叫 ===\n";
	seed_variable();
	$fv = Functions::format_variations( $GLOBALS['__wc_products'][123] );
	print_r( $fv );
	ok( '有特價變體 salesPrice=450', 450.0 === $fv[0]['salesPrice'] );
	ok(
		'無特價變體 salesPrice=0（不得等於原價，否則前台假特價）',
		0.0 === $fv[1]['salesPrice'],
		'got=' . var_export( $fv[1]['salesPrice'], true )
	);
	ok( '無特價變體 regularPrice=800', 800.0 === $fv[1]['regularPrice'] );

	echo "\n=== T2 format_variations() 對非可變商品 ===\n";
	ok( 'simple 商品回空陣列', [] === Functions::format_variations( new WC_Product( 7, 'simple', '100' ) ) );

	echo "\n=== T3 handle_shop_meta() 補回缺失的 variations ===\n";
	$GLOBALS['__meta_writes'] = [];
	seed_variable();
	$out = call_handle_shop_meta( $broken_meta_factory() );
	ok( 'variations 已補回', ! empty( $out[0]['variations'] ), 'count=' . count( $out[0]['variations'] ?? [] ) );
	ok( '有寫回 post meta', 1 === count( $GLOBALS['__meta_writes'] ) );
	ok( '無特價變體寫入 DB 的 salesPrice=0', 0.0 === ( $out[0]['variations'][1]['salesPrice'] ?? null ) );

	echo "\n=== T4 補 variations 時保留該筆其他自訂欄位（團購設定）===\n";
	$GLOBALS['__meta_writes'] = [];
	seed_variable();
	$with_custom = [ [ 'productId' => 123, 'productType' => 'variable', 'extraBuyerCount' => 5, 'customField' => 'keep-me' ] ];
	$out         = call_handle_shop_meta( $with_custom );
	ok( 'extraBuyerCount 未被抹除', 5 === ( $out[0]['extraBuyerCount'] ?? null ) );
	ok( 'customField 未被抹除', 'keep-me' === ( $out[0]['customField'] ?? null ) );

	echo "\n=== T5 收斂性：可變商品但 WC 也沒變體 → 不得寫 DB ===\n";
	$GLOBALS['__meta_writes']      = [];
	$GLOBALS['__wc_products'][124] = new WC_Product_Variable( 124, [] );
	$out                           = call_handle_shop_meta( [ [ 'productId' => 124, 'productType' => 'variable' ] ] );
	ok( '未寫入 post meta', 0 === count( $GLOBALS['__meta_writes'] ), 'writes=' . count( $GLOBALS['__meta_writes'] ) );

	echo "\n=== T6 收斂性：修好後再跑一次不得重複寫入 ===\n";
	seed_variable();
	$GLOBALS['__meta_writes'] = [];
	$healed                   = call_handle_shop_meta( $broken_meta_factory() ); // 第 1 次：寫
	$writes_first             = count( $GLOBALS['__meta_writes'] );
	$GLOBALS['__meta_writes'] = [];
	call_handle_shop_meta( $healed );                                            // 第 2 次：不該寫
	ok( '第 1 次寫入', 1 === $writes_first );
	ok( '第 2 次不寫入', 0 === count( $GLOBALS['__meta_writes'] ), 'writes=' . count( $GLOBALS['__meta_writes'] ) );

	echo "\n=== T7 type mismatch（simple → variable）整筆重建，丟棄失效舊欄位 ===\n";
	seed_variable();
	$GLOBALS['__meta_writes'] = [];
	$stale                    = [ [ 'productId' => 123, 'productType' => 'simple', 'regularPrice' => 999, 'salesPrice' => 888 ] ];
	$out                      = call_handle_shop_meta( $stale );
	ok( 'productType 已更新為 variable', 'variable' === $out[0]['productType'] );
	ok( '失效的 regularPrice 已丟棄', ! array_key_exists( 'regularPrice', $out[0] ) );
	ok( 'variations 已建立', 2 === count( $out[0]['variations'] ?? [] ) );

	echo "\n=== T8 商品已刪除 → 略過不 fatal ===\n";
	$GLOBALS['__meta_writes'] = [];
	$out                      = call_handle_shop_meta( [ [ 'productId' => 99999, 'productType' => 'variable' ] ] );
	ok( '未 fatal 且原樣返回', isset( $out[0]['productId'] ) );
	ok( '未寫入 post meta', 0 === count( $GLOBALS['__meta_writes'] ) );

	echo "\n=== T9 grouped 型態只同步 productType，保留其他 key ===\n";
	$GLOBALS['__wc_products'][200] = new WC_Product( 200, 'grouped' );
	$out                           = call_handle_shop_meta( [ [ 'productId' => 200, 'productType' => 'simple', 'regularPrice' => 111 ] ] );
	ok( 'productType 同步為 grouped', 'grouped' === $out[0]['productType'] );
	ok( '其他 key 保留', 111 === ( $out[0]['regularPrice'] ?? null ) );

	printf( "\n----------------------------------------\nPASS %d / FAIL %d\n", $GLOBALS['__assert_pass'], $GLOBALS['__assert_fail'] );
	exit( $GLOBALS['__assert_fail'] > 0 ? 1 : 0 );
}
