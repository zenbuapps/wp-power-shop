<?php
/**
 * 賣場前台 React bundle 的資料組裝器
 *
 * Phase 9：賣場前台 UI 照搬 legacy 一頁商店。
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
	 * 同時供 template 印出 CSS 變數 `--ps-primary`（`.ps-btn-primary` 的背景色）
	 * 與 React `ConfigProvider` 的 `colorPrimary`，兩邊必須是同一個值。
	 */
	public const DEFAULT_COLOR_PRIMARY = '#1677ff';

	/**
	 * 組出前台 bundle 的完整 payload
	 *
	 * @param ProfitShop $shop 已驗證的賣場聚合根。
	 *
	 * @return array{env: array<string, mixed>, settings: array<string, mixed>, products: array<int, array<string, mixed>>}
	 */
	public static function build( ProfitShop $shop ): array {
		return [
			'env'      => self::build_env( $shop ),
			'settings' => self::settings( $shop ),
			'products' => self::build_products( $shop ),
		];
	}

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
	 * 這樣後台一旦補上設定 UI，前台無需再改一行就會生效。
	 *
	 * @param ProfitShop $shop 賣場聚合根。
	 *
	 * @return array{colorPrimary: string, showConfetti: bool, showStock: bool, showBuyerCount: bool, startTime: int, endTime: int, shopStatus: string}
	 */
	public static function settings( ProfitShop $shop ): array {
		$raw = $shop->settings;

		$start_time = self::to_timestamp_ms( $raw['startTime'] ?? null );
		$end_time   = self::to_timestamp_ms( $raw['endTime'] ?? null );

		return [
			'colorPrimary'   => self::to_color( $raw['btnColor'] ?? null ),
			'showConfetti'   => self::to_bool( $raw['showConfetti'] ?? null ),
			'showStock'      => self::to_bool( $raw['showStock'] ?? null ),
			'showBuyerCount' => self::to_bool( $raw['showBuyerCount'] ?? null ),
			'startTime'      => $start_time,
			'endTime'        => $end_time,
			'shopStatus'     => self::resolve_shop_status( $start_time, $end_time ),
		];
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
	 * 這個值會直接進 CSS 變數與 inline `<style>`，因此用白名單而非 escape，
	 * 避免任何 CSS injection 的可能。
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
	 * 環境資訊
	 *
	 * Phase 10：前台購物車改走本外掛自訂 REST（`FrontCartApi`），因此
	 *   - `nonce` 是 WordPress 的 `wp_rest` nonce（前端以 `X-WP-Nonce` 送出），
	 *     不再是 admin-ajax 的自訂 action nonce
	 *   - 新增 `cartApiUrl`（購物車 endpoint base，已去尾斜線）
	 *   - 移除 `ajaxUrl`（admin-ajax 已無任何消費者）
	 *   - 移除 `restUrl`（WC Store API 已無任何消費者；購物車一律走 `cartApiUrl`）
	 *
	 * ⚠ 本陣列的欄位必須與前端 `js/src/profit-shop-front/types.ts` 的 `TFrontEnv`
	 * **完全一致**。多送的欄位在 TypeScript 端不會有任何編譯錯誤，只會變成沒人讀的
	 * payload，日後容易被誤認為「還有人在用」而不敢刪。
	 *
	 * @param ProfitShop $shop 賣場聚合根。
	 *
	 * @return array{siteUrl: string, checkoutUrl: string, cartApiUrl: string, shopId: int, shopSlug: string, nonce: string}
	 */
	private static function build_env( ProfitShop $shop ): array {
		$checkout_url = \function_exists( 'wc_get_checkout_url' ) ? (string) \wc_get_checkout_url() : '';

		return [
			'siteUrl'     => \untrailingslashit( (string) \site_url() ),
			'checkoutUrl' => $checkout_url,
			'cartApiUrl'  => \untrailingslashit( \esc_url_raw( \rest_url( 'power-shop/profit-shop-cart' ) ) ),
			'shopId'      => $shop->id,
			'shopSlug'    => $shop->slug,
			'nonce'       => \wp_create_nonce( 'wp_rest' ),
		];
	}

	/**
	 * 商品清單
	 *
	 * 保序：依 `$shop->items()` 的陣列順序輸出（賣場商品排序是 business
	 * data，見 .claude/rules/profit-shop.rule.md §7）。查不到的商品會被
	 * 跳過而非留下空洞。
	 *
	 * @param ProfitShop $shop 賣場聚合根。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_products( ProfitShop $shop ): array {
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
