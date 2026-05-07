<?php
/**
 * Profit Shop 前台 template
 *
 * 由 ProfitShopRenderer::render_shop() 注入 $shop_for_template。
 * 走 theme get_header / get_footer，所以這裡只負責 main 內容區塊。
 *
 * 注意：本檔不可被直接訪問；只能透過 ProfitShopRenderer 載入。
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop $shop_for_template */
$shop = $shop_for_template;

$partner_name = '';
$partner_term = \get_term( $shop->partner_term_id, 'profit_partner' );
if ( $partner_term instanceof \WP_Term ) {
	$partner_name = $partner_term->name;
}

$is_draft_preview = ( 'publish' !== $shop->status() );
?>

<main class="ps-profit-shop-front" style="max-width:1200px;margin:32px auto;padding:0 16px;">

	<?php if ( $is_draft_preview ) : ?>
		<div style="background:#fff7e6;border:1px solid #ffd591;border-radius:6px;padding:12px 16px;margin-bottom:24px;color:#874d00;">
			<strong><?php echo \esc_html__( '草稿預覽', 'power_shop' ); ?></strong>
		<?php echo \esc_html__( '：此賣場尚未發佈，僅有編輯權限的使用者可見。', 'power_shop' ); ?>
		</div>
	<?php endif; ?>

	<header style="margin-bottom:32px;">
		<h1 style="font-size:2em;margin-bottom:8px;"><?php echo \esc_html( $shop->title ); ?></h1>
		<?php if ( '' !== $partner_name ) : ?>
			<p style="color:#666;">
			<?php
			echo \esc_html__( '由', 'power_shop' );
			echo ' <strong>' . \esc_html( $partner_name ) . '</strong> ';
			echo \esc_html__( '推廣', 'power_shop' );
			?>
			</p>
		<?php endif; ?>
	</header>

	<?php
	$items = $shop->items();

	if ( empty( $items ) ) :
		?>
		<p style="text-align:center;padding:60px 20px;color:#999;">
		<?php echo \esc_html__( '此賣場暫無商品', 'power_shop' ); ?>
		</p>
		<?php
	else :
		?>
		<div class="ps-shop-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:24px;">
		<?php
		foreach ( $items as $override_item ) :
			if ( ! function_exists( 'wc_get_product' ) ) {
				continue;
			}

			$product = \wc_get_product( $override_item->product_id );
			if ( ! $product ) {
				continue;
			}

			$product_type      = (string) $product->get_type();
			$is_simple         = ( 'simple' === $product_type );
			$product_image     = $product->get_image( 'medium' );
			$product_name      = (string) $product->get_name();
			$product_permalink = (string) \get_permalink( $override_item->product_id );

			// override.sale_price 優先；無則用 product 自身價格。
			$override   = $override_item->override;
			$has_sale   = ( null !== $override->sale_price );
			$sale_price = $has_sale ? (string) $override->sale_price : (string) $product->get_price();

			$has_regular   = ( null !== $override->regular_price );
			$regular_price = $has_regular ? (string) $override->regular_price : (string) $product->get_regular_price();

			$show_strike = (
				'' !== $regular_price
				&& '' !== $sale_price
				&& is_numeric( $regular_price )
				&& is_numeric( $sale_price )
				&& (float) $regular_price > (float) $sale_price
			);
			?>
				<article class="ps-shop-item" style="border:1px solid #eee;border-radius:8px;overflow:hidden;background:#fff;">
					<a href="<?php echo \esc_url( $product_permalink ); ?>">
			<?php
			// $product_image 由 WC_Product::get_image() 產出，已 escape 完整 <img> 標籤。
			echo $product_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
					</a>
					<div style="padding:16px;">
						<h3 style="font-size:1em;margin:0 0 8px;line-height:1.4;">
							<a href="<?php echo \esc_url( $product_permalink ); ?>" style="color:#333;text-decoration:none;">
			<?php echo \esc_html( $product_name ); ?>
							</a>
						</h3>
						<p style="margin:0 0 12px;">
			<?php if ( $show_strike ) : ?>
								<del style="color:#999;">
				<?php
				// wc_price() 已 escape。
				echo \wc_price( $regular_price ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
								</del>
								<ins style="color:#d9534f;font-weight:600;text-decoration:none;margin-left:6px;">
				<?php
				echo \wc_price( $sale_price ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
								</ins>
							<?php else : ?>
								<span style="font-weight:600;">
								<?php
								echo \wc_price( $sale_price ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
								</span>
							<?php endif; ?>
						</p>
			<?php if ( $is_simple ) : ?>
							<form method="get" action="<?php echo \esc_url( \home_url( '/' ) ); ?>" style="margin:0;">
								<input type="hidden" name="add-to-cart" value="<?php echo \esc_attr( (string) $override_item->product_id ); ?>">
								<input type="hidden" name="profit_shop_id" value="<?php echo \esc_attr( (string) $shop->id ); ?>">
								<button type="submit" style="display:block;width:100%;padding:10px;background:#1677ff;color:#fff;border:0;border-radius:4px;cursor:pointer;">
				<?php echo \esc_html__( '加入購物車', 'power_shop' ); ?>
								</button>
							</form>
						<?php else : ?>
							<a href="<?php echo \esc_url( \add_query_arg( 'profit_shop_id', (string) $shop->id, $product_permalink ) ); ?>" style="display:block;text-align:center;padding:10px;background:#fff;color:#1677ff;border:1px solid #1677ff;border-radius:4px;text-decoration:none;">
							<?php echo \esc_html__( '前往選購', 'power_shop' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</article>
			<?php
			endforeach;
		?>
		</div>
		<?php
	endif;
	?>

</main>
