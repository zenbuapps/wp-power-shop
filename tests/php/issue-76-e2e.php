<?php
/**
 * issue #76 回歸測試（端到端層）：在真實 WordPress + WooCommerce 上
 * 建臨時 power-shop 頁 → 灌入壞資料（productType=variable 但無 variations）
 * → 實際跑 shortcode → 驗證前台價格與 React appData → 刪除臨時頁。
 *
 * 用法：wp eval-file tests/php/issue-76-e2e.php
 * 需求：站上要有至少一個含變體的 publish variable 商品。
 */

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
	echo "站上沒有含變體的 publish variable 商品，無法執行端到端驗證\n";
	return;
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

	$html = do_shortcode( '[power_shop_products]' );

	echo "=== 渲染後的商品格 HTML ===\n";
	if ( preg_match( '#<div data-ps-product-id="' . $product_id . '".*?</div>\s*</div>\s*<button#s', $html, $m ) ) {
		echo preg_replace( '/\n\s*\n/', "\n", $m[0] ) . "\n\n";
	} else {
		echo substr( $html, 0, 1500 ) . "\n\n";
	}

	echo "=== 症狀 A：前台有沒有價格 ===\n";
	$has_price = (bool) preg_match( '/NT\$\s*[\d,]+/', $html );
	printf( "  價格字串出現: %s\n", $has_price ? 'YES (PASS)' : 'NO (FAIL)' );
	preg_match_all( '/NT\$\s*[\d,]+/', $html, $prices );
	printf( "  抓到的價格: %s\n", implode( ', ', $prices[0] ) );
	printf( "  加入購物車按鈕: %s\n", strpos( $html, '加入購物車' ) !== false ? 'YES' : 'NO' );

	echo "\n=== 自癒後的 power_shop_meta ===\n";
	$healed = json_decode( get_post_meta( $post_id, 'power_shop_meta', true ), true );
	echo wp_json_encode( $healed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";

	$entry = $healed[0] ?? [];
	printf( "\n  variations 已補回: %s\n", ! empty( $entry['variations'] ) ? 'YES (PASS)' : 'NO (FAIL)' );
	printf( "  自訂欄位 myCustomKey 保留 (R2): %s\n", ( $entry['myCustomKey'] ?? '' ) === 'must-survive' ? 'YES (PASS)' : 'NO (FAIL)' );

	$fake_sale = 0;
	foreach ( $entry['variations'] ?? [] as $v ) {
		$vp = wc_get_product( $v['variationId'] );
		if ( $vp && ! $vp->is_on_sale() && ! empty( $v['salesPrice'] ) ) {
			$fake_sale++;
		}
	}
	printf( "  假特價變體數 (R1，須為 0): %d %s\n", $fake_sale, 0 === $fake_sale ? '(PASS)' : '(FAIL)' );

	echo "\n=== 症狀 B：餵給 React 的 appData 有沒有變體 ===\n";
	$info = J7\PowerShopV2\Functions::get_products_info( $post_id );
	$p    = $info['products'][0] ?? [];
	printf( "  variations 筆數: %d %s\n", count( $p['variations'] ?? [] ), ! empty( $p['variations'] ) ? '(PASS)' : '(FAIL)' );
	printf( "  variation_attributes: %s %s\n", wp_json_encode( array_keys( $p['variation_attributes'] ?? [] ) ), ! empty( $p['variation_attributes'] ) ? '(PASS - 規格按鈕可渲染)' : '(FAIL - 按鈕永遠 disabled)' );
	foreach ( array_slice( $p['variations'] ?? [], 0, 4 ) as $i => $v ) {
		$vp = wc_get_product( $v['variation_id'] );
		printf(
			"    [%d] id=%d %-7s regularPrice=%-6s salesPrice=%-6s%s\n",
			$i,
			$v['variation_id'],
			$vp && $vp->is_on_sale() ? 'on-sale' : 'no-sale',
			var_export( $v['regularPrice'], true ),
			var_export( $v['salesPrice'], true ),
			( $vp && ! $vp->is_on_sale() && ! empty( $v['salesPrice'] ) ) ? '  <-- 假特價' : ''
		);
	}

	echo "\n=== 收斂性：再跑一次 shortcode 不得重複寫 DB ===\n";
	$before = get_post_meta( $post_id, 'power_shop_meta', true );
	do_shortcode( '[power_shop_products]' );
	$after = get_post_meta( $post_id, 'power_shop_meta', true );
	printf( "  meta 不變: %s\n", $before === $after ? 'YES (PASS)' : 'NO (FAIL)' );

} finally {
	if ( $post_id && ! is_wp_error( $post_id ) ) {
		wp_delete_post( $post_id, true );
		printf( "\n已刪除臨時頁 post_id=%d，站台資料復原\n", $post_id );
	}
}
