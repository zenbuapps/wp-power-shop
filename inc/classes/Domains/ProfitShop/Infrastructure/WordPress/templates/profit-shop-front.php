<?php
/**
 * Profit Shop 前台 template
 *
 * 由 ProfitShopRenderer::render_shop()（獨立頁面）與 ProfitShopShortcode::render_template()
 * （shortcode）共用。**兩條路徑渲染完全相同的 DOM**——包含外層標籤、賣場設定、
 * 商品卡片與商品 Modal，不再有任何 `$is_shortcode_context` 分支。
 *
 * ## 架構（Phase 14：移除 React / antd）
 *
 * 畫面 100% 由 PHP 印出：商品卡片 grid + **每個商品一個 `<dialog class="pc-modal">`**。
 * `inc/assets/js/profit-shop-front.js`（手寫 vanilla JS，不進 Vite）只負責五件互動：
 * 開 Modal、Gallery 縮圖、描述展開收合、數量加減、變體選擇。
 *
 * 樣式一律使用 powerhouse 提供的 Tailwind + daisyUI（`pc-` 前綴），power-shop
 * 自己不再有任何 CSS 檔或 inline `<style>`：
 *   - 顏色只用 daisyUI 語意色（`pc-btn-primary` / `text-error` / `text-base-content/60`
 *     / `bg-base-200`），跟隨站台 daisyUI theme，不再由賣場設定的 `btnColor` 決定
 *   - Tailwind utility 由 powerhouse 的 build 產出（其 tailwind config 的 `content` 已把
 *     兄弟目錄 `power-*` 底下的 `inc` 與 `js/src` 納入掃描），所以改動本檔的 class 之後
 *     必須到 powerhouse 重跑 `pnpm build-css:front` / `build-css:admin` 才會生效
 *
 * ## 漸進增強（不需要任何 `<noscript>` 樣式）
 *
 * 每張卡片只印**一個**動作元素，且那個元素本身在沒有 JS 時就能用：
 *   - simple  → `<form method="get">`（`add-to-cart` + `profit_shop_id`）
 *   - 其他    → `<a href="{permalink}?profit_shop_id={id}">`
 *
 * JS 載入後在**卡片層**攔截 click（`preventDefault()` 會一併取消表單送出），改開 Modal，
 * 並把按鈕文字換成 JS 模式的文案。因此 JS 停用時畫面沒有任何「壞掉的按鈕」，
 * 也不需要舊版那組 `.ps-nojs-fallback` / `.ps-not-ready` 的 CSS。
 *
 * ## 加入購物車
 *
 * Modal 內是一張真正的 `<form method="post">`，交給 WooCommerce 原生的
 * `WC_Form_Handler::add_to_cart_action()` 處理，結果由本檔的 `wc_print_notices()` 呈現。
 * `profit_shop_id` 由 PHP 直接印進表單——`AddToCartHook`（priority 5）只從 `$_GET` / `$_POST`
 * 讀它，漏送會**靜默走商品原價、分潤失效且沒有任何錯誤訊息**。
 *
 * 注意：本檔不可被直接訪問；只能透過 ProfitShopRenderer / ProfitShopShortcode 載入。
 *
 * @var \J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop $shop_for_template
 * @var array<string, mixed>|null                                 $front_settings
 */

declare(strict_types=1);

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontData;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontView;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop $shop */
$shop = $shop_for_template;

/*
* 前台顯示設定。
*
* 兩條渲染路徑都必須傳入——早期只有 ProfitShopRenderer 會傳，shortcode 路徑因此拿到空陣列，
* `shopStatus` 一律 fallback 成 `published`，導致「已結束販售的賣場用 shortcode 嵌入時
* 照樣能加購」。這裡保留 fallback 只當防呆，正式路徑一律由 caller 傳入。
*/
$settings = isset( $front_settings ) && \is_array( $front_settings )
? $front_settings
: ProfitShopFrontData::settings( $shop );

$shop_status = isset( $settings['shopStatus'] ) && \is_string( $settings['shopStatus'] )
? $settings['shopStatus']
: 'published';

// 非 published（未開賣 / 已結束）時，卡片不可點、按鈕停用並顯示狀態文案。
$is_shop_open = ( 'published' === $shop_status );
$closed_label = ( 'coming' === $shop_status )
? \__( '尚未開始販售', 'power_shop' )
: \__( '商店已關閉', 'power_shop' );

// 卡片「已售 N 件」與 Modal「已有 N 人購買」共用同一個開關（都是購買人數的呈現）。
$show_buyer_count = ! isset( $settings['showBuyerCount'] ) || (bool) $settings['showBuyerCount'];

// 庫存資訊顯示開關（Modal 內的「還有庫存 / 只剩最後 N 件」）。
$show_stock = ! isset( $settings['showStock'] ) || (bool) $settings['showStock'];

$partner_name = '';
$partner_term = \get_term( $shop->partner_term_id, 'profit_partner' );
if ( $partner_term instanceof \WP_Term ) {
	$partner_name = $partner_term->name;
}

$is_draft_preview = ( 'publish' !== $shop->status() );

/*
* 商品資料：卡片與 Modal **共用同一份** payload。
*
* 早期卡片自己跑一次 `wc_get_product()` + 過濾條件，Modal 的資料另外由
* `build_products()` 組一次，兩邊的「非 publish 商品要跳過」必須靠人維持同步——
* 不同步就會出現「有卡片但 Modal 找不到商品」的破 Modal。改為單一來源後
* 結構上不可能分歧。
*/
$ps_products = ProfitShopFrontData::build_products( $shop );

$ps_shop_id = (int) $shop->id;
$templates  = __DIR__;
?>

<div class="ps-profit-shop-front mx-auto my-8 w-full max-w-[1200px] px-4" data-ps-shop="<?php echo \esc_attr( (string) $ps_shop_id ); ?>">

	<?php if ( $is_draft_preview ) : ?>
		<div role="alert" class="pc-alert pc-alert-warning mb-6">
			<span>
				<strong><?php echo \esc_html__( '草稿預覽', 'power_shop' ); ?></strong>
		<?php echo \esc_html__( '：此賣場尚未發佈，僅有編輯權限的使用者可見。', 'power_shop' ); ?>
			</span>
		</div>
	<?php endif; ?>

	<?php
	/*
	* WooCommerce 通知（「XXX 已加入購物車」/ 庫存不足 / 請選擇商品屬性…）。
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

	<?php
	/*
	* AJAX 加購成功後的浮出通知。
	*
	* 三個刻意的設計，改動前先讀完：
	*
	* 1. **印在賣場容器內**：一頁可以放多個 shortcode 賣場，JS 一律以最近的
	*    `.ps-profit-shop-front` 祖先為查找邊界，toast 跟著容器走才不會互相搶。
	* 2. **`style="z-index:9999"` 是刻意的靜態 inline style，不可拿掉**：daisyUI 的
	*    `.pc-toast` 完全沒有設 z-index，而 powerhouse 的 Tailwind 只產出到 `z-50`，
	*    佈景主題的 sticky header 常在 999 以上，不釘死會被蓋住。這與 Phase 14 移除的
	*    「動態 `<style>` 區塊」是兩回事——那是 CSS injection 面，靜態屬性沒有此風險。
	* 3. **`w-max` 與 `whitespace-normal` 必須成對**：`.pc-toast` 自帶 `white-space:nowrap`，
	*    中文長訊息不換行會橫向撐爆視窗；但**只加 `whitespace-normal` 會讓 fixed
	*    定位的 shrink-to-fit 改用 min-content 寬度**，而中文可在任意字元間斷行，
	*    整段文字會被壓成一字一行的直排（實測踩過）。`w-max`（width:max-content）
	*    把寬度拉回自然單行寬，再由 `max-w-[min(24rem,90vw)]` 封頂，
	*    長訊息才會正常折行而不是直排。
	*
	* ⚠ 成功時 JS 會**先關閉 Modal 再顯示 toast**。原生 `<dialog>` 開啟時進入 top layer，
	* body 層元素不論 z-index 多大都在它與 `::backdrop` 之下；先關 Modal 這個問題就
	* 結構性消失，不需要把 toast 塞進每一顆 dialog 裡。失敗時 Modal 不關，錯誤改由
	* Modal 內的 `[data-ps-submit-error]` 就地顯示。
	*
	* 非販售期間整段不印——那時 Modal 本來就不印，也就不會有任何 AJAX 加購。
	*/
	if ( $is_shop_open ) :
		$ps_cart_url = \function_exists( 'wc_get_cart_url' ) ? (string) \wc_get_cart_url() : '';
		?>
		<div class="pc-toast pc-toast-top pc-toast-center tw-hidden" style="z-index:9999;" data-ps-toast>
			<div class="pc-alert w-max max-w-[min(24rem,90vw)] whitespace-normal shadow-lg" role="status" aria-live="polite" data-ps-toast-body>
		<?php
		echo ProfitShopFrontView::icon( 'check-circle', 'size-5 align-middle text-success', [ 'data-ps-icon' => 'success' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon() 內部已逐項 esc_attr
		echo ProfitShopFrontView::icon( 'close-circle', 'tw-hidden size-5 align-middle text-error', [ 'data-ps-icon' => 'error' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 同上
		?>
				<span data-ps-toast-label></span>
		<?php if ( '' !== $ps_cart_url ) : ?>
				<a class="pc-btn pc-btn-xs" href="<?php echo \esc_url( $ps_cart_url ); ?>"><?php echo \esc_html__( '查看購物車', 'power_shop' ); ?></a>
			<?php endif; ?>
			</div>
		</div>
		<?php
	endif;
	?>

	<header class="mb-8">
		<h1 class="mb-2 text-3xl font-bold"><?php echo \esc_html( $shop->title ); ?></h1>
		<?php if ( '' !== $partner_name ) : ?>
			<p class="text-base-content/60">
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
	* powershop CPT 有 `supports: editor`（CptRegistrar.php），與 legacy 的 power-shop CPT
	* 一致——legacy 賣場頁的標題／描述／banner 全靠站長在編輯器排版，plugin 只提供商品
	* grid。新版是自訂 renderer 而非 theme single.php，若不主動印出 post_content，
	* 站長寫的文案會整個消失。
	*
	* 走 `the_content` filter 以支援 Gutenberg 區塊與 shortcode；輸出刻意不 escape，
	* 這是 WP 對編輯器內容的標準處理，內容在儲存時已由 kses 過濾，且只有具 edit 權限者能寫入。
	*/
	$shop_content = (string) \get_post_field( 'post_content', $shop->id );
	if ( '' !== \trim( $shop_content ) ) :
		?>
		<div class="ps-shop-content entry-content mb-8">
		<?php echo \apply_filters( 'the_content', $shop_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
	endif;

	if ( empty( $ps_products ) ) :
		?>
		<p class="py-16 text-center text-base-content/60">
		<?php echo \esc_html__( '此賣場暫無商品', 'power_shop' ); ?>
		</p>
		<?php
	else :
		?>
		<div class="power-shop-products">
			<div class="grid grid-cols-2 gap-8 md:grid-cols-3 xl:grid-cols-4">
		<?php
		foreach ( $ps_products as $ps_product ) :
			require $templates . '/partials/profit-shop-card.php';
		endforeach;
		?>
			</div>
		</div>

		<?php
		/*
		* 商品 Modal：每個商品各一個 `<dialog>`，由 PHP 直接印出。
		*
		* 刻意印在賣場容器**內部**——JS 查找 Modal 時限定在最近的
		* `.ps-profit-shop-front` 祖先內，一個頁面放多個 shortcode 賣場時才不會開錯商品。
		* `<dialog>` 開啟時會被提升到 top layer，巢狀位置不影響視覺與疊層。
		*
		* ⚠ 非販售期間（尚未開賣 / 已結束）**整段不印**。Modal 內含一張真正的
		* `<form method="post">`，即使按鈕是 disabled，表單本身仍然存在於 DOM，
		* 改一下屬性就能送出——而 `AddToCartHook` 只驗「賣場存在 / status=publish /
		* 商品屬於該賣場」三道閘門，**不檢查販售起訖時間**，那道防線接不住。
		* 不印出來才是唯一可靠的做法（舊版在閉店時也只印停用按鈕、不印任何表單）。
		*/
		if ( $is_shop_open ) :
			foreach ( $ps_products as $ps_product ) :
				require $templates . '/partials/profit-shop-modal.php';
			endforeach;
		endif;
		?>
		<?php
	endif;
	?>

</div>
