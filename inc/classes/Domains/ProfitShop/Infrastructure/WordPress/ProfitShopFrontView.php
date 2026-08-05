<?php
/**
 * 賣場前台呈現層工具
 *
 * Phase 14：前台從 React / antd 改為 PHP + daisyUI + vanilla JS 之後，卡片、Modal
 * 與前端 JS 三處都需要同一套「怎麼把價格 / 庫存 / 圖示變成畫面」的規則。
 * 這個類別是那套規則的單一來源，避免像 Phase 13 的取價語意那樣，靠人維持
 * 「兩份實作要逐字一致」的紀律（那條紀律已經失敗過一次）。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Plugin;

/**
 * 賣場前台呈現輔助
 *
 * 全部是純函式 static method，不持有狀態、不碰 DB。
 *
 * ⚠ `currency()` 的格式（前綴符號 + 空格 + 原始數字、不加千分位）與
 * `inc/assets/js/profit-shop-front.js` 的 `currency()` **必須一致**——卡片由 PHP 印、
 * Modal 選變體後的價格由 JS 重繪，兩邊格式不同會出現「NT$ 8,000 / NT$ 8000」的落差。
 */
final class ProfitShopFrontView {

	/**
	 * 幣別符號
	 *
	 * 與前端 JS 的 `i18n.currencySymbol` 同源（由 `enqueue()` 一併 localize）。
	 */
	public const CURRENCY_SYMBOL = 'NT$';

	/**
	 * 前台互動層的 script handle
	 */
	public const SCRIPT_HANDLE = 'power-shop-profit-shop-front';

	/*
	* ⚠ 這裡曾經有 `BTN_SIZE_SM` / `BTN_SIZE_XS` / `INPUT_SIZE` 三個常數，
	* 內容是 `h-8 min-h-8 px-3 text-sm` 這類 Tailwind utility，用途是把 daisyUI
	* 按鈕 / 輸入框的尺寸「釘」回來——因為賣場頁走佈景主題，主題（實測 Blocksy）有一條
	* 命中 `[type="submit"]` 的規則，specificity 與 `.pc-btn-sm` **同樣是 (0,1,0)**，
	* 但載入順序在 plugin CSS 之後，同分時後者勝 → daisyUI 全被蓋掉。
	*
	* 那三個常數已移除：powerhouse 在 CSS 建置階段加了
	* `scripts/postcss-scope-daisyui.cjs`，把**所有** daisyUI 選擇器收進 `#tw`
	* （與 Tailwind utility 的 `important: '#tw'` 同一套機制），daisyUI 因此升到
	* (1,1,0)、穩定勝過主題。使用端可以放心直接寫 `pc-btn-sm` / `pc-btn-xs` /
	* `pc-input-sm`，不需要再逐顆補 utility。
	*
	* 唯一的例外：**daisyUI 根本沒有出規則**的位置（例如 `.pc-modal-backdrop` 內那顆
	* `<button>`，daisyUI 只管 form 本身），主題的樣式沒有對手，仍需使用端自己加
	* `bg-transparent` 之類壓過去——見 `templates/partials/profit-shop-modal.php`。
	*/

	/**
	 * 相對於外掛根目錄的 script 路徑
	 */
	private const SCRIPT_PATH = '/inc/assets/js/profit-shop-front.js';

	/**
	 * Enqueue 賣場前台互動層
	 *
	 * 由 `ProfitShopRenderer`（獨立頁面）與 `ProfitShopShortcode`（shortcode）共用，
	 * 兩條路徑因此載到完全相同的資源——早期只有 renderer 會 enqueue，shortcode 賣場
	 * 的商品 Modal 根本開不起來。
	 *
	 * ⚠ **不再走 Vite**：Phase 14 移除 React bundle 後這支是手寫 vanilla JS，
	 * 不進 build pipeline。連帶好處是不會再有「Vite manifest 缺 entry → 前台降級」
	 * 這條需要處理的失敗路徑。
	 *
	 * ⚠ **沒有 CSS**：樣式一律由 powerhouse 的 `front.min.css`（前台無條件載入，
	 * 含 Tailwind + daisyUI）提供。本外掛不再有任何自己的 CSS 檔。
	 *
	 * `wp_enqueue_script` 對同一個 handle 是冪等的，一頁放多個 shortcode 賣場
	 * 不會重複載入；`wp_localize_script` 的 i18n 與 config 內容都是**站台層級、與賣場無關**，
	 * 因此可以安全地跟著上面的冪等 early-return 只跑一次。
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( \wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}

		\wp_enqueue_script(
			self::SCRIPT_HANDLE,
			Plugin::$url . self::SCRIPT_PATH,
			[],
			Plugin::$version,
			true
		);

		\wp_localize_script(
			self::SCRIPT_HANDLE,
			'power_shop_front_i18n',
			self::js_i18n()
		);

		\wp_localize_script(
			self::SCRIPT_HANDLE,
			'power_shop_front_config',
			self::js_config()
		);
	}

	/**
	 * 商品描述超過這個高度（px）才顯示「展開全部內容」
	 *
	 * 與 `inc/assets/js/profit-shop-front.js` 的 `READ_MORE_THRESHOLD` 同值；
	 * 實際判斷在 JS（PHP 量不到瀏覽器算繪高度），此處僅作為文件用途的單一定義點。
	 */
	public const READ_MORE_THRESHOLD = 299;

	/**
	 * 組出帶貨幣符號的價格字串
	 *
	 * @param string|int|float|null $price 價格。
	 *
	 * @return string 例：`NT$ 1200`；null / 空字串回空字串。
	 */
	public static function currency( $price ): string {
		if ( null === $price || '' === $price ) {
			return '';
		}

		return self::CURRENCY_SYMBOL . ' ' . self::number( $price );
	}

	/**
	 * 數字轉顯示字串（去掉尾隨的無意義零）
	 *
	 * `wc_format_decimal( $v, '', true )` 會把 `8000.00` 變 `'8000'`、`99.50` 變 `'99.5'`。
	 * 少了 `$trim_zeros = true`，整數價會輸出成 `8000.00`，與 JS 端重繪的
	 * `8000` 對不上，同一顆 Modal 內選變體前後會看到兩種寫法。
	 *
	 * @param string|int|float $value 數值。
	 *
	 * @return string
	 */
	public static function number( $value ): string {
		if ( \function_exists( 'wc_format_decimal' ) ) {
			return (string) \wc_format_decimal( $value, '', true );
		}

		return (string) $value;
	}

	/**
	 * 計算 variable 商品的價格區間
	 *
	 * 照搬 legacy `variable.php` 的邏輯：
	 * - `price` 取每個變體的實售價（有特價用特價，否則原價）
	 * - `regular` 取每個變體的原價
	 *
	 * 用 float 而非 legacy 的 `(int)`：legacy 會把 `99.5` 截成 `99`，是實際的顯示錯誤
	 * 而非風格差異。整數價經 `number()` 後輸出完全相同（`8000` → `'8000'`）。
	 *
	 * @param array<int, array<string, mixed>> $variations `ProfitShopFrontData::build_products()`
	 *                                                     的 `variations` 欄位。
	 *
	 * @return array{min: float|null, max: float|null, max_regular: float|null}
	 */
	public static function price_range( array $variations ): array {
		$prices   = [];
		$regulars = [];

		foreach ( $variations as $variation ) {
			$regular = isset( $variation['regularPrice'] ) ? (string) $variation['regularPrice'] : '';
			$sale    = isset( $variation['salesPrice'] ) ? (string) $variation['salesPrice'] : '';

			$effective = ( '' === $sale || 0.0 === (float) $sale ) ? (float) $regular : (float) $sale;

			if ( $effective > 0.0 ) {
				$prices[] = $effective;
			}
			if ( (float) $regular > 0.0 ) {
				$regulars[] = (float) $regular;
			}
		}

		return [
			'min'         => empty( $prices ) ? null : min( $prices ),
			'max'         => empty( $prices ) ? null : max( $prices ),
			'max_regular' => empty( $regulars ) ? null : max( $regulars ),
		];
	}

	/**
	 * 把內容中的 YouTube 連結換成 iframe 嵌入
	 *
	 * 從舊版前端 `utils.tsx` 的 `formatYoutubeLinkToIframe()` 移植到 PHP——
	 * 描述現在由 PHP 直接印進 Modal，這層轉換也必須跟著搬過來，否則站長貼的
	 * YouTube 連結會退化成純文字網址。
	 *
	 * 只處理 `https://www.youtube.com/watch?v={id}` 這一種形式（與舊版相同），
	 * 且 video id 走 `[A-Za-z0-9_-]` 白名單，不讓任意字串進 `src`。
	 *
	 * @param string $content 原始 HTML 內容。
	 *
	 * @return string 已將 YouTube 連結替換為 iframe 的 HTML。
	 */
	public static function embed_youtube( string $content ): string {
		$replaced = \preg_replace_callback(
			'#https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]+)[^\s"\'<]*#',
			static function ( array $matches ): string {
				return \sprintf(
					'<iframe src="https://www.youtube.com/embed/%s" style="width:100%%;border:none;aspect-ratio:16/9;" allowfullscreen loading="lazy"></iframe>',
					\esc_attr( $matches[1] )
				);
			},
			$content
		);

		return \is_string( $replaced ) ? $replaced : $content;
	}

	/**
	 * 依庫存資訊組出文案與色調
	 *
	 * 判斷順序與 `inc/assets/js/profit-shop-front.js` 的 `stockPresentation()` 完全一致：
	 * simple 商品由本方法印死在 HTML 裡，variable 商品選了變體之後由 JS 重繪。
	 *
	 * @param array{manageStock?: bool, stockQuantity?: int|null, stockStatus?: string} $stock 庫存資訊。
	 *
	 * @return array{tone: string, text: string} tone = success | error | warning
	 */
	public static function stock_presentation( array $stock ): array {
		$status   = isset( $stock['stockStatus'] ) ? (string) $stock['stockStatus'] : 'instock';
		$manage   = ! empty( $stock['manageStock'] );
		$quantity = isset( $stock['stockQuantity'] ) ? $stock['stockQuantity'] : null;

		switch ( $status ) {
			case 'outofstock':
				return [
					'tone' => 'error',
					'text' => \__( '此商品已售完', 'power_shop' ),
				];

			case 'onbackorder':
				return [
					'tone' => 'warning',
					'text' => \__( '此商品為延期交貨商品', 'power_shop' ),
				];

			case 'instock':
				if ( ! $manage ) {
					return [
						'tone' => 'success',
						'text' => \__( '此商品還有庫存', 'power_shop' ),
					];
				}

				if ( ! empty( $quantity ) ) {
					$template = ( (int) $quantity <= 10 )
					/* translators: %s: 剩餘件數 */
					? \__( '此商品只剩最後 %s 件', 'power_shop' )
					/* translators: %s: 剩餘件數 */
					: \__( '此商品剩餘 %s 件', 'power_shop' );

					return [
						'tone' => 'success',
						'text' => \sprintf( $template, (string) (int) $quantity ),
					];
				}

				return [
					'tone' => 'error',
					'text' => \__( '此商品已售完', 'power_shop' ),
				];

			default:
				return [
					'tone' => 'success',
					'text' => \__( '此商品還有庫存', 'power_shop' ),
				];
		}
	}

	/**
	 * 取得可購買的庫存上限
	 *
	 * 判斷順序對齊後端 `WC_Product::get_max_purchase_quantity()`：
	 * 「單獨銷售」優先於一切；允許延期交貨時視為無上限。
	 *
	 * @param array<string, mixed> $product `build_products()` 的單一商品資料。
	 *
	 * @return int|null 上限；無上限時回 null（呼叫端據此決定不輸出 `max` 屬性）。
	 */
	public static function max_qty( array $product ): ?int {
		if ( ! empty( $product['is_sold_individually'] ) ) {
			return 1;
		}

		$backorders = isset( $product['backorders'] ) ? (string) $product['backorders'] : 'no';
		if ( 'no' !== $backorders ) {
			return null;
		}

		$quantity = $product['stock']['stockQuantity'] ?? null;

		return null === $quantity ? null : (int) $quantity;
	}

	/**
	 * 內嵌 SVG 圖示
	 *
	 * 取代舊版的 `@ant-design/icons`（那是 React 元件，隨 antd 一併移除）。
	 * 刻意內嵌而非用 icon font 或外部 sprite：賣場頁不該為了 5 個圖示多一次 HTTP 請求，
	 * 而 powerhouse 的 CSS 也沒有提供 icon 系統。
	 *
	 * 尺寸與顏色由呼叫端用 Tailwind class 控制（`currentColor` + `w-*` / `h-*`）。
	 *
	 * ⚠ **不要給圖示加任何 display utility**（`inline` / `tw-inline` / `tw-block`）：
	 *   1. `inline` 在 powerhouse 的 `blocklist` 內（與 WordPress 衝突），寫了不會產出，
	 *      樣式安靜地不生效
	 *   2. 替代品 `tw-inline` 更糟——它與 `tw-hidden` 同樣來自 `addUtilities`、權重相同，
	 *      而 `.tw-inline` 在產出的 CSS 中排在 `.tw-hidden` **之後**，兩者同時存在時
	 *      `tw-inline` 會贏，於是「本來要隱藏的圖示」永遠顯示。庫存 / 屬性狀態的圖示
	 *      切換就是靠 `tw-hidden`，踩到這條會讓三種庫存圖示同時出現
	 *
	 * SVG 的瀏覽器預設 display 就是 `inline`，而本專案 Tailwind preflight 為關閉、
	 * powerhouse 的 CSS 也沒有任何裸 `svg` 的 display 規則，因此**什麼都不加**即可。
	 * 垂直對齊用 `align-middle`（一般 utility，不在 blocklist，也不影響 display）。
	 *
	 * @param string                $name  圖示名稱：check-circle / close-circle / exclamation-circle / fire / arrows-alt / shrink。
	 * @param string                $class 附加的 class。
	 * @param array<string, string> $attrs 額外屬性（例如 `['data-ps-icon' => 'success']`，
	 *                                     供前端 JS 在切換庫存狀態時找到對應圖示）。
	 *
	 * @return string SVG HTML；未知名稱回空字串。
	 */
	public static function icon( string $name, string $class = '', array $attrs = [] ): string {
		$paths = [
			// 實心圓 + 勾（對應 antd CheckCircleFilled）
			'check-circle'         => '<path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm193.5 301.7l-210.6 292a31.8 31.8 0 0 1-51.7 0L318.5 484.9c-3.8-5.3 0-12.7 6.5-12.7h46.9c10.2 0 19.9 4.9 25.9 13.3l71.2 98.8 157.2-218c6-8.3 15.6-13.3 25.9-13.3H699c6.5 0 10.3 7.4 6.5 12.7z"/>',
			// 實心圓 + 叉（對應 antd CloseCircleFilled）
			'close-circle'         => '<path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm165.4 618.2l-66-.3L512 563.4l-99.3 118.4-66.1.3c-4.4 0-8-3.5-8-8 0-1.9.7-3.7 1.9-5.2l130.1-155L340.5 359a8.32 8.32 0 0 1-1.9-5.2c0-4.4 3.6-8 8-8l66.1.3L512 464.6l99.3-118.4 66-.3c4.4 0 8 3.5 8 8 0 1.9-.7 3.7-1.9 5.2L553.5 514l130 155c1.2 1.5 1.9 3.3 1.9 5.2 0 4.4-3.6 8-8 8z"/>',
			// 實心圓 + 驚嘆號（對應 antd ExclamationCircleFilled）
			'exclamation-circle'   => '<path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm-32 232c0-4.4 3.6-8 8-8h48c4.4 0 8 3.6 8 8v272c0 4.4-3.6 8-8 8h-48c-4.4 0-8-3.6-8-8V296zm32 440a48.01 48.01 0 0 1 0-96 48.01 48.01 0 0 1 0 96z"/>',
			// 火焰（對應 antd FireFilled）
			'fire'                 => '<path d="M834.1 469.2A347.49 347.49 0 0 0 751.2 354l-29.1-26.7a8.09 8.09 0 0 0-13 3.3l-13 37.3c-8.1 23.4-23 47.3-44.1 70.8-1.4 1.5-3 1.9-4.1 2-1.1.1-2.8-.1-4.3-1.5-1.4-1.2-2.1-3-2-4.8 3.7-60.2-14.3-128.1-53.7-202C555.3 171 510 123.1 453.4 89.7l-41.3-24.3c-5.4-3.2-12.3 1-12 7.3l2.2 48c1.5 32.8-2.3 61.8-11.3 85.9-11 29.5-26.8 56.9-47 81.5a295.64 295.64 0 0 1-47.5 46.1 352.6 352.6 0 0 0-100.3 121.5A347.75 347.75 0 0 0 160 610c0 47.2 9.3 92.9 27.7 136a349.4 349.4 0 0 0 75.5 111c32.4 32 70 57.2 111.9 74.7C418.5 949.8 464.5 959 512 959s93.5-9.2 136.9-27.3A348.6 348.6 0 0 0 760.8 857c32.4-32 57.8-69.4 75.5-111a344.2 344.2 0 0 0 27.7-136c0-48.8-10-96.2-29.9-140.8z"/>',
			// 雙向箭頭：展開（對應 antd ArrowsAltOutlined）
			'arrows-alt'           => '<path d="M855 160.1l-189.2 23.5c-6.6.8-9.3 8.8-4.7 13.5l54.7 54.7-153.5 153.5a8.03 8.03 0 0 0 0 11.3l45.1 45.1c3.1 3.1 8.2 3.1 11.3 0l153.6-153.6 54.7 54.7a7.94 7.94 0 0 0 13.5-4.7L863.9 169a7.9 7.9 0 0 0-8.9-8.9zM416.6 562.3a8.03 8.03 0 0 0-11.3 0L251.8 715.9l-54.7-54.7a7.94 7.94 0 0 0-13.5 4.7L160.1 855c-.6 5.2 3.7 9.5 8.9 8.9l189.2-23.5c6.6-.8 9.3-8.8 4.7-13.5l-54.7-54.7 153.6-153.6c3.1-3.1 3.1-8.2 0-11.3l-45.2-45z"/>',
			// 內縮箭頭：收合（對應 antd ShrinkOutlined）
			'shrink'               => '<path d="M881.7 187.4l-45.1-45.1a8.03 8.03 0 0 0-11.3 0L667.8 299.9l-54.7-54.7a7.94 7.94 0 0 0-13.5 4.7L576.1 439c-.6 5.2 3.7 9.5 8.9 8.9l189.2-23.5c6.6-.8 9.3-8.8 4.7-13.5l-54.7-54.7 157.6-157.6c3.1-3 3.1-8.1-.1-11.2zM439 576.1l-189.2 23.5c-6.6.8-9.3 8.9-4.7 13.5l54.7 54.7-157.5 157.5a8.03 8.03 0 0 0 0 11.3l45.1 45.1c3.1 3.1 8.2 3.1 11.3 0l157.6-157.6 54.7 54.7a7.94 7.94 0 0 0 13.5-4.7L447.9 585a7.9 7.9 0 0 0-8.9-8.9z"/>',
		];

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		$extra = '';
		foreach ( $attrs as $attr_name => $attr_value ) {
			$extra .= \sprintf( ' %s="%s"', \esc_attr( (string) $attr_name ), \esc_attr( (string) $attr_value ) );
		}

		return \sprintf(
			'<svg class="%1$s"%2$s viewBox="0 0 1024 1024" fill="currentColor" aria-hidden="true" focusable="false">%3$s</svg>',
			\esc_attr( $class ),
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上方迴圈已逐一 esc_attr
			$paths[ $name ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 白名單常數，非使用者輸入
		);
	}

	/**
	 * 前端 JS 需要的 i18n 文案
	 *
	 * JS 在使用者選變體之後會重繪庫存 / 購買人數 / 屬性狀態，那些字串必須與 PHP
	 * 首次印出的完全一致，否則同一顆 Modal 在互動前後會出現兩種文案。
	 *
	 * @return array<string, string>
	 */
	public static function js_i18n(): array {
		return [
			'currencySymbol' => self::CURRENCY_SYMBOL,
			'unitSuffix'     => \__( '件', 'power_shop' ),
			'soldOut'        => \__( '此商品已售完', 'power_shop' ),
			'inStock'        => \__( '此商品還有庫存', 'power_shop' ),
			'lastFew'        => \__( '此商品只剩最後', 'power_shop' ),
			'remaining'      => \__( '此商品剩餘', 'power_shop' ),
			'backorder'      => \__( '此商品為延期交貨商品', 'power_shop' ),
			'attrSelected'   => \__( '已選擇商品屬性', 'power_shop' ),
			'attrUnselected' => \__( '未選擇商品屬性', 'power_shop' ),
			'buyerPrefix'    => \__( '已有', 'power_shop' ),
			'buyerSuffix'    => \__( '人購買', 'power_shop' ),
			'expandAll'      => \__( '展開全部內容', 'power_shop' ),
			'collapseAll'    => \__( '收合全部內容', 'power_shop' ),
			'addToCartSuccess'  => \__( '已加入購物車', 'power_shop' ),
			'addToCartFailed'   => \__( '加入購物車失敗，請稍後再試', 'power_shop' ),
			'addToCartNetwork'  => \__( '連線發生問題，請確認購物車內容後再試一次', 'power_shop' ),
			'addToCartUnknown'  => \__( '無法確認加購結果，請重新整理頁面並查看購物車', 'power_shop' ),
		];
	}

	/**
	 * 前端 JS 需要的站台層級設定（AJAX 加購用）
	 *
	 * ⚠ `addToCartUrl` 指向 **WooCommerce 原生**的 `?wc-ajax=add_to_cart`，本外掛沒有、
	 * 也不該有自己的加購 endpoint（Phase 11 的 `FrontCartApi` 已於 Phase 12 刪檔，不要復活）。
	 * 選這條通道的關鍵理由：它吃 `application/x-www-form-urlencoded`，PHP 會正常填 `$_POST`，
	 * `AddToCartHook`（priority 5）因此讀得到 `profit_shop_id`、三道閘門照常跑。
	 * 改送 JSON body 會讓 `$_POST` 全空 → 分潤靜默失效且無任何錯誤訊息。
	 *
	 * `redirectAfterAdd` 對應站台選項 `woocommerce_cart_redirect_after_add`：為 `yes` 時
	 * WooCommerce 會在成功路徑寫一則 session notice（class-wc-ajax.php 的
	 * `wc_add_to_cart_message()`）。我們若停在原頁不跳轉，那則 notice 會在使用者
	 * **下次載入任何頁面時遲到跳出**，因此這個選項為真時前端改為直接導向購物車，與原生一致。
	 *
	 * 空字串代表「這項不可用」（例如 WooCommerce 未啟用），JS 端會據此整個放棄 AJAX、
	 * 退回 PHP 印出的原生表單送出。
	 *
	 * @return array<string, string>
	 */
	public static function js_config(): array {
		return [
			'addToCartUrl'     => \class_exists( '\WC_AJAX' ) ? (string) \WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
			'cartUrl'          => \function_exists( 'wc_get_cart_url' ) ? (string) \wc_get_cart_url() : '',
			'redirectAfterAdd' => ( 'yes' === \get_option( 'woocommerce_cart_redirect_after_add' ) ) ? '1' : '',
		];
	}
}
