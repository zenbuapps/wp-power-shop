<?php
use J7\PowerShopV2\Bootstrap;
use J7\PowerShopV2\Functions;

[
	'product'           => $product,
	'meta'              => $meta,
	'default_image_src' => $default_image_src,
	'is_shop_closed'    => $is_shop_closed,
] = $args;

$feature_image_id = $product->get_image_id();
$img_src          = \wp_get_attachment_url( $feature_image_id ) ?: $default_image_src;

$name = $product->get_name();

$product_id = $meta['productId'] ?? '';
$variations = $meta['variations'] ?? [];

// 保險：快取的 power_shop_meta 缺變體資料時（issue #76），直接現查 WooCommerce 變體，避免價格區塊整塊空白
if (empty($variations)) {
	$variations = Functions::format_variations($product);
}

$price_arr         = [];
$regular_price_arr = [];

foreach ($variations as $variation) {
	// 部分寫入的變體可能缺價格欄位，補預設值避免 undefined array key 與價格算錯
	$sales_price   = (int) ( $variation['salesPrice'] ?? 0 );
	$regular_price = (int) ( $variation['regularPrice'] ?? 0 );

	if (empty($sales_price)) {
		$price_arr[] = $regular_price;
	} else {
		$price_arr[] = $sales_price;
	}
	$regular_price_arr[] = $regular_price;
}

$filtered_price_arr = array_filter(
	$price_arr,
	function ( $price ) {
		return !empty($price);
	}
	);

$max               = empty($filtered_price_arr) ? '' : max($filtered_price_arr);
$min               = empty($filtered_price_arr) ? '' : min($filtered_price_arr);
$max_regular_price = empty($regular_price_arr) ? '' : max($regular_price_arr);

$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
if (strpos($request_uri, Bootstrap::KEBAB) === false) {
	// 如果不包含 Bootstrap::KEBAB 字串，有可能在其他編輯器預覽，則不加入 ps-not-ready class
	$class = '';
} else {
	// 如果包含 Bootstrap::KEBAB 字串，則加入 ps-not-ready class
	$class = 'ps-not-ready';
}

?>
<div data-ps-product-id="<?php echo $product_id; ?>" class="group relative pb-12 <?php echo $is_shop_closed ? 'pointer-events-none' : ''; ?> <?php echo $class; ?>">
	<div class="w-full aspect-square overflow-hidden">
		<img src="<?php echo $img_src; ?>" class="group-hover:scale-125 duration-300 w-full aspect-square object-cover" alt="<?php echo $name; ?>">
	</div>
	<div class="mt-2">
		<?php echo $name; ?>
	</div>
	<div>
		<?php if ($max === $min && !empty($min)) : ?>
			<?php if ($max === $max_regular_price) : ?>
				<p class="mb-0 mt-1">NT$ <?php echo $min; ?></p>
			<?php else : ?>
				<p class="mb-0 mt-1"><del>NT$ <?php echo $max_regular_price; ?></del></p>
				<p class="mb-0 mt-1 text-red-500">NT$ <?php echo $min; ?></p>
			<?php endif; ?>
		<?php else : ?>
			<?php if (!empty($max_regular_price)) : ?>
				<p class="mb-0 mt-1"><del>NT$ <?php echo $max_regular_price; ?></del></p>
			<?php endif; ?>
			<?php if (!empty($max)) : ?>
				<p class="mb-0 mt-1 text-red-500">NT$ <?php echo $min; ?> – NT$ <?php echo $max; ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<button type="button" <?php echo $is_shop_closed ? 'disabled' : ''; ?> class="ps-btn ps-btn-primary w-full absolute bottom-0"><span><?php echo $is_shop_closed ? '商店已關閉' : '加入購物車'; ?></span></button>
</div>
