<?php
/**
 * 賣場商品卡片
 *
 * 由 templates/profit-shop-front.php 在 foreach 內 require，變數由外層 scope 提供。
 *
 * 版型與 Phase 10-12 的 React 版本**逐項對應**（`group relative pb-12` 外框、
 * `aspect-square overflow-hidden` 圖框、`group-hover:scale-125 duration-300` 放大、
 * `mt-2` 名稱、劃線原價 + 紅字特價、`absolute bottom-0 w-full` 底部按鈕），
 * 只把顏色從硬編碼換成 daisyUI 語意色：
 *
 * | 舊 | 新 | 原因 |
 * |---|---|---|
 * | `.ps-btn .ps-btn-primary`（自訂 CSS + `--ps-primary`） | `pc-btn pc-btn-primary pc-btn-sm` | 顏色與尺寸都跟隨 daisyUI theme；`pc-btn-sm` 高 32px 與舊版一致 |
 * | `text-red-500` | `text-error` | 深色 theme 下 `red-500` 對比不足 |
 * | `style="background:#f0f0f0"` | `bg-base-200` | 無圖佔位跟隨 theme |
 * | `style="color:#999;font-size:0.85em"` | `text-sm text-base-content/60` | 同上 |
 *
 * ## 漸進增強：卡片只印一個動作元素
 *
 * 這裡印出的 form / 連結**在沒有 JavaScript 時就是完整可用的加購路徑**
 * （與 Phase 4-C1 的原始行為相同，`AddToCartHook` 的三道閘門照常驗證）。
 * JS 載入後在卡片層攔截 click 並 `preventDefault()`，改開 Modal，同時把按鈕文字
 * 換成 `data-ps-js-label`。因此不需要舊版那組「印兩顆按鈕、再用 `<noscript>` CSS
 * 藏掉其中一顆」的做法，也就不再需要任何 inline `<style>`。
 *
 * @var array<string, mixed> $ps_product       單一商品資料（ProfitShopFrontData::build_products）
 * @var int                  $ps_shop_id       賣場 post ID
 * @var bool                 $is_shop_open     賣場是否在販售期間內
 * @var string               $closed_label     非販售期間的按鈕文案
 * @var bool                 $show_buyer_count 是否顯示已售件數
 */

declare(strict_types=1);

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontView;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$card_id        = (int) $ps_product['id'];
$card_type      = (string) $ps_product['type'];
$card_name      = (string) $ps_product['name'];
$card_permalink = (string) $ps_product['permalink'];
$card_image     = isset( $ps_product['images'][0] ) ? (string) $ps_product['images'][0] : '';

// 已售件數 = WC 真實銷量 + 賣場設定的灌水銷量。
$card_sold = (int) $ps_product['total_sales'] + (int) $ps_product['extraBuyerCount'];

// 非 simple 的商品（variable / grouped / external）沒有單一固定價，無 JS 時導去單品頁選購。
$card_is_simple = ( 'simple' === $card_type );

$card_js_label = \__( '加入購物車', 'power_shop' );
$card_nojs_label = $card_is_simple
? \__( '加入購物車', 'power_shop' )
: \__( '前往選購', 'power_shop' );

$card_action_url = \add_query_arg( 'profit_shop_id', (string) $ps_shop_id, $card_permalink );
?>
<div
	data-ps-product-id="<?php echo \esc_attr( (string) $card_id ); ?>"
	data-ps-disabled="<?php echo $is_shop_open ? '0' : '1'; ?>"
	class="group relative pb-12<?php echo $is_shop_open ? ' cursor-pointer' : ' pointer-events-none opacity-60'; ?>"
>
	<div class="w-full aspect-square overflow-hidden">
		<?php if ( '' !== $card_image ) : ?>
			<img
				src="<?php echo \esc_url( $card_image ); ?>"
				class="group-hover:scale-125 duration-300 w-full aspect-square object-cover"
				alt="<?php echo \esc_attr( $card_name ); ?>"
				loading="lazy"
			>
		<?php else : ?>
			<div class="w-full aspect-square bg-base-200"></div>
		<?php endif; ?>
	</div>

	<div class="mt-2"><?php echo \esc_html( $card_name ); ?></div>

	<div>
		<?php
		if ( 'variable' === $card_type ) :
			/*
			* 變體商品：價格區間。
			* 顯示規則照搬 legacy：單一價 → 一行；有折扣 → 劃線原價 + 紅字實售；
			* 區間 → 劃線最高原價 + 紅字 min – max。
			*/
			$range       = ProfitShopFrontView::price_range( $ps_product['variations'] ?? [] );
			$range_min   = $range['min'];
			$range_max   = $range['max'];
			$max_regular = $range['max_regular'];

			if ( null !== $range_min && $range_min === $range_max ) :
				if ( $range_min === $max_regular ) :
					?>
					<p class="mb-0 mt-1"><?php echo \esc_html( ProfitShopFrontView::currency( $range_min ) ); ?></p>
					<?php else : ?>
					<p class="mb-0 mt-1"><del><?php echo \esc_html( ProfitShopFrontView::currency( $max_regular ) ); ?></del></p>
					<p class="mb-0 mt-1 text-error"><?php echo \esc_html( ProfitShopFrontView::currency( $range_min ) ); ?></p>
						<?php
				endif;
				else :
					if ( null !== $max_regular ) :
						?>
					<p class="mb-0 mt-1"><del><?php echo \esc_html( ProfitShopFrontView::currency( $max_regular ) ); ?></del></p>
						<?php
					endif;
					if ( null !== $range_max ) :
						?>
					<p class="mb-0 mt-1 text-error">
						<?php
						echo \esc_html(
							\sprintf(
								/* translators: 1: 最低價 2: 最高價 */
								'%1$s – %2$s',
								ProfitShopFrontView::currency( $range_min ),
								ProfitShopFrontView::currency( $range_max )
							)
						);
						?>
					</p>
						<?php
					endif;
				endif;
				else :
					/*
					* 簡單商品（與其他非變體類型）：有特價 → 劃線原價 + 紅字特價；無特價 → 單行原價。
					* 價格已由 build_products() 套用賣場覆寫。
					*/
					$card_regular = (string) $ps_product['regularPrice'];
					$card_sale    = (string) $ps_product['salesPrice'];

					if ( '' !== $card_sale ) :
						?>
					<p class="mb-0 mt-1"><del><?php echo \esc_html( ProfitShopFrontView::currency( $card_regular ) ); ?></del></p>
					<p class="mb-0 mt-1 text-error"><?php echo \esc_html( ProfitShopFrontView::currency( $card_sale ) ); ?></p>
						<?php else : ?>
					<p class="mb-0 mt-1"><?php echo \esc_html( ProfitShopFrontView::currency( $card_regular ) ); ?></p>
							<?php
					endif;
				endif;
				?>
	</div>

	<?php if ( $show_buyer_count && $card_sold > 0 ) : ?>
		<p class="mb-0 mt-1 text-sm text-base-content/60">
		<?php
		echo \esc_html(
				\sprintf(
					/* translators: %s: 已售出的件數 */
					\__( '已售 %s 件', 'power_shop' ),
					\number_format_i18n( $card_sold )
				)
			);
		?>
		</p>
	<?php endif; ?>

	<?php if ( ! $is_shop_open ) : ?>
		<button type="button" disabled class="pc-btn pc-btn-primary pc-btn-sm w-full absolute bottom-0">
			<span><?php echo \esc_html( $closed_label ); ?></span>
		</button>
	<?php elseif ( $card_is_simple ) : ?>
		<?php // 無 JS 時這張表單就是完整的加購路徑；有 JS 時卡片的 click handler 會 preventDefault 改開 Modal。 ?>
		<form class="absolute bottom-0 w-full m-0" method="get" action="<?php echo \esc_url( \home_url( '/' ) ); ?>">
			<input type="hidden" name="add-to-cart" value="<?php echo \esc_attr( (string) $card_id ); ?>">
			<input type="hidden" name="profit_shop_id" value="<?php echo \esc_attr( (string) $ps_shop_id ); ?>">
			<button
				type="submit"
				class="pc-btn pc-btn-primary pc-btn-sm w-full"
				data-ps-js-label="<?php echo \esc_attr( $card_js_label ); ?>"
			>
				<span><?php echo \esc_html( $card_nojs_label ); ?></span>
			</button>
		</form>
	<?php else : ?>
		<a
			href="<?php echo \esc_url( $card_action_url ); ?>"
			class="pc-btn pc-btn-primary pc-btn-sm w-full absolute bottom-0"
			data-ps-js-label="<?php echo \esc_attr( $card_js_label ); ?>"
		>
			<span><?php echo \esc_html( $card_nojs_label ); ?></span>
		</a>
	<?php endif; ?>
</div>
