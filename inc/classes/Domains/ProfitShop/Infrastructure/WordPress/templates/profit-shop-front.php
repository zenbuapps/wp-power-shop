<?php
/**
 * Profit Shop 前台 template
 *
 * 由 ProfitShopRenderer::render_shop() 注入 $shop_for_template 與 $front_bundle_ready。
 * 走 theme get_header / get_footer，所以這裡只負責 main 內容區塊。
 *
 * Phase 10：版型照搬 legacy 一頁商店（legacy/inc/classes/ShortCode.php +
 * legacy/inc/templates/single-product/{simple,variable}.php）。
 *
 * 架構：PHP 印出商品 grid（SEO 內容留在 HTML 內），React bundle 只掛「商品 Modal」
 * 這一層互動。卡片上的 data-ps-product-id / ps-btn / ps-not-ready 是 React 的
 * DOM 契約，改動 class 或 attribute 會讓 Modal 開不起來（見 .claude/rules/profit-shop.rule.md）。
 *
 * Phase 12：移除前台購物車表格。Modal 的「加入購物車」改為建立一張隱藏表單 POST
 * 回本頁，交給 WooCommerce 原生的 `WC_Form_Handler::add_to_cart_action()` 處理；
 * 購物車與結帳一律由 WooCommerce 自己的頁面負責。加購結果由下方的
 * `wc_print_notices()` 呈現。
 *
 * $front_bundle_ready 為 false 時（Vite manifest 缺 entry）會退回不依賴 JS 的
 * 表單加購版本，避免整頁只剩無法點擊的灰色卡片。
 *
 * 也由 ProfitShopShortcode::render_template() 載入（額外注入 $is_shortcode_context = true），
 * 此時外層標籤改用 <div>——見下方 $wrapper_tag 的說明。
 *
 * 注意：本檔不可被直接訪問；只能透過 ProfitShopRenderer / ProfitShopShortcode 載入。
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop $shop_for_template */
$shop = $shop_for_template;

/** @var bool $front_bundle_ready React bundle 是否可用（Vite manifest 命中） */
$bundle_ready = isset( $front_bundle_ready ) ? (bool) $front_bundle_ready : false;

/** @var array<string, mixed> $front_settings 前台顯示設定（ProfitShopFrontData::settings） */
$settings = isset( $front_settings ) && \is_array( $front_settings ) ? $front_settings : [];

$color_primary = isset( $settings['colorPrimary'] ) && \is_string( $settings['colorPrimary'] )
? $settings['colorPrimary']
: \J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontData::DEFAULT_COLOR_PRIMARY;

$shop_status = isset( $settings['shopStatus'] ) && \is_string( $settings['shopStatus'] )
? $settings['shopStatus']
: 'published';

// 非 published（未開賣 / 已結束）時，卡片停用點擊，按鈕改顯示狀態文案（照搬 legacy）。
$is_shop_closed = ( 'published' !== $shop_status );
$closed_label   = ( 'coming' === $shop_status )
? \__( '尚未開始販售', 'power_shop' )
: \__( '商店已關閉', 'power_shop' );

$partner_name = '';
$partner_term = \get_term( $shop->partner_term_id, 'profit_partner' );
if ( $partner_term instanceof \WP_Term ) {
	$partner_name = $partner_term->name;
}

$is_draft_preview = ( 'publish' !== $shop->status() );

// 卡片「已售 N 件」是否顯示：與 React Modal 的「已有 N 人購買」共用同一個
// settings 開關（都是購買人數的呈現），關閉時兩處一起消失，避免站長關了設定
// 卻發現卡片還在印。`ProfitShopFrontData::settings()` 已正規化為真正的 bool，
// 缺值時的預設同樣落在那裡（legacy 預設全部顯示）。
$show_buyer_count = ! isset( $settings['showBuyerCount'] ) || (bool) $settings['showBuyerCount'];

// 外層標籤：獨立頁面用 <main>，shortcode 情境改用 <div>。
// 理由：shortcode 嵌在宿主頁面內容裡，宿主頁面已經有自己的 <main>，
// 而 HTML spec 規定一份文件內只能有一個非 hidden 的 main，巢狀 <main> 是無效 HTML。
// $is_shortcode_context 未定義時（ProfitShopRenderer 的獨立頁面路徑）預設為 false，
// 維持 <main> 行為 100% 不變。
$is_shortcode_context = isset( $is_shortcode_context ) && true === $is_shortcode_context;
$wrapper_tag          = $is_shortcode_context ? 'div' : 'main';

/**
 * 幣別字串格式化
 *
 * 刻意與前台 React 的 `getCurrencyString()`（照搬 legacy）保持一致：
 * 前綴符號 + 空格 + 原始數字，不加千分位，卡片與 Modal 才不會出現
 * 「NT$ 8,000 / NT$ 8000」兩種寫法。
 *
 * @param string|int|float|null $price 價格。
 *
 * @return string
 */
$ps_currency = static function ( $price ): string {
	return 'NT$ ' . (string) $price;
};

/**
 * 數字轉顯示字串（去掉尾隨的無意義零）
 *
 * `wc_format_decimal( $v, '' )` 會把 `8000.0` 變 `'8000'`、`99.50` 變 `'99.5'`，
 * 讓整數價的輸出與 legacy 的 `(int)` 完全一致，同時不再截斷小數。
 *
 * @param float|int|string $value 數值。
 *
 * @return string
 */
$ps_price_number = static function ( $value ): string {
	if ( \function_exists( 'wc_format_decimal' ) ) {
		return (string) \wc_format_decimal( $value, '' );
	}

	return (string) $value;
};

/**
 * 商品主圖 URL（無圖時回空字串，交由呼叫端決定 placeholder）
 *
 * @param \WC_Product $product WooCommerce 商品。
 *
 * @return string
 */
$ps_image_url = static function ( \WC_Product $product ): string {
	$image_id = (int) $product->get_image_id();
	if ( $image_id <= 0 ) {
		return '';
	}
	$url = \wp_get_attachment_url( $image_id );

	return \is_string( $url ) ? $url : '';
};
?>

<?php
/*
* 這段 inline style 刻意**不放進 bundle 的 CSS**：
* - `--ps-primary` 是逐店設定值，只能由 PHP 印出
* - `.ps-nojs-fallback` 的隱藏規則必須「即使 bundle CSS 沒載入也生效」，
*   否則 JS 正常但 CSS 失敗時會同時看到兩顆加購按鈕
*/
?>
<style>
	:root { --ps-primary: <?php echo \esc_html( $color_primary ); ?>; }
	.power-shop-products .ps-nojs-fallback { display: none; }
</style>
<noscript>
	<style>
		/*
		 * JavaScript 停用：Modal 開不起來，改走不依賴 JS 的降級加購路徑。
		 * 同時取消 `ps-not-ready` 的灰階與等待游標——那個狀態是給「JS 即將接手」
		 * 用的，JS 不會來的情況下不該讓整排商品永久看起來壞掉。
		 */
		.ps-not-ready { cursor: auto !important; filter: none !important; }
		.power-shop-products div[data-ps-product-id] { cursor: auto !important; }
		.power-shop-products .ps-nojs-fallback { display: block !important; }
		.power-shop-products .ps-modal-trigger { display: none !important; }
	</style>
</noscript>

<<?php echo \esc_html( $wrapper_tag ); ?> class="ps-profit-shop-front" style="max-width:1200px;margin:32px auto;padding:0 16px;">

	<?php if ( $is_draft_preview ) : ?>
		<div style="background:#fff7e6;border:1px solid #ffd591;border-radius:6px;padding:12px 16px;margin-bottom:24px;color:#874d00;">
			<strong><?php echo \esc_html__( '草稿預覽', 'power_shop' ); ?></strong>
		<?php echo \esc_html__( '：此賣場尚未發佈，僅有編輯權限的使用者可見。', 'power_shop' ); ?>
		</div>
	<?php endif; ?>

	<?php
	/*
	* WooCommerce 通知（「XXX 已加入購物車」/ 庫存不足 / 請選擇商品屬性…）。
	*
	* 加入購物車走 WooCommerce 原生表單送出（React Modal 內的按鈕會建一張隱藏
	* form POST 回本頁，`<noscript>` 降級路徑則是 GET query string），由
	* `WC_Form_Handler::add_to_cart_action()` 處理後把結果寫進 WC session 的
	* notices，再 redirect 回來。
	*
	* 這一段**必須**由本 template 自己印：賣場頁不是 WooCommerce 的 template
	* （`is_woocommerce()` 為 false），佈景主題掛在 `woocommerce_before_main_content`
	* 上的 `woocommerce_output_all_notices` 不會被觸發。少了它，使用者按下加入
	* 購物車之後畫面除了重新載入之外**沒有任何變化**，功能看起來像是壞掉的。
	*
	* `wc_print_notices()` 印完會清空 notices，所以就算佈景主題在別處也印了一份，
	* 也不會重複顯示，只是先後順序不同。
	*/
	if ( \function_exists( 'wc_print_notices' ) && \function_exists( 'WC' ) && null !== \WC()->session ) :
		?>
		<div class="woocommerce ps-shop-notices">
		<?php \wc_print_notices(); ?>
		</div>
		<?php
	endif;
	?>

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
	/*
	* 站長自訂內容區。
	*
	* powershop CPT 有 `supports: editor`（CptRegistrar.php:94），與 legacy 的
	* power-shop CPT 一致——legacy 賣場頁的標題／描述／banner 全靠站長在編輯器
	* 排版，plugin 只提供商品 grid 的 shortcode。新版是自訂 renderer 而非
	* theme single.php，若不主動印出 post_content，站長寫的文案會整個消失。
	*
	* 走 `the_content` filter 以支援 Gutenberg 區塊與 shortcode（與 legacy
	* 相同的 filter 鏈）；輸出刻意不 escape，這是 WP 對編輯器內容的標準處理，
	* 內容在儲存時已由 kses 過濾，且只有具 edit 權限者能寫入。
	* Elementor 版型不在此路徑生效（見 specs/tech-debt.md）。
	*/
	$shop_content = (string) \get_post_field( 'post_content', $shop->id );
	if ( '' !== \trim( $shop_content ) ) :
		?>
		<div class="ps-shop-content entry-content" style="margin-bottom:32px;">
		<?php echo \apply_filters( 'the_content', $shop_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
	endif;

	$items = $shop->items();

	if ( empty( $items ) ) :
		?>
		<p style="text-align:center;padding:60px 20px;color:#999;">
		<?php echo \esc_html__( '此賣場暫無商品', 'power_shop' ); ?>
		</p>
		<?php
	elseif ( ! \function_exists( 'wc_get_product' ) ) :
		// WooCommerce 未啟用：理論上 Bootstrap 已驗證 WC 為必要相依，這裡只做 defensive guard。
		?>
		<p style="text-align:center;padding:60px 20px;color:#999;">
		<?php echo \esc_html__( '商品列表暫時無法顯示', 'power_shop' ); ?>
		</p>
		<?php
	else :
		?>
		<div class="power-shop-products">
			<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-8">
		<?php
		foreach ( $items as $override_item ) :
			$product = \wc_get_product( $override_item->product_id );
			if ( ! $product ) {
				continue;
			}

			// 與 legacy 一致：非 publish 的商品不出現在賣場。
			if ( 'publish' !== $product->get_status() ) {
				continue;
			}

			$product_type      = (string) $product->get_type();
			$product_name      = (string) $product->get_name();
			$product_permalink = (string) \get_permalink( $override_item->product_id );
			$image_url         = $ps_image_url( $product );

			$override = $override_item->override;

			// 已售件數 = WC 真實銷量 + 賣場設定的灌水銷量。
			// 這是商品層 inflated_count 唯一的前台消費點：在此之前該欄位「存得進去、
			// 讀得出來、但沒有任何地方使用」，屬於 profit-shop.rule.md §7 警告的反模式。
			// 註：變體層的 inflated_count 目前仍無顯示位置（賣場前台只渲染父商品卡片，
			// 變體選擇發生在 WC 單品頁），已登記 specs/tech-debt.md TD-15。
			$sold_count = (int) $product->get_total_sales() + $override_item->inflated_count->value();
			?>
				<div
					data-ps-product-id="<?php echo \esc_attr( (string) $override_item->product_id ); ?>"
					class="group relative pb-12<?php echo $is_shop_closed ? ' pointer-events-none' : ''; ?><?php echo $bundle_ready ? ' ps-not-ready' : ''; ?>"
				>
					<div class="w-full aspect-square overflow-hidden">
			<?php if ( '' !== $image_url ) : ?>
							<img
								src="<?php echo \esc_url( $image_url ); ?>"
								class="group-hover:scale-125 duration-300 w-full aspect-square object-cover"
								alt="<?php echo \esc_attr( $product_name ); ?>"
								loading="lazy"
							>
						<?php else : ?>
							<div class="w-full aspect-square" style="background:#f0f0f0;"></div>
						<?php endif; ?>
					</div>
					<div class="mt-2">
			<?php echo \esc_html( $product_name ); ?>
					</div>
					<div>
			<?php
			if ( 'variable' === $product_type ) :
				/*
				* 變體商品：照搬 legacy variable.php 的價格區間邏輯。
				* price_arr         = 每個變體的實售價（有特價用特價，否則原價）
				* regular_price_arr = 每個變體的原價
				* 顯示規則：單一價 → 一行；有折扣 → del 原價 + 紅字實售；區間 → del 最高原價 + 紅字 min – max
				*/
				$price_arr         = [];
				$regular_price_arr = [];

				if ( $product instanceof \WC_Product_Variable ) {
					foreach ( $product->get_children() as $variation_id ) {
						$variation_id      = (int) $variation_id;
						$variation_product = \wc_get_product( $variation_id );
						if ( ! $variation_product ) {
							continue;
						}

						$variation_override = $override_item->get_variation_override( $variation_id );

						$v_regular = ( null !== $variation_override && null !== $variation_override->regular_price )
						? $variation_override->regular_price
						: (string) $variation_product->get_regular_price();

						$v_sale = ( null !== $variation_override && null !== $variation_override->sale_price )
						? $variation_override->sale_price
						: (string) $variation_product->get_sale_price();

						/*
						* 用 float 而非 legacy 的 `(int)`：legacy 會把 99.5 截成 99，
						* 是實際的顯示錯誤而非風格差異。整數價經 wc_format_decimal
						* 後輸出完全相同（8000 → '8000'），所以修掉它不會造成任何視覺回歸。
						*/
						$price_arr[]         = ( '' === $v_sale || 0.0 === (float) $v_sale ) ? (float) $v_regular : (float) $v_sale;
						$regular_price_arr[] = (float) $v_regular;
					}
				}

				$filtered_price_arr = array_filter(
					$price_arr,
					static function ( $price ) {
						return ! empty( $price );
					}
				);

				$max               = empty( $filtered_price_arr ) ? '' : max( $filtered_price_arr );
				$min               = empty( $filtered_price_arr ) ? '' : min( $filtered_price_arr );
				$max_regular_price = empty( $regular_price_arr ) ? '' : max( $regular_price_arr );

				if ( $max === $min && ! empty( $min ) ) :
					if ( $max === $max_regular_price ) :
						?>
									<p class="mb-0 mt-1"><?php echo \esc_html( $ps_currency( $ps_price_number( $min ) ) ); ?></p>
									<?php else : ?>
									<p class="mb-0 mt-1"><del><?php echo \esc_html( $ps_currency( $ps_price_number( $max_regular_price ) ) ); ?></del></p>
									<p class="mb-0 mt-1 text-red-500"><?php echo \esc_html( $ps_currency( $ps_price_number( $min ) ) ); ?></p>
										<?php
					endif;
					else :
						if ( ! empty( $max_regular_price ) ) :
							?>
									<p class="mb-0 mt-1"><del><?php echo \esc_html( $ps_currency( $ps_price_number( $max_regular_price ) ) ); ?></del></p>
							<?php
						endif;
						if ( ! empty( $max ) ) :
							?>
									<p class="mb-0 mt-1 text-red-500">
							<?php
							echo \esc_html(
									\sprintf(
										/* translators: 1: 最低價 2: 最高價 */
										'%1$s – %2$s',
										$ps_currency( $ps_price_number( $min ) ),
										$ps_currency( $ps_price_number( $max ) )
									)
								);
							?>
									</p>
							<?php
						endif;
					endif;
					else :
						/*
						* 簡單商品（與其他非變體類型）：照搬 legacy simple.php。
						* 有特價 → del 原價 + 紅字特價；無特價 → 單行原價。
						*/
						$regular_price = ( null !== $override->regular_price )
						? $override->regular_price
						: (string) $product->get_regular_price();

						$sale_price = ( null !== $override->sale_price )
						? $override->sale_price
						: (string) $product->get_sale_price();

						if ( ! empty( $sale_price ) ) :
							?>
								<p class="mb-0 mt-1"><del><?php echo \esc_html( $ps_currency( $regular_price ) ); ?></del></p>
								<p class="mb-0 mt-1 text-red-500"><?php echo \esc_html( $ps_currency( $sale_price ) ); ?></p>
								<?php else : ?>
								<p class="mb-0 mt-1"><?php echo \esc_html( $ps_currency( $regular_price ) ); ?></p>
									<?php
						endif;
					endif;
					?>
					</div>

			<?php if ( $show_buyer_count && $sold_count > 0 ) : ?>
					<p class="mb-0 mt-1" style="color:#999;font-size:0.85em;">
				<?php
				echo \esc_html(
					\sprintf(
						/* translators: %s: 已售出的件數 */
						\__( '已售 %s 件', 'power_shop' ),
						\number_format_i18n( $sold_count )
					)
				);
				?>
					</p>
			<?php endif; ?>

			<?php if ( $is_shop_closed ) : ?>
				<?php // 未開賣 / 已結束：按鈕停用並顯示狀態文案（照搬 legacy）。 ?>
						<button type="button" disabled class="ps-btn ps-btn-primary w-full absolute bottom-0">
							<span><?php echo \esc_html( $closed_label ); ?></span>
						</button>
					<?php elseif ( $bundle_ready ) : ?>
						<?php // React 攔整張卡片的 click 開 Modal；這顆按鈕是視覺入口。 ?>
						<button type="button" class="ps-modal-trigger ps-btn ps-btn-primary w-full absolute bottom-0">
							<span><?php echo \esc_html__( '加入購物車', 'power_shop' ); ?></span>
						</button>
						<?php
						/*
						* JS 停用時的降級路徑（由頁首 <noscript> 的 CSS 顯示，
						* 並同時隱藏上面那顆 Modal 觸發鈕）。
						* 走與 Phase 4-C1 相同的 query string 加購，AddToCartHook
						* 的三道閘門一樣會驗證，不會產生未授權的分潤價。
						*/
						?>
						<?php if ( 'simple' === $product_type ) : ?>
							<form class="ps-nojs-fallback absolute bottom-0 w-full" method="get" action="<?php echo \esc_url( \home_url( '/' ) ); ?>" style="margin:0;">
								<input type="hidden" name="add-to-cart" value="<?php echo \esc_attr( (string) $override_item->product_id ); ?>">
								<input type="hidden" name="profit_shop_id" value="<?php echo \esc_attr( (string) $shop->id ); ?>">
								<button type="submit" class="ps-btn ps-btn-primary w-full">
									<span><?php echo \esc_html__( '加入購物車', 'power_shop' ); ?></span>
								</button>
							</form>
						<?php else : ?>
							<a
								class="ps-nojs-fallback ps-btn ps-btn-primary w-full absolute bottom-0"
								href="<?php echo \esc_url( \add_query_arg( 'profit_shop_id', (string) $shop->id, $product_permalink ) ); ?>"
							>
								<span><?php echo \esc_html__( '前往選購', 'power_shop' ); ?></span>
							</a>
						<?php endif; ?>
					<?php elseif ( 'simple' === $product_type ) : ?>
						<?php // 無 JS fallback：維持 Phase 4-C1 的表單加購，query string 交給 AddToCartHook。 ?>
						<form method="get" action="<?php echo \esc_url( \home_url( '/' ) ); ?>" class="absolute bottom-0 w-full" style="margin:0;">
							<input type="hidden" name="add-to-cart" value="<?php echo \esc_attr( (string) $override_item->product_id ); ?>">
							<input type="hidden" name="profit_shop_id" value="<?php echo \esc_attr( (string) $shop->id ); ?>">
							<button type="submit" class="ps-btn ps-btn-primary w-full">
								<span><?php echo \esc_html__( '加入購物車', 'power_shop' ); ?></span>
							</button>
						</form>
					<?php else : ?>
						<a
							href="<?php echo \esc_url( \add_query_arg( 'profit_shop_id', (string) $shop->id, $product_permalink ) ); ?>"
							class="ps-btn ps-btn-primary w-full absolute bottom-0"
						>
							<span><?php echo \esc_html__( '前往選購', 'power_shop' ); ?></span>
						</a>
					<?php endif; ?>
				</div>
			<?php
			endforeach;
		?>
			</div>
		</div>

		<?php if ( $bundle_ready ) : ?>
			<?php // React mount 點：商品 Modal 掛在這裡（對應 legacy RENDER_ID_3）。 ?>
			<div id="profit_shop_front_app"></div>
		<?php endif; ?>
		<?php
	endif;
	?>

</<?php echo \esc_html( $wrapper_tag ); ?>>
