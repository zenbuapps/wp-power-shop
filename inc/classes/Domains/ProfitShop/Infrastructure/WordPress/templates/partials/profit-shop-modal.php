<?php
/**
 * 賣場商品 Modal
 *
 * 由 templates/profit-shop-front.php 在 foreach 內 require，變數由外層 scope 提供。
 * 每個商品一個 `<dialog class="pc-modal">`，全部由 PHP 印出。
 *
 * ## 版型對應（與 Phase 10-12 的 antd Modal 逐項對照）
 *
 * | antd | daisyUI / Tailwind | 說明 |
 * |---|---|---|
 * | `<Modal className="lg:w-1/2 lg:max-w-[960px]" centered footer={null}>` | `pc-modal pc-modal-middle` + `pc-modal-box w-11/12 max-w-[520px] lg:w-1/2 lg:max-w-[960px]` | antd Modal 預設寬 520px |
 * | `zIndex: 1000000`（壓過佈景主題 sticky header） | 不需要 | 原生 `<dialog>` 開啟時進入 top layer，天然蓋過所有元素 |
 * | `<Row gutter={24} className="max-h-[75vh] overflow-y-auto overflow-x-hidden">` | `grid gap-6 max-h-[75vh] overflow-y-auto overflow-x-hidden` | antd gutter 24 = 24px = `gap-6` |
 * | `<Col span={24} lg={{span:10}}>` / `lg={{span:14}}` | `lg:col-span-5` / `lg:col-span-7`（`lg:grid-cols-12`） | 10/24 = 5/12、14/24 = 7/12，比例完全相同 |
 * | `<Button type="primary">` | `pc-btn pc-btn-primary` | 顏色跟隨 daisyUI theme |
 * | `text-gray-500` / `text-red-500` / `text-green-500` / `text-orange-500` / `border-blue-500` / `bg-white` | `text-base-content/60` / `text-error` / `text-success` / `text-warning` / `border-primary` / `bg-base-100` | 深色 theme 下硬編碼色會對比不足 |
 *
 * `pc-modal-box` 刻意加 `overflow-visible`：daisyUI 預設 `overflow-y:auto`，會把
 * 右側那支 `md:-right-8`（外移到 Modal 之外）的展開把手裁掉。捲動改由內層的
 * `max-h-[75vh] overflow-y-auto` 負責，與 antd 把捲動放在 `<Row>` 上是同一個做法。
 *
 * ## 加入購物車
 *
 * 這裡是一張真正的 `<form method="post">`（不寫 `action`，瀏覽器會送回當前網址），
 * 交給 WooCommerce 原生的 `WC_Form_Handler::add_to_cart_action()` 處理。
 * `variation_id` 與 `attribute_*` 由 `inc/assets/js/profit-shop-front.js` 在使用者選定
 * 變體時填入；`profit_shop_id` 由 PHP 直接印死——`AddToCartHook`（priority 5）只從
 * `$_GET` / `$_POST` 讀它，漏送會**靜默走商品原價、分潤失效且沒有任何錯誤訊息**。
 *
 * ⚠ `<form method="dialog">`（關閉鈕 / backdrop）與加購表單必須是**兄弟**而非巢狀——
 * HTML 不允許巢狀 form，巢狀時內層會被瀏覽器丟棄，加購鈕會變成關閉 Modal。
 *
 * @var array<string, mixed> $ps_product       單一商品資料（ProfitShopFrontData::build_products）
 * @var int                  $ps_shop_id       賣場 post ID
 * @var bool                 $is_shop_open     賣場是否在販售期間內
 * @var string               $closed_label     非販售期間的按鈕文案
 * @var bool                 $show_buyer_count 是否顯示購買人數
 * @var bool                 $show_stock       是否顯示庫存資訊
 */

declare(strict_types=1);

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontView;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$m_id     = (int) $ps_product['id'];
$m_type   = (string) $ps_product['type'];
$m_name   = (string) $ps_product['name'];
$m_images = \is_array( $ps_product['images'] ) ? $ps_product['images'] : [];
$m_stock  = \is_array( $ps_product['stock'] ) ? $ps_product['stock'] : [];

$m_is_variable = ( 'variable' === $m_type );

// 描述 = 短描述 + 完整描述（兩者 build_products() 都已 wpautop），再把 YouTube 連結轉 iframe。
$m_description = ProfitShopFrontView::embed_youtube(
	(string) $ps_product['shortDescription'] . (string) $ps_product['description']
);

$m_max_qty = ProfitShopFrontView::max_qty( $ps_product );

// 賣場層級關閉時整顆按鈕停用；variable 商品則是「尚未選齊屬性」也要停用。
$m_submit_disabled = ! $is_shop_open || $m_is_variable
|| ( isset( $m_stock['stockStatus'] ) && 'outofstock' === $m_stock['stockStatus'] );

/*
* 變體資料：只挑 JS 真正會用到的欄位，不把 `get_available_variations()` 的完整輸出
* （含 price_html / variation_description 等）整包塞進 HTML——那會讓一個 30 變體的
* 商品多印出數十 KB 的 JSON，而其中絕大多數欄位沒有任何消費者。
*/
$m_variations = [];
if ( $m_is_variable && ! empty( $ps_product['variations'] ) ) {
	foreach ( $ps_product['variations'] as $variation ) {
		$image_src = '';
		if ( isset( $variation['image'] ) && \is_array( $variation['image'] ) ) {
			$image_src = (string) ( $variation['image']['full_src'] ?? $variation['image']['src'] ?? '' );
		}

		$m_variations[] = [
			'id'              => (int) ( $variation['variation_id'] ?? 0 ),
			'attributes'      => isset( $variation['attributes'] ) && \is_array( $variation['attributes'] )
				? $variation['attributes']
				: [],
			'regularPrice'    => (string) ( $variation['regularPrice'] ?? '' ),
			'salesPrice'      => (string) ( $variation['salesPrice'] ?? '' ),
			'stock'           => isset( $variation['stock'] ) && \is_array( $variation['stock'] )
				? $variation['stock']
				: [],
			'extraBuyerCount' => (int) ( $variation['extraBuyerCount'] ?? 0 ),
			'image'           => $image_src,
		];
	}
}

$m_dialog_id = \sprintf( 'ps-modal-%d-%d', $ps_shop_id, $m_id );
?>
<dialog
	id="<?php echo \esc_attr( $m_dialog_id ); ?>"
	class="pc-modal pc-modal-middle"
	data-ps-modal-for="<?php echo \esc_attr( (string) $m_id ); ?>"
	data-ps-type="<?php echo \esc_attr( $m_type ); ?>"
	data-ps-shop-open="<?php echo $is_shop_open ? '1' : '0'; ?>"
	data-ps-sold-individually="<?php echo ! empty( $ps_product['is_sold_individually'] ) ? '1' : '0'; ?>"
	data-ps-backorders="<?php echo \esc_attr( (string) $ps_product['backorders'] ); ?>"
	data-ps-total-sales="<?php echo \esc_attr( (string) (int) $ps_product['total_sales'] ); ?>"
	data-ps-stock="<?php echo \esc_attr( (string) \wp_json_encode( $m_stock ) ); ?>"
>
	<div class="pc-modal-box relative w-11/12 max-w-[520px] overflow-visible lg:w-1/2 lg:max-w-[960px]">

		<?php // 關閉鈕：`<form method="dialog">` 內的 button 會關閉 dialog，不需要 JS。 ?>
		<form method="dialog">
			<button
				type="submit"
				class="pc-btn pc-btn-ghost pc-btn-circle pc-btn-sm absolute right-2 top-2 z-10"
				aria-label="<?php echo \esc_attr__( '關閉', 'power_shop' ); ?>"
			>✕</button>
		</form>

		<div class="grid max-h-[75vh] grid-cols-1 gap-6 overflow-y-auto overflow-x-hidden lg:grid-cols-12">

			<?php // ── 左欄：圖片畫廊（sticky，捲動右欄時圖片停在原地，與舊版相同） ── ?>
			<div class="relative mb-4 lg:col-span-5">
				<div class="sticky top-0">
					<?php if ( empty( $m_images ) ) : ?>
						<div class="aspect-square w-full bg-base-200"></div>
					<?php else : ?>
						<img
							class="aspect-square w-full object-cover"
							src="<?php echo \esc_url( (string) $m_images[0] ); ?>"
							alt="<?php echo \esc_attr( $m_name ); ?>"
							data-ps-gallery-main
						>
						<?php if ( \count( $m_images ) > 1 ) : ?>
							<div class="mt-2 grid w-full grid-cols-4 gap-2">
							<?php foreach ( $m_images as $index => $image_url ) : ?>
									<div
										role="button"
										tabindex="0"
										data-ps-gallery-thumb="<?php echo \esc_url( (string) $image_url ); ?>"
										aria-label="<?php echo \esc_attr( \sprintf( /* translators: %d: 圖片序號 */ \__( '切換至第 %d 張圖片', 'power_shop' ), (int) $index + 1 ) ); ?>"
									>
								<?php
								/*
								* 縮圖刻意用 `<div role="button">` 而非原生 `<button>`：本專案 Tailwind
								* preflight 關閉，佈景主題對 `button` 設的 padding / min-height /
								* display 會蓋進來，把 grid-cols-4 的 aspect-square 正方形排列撐歪，
								* focus 時還會多一圈瀏覽器預設 outline。
								*/
								?>
										<img
											class="aspect-square w-full cursor-pointer object-cover<?php echo 0 === (int) $index ? ' border-2 border-primary border-solid' : ''; ?>"
											src="<?php echo \esc_url( (string) $image_url ); ?>"
											alt=""
											loading="lazy"
										>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<?php // ── 右欄：名稱 / 描述 / 變體 / 庫存 / 購買人數 / 價格 / 數量 / 加購 ── ?>
			<div class="lg:col-span-7">
				<div class="flex flex-col">

					<div>
						<div class="mb-4 text-xl"><?php echo \esc_html( $m_name ); ?></div>
					</div>

					<div class="my-4">
						<div class="relative h-full overflow-hidden" data-ps-content>
							<div class="max-h-[300px]" data-ps-content-inner>
								<?php
								/*
								* 商品描述：站長在 WordPress 編輯器撰寫、已由 PHP wpautop 處理過的 HTML
								* （含 <p> / <strong> / 圖片等），必須以 HTML 渲染才能保留排版。
								* 內容來源是後台管理者，不是使用者輸入——與 `the_content` 同一套信任模型。
								*/
								echo $m_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</div>
							<?php // 底部漸層遮罩：點擊即展開。預設隱藏，由 JS 量測高度後決定是否顯示。 ?>
							<div
								role="button"
								tabindex="0"
								aria-label="<?php echo \esc_attr__( '展開全部內容', 'power_shop' ); ?>"
								class="tw-hidden absolute bottom-0 w-full cursor-pointer bg-gradient-to-t from-base-100 to-transparent pb-4 pt-12 text-center"
								data-ps-content-fade
							></div>
						</div>
					</div>

					<div>
						<?php if ( $m_is_variable ) : ?>
							<?php
							$m_attributes = isset( $ps_product['variation_attributes'] ) && \is_array( $ps_product['variation_attributes'] )
							? $ps_product['variation_attributes']
							: [];

							foreach ( $m_attributes as $attribute_name => $options ) :
								$attribute_label = \function_exists( 'wc_attribute_label' )
								? (string) \wc_attribute_label( (string) $attribute_name )
								: (string) $attribute_name;
								?>
								<div class="mb-4">
									<p class="mb-0"><?php echo \esc_html( $attribute_label ); ?></p>
									<div class="flex flex-wrap">
								<?php foreach ( (array) $options as $option ) : ?>
											<button
												type="button"
												class="pc-btn pc-btn-xs mb-1 mr-1"
												aria-pressed="false"
												data-ps-attr-option
												data-ps-attr-name="<?php echo \esc_attr( (string) $attribute_name ); ?>"
												data-ps-attr-value="<?php echo \esc_attr( (string) $option ); ?>"
											>
												<span class="text-xs"><?php echo \esc_html( \urldecode( (string) $option ) ); ?></span>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>

							<p class="m-0 text-xs text-base-content/60" data-ps-attr-status>
							<?php
								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
							echo ProfitShopFrontView::icon(
									'check-circle',
									'tw-hidden mr-2 align-middle h-3 w-3 text-success',
									[ 'data-ps-icon' => 'success' ]
								);
							echo ProfitShopFrontView::icon(
									'close-circle',
									'mr-2 align-middle h-3 w-3 text-error',
									[ 'data-ps-icon' => 'error' ]
								);
								// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
								<span data-ps-attr-status-label><?php echo \esc_html__( '未選擇商品屬性', 'power_shop' ); ?></span>
							</p>
						<?php endif; ?>

						<?php
						if ( $show_stock ) :
							$m_stock_view = ProfitShopFrontView::stock_presentation( $m_stock );
							?>
							<p class="m-0 text-xs text-base-content/60" data-ps-stock>
							<?php
							/*
							* 三種色調的圖示都先印出來，只有當前狀態不帶 tw-hidden。
							* 使用者選了變體之後，JS 只需要切 class 就能換圖示，
							* 不必在前端重建 SVG（那會讓同一組圖示存在 PHP / JS 兩份定義）。
							*/
							$m_stock_icons = [
								'success' => [ 'check-circle', 'text-success' ],
								'error'   => [ 'close-circle', 'text-error' ],
								'warning' => [ 'exclamation-circle', 'text-warning' ],
							];
							foreach ( $m_stock_icons as $tone => $icon_spec ) {
								$hidden = ( $tone === $m_stock_view['tone'] ) ? '' : 'tw-hidden ';
								echo ProfitShopFrontView::icon( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									$icon_spec[0],
									$hidden . 'mr-2 align-middle h-3 w-3 ' . $icon_spec[1],
									[ 'data-ps-icon' => $tone ]
								);
							}
							?>
								<span data-ps-stock-label><?php echo \esc_html( $m_stock_view['text'] ); ?></span>
							</p>
						<?php endif; ?>

						<?php
						if ( $show_buyer_count ) :
							$m_buyer_total = (int) $ps_product['total_sales'] + (int) $ps_product['extraBuyerCount'];
							?>
							<p
								class="m-0 text-xs text-base-content/60<?php echo $m_is_variable ? ' tw-hidden' : ''; ?>"
								data-ps-buyer
							>
							<?php echo ProfitShopFrontView::icon( 'fire', 'mr-2 align-middle h-3 w-3 text-error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span data-ps-buyer-label>
							<?php
							echo \esc_html(
										\sprintf(
											/* translators: %s: 購買人數 */
											\__( '已有 %s 人購買', 'power_shop' ),
											\number_format_i18n( $m_buyer_total )
										)
									);
							?>
								</span>
							</p>
						<?php endif; ?>
					</div>

					<div class="mt-4" data-ps-price>
						<?php
						if ( ! $m_is_variable ) :
							// variable 商品的價格由 JS 在選定變體後填入（未選時留白，與舊版相同）。
							$m_regular = (string) $ps_product['regularPrice'];
							$m_sale    = (string) $ps_product['salesPrice'];

							if ( '' !== $m_sale ) :
								?>
								<p class="mb-0"><del><?php echo \esc_html( ProfitShopFrontView::currency( $m_regular ) ); ?></del></p>
								<p class="mb-0 text-error"><?php echo \esc_html( ProfitShopFrontView::currency( $m_sale ) ); ?></p>
								<?php else : ?>
								<p class="mb-0"><?php echo \esc_html( ProfitShopFrontView::currency( $m_regular ) ); ?></p>
									<?php
							endif;
						endif;
						?>
					</div>

					<?php
					/*
					* 加購表單。刻意不寫 `action`：瀏覽器會送回當前網址，這同時涵蓋
					* 「獨立頁面」與「shortcode 嵌在任意頁面」兩種情境，不需要 PHP 猜測當前 URL。
					*/
					?>
					<form method="post" data-ps-form>
						<input type="hidden" name="add-to-cart" value="<?php echo \esc_attr( (string) $m_id ); ?>">
						<input type="hidden" name="profit_shop_id" value="<?php echo \esc_attr( (string) $ps_shop_id ); ?>">
						<?php if ( $m_is_variable ) : ?>
							<input type="hidden" name="variation_id" value="" data-ps-variation-id>
							<?php // JS 依選中變體自己的 attributes 鍵重建這些欄位（見 profit-shop-front.js）。 ?>
							<span data-ps-attr-fields></span>
						<?php endif; ?>

						<p class="mb-0 mt-4"><?php echo \esc_html__( '數量', 'power_shop' ); ?></p>
						<?php
						/*
						* -/+ 按鈕的 border-base-content/20 與中間輸入框的 pc-input-bordered 是同一個
						* border-color 值（oklch(var(--bc)/.2)），三者框線因此完全一致。三個 class 缺一不可：
						*
						* 1. border-solid：本專案 Tailwind preflight 關閉，<button> 沿用瀏覽器預設的
						*    border-style:none，此時 border-width 一律被算成 0px，daisyUI 的
						*    .pc-btn{border-width:var(--border-btn,1px)} 完全看不出來（同檔畫廊縮圖亦然）。
						* 2. border：明確釘住 1px，與 pc-join 的 margin-inline-start:-1px 相消，接縫不會變 2px。
						* 3. hover: 變體：.pc-btn:hover 會重設 border-color，其 specificity (1,2,0) 高過一般
						*    utility (1,1,0)，只寫靜態版會在滑鼠移入時框線變色、與中間輸入框不一致。
						*/
						?>
						<div class="pc-join w-full">
							<button type="button" class="pc-btn pc-btn-sm pc-join-item border border-solid border-base-content/20 hover:border-base-content/20" data-ps-qty-minus aria-label="<?php echo \esc_attr__( '減少數量', 'power_shop' ); ?>">-</button>
							<input
								type="number"
								name="quantity"
								class="pc-input pc-input-bordered pc-input-sm pc-join-item w-full text-center"
								value="1"
								min="1"
								<?php echo null === $m_max_qty ? '' : 'max="' . \esc_attr( (string) $m_max_qty ) . '"'; ?>
								data-ps-qty
							>
							<button type="button" class="pc-btn pc-btn-sm pc-join-item border border-solid border-base-content/20 hover:border-base-content/20" data-ps-qty-plus aria-label="<?php echo \esc_attr__( '增加數量', 'power_shop' ); ?>">+</button>
						</div>

						<?php
						/*
						* 加購按鈕：文字包成 span，前面多一顆預設隱藏的 spinner，AJAX 飛行中才顯示。
						* `#tw .pc-btn` 本身是 inline-flex + gap:.5rem，spinner 隱藏時是 display:none，
						* 不會留下幽靈間距，因此不需要任何 margin utility。
						*/
						?>
						<button
							type="submit"
							class="pc-btn pc-btn-primary pc-btn-sm mt-4 w-full"
							data-ps-submit
							<?php echo $m_submit_disabled ? 'disabled' : ''; ?>
						>
							<span class="pc-loading pc-loading-spinner tw-hidden size-4" data-ps-submit-spinner></span>
							<span data-ps-submit-label><?php echo \esc_html( $is_shop_open ? \__( '加入購物車', 'power_shop' ) : $closed_label ); ?></span>
						</button>

						<?php
						/*
						* AJAX 失敗時的就地錯誤列。寫在 Modal **內**而不是用外面的 toast，是因為失敗時
						* Modal 仍然開著——原生 `<dialog>` 開啟時進入 top layer，body 層的 toast 不論
						* z-index 設多大都在它之下，根本看不到。
						*/
						?>
						<p class="tw-hidden mb-0 mt-2 text-sm text-error" role="alert" data-ps-submit-error>
							<span data-ps-submit-error-label></span>
							<?php if ( \function_exists( 'wc_get_cart_url' ) ) : ?>
								<a class="ml-1 underline" href="<?php echo \esc_url( (string) \wc_get_cart_url() ); ?>"><?php echo \esc_html__( '查看購物車', 'power_shop' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				</div>
			</div>
		</div>

		<?php
		/*
		* 描述展開 / 收合把手：文字直排，md 以上外移到 Modal 之外（靠 modal-box 的
		* `overflow-visible` 才不會被裁掉），md 以下貼齊右緣並改為半透明 + 陰影 + 左側圓角。
		* 預設隱藏，由 JS 量測描述高度後決定是否顯示——`<dialog>` 未開啟時 display:none，
		* 量不到高度，因此這件事只能在開啟後做。
		*/
		?>
		<div
			role="button"
			tabindex="0"
			data-ps-content-handle
			data-ps-expanded="0"
			class="tw-hidden absolute bottom-24 right-0 flex w-8 cursor-pointer items-center rounded-l-lg bg-base-100 py-3 opacity-50 shadow md:-right-8 md:rounded-l-none md:rounded-r-lg md:opacity-100 md:shadow-none"
			style="writing-mode: vertical-rl;"
		>
			<?php
			echo ProfitShopFrontView::icon( 'arrows-alt', 'mb-2 h-3 w-3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo ProfitShopFrontView::icon( 'shrink', 'tw-hidden mb-2 h-3 w-3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<span data-ps-handle-label><?php echo \esc_html__( '展開全部內容', 'power_shop' ); ?></span>
		</div>

		<?php if ( $m_is_variable ) : ?>
			<?php
			/*
			* 變體資料。刻意印在 dialog 內而非 wp_localize_script 的全域物件：
			* 一個頁面可以放多個 shortcode 賣場，全域單一物件會互相覆蓋。
			*
			* `wp_json_encode` 已對 `<` / `>` / `&` 做 escape（預設帶 JSON_HEX_TAG 等旗標時），
			* 這裡另外顯式擋掉 `</script`，避免商品屬性值裡的字串提前結束 script 區塊。
			*/
			$m_json = (string) \wp_json_encode( $m_variations );
			?>
			<script type="application/json" class="ps-variations-data">
			<?php echo \str_replace( '</', '<\/', $m_json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</script>
		<?php endif; ?>
	</div>

	<?php
	/*
	* 點背景關閉（daisyUI 慣例；`<form method="dialog">` 不需要 JS）。
	*
	* ⚠ 這顆按鈕**必須**明確設成透明。daisyUI 只給它 `color: transparent`、背景留空，
	* 預期是瀏覽器預設的透明背景；但賣場頁走佈景主題，主題有一條命中 `[type="submit"]`
	* 的規則會塗上 primary 背景色與邊框 —— 於是整片遮罩變成一塊實心色塊，
	* 把 Modal 以外的畫面全部蓋掉。
	*
	* `bg-transparent` / `border-0` 是 Tailwind utility，帶 `#tw` 前綴（specificity 1,1,0），
	* 穩定勝過主題那條 (0,1,0) 的規則。（daisyUI 其餘元件已由 powerhouse 的 postcss `scope-daisyui` 統一收進 `#tw`，
	* 只有這種「daisyUI 根本沒出規則」的位置才需要使用端自己壓。）
	*/
	?>
	<form method="dialog" class="pc-modal-backdrop">
		<button type="submit" class="bg-transparent text-transparent border-0 cursor-default"><?php echo \esc_html__( '關閉', 'power_shop' ); ?></button>
	</form>
</dialog>
