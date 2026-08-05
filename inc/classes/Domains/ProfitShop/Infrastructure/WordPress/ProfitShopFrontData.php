<?php
/**
 * 賣場前台 React bundle 的資料組裝器
 *
 * Phase 10：賣場前台 UI 照搬 legacy 一頁商店。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;

/**
 * 把 ProfitShop 聚合根 + WooCommerce 商品資料組成前台 bundle 需要的 payload
 *
 * 對應 legacy `J7\PowerShopV2\Functions::get_products_info()` /
 * `get_product_data()`——欄位名稱刻意與 legacy 完全一致（camelCase 的
 * `regularPrice` / `salesPrice` / `shortDescription` / `extraBuyerCount`
 * 等），這樣 React 元件可以 1:1 照搬舊版而不需要改任何欄位對映。
 *
 * 效能：每個 item 呼叫 `wc_get_product()` 一次（variable 商品會再取
 * `get_available_variations()`，WC 內部已對變體批次 prime cache）。圖片查詢
 * 另做一次性 `_prime_post_caches()` 批次預熱（見 `build_products()`），避免
 * 「商品數 × 圖片數」次 DB 往返。賣場商品數前端軟上限為 500，實務上通常遠低
 * 於此；若未來要支援更大規模，應改為分批 lazy load（見 specs/tech-debt.md）。
 *
 * 安全：本 payload 只含前台本來就公開可見的商品資訊（名稱／描述／圖片／
 * 庫存／價格），不含任何 partner 或分潤設定；序列化後由
 * `wp_localize_script()` 輸出，escape 由 WP 負責。
 */
final class ProfitShopFrontData {

	/**
	 * 主色預設值（對齊 legacy `btnColor` 的預設）
	 *
	 * ⚠ **前台已不再消費這個值**（Phase 14）。賣場前台的顏色一律跟隨 powerhouse 的
	 * daisyUI theme（`pc-btn-primary` / `text-error` / `bg-base-200` 等語意色），
	 * 不再由賣場設定的 `btnColor` 決定，也不再印出 `--ps-primary` 這個 CSS 變數。
	 *
	 * 欄位刻意保留而非刪除：
	 *   1. wire 契約（`ProfitShop::$settings`）維持穩定，既有站台存的值不會變成孤兒
	 *   2. `to_color()` 的 hex 白名單本身是有效的輸入驗證，刪掉等於少一道防線
	 *   3. 日後若要支援「每個賣場各自的 daisyUI theme」，這個欄位是天然的載體
	 *      （daisyUI 支援 `data-theme` 巢狀覆蓋）
	 *
	 * 在那之前它是**沒有消費者的設定值**，已登記技術債；不要因為「看起來該用」
	 * 就把它接回前台顏色——那會讓賣場顏色脫離站台 theme，與需求相反。
	 */
	public const DEFAULT_COLOR_PRIMARY = '#1677ff';

	/**
	 * 非販售期間可顯示的區塊：倒數計時盤
	 */
	public const PART_COUNTDOWN = 'countdown';

	/**
	 * 非販售期間可顯示的區塊：站長自訂文字
	 */
	public const PART_TEXT = 'text';

	/**
	 * 非販售期間可顯示的區塊：商品列表（按鈕停用、不可購買）
	 */
	public const PART_PRODUCTS = 'products';

	/**
	 * 呈現區塊預設值
	 *
	 * ⚠ **必須是 `[products]`**：這個設定是後加的，既有賣場的 `settings` 裡沒有
	 * `beforeDisplay` / `afterDisplay` 這兩個 key。只顯示商品（按鈕停用）正是本功能
	 * 上線前的既有行為，選它才能讓所有既有賣場的前台外觀零變化。預設成空陣列
	 * 會讓全站已排程的賣場在時間外突然變空白。
	 *
	 * ⚠ 與**空陣列**（`[]`，站長刻意全部取消勾選＝什麼都不顯示）是兩件事，
	 * 正規化時必須用 `is_array()` 而不是 `empty()` 區分「沒設定」與「設定為空」。
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_DISPLAY_PARTS = [ self::PART_PRODUCTS ];

	/**
	 * 自訂文字長度上限（字元）
	 *
	 * 這段文字由站長輸入、直接印進前台（`esc_html`）。設上限不是為了防 XSS
	 * （escape 才是），而是防止一段失控的長字串把版面撐爛。
	 */
	public const TEXT_MAX_LENGTH = 200;

	/**
	 * 合法的呈現區塊白名單（順序即輸出順序，讓序列化結果穩定）
	 *
	 * @var array<int, string>
	 */
	private const DISPLAY_PARTS = [
		self::PART_COUNTDOWN,
		self::PART_TEXT,
		self::PART_PRODUCTS,
	];

	/**
	 * 前台顯示設定（供 template 與 localize 共用）
	 *
	 * 刻意獨立成 public：template 即使在 React bundle 不可用時也需要
	 * `shopStatus` 來決定按鈕是否 disabled，而 `build()` 會連帶組出昂貴的
	 * products payload，不該為了讀幾個 flag 付那個代價。
	 *
	 * 資料來源是 `ProfitShop::$settings`（persist 於 `_profit_shop_settings`
	 * post meta，端到端已通 Repository / Hydrator / DTO / V2Api），欄位名與
	 * legacy 的 `power_shop_settings` 完全對齊，缺值時 fallback 到 legacy 預設。
	 *
	 * ## 排程呈現（`beforeDisplay` / `afterDisplay` → `showCountdown` / `showDisplayText` / `showProducts`）
	 *
	 * `beforeDisplay` / `afterDisplay` 是站長設定的**原始意圖**：一組**互相獨立**的
	 * 呈現區塊（`countdown` / `text` / `products`），對應後台三個各自獨立的勾選框，
	 * 全部不勾就是「什麼都不顯示」。`showCountdown` / `showDisplayText` /
	 * `showProducts` / `displayText` / `countdownTarget` 是把它們與當下的
	 * `shopStatus` 合併後的**結論**，template 只該讀後者。
	 *
	 * 這個分工是刻意的：`shopStatus` 有三態、呈現設定有兩組，若讓 template 自己
	 * 配對，兩條渲染路徑（獨立頁面 / shortcode）就各有一份配對邏輯要維持一致——
	 * 這個 codebase 已經因為「靠人維持兩份邏輯逐字一致」吃過虧（見
	 * `.claude/rules/profit-shop.rule.md` §7 的 `OverridePriceResolver`）。
	 *
	 * @param ProfitShop $shop 賣場聚合根。
	 *
	 * @return array{colorPrimary: string, showConfetti: bool, showStock: bool, showBuyerCount: bool, startTime: int, endTime: int, shopStatus: string, beforeDisplay: array<int, string>, beforeText: string, afterDisplay: array<int, string>, afterText: string, showCountdown: bool, showDisplayText: bool, displayText: string, showProducts: bool, countdownTarget: int}
	 */
	public static function settings( ProfitShop $shop ): array {
		$raw = $shop->settings;

		$start_time = self::to_timestamp_ms( $raw['startTime'] ?? null );
		$end_time   = self::to_timestamp_ms( $raw['endTime'] ?? null );
		$status     = self::resolve_shop_status( $start_time, $end_time );

		$before_display = self::to_display_parts( $raw['beforeDisplay'] ?? null );
		$before_text    = self::to_text( $raw['beforeText'] ?? null );
		$after_display  = self::to_display_parts( $raw['afterDisplay'] ?? null );
		$after_text     = self::to_text( $raw['afterText'] ?? null );

		$is_published = ( 'published' === $status );

		// 販售中不受排程呈現設定影響：一律只顯示商品、不顯示倒數與排程文字。
		if ( $is_published ) {
			$active_parts = self::DEFAULT_DISPLAY_PARTS;
			$active_text  = '';
		} elseif ( 'coming' === $status ) {
			$active_parts = $before_display;
			$active_text  = $before_text;
		} else {
			$active_parts = $after_display;
			$active_text  = $after_text;
		}

		$show_text = \in_array( self::PART_TEXT, $active_parts, true ) && '' !== $active_text;

		return [
			'colorPrimary'    => self::to_color( $raw['btnColor'] ?? null ),
			'showConfetti'    => self::to_bool( $raw['showConfetti'] ?? null ),
			'showStock'       => self::to_bool( $raw['showStock'] ?? null ),
			'showBuyerCount'  => self::to_bool( $raw['showBuyerCount'] ?? null ),
			'startTime'       => $start_time,
			'endTime'         => $end_time,
			'shopStatus'      => $status,
			'beforeDisplay'   => $before_display,
			'beforeText'      => $before_text,
			'afterDisplay'    => $after_display,
			'afterText'       => $after_text,
			'showCountdown'   => \in_array( self::PART_COUNTDOWN, $active_parts, true ),

			// 勾了「顯示自訂文字」但文字是空的 → 不印（否則會多出一個空的 <p>）。
			'showDisplayText' => $show_text,
			'displayText'     => $show_text ? $active_text : '',
			'showProducts'    => \in_array( self::PART_PRODUCTS, $active_parts, true ),
			'countdownTarget' => ( 'coming' === $status ) ? $start_time : 0,
		];
	}

	/**
	 * 呈現區塊正規化（白名單過濾，缺 key / 壞型別退回預設）
	 *
	 * 三個區塊互相獨立，因此值是**陣列**而非單一字串。
	 *
	 * ⚠ **空陣列是合法值**（站長把三個勾選框全部取消＝什麼都不顯示），
	 * 與「這個 key 根本不存在」（既有賣場，應退回預設的「只顯示商品」）是兩件事。
	 * 判斷一定要用 `is_array()`，**不可**用 `empty()`——`empty([])` 為 true，
	 * 會讓「刻意全部取消勾選」被靜默還原成「顯示商品」，站長改了設定卻沒有反應。
	 *
	 * 白名單過濾而非原樣帶出：這個值決定「商品要不要印出來」，是實質的可見性開關。
	 * 未知字串一律丟掉，`in_array()` 的判斷因此只會落在三個已知區塊上。
	 *
	 * @param mixed $value 原始值。
	 *
	 * @return array<int, string>
	 */
	private static function to_display_parts( $value ): array {
		/*
		* 過渡相容：本設定最初的版本是單選字串（`hidden` / `countdown` / `browse`），
		* 改成多選之後仍可能在開發中的資料庫裡遇到舊格式。對映成等價的區塊組合，
		* 而不是丟掉——後者會讓站長已經設好的倒數計時安靜消失。
		*/
		if ( \is_string( $value ) ) {
			switch ( $value ) {
				case 'hidden':
					return [];
				case 'countdown':
					return [ self::PART_COUNTDOWN, self::PART_TEXT ];
				case 'browse':
					return self::DEFAULT_DISPLAY_PARTS;
			}

			return self::DEFAULT_DISPLAY_PARTS;
		}

		if ( ! \is_array( $value ) ) {
			return self::DEFAULT_DISPLAY_PARTS;
		}

		// 依常數順序輸出，序列化結果才穩定（前端送來的順序不影響 DB 內容）。
		$parts = [];
		foreach ( self::DISPLAY_PARTS as $part ) {
			if ( \in_array( $part, $value, true ) ) {
				$parts[] = $part;
			}
		}

		return $parts;
	}

	/**
	 * 自訂文字正規化（非字串 → 空字串；截斷至上限）
	 *
	 * 不在此處 escape——輸出時機在 template，escape 必須發生在輸出點
	 * （`esc_html`），提前 escape 會讓長度計算與實際顯示對不上。
	 *
	 * @param mixed $value 原始值。
	 *
	 * @return string
	 */
	private static function to_text( $value ): string {
		if ( ! \is_string( $value ) && ! \is_numeric( $value ) ) {
			return '';
		}

		$text = \trim( (string) $value );
		if ( '' === $text ) {
			return '';
		}

		// 以 codepoint 計算，避免中文被從中間切成半個字（mb_substr 對無效位元組安全）。
		if ( \function_exists( 'mb_substr' ) ) {
			return (string) \mb_substr( $text, 0, self::TEXT_MAX_LENGTH, 'UTF-8' );
		}

		return \substr( $text, 0, self::TEXT_MAX_LENGTH );
	}

	/**
	 * 依起訖時間決定賣場販售狀態
	 *
	 * 照搬 legacy `PowerShopProducts/index.tsx` 的 `getStatus()`。在 PHP 端
	 * 算完再送前端，避免瀏覽器時鐘偏移造成「後台已開賣、前端仍顯示未開始」。
	 *
	 * @param int $start_time 開賣時間（毫秒；0 表示未設定）。
	 * @param int $end_time   結束時間（毫秒；0 表示未設定）。
	 *
	 * @return string 'published' | 'coming' | 'closed'
	 */
	private static function resolve_shop_status( int $start_time, int $end_time ): string {
		$now = (int) ( \time() * 1000 );

		if ( 0 === $start_time && 0 === $end_time ) {
			return 'published';
		}

		if ( 0 === $start_time ) {
			return $now < $end_time ? 'published' : 'closed';
		}

		if ( 0 === $end_time ) {
			return $now > $start_time ? 'published' : 'coming';
		}

		if ( $now < $start_time ) {
			return 'coming';
		}

		if ( $now > $end_time ) {
			return 'closed';
		}

		return 'published';
	}

	/**
	 * 毫秒時間戳正規化（非數字 / 負數 → 0 表示未設定）
	 *
	 * @param mixed $value 原始值。
	 *
	 * @return int
	 */
	private static function to_timestamp_ms( $value ): int {
		if ( ! \is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	/**
	 * 布林旗標正規化（未設定 → true，與 legacy 預設一致）
	 *
	 * @param mixed $value 原始值。
	 *
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( null === $value ) {
			return true;
		}

		return (bool) $value;
	}

	/**
	 * 主色正規化（只接受 #RGB / #RRGGBB，否則回預設）
	 *
	 * 白名單而非 escape：這個值**曾經**被直接印進 template 的 inline `<style>`
	 * （`--ps-primary`），是實打實的 CSS injection 面。Phase 14 移除了那段 inline
	 * `<style>`（顏色改跟隨 daisyUI theme），但白名單刻意保留——一旦日後有人把這個值
	 * 接回任何樣式輸出，防線必須已經在原地，而不是那時才想起要加。
	 *
	 * @param mixed $value 原始值。
	 *
	 * @return string
	 */
	private static function to_color( $value ): string {
		if ( \is_string( $value ) && 1 === \preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
			return $value;
		}

		return self::DEFAULT_COLOR_PRIMARY;
	}

	/**
	 * 商品清單
	 *
	 * 保序：依 `$shop->items()` 的陣列順序輸出（賣場商品排序是 business
	 * data，見 .claude/rules/profit-shop.rule.md §7）。查不到的商品會被
	 * 跳過而非留下空洞。
	 *
	 * Phase 14 起改為 **public**：賣場前台 template 的商品卡片與商品 Modal 都直接
	 * 消費這份資料。早期卡片自己跑一次 `wc_get_product()` + 過濾條件、Modal 的資料
	 * 另外由本方法組一次，兩邊的「非 publish 商品要跳過」必須靠人維持同步——
	 * 不同步就會出現「有卡片但 Modal 找不到商品」的破 Modal。收斂成單一來源後，
	 * 那種分歧在結構上不可能發生。
	 *
	 * ⚠ `env`（`build()` / `build_env()`）已隨 React bundle 一併移除：前台不再有
	 * `wp_localize_script` 的全域 payload，賣場 ID 由 PHP 直接印進加購表單，
	 * 商品資料由 PHP 直接印成 HTML。留著沒有消費者的組裝方法，正是
	 * `.claude/rules/profit-shop.rule.md` §7 警告的「存得進去、讀得出來、沒人用」反模式。
	 *
	 * @param ProfitShop $shop 賣場聚合根。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_products( ProfitShop $shop ): array {
		if ( ! \function_exists( 'wc_get_product' ) ) {
			return [];
		}

		/*
		* 兩輪處理，為的是把圖片查詢批次化。
		*
		* 單輪寫法下每張圖的 `wp_get_attachment_image_url()` 都是一次
		* `get_post` + 一次 postmeta 查詢——50 商品 × 5 張圖 = 250 次往返。
		* 先收集全部 attachment id 一次性 prime object cache，第二輪就全部命中。
		*
		* `wc_get_product()` 只呼叫一次（物件在第一輪就留著），沒有重複載入成本。
		*/
		$loaded         = [];
		$attachment_ids = [];

		foreach ( $shop->items() as $item ) {
			$product = \wc_get_product( $item->product_id );
			if ( ! $product ) {
				continue;
			}

			// 與 template 的卡片渲染條件一致：非 publish 的商品不進賣場。
			// 兩邊必須同步，否則會出現「有卡片但 payload 找不到商品」的破 Modal。
			if ( 'publish' !== $product->get_status() ) {
				continue;
			}

			$loaded[]       = [ $item, $product ];
			$attachment_ids = array_merge( $attachment_ids, self::collect_image_ids( $product ) );
		}

		if ( [] !== $attachment_ids && \function_exists( '_prime_post_caches' ) ) {
			\_prime_post_caches( array_unique( $attachment_ids ), false, true );
		}

		$products = [];
		foreach ( $loaded as [ $item, $product ] ) {
			$products[] = self::build_product( $item, $product );
		}

		return $products;
	}

	/**
	 * 收集商品的主圖 + gallery attachment ID（用於批次 prime cache）
	 *
	 * @param \WC_Product $product WooCommerce 商品。
	 *
	 * @return array<int, int>
	 */
	private static function collect_image_ids( \WC_Product $product ): array {
		$ids = [];
		foreach ( array_merge( [ $product->get_image_id() ], $product->get_gallery_image_ids() ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * 單一商品資料
	 *
	 * @param OverrideItem $item    賣場內的商品覆寫項目。
	 * @param \WC_Product  $product 已載入的 WooCommerce 商品（由 build_products 傳入，避免重複載入）。
	 *
	 * @return array<string, mixed>
	 */
	private static function build_product( OverrideItem $item, \WC_Product $product ): array {
		$type = (string) $product->get_type();

		$data = [
			'id'                   => $item->product_id,
			'type'                 => $type,
			'name'                 => (string) $product->get_name(),
			'description'          => \wpautop( (string) $product->get_description() ),
			'shortDescription'     => \wpautop( (string) $product->get_short_description() ),
			'images'               => self::build_images( $product ),
			'sku'                  => (string) $product->get_sku(),
			'stock'                => self::build_stock( $product ),
			'is_sold_individually' => (bool) $product->is_sold_individually(),
			'is_in_stock'          => (bool) $product->is_in_stock(),
			'is_purchasable'       => (bool) $product->is_purchasable(),
			'backorders'           => (string) $product->get_backorders(),
			'total_sales'          => (int) $product->get_total_sales(),
			'permalink'            => (string) \get_permalink( $item->product_id ),
			// 灌水購買人數：新版 Domain 的 InflatedCount 對應 legacy 的 extraBuyerCount。
			'extraBuyerCount'      => $item->inflated_count->value(),
			'regularPrice'         => self::resolve_price(
				$item->override->regular_price,
				(string) $product->get_regular_price()
			),
			'salesPrice'           => self::resolve_price(
				$item->override->sale_price,
				(string) $product->get_sale_price()
			),
		];

		if ( 'variable' === $type ) {
			$data['variation_attributes'] = $product->get_variation_attributes();
			$data['variations']           = self::build_variations( $item, $product );
		}

		return $data;
	}

	/**
	 * 主圖 + gallery 圖片 URL 清單（主圖固定在索引 0）
	 *
	 * 用 `large` 尺寸而非 legacy 的 `wp_get_attachment_url()`（原圖）：Modal
	 * 左欄實際寬度約 384px，載入 3000px 原圖純屬浪費頻寬；若原圖小於 large，
	 * WordPress 會自動回退成原圖，因此視覺上不會有任何差異。
	 *
	 * @param \WC_Product $product WooCommerce 商品物件。
	 *
	 * @return array<int, string>
	 */
	private static function build_images( \WC_Product $product ): array {
		$images = [];
		foreach ( self::collect_image_ids( $product ) as $attachment_id ) {
			$url = \wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( \is_string( $url ) && '' !== $url ) {
				$images[] = $url;
			}
		}

		return $images;
	}

	/**
	 * 庫存資訊（欄位名對齊 legacy TStockInfo）
	 *
	 * @param \WC_Product $product WooCommerce 商品或變體物件。
	 *
	 * @return array{manageStock: bool, stockQuantity: int|null, stockStatus: string}
	 */
	private static function build_stock( \WC_Product $product ): array {
		$qty = $product->get_stock_quantity();

		return [
			'manageStock'   => (bool) $product->get_manage_stock(),
			'stockQuantity' => null === $qty ? null : (int) $qty,
			'stockStatus'   => (string) $product->get_stock_status(),
		];
	}

	/**
	 * 變體清單
	 *
	 * 保留 WooCommerce `get_available_variations()` 的原始欄位（image /
	 * max_qty / price_html 等），再疊上賣場覆寫價與庫存，行為與 legacy
	 * `Functions::get_product_data()` 相同。
	 *
	 * 已知落差：legacy 支援 per-variation 的灌水購買人數，新版 Domain 的
	 * `OverrideItem::$variations` 只存 PriceOverride，故各變體一律沿用
	 * 商品層級的 inflated_count（見 specs/tech-debt.md）。
	 *
	 * @param OverrideItem $item    賣場內的商品覆寫項目。
	 * @param \WC_Product  $product 父商品（variable）。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_variations( OverrideItem $item, \WC_Product $product ): array {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return [];
		}

		$variations = [];
		foreach ( $product->get_available_variations() as $variation ) {
			$variation_id = (int) ( $variation['variation_id'] ?? 0 );
			if ( $variation_id <= 0 ) {
				continue;
			}

			$variation_product = \wc_get_product( $variation_id );
			if ( ! $variation_product ) {
				continue;
			}

			$override = $item->get_variation_override( $variation_id );

			$variation['regularPrice']    = self::resolve_price(
				null === $override ? null : $override->regular_price,
				(string) $variation_product->get_regular_price()
			);
			$variation['salesPrice']      = self::resolve_price(
				null === $override ? null : $override->sale_price,
				(string) $variation_product->get_sale_price()
			);
			$variation['stock']           = self::build_stock( $variation_product );
			$variation['extraBuyerCount'] = $item->inflated_count->value();

			$variations[] = $variation;
		}

		return $variations;
	}

	/**
	 * 覆寫價優先，否則沿用商品自身價格
	 *
	 * 回傳空字串（而非 null）時，前台 Price 元件會視為「沒有這個價格」，
	 * 與 legacy 對空 `salesPrice` 的處理一致。
	 *
	 * @param string|null $override_price 賣場覆寫價（null 表示不覆寫）。
	 * @param string      $original_price 商品自身價格。
	 *
	 * @return string
	 */
	private static function resolve_price( ?string $override_price, string $original_price ): string {
		if ( null !== $override_price && '' !== $override_price ) {
			return $override_price;
		}

		return $original_price;
	}
}
