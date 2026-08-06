<?php
/**
 * Phase 9：WC 單品頁套用分潤賣場覆寫價（含變體）
 *
 * 對應規格：specs/plan/2026-07-30-profit-shop-variation-override-plan.md 第六-B 階段（R-1）
 *
 * 解決的問題：
 *   賣場前台卡片顯示 override 價 → 顧客點「前往選購」進 WC 單品頁 →
 *   單品頁顯示「商品原價」→ 加入購物車後又變回 override 價。
 *   這個「價格跳動」在開放變體覆寫 UI 之後會變成顧客可見的信任問題。
 *
 * 作用範圍（刻意收斂）：
 *   只在 `is_product()` 為真、且網址帶 `?profit_shop_id={id}` 的單品頁請求註冊 filter。
 *   購物車 / 結帳 / 商品列表頁一律不掛，避免干擾 CartPriceOverrideHook 的簽章與
 *   驗章失敗時的 fallback 保護。
 *
 * 安全規則（沿用 AddToCartHook 的三道閘門）：
 *   1. shop 必須存在
 *   2. shop status = 'publish'（draft 預覽價不得外洩）
 *   3. 當前商品必須存在於 shop->items()
 *   任一條件失敗 → 完全不註冊 filter，單品頁走商品原價。
 *
 * ⚠️ 價格語意由 Domain\Service\OverridePriceResolver 統一提供，與 CartPriceOverrideHook
 *    共用同一份實作——兩邊各自複製一份 fallback chain 曾經導致「單品頁顯示覆寫原價、
 *    購物車劃線價卻是商品原始原價」的分歧，且沒有任何錯誤訊息。不要把解析邏輯搬回本類。
 *
 * ⚠️ 「哪些 filter、callback 怎麼把三元組餵回 WC」自 2026-08 起搬到
 *    OverridePriceFilters，本類只保留「判斷要不要掛」與 add_filter 清單。搬家的理由是
 *    賣場前台的 ProfitShopFrontData::build_variations() 也需要同一組 filter（但生命週期
 *    完全不同：用完就 disarm），共用實作才不會產生第二份取價邏輯。
 *
 *    本類刻意**保留**十個同名 public callback 與原本的 add_filter 清單（而非直接改用
 *    OverridePriceFilters::arm()）：SingleProductPriceHookTest 直接斷言
 *    `has_filter( 'woocommerce_product_get_price', [ $hook, 'filter_price' ] )`、直接呼叫
 *    `$hook->filter_variation_prices_hash(...)`，並在 unregister_filters() 維護一張
 *    hook↔callback 對照表。保留這層薄委派 ⇒ 那 11 個測試方法**零修改全綠**，就是
 *    「抽取沒有改變單品頁既有行為」的直接證據（同 Phase 13 用 git diff 為空證明
 *    OverridePriceResolver 重構無誤的手法）。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\OverridePriceResolver;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontData;

/**
 * 單品頁價格 override hook
 */
final class SingleProductPriceHook {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 覆寫價 filter 組（承載情境與全部 callback 實作）
	 *
	 * @var OverridePriceFilters
	 */
	private readonly OverridePriceFilters $filters;

	/**
	 * 建構子
	 *
	 * 掛 template_redirect priority 20：
	 * - 晚於 ProfitShopRenderer 的 9（賣場頁自己 exit，不會走到這裡）
	 * - 此時主查詢已解析完成，is_product() 才可靠
	 *
	 * @param ProfitShopRepositoryInterface $shops    賣場 Repository（DIP，禁鴨子型別）
	 * @param OverridePriceResolver|null    $resolver 價格解析器；null 時建立預設實例（純 Domain 物件、無狀態）
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shops,
		?OverridePriceResolver $resolver = null
	) {
		$this->filters = new OverridePriceFilters( $resolver ?? new OverridePriceResolver() );

		// admin context guard：純後台（非 ajax）不掛 hook，與其餘 WooCommerce hook 一致
		if ( \is_admin() && ! \wp_doing_ajax() ) {
			return;
		}

		\add_action( 'template_redirect', [ $this, 'maybe_register_filters' ], 20 );
	}

	/**
	 * 判斷當前請求是否為「帶 profit_shop_id 的單品頁」，是才註冊價格 filter
	 *
	 * @return void
	 */
	public function maybe_register_filters(): void {
		if ( ! \function_exists( 'is_product' ) || ! \is_product() ) {
			return;
		}

		$shop_id = $this->extract_shop_id_from_query();
		if ( $shop_id <= 0 ) {
			return;
		}

		$shop = $this->try_find_published_shop( $shop_id );
		if ( null === $shop ) {
			return;
		}

		/*
		* 🔴 N-1 / TD-57：販售期間閘門。
		*
		* 第 5 輪只把它補在 AddToCartHook，但本類是**同一個賣場情境的另一個入口**，
		* 而且對「自身無定價、只靠覆寫價出售」的商品，本類的 filter 直接決定
		* is_purchasable()（四道閘門的條件都收斂到 `'' !== get_price()`）。
		*
		* 缺這一行的實測後果（已結束販售的賣場、無定價變體、變體層覆寫 30/27）：
		*   (1) 單品頁 price='27' / purchasable=true ⇒ **加得進購物車**
		*   (2) AddToCartHook 的第三道閘門正確拒絕注入 ⇒ 零 _profit_* meta
		*       ⇒ 以 27 元成交但**無分潤歸屬**
		*   (3) 下一個請求被閘門 ③ 判定不可購買而移除 ⇒ 顧客看到剛加的東西不見了
		* 修法前無定價變體在任何地方都加不進購物車，所以這是第 5 輪**新產生**的路徑。
		*
		* 定義與 AddToCartHook / template 的 $is_shop_open **共用同一份實作**
		* （ProfitShopFrontData::is_sale_window_open）——各寫一份就會回到
		* 「畫面說關店、後端卻收單」。
		*
		* 連帶的使用者可見行為變更：期間外從單品頁看到的價格會從覆寫價變回商品原價，
		* 也就是「看到什麼 = 買得到什麼」。
		*
		* ⚠️ 刻意放在這裡而不是塞進 try_find_published_shop()：那個方法的名字只承諾
		* 「找到且 publish」，把時間判斷藏進去會讓方法名說謊——這個 codebase 已經因為
		* 「註解 / 命名與實作不符」吃過 TD-20 / TD-54 / TD-62 三次虧。
		*/
		if ( ! ProfitShopFrontData::is_sale_window_open( $shop ) ) {
			return;
		}

		$product_id = (int) \get_queried_object_id();
		if ( $product_id <= 0 || ! $shop->has_item( $product_id ) ) {
			return;
		}

		$item = $shop->items[ $product_id ] ?? null;
		if ( ! $item instanceof OverrideItem ) {
			return;
		}

		// 只設情境、不由 OverridePriceFilters 自己 add_filter：本類的 filter 清單刻意留在
		// 這裡（見檔頭說明——測試直接斷言 has_filter( …, [ $hook, 'filter_price' ] )）。
		$this->filters->set_context( $shop_id, $product_id, $item );

		// 一般商品 / 變體商品父層（view context 才會觸發，'edit' context 不受影響）
		\add_filter( 'woocommerce_product_get_price', [ $this, 'filter_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_get_regular_price', [ $this, 'filter_regular_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_get_sale_price', [ $this, 'filter_sale_price' ], 10, 2 );

		// 已選定的單一變體（get_available_variations 的 display_price 走這裡）
		\add_filter( 'woocommerce_product_variation_get_price', [ $this, 'filter_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_variation_get_regular_price', [ $this, 'filter_regular_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_variation_get_sale_price', [ $this, 'filter_sale_price' ], 10, 2 );

		// 變體價格區間（WC 以 'edit' context 讀值後才套這三個 filter，故必須另外掛）
		\add_filter( 'woocommerce_variation_prices_price', [ $this, 'filter_variation_prices_price' ], 10, 3 );
		\add_filter( 'woocommerce_variation_prices_regular_price', [ $this, 'filter_variation_prices_regular_price' ], 10, 3 );
		\add_filter( 'woocommerce_variation_prices_sale_price', [ $this, 'filter_variation_prices_sale_price' ], 10, 3 );

		// ⚠️ 關鍵：WC 把變體價格陣列存在 transient，hash 只含上面三個 filter 的「callback 名稱」，
		// 不含賣場 ID。不加這條的話「賣場 A」與「賣場 B」會算出同一個 hash → 互相讀到對方的
		// 快取價格。加入 shop_id 讓每個賣場各自一個快取分桶。
		\add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'filter_variation_prices_hash' ], 10, 3 );
	}

	// ========== Filter callbacks（全部委派 OverridePriceFilters） ==========
	//
	// 這十個方法故意留成薄委派而不是直接把 OverridePriceFilters 掛上去：
	// SingleProductPriceHookTest 以 [ $hook, 'filter_price' ] 這種 callable 形式做
	// has_filter 斷言與 remove_filter，改掉方法名或改由別的物件承接會讓那 11 個測試
	// 全部要改寫，也就失去「重構沒改變既有行為」的直接證據。

	/**
	 * 覆寫 get_price()（實際結帳價，與購物車一致）
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed 覆寫後的價格字串；未命中時原樣回傳
	 */
	public function filter_price( $value, $product ) {
		return $this->filters->filter_price( $value, $product );
	}

	/**
	 * 覆寫 get_regular_price()
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed
	 */
	public function filter_regular_price( $value, $product ) {
		return $this->filters->filter_regular_price( $value, $product );
	}

	/**
	 * 覆寫 get_sale_price()
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed
	 */
	public function filter_sale_price( $value, $product ) {
		return $this->filters->filter_sale_price( $value, $product );
	}

	/**
	 * 覆寫變體價格區間的 price
	 *
	 * @param mixed $value     原始值
	 * @param mixed $variation WC_Product_Variation 實例
	 * @param mixed $parent    父商品（未使用，WC 簽章要求）
	 *
	 * @return mixed
	 */
	public function filter_variation_prices_price( $value, $variation, $parent = null ) {
		return $this->filters->filter_variation_prices_price( $value, $variation, $parent );
	}

	/**
	 * 覆寫變體價格區間的 regular_price
	 *
	 * @param mixed $value     原始值
	 * @param mixed $variation WC_Product_Variation 實例
	 * @param mixed $parent    父商品（未使用，WC 簽章要求）
	 *
	 * @return mixed
	 */
	public function filter_variation_prices_regular_price( $value, $variation, $parent = null ) {
		return $this->filters->filter_variation_prices_regular_price( $value, $variation, $parent );
	}

	/**
	 * 覆寫變體價格區間的 sale_price
	 *
	 * @param mixed $value     原始值
	 * @param mixed $variation WC_Product_Variation 實例
	 * @param mixed $parent    父商品（未使用，WC 簽章要求）
	 *
	 * @return mixed
	 */
	public function filter_variation_prices_sale_price( $value, $variation, $parent = null ) {
		return $this->filters->filter_variation_prices_sale_price( $value, $variation, $parent );
	}

	/**
	 * 把 shop_id 併入 WC 變體價格快取的 hash
	 *
	 * 見上方註冊處的說明：不加這條會讓不同賣場共用同一份快取價格。
	 *
	 * @param mixed $price_hash  WC 組出的 hash 來源陣列
	 * @param mixed $product     父商品
	 * @param mixed $for_display 是否為顯示用（未使用，WC 簽章要求）
	 *
	 * @return mixed 併入 shop_id 後的 hash 來源
	 */
	public function filter_variation_prices_hash( $price_hash, $product, $for_display = false ) {
		return $this->filters->filter_variation_prices_hash( $price_hash, $product, $for_display );
	}

	// ========== Helpers ==========

	/**
	 * 從 $_GET 抽出 profit_shop_id
	 *
	 * 刻意只讀 $_GET：單品頁的分潤入口一律是賣場前台產生的連結（query string），
	 * 不讀 $_COOKIE / $_POST 以收斂攻擊面（與 AddToCartHook 同一條紀律）。
	 *
	 * @return int profit_shop_id；無或不合法回 0
	 */
	private function extract_shop_id_from_query(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['profit_shop_id'] ) || is_array( $_GET['profit_shop_id'] ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = \sanitize_text_field( \wp_unslash( $_GET['profit_shop_id'] ) );

		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			return 0;
		}

		$id = (int) $raw;
		return $id > 0 ? $id : 0;
	}

	/**
	 * 取得賣場並驗證 status
	 *
	 * @param int $shop_id 賣場 ID
	 *
	 * @return ProfitShop|null 找到且為 publish 才回傳；其餘一律 null
	 */
	private function try_find_published_shop( int $shop_id ): ?ProfitShop {
		try {
			$shop = $this->shops->find( $shop_id );
		} catch ( \Throwable $e ) {
			// 過濾 \r \n \t 防 log injection
			$safe_message = (string) preg_replace( "/[\r\n\t]/", ' ', $e->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				sprintf(
					'[ProfitShop][SingleProductPriceHook] find shop %d failed: %s',
					$shop_id,
					$safe_message
				)
			);
			return null;
		}

		if ( ! $shop instanceof ProfitShop ) {
			return null;
		}

		// draft 賣場的促銷預覽價不得洩漏給一般顧客
		if ( 'publish' !== $shop->status() ) {
			return null;
		}

		return $shop;
	}
}
