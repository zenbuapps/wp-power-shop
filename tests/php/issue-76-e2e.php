<?php
/**
 * issue #76 回歸測試（端到端層）：在真實 WordPress + WooCommerce 上
 * 建臨時 power-shop 頁 → 灌入壞資料（productType=variable 但無 variations）
 * → 實際跑 shortcode → 驗證前台價格與 React appData → 刪除臨時頁。
 *
 * 用法：wp eval-file tests/php/issue-76-e2e.php
 * 離開碼 0 = 全過，1 = 有失敗或無法執行。
 *
 * 前提：
 * 1. 站上要有至少一個含變體的 publish variable 商品。
 * 2. wp-cli 必須連得到 DB。Local (Flywheel) 的 wp-config.php 寫死 DB_HOST = 'localhost'，
 *    但 MySQL 其實跑在自訂 port，wp-cli 走 localhost 會連到別的 MySQL 而失敗。
 *    此時用 --exec 覆寫（不要改 wp-config.php），port 見 Local 的 sites.json：
 *      wp --exec="define('DB_HOST','127.0.0.1:<port>');" eval-file tests/php/issue-76-e2e.php
 */

$GLOBALS['__e2e_pass'] = 0;
$GLOBALS['__e2e_fail'] = 0;

/**
 * 斷言
 *
 * @param string $label  斷言說明
 * @param bool   $cond   條件
 * @param string $detail 補充資訊
 * @return void
 */
function e2e_ok( string $label, bool $cond, string $detail = '' ): void {
	if ( $cond ) {
		$GLOBALS['__e2e_pass']++;
		printf( "  PASS  %s%s\n", $label, $detail ? " ($detail)" : '' );
	} else {
		$GLOBALS['__e2e_fail']++;
		printf( "  FAIL  %s%s\n", $label, $detail ? " ($detail)" : '' );
	}
}

// 挑一個真的有變體的 variable 商品
$product_id = 0;
foreach ( wc_get_products( [ 'type' => 'variable', 'limit' => 20, 'status' => 'publish', 'return' => 'ids' ] ) as $candidate ) {
	$candidate_product = wc_get_product( $candidate );
	if ( $candidate_product && $candidate_product->get_available_variations() ) {
		$product_id = $candidate;
		break;
	}
}
if ( ! $product_id ) {
	// 不可靜默 return：CI 會拿到 exit 0 卻零斷言，看起來像全過
	echo "FAIL 站上沒有含變體的 publish variable 商品，無法執行端到端驗證\n";
	WP_CLI::halt( 1 );
}
printf( "使用商品 #%d %s\n", $product_id, wc_get_product( $product_id )->get_name() );

$post_id = 0;

try {
	$post_id = wp_insert_post(
		[
			'post_type'    => 'power-shop',
			'post_title'   => '[TEMP] issue76 verify',
			'post_status'  => 'publish',
			'post_content' => '[power_shop_products]',
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		echo "建立測試頁失敗: " . $post_id->get_error_message() . "\n";
		return;
	}
	printf( "建立臨時頁 post_id=%d\n", $post_id );

	// 灌入 issue #76 的壞資料：productType=variable 但沒有 variations，外加一個自訂欄位驗 R2
	$broken = [
		[
			'productId'   => $product_id,
			'productType' => 'variable',
			'myCustomKey' => 'must-survive',
		],
	];
	update_post_meta( $post_id, 'power_shop_meta', wp_json_encode( $broken ) );
	echo "灌入壞資料: " . wp_json_encode( $broken ) . "\n\n";

	// 讓 shortcode 的 REQUEST_URI 檢查通過
	$_SERVER['REQUEST_URI'] = '/power-shop/temp-issue76-verify/';
	global $post;
	$post = get_post( $post_id ); // phpcs:ignore
	setup_postdata( $post );

	/**
	 * === 未自癒路徑（生產環境首次載入的真實情境）===
	 *
	 * Bootstrap::enqueue_script() 掛在 wp_enqueue_scripts，早於 the_content 的 shortcode，
	 * 且 wp_localize_script() 當下就把值凍結進 WP_Scripts。所以首次載入時 React 拿到的是
	 * 「還沒被 handle_shop_meta() 自癒」的 meta。這條路徑只靠 get_product_data() 的讀取端
	 * 備援撐住，必須在 do_shortcode() 之前驗，否則驗到的是自癒後的狀態，備援分支根本不會執行。
	 */
	echo "=== 症狀 B（未自癒路徑）：首次載入餵給 React 的 appData ===\n";
	$raw_meta = json_decode( get_post_meta( $post_id, 'power_shop_meta', true ), true );
	e2e_ok( '前提：此刻 meta 確實沒有 variations', empty( $raw_meta[0]['variations'] ) );

	$pre_info = J7\PowerShopV2\Functions::get_products_info( $post_id );
	$pre      = $pre_info['products'][0] ?? [];
	e2e_ok( 'variations 有值（備援生效）', ! empty( $pre['variations'] ), 'count=' . count( $pre['variations'] ?? [] ) );
	e2e_ok( 'variation_attributes 有值（規格按鈕可渲染，否則按鈕永遠 disabled）', ! empty( $pre['variation_attributes'] ), wp_json_encode( array_keys( $pre['variation_attributes'] ?? [] ) ) );

	$pre_fake = 0;
	foreach ( $pre['variations'] ?? [] as $v ) {
		$vp = wc_get_product( $v['variation_id'] );
		if ( $vp && ! $vp->is_on_sale() && ! empty( $v['salesPrice'] ) ) {
			$pre_fake++;
		}
		printf(
			"    id=%d %-7s regularPrice=%-6s salesPrice=%-6s\n",
			$v['variation_id'],
			$vp && $vp->is_on_sale() ? 'on-sale' : 'no-sale',
			var_export( $v['regularPrice'], true ),
			var_export( $v['salesPrice'], true )
		);
	}
	// 綁上 count > 0：變體為空時迴圈不跑、$pre_fake 恆為 0，這條會「真空通過」
	e2e_ok( '備援路徑無假特價（R1）', ! empty( $pre['variations'] ) && 0 === $pre_fake, 'variations=' . count( $pre['variations'] ?? [] ) . ' fake=' . $pre_fake );
	e2e_ok( 'get_products_info() 未觸發自癒寫入', empty( json_decode( get_post_meta( $post_id, 'power_shop_meta', true ), true )[0]['variations'] ) );

	echo "\n";
	$html = do_shortcode( '[power_shop_products]' );

	echo "=== 渲染後的商品格 HTML ===\n";
	if ( preg_match( '#<div data-ps-product-id="' . $product_id . '".*?</div>\s*</div>\s*<button#s', $html, $m ) ) {
		echo preg_replace( '/\n\s*\n/', "\n", $m[0] ) . "\n\n";
	} else {
		echo substr( $html, 0, 1500 ) . "\n\n";
	}

	echo "=== 症狀 A：前台有沒有價格 ===\n";
	preg_match_all( '/NT\$\s*[\d,]+/', $html, $prices );
	e2e_ok( '商品格渲染出價格', ! empty( $prices[0] ), implode( ', ', $prices[0] ) );
	e2e_ok( '加入購物車按鈕存在', strpos( $html, '加入購物車' ) !== false );

	echo "\n=== 自癒後的 power_shop_meta ===\n";
	$healed = json_decode( get_post_meta( $post_id, 'power_shop_meta', true ), true );
	echo wp_json_encode( $healed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";

	$entry = $healed[0] ?? [];
	e2e_ok( 'variations 已補回', ! empty( $entry['variations'] ) );
	e2e_ok( '自訂欄位 myCustomKey 保留（R2）', ( $entry['myCustomKey'] ?? '' ) === 'must-survive' );

	$fake_sale = 0;
	foreach ( $entry['variations'] ?? [] as $v ) {
		$vp = wc_get_product( $v['variationId'] );
		if ( $vp && ! $vp->is_on_sale() && ! empty( $v['salesPrice'] ) ) {
			$fake_sale++;
		}
	}
	e2e_ok( '寫回 DB 的資料無假特價（R1）', 0 === $fake_sale, 'fake=' . $fake_sale );

	echo "\n=== 症狀 B（自癒後）：餵給 React 的 appData ===\n";
	$info = J7\PowerShopV2\Functions::get_products_info( $post_id );
	$p    = $info['products'][0] ?? [];
	e2e_ok( 'variations 有值', ! empty( $p['variations'] ), 'count=' . count( $p['variations'] ?? [] ) );
	e2e_ok( 'variation_attributes 有值', ! empty( $p['variation_attributes'] ), wp_json_encode( array_keys( $p['variation_attributes'] ?? [] ) ) );

	echo "\n=== 收斂性：再跑一次 shortcode 不得重複寫 DB ===\n";
	$before = get_post_meta( $post_id, 'power_shop_meta', true );
	do_shortcode( '[power_shop_products]' );
	$after = get_post_meta( $post_id, 'power_shop_meta', true );
	e2e_ok( '第二次執行 meta 不變', $before === $after );

} finally {
	if ( $post_id && ! is_wp_error( $post_id ) ) {
		wp_delete_post( $post_id, true );
		printf( "\n已刪除臨時頁 post_id=%d，站台資料復原\n", $post_id );
	}
	printf( "\n----------------------------------------\nPASS %d / FAIL %d\n", $GLOBALS['__e2e_pass'], $GLOBALS['__e2e_fail'] );
	if ( $GLOBALS['__e2e_fail'] > 0 ) {
		WP_CLI::halt( 1 );
	}
}
