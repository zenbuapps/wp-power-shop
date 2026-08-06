<?php
/**
 * 前台 Cart 價格 Override Hook（Phase 3-D 最高風險元件；Phase 13 擴充 regular / sale）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3、§5、§6
 *          specs/plan/2026-08-03-cart-regular-price-override-plan.md（Phase 13）
 *
 * 安全性要點：
 *   - cart_item meta 攜帶 6 筆（見 META_KEYS_ALL）
 *   - signature 透過 CartPriceSignatureService（HMAC-SHA256 + wp_salt('auth')）驗證
 *   - 驗章失敗一律 fallback 至商品原始價格，並寫 error_log（避免 oracle，不告知前端）
 *   - priority 999 確保我方 set_price 晚於其他 plugin（不被覆蓋）
 *   - admin 純後台 context（非 ajax）不掛 hook，admin-ajax 仍掛（讓 add_to_cart_ajax 正常）
 *
 * ═══ Phase 13：為什麼不能只 set_price() ═══
 *
 * 本 hook 原本只呼叫 set_price()，於是 Cart Block / Store API 顯示的**劃線原價**讀到的是
 * 商品的**原始** regular_price（例如 1000），而不是賣場覆寫後的原價（800）——實收價正確、
 * 劃線價錯誤，且沒有任何錯誤訊息。成因是舊的 resolve_effective_price() 回傳型別是 ?string，
 * 結構上就把「原價覆寫」這個資訊丟掉了。
 *
 * 現在改為向 Domain\Service\OverridePriceResolver 取 {price, regular, sale} 三元組，
 * 與 SingleProductPriceHook（單品頁）共用同一份取價語意。
 *
 * ⚠️ regular 與 sale 的空字串**語意不同**，這是本檔最容易寫錯的地方：
 *   - regular === '' ⇒「不覆寫原價」⇒ **不呼叫** set_regular_price()，沿用商品原本的原價
 *   - sale    === '' ⇒「沒有特價」  ⇒ **必須呼叫** set_sale_price('') 清掉商品原本的特價
 *     （不清會出現「原價劃掉 → 特價」但實收卻是覆寫原價的矛盾）
 *
 * ═══ 2026-08：無定價商品的 per-cart-item 授權 ═══
 *
 * 商品／變體自身沒有價格、只靠賣場覆寫價出售時，WooCommerce 的 is_purchasable()
 * （條件含 `'' !== get_price()`）會在加購、每次載入購物車、Store API 三處擋下它。
 *
 * 🔴 授權粒度是「**這一筆通過 HMAC 驗章的 cart item**」，不是「這個 product ID」。
 *    完整推導（含四道閘門的原始碼位置、為什麼 wc_get_product() 的實例隔離性讓授權
 *    在結構上不可能外溢、以及為什麼不能改去騙 is_purchasable()）見建構子內的註解。
 *
 * ⚠️ 行為變更（刻意接受）：設了 regular/sale 之後 WC_Product::is_on_sale() 會變 true，
 *    而 WC_Discounts 用它判斷優惠券的 exclude_sale_items。所以站台若有「排除特價商品」的
 *    優惠券，分潤賣場的商品會從可折扣變成不可折扣。這在語意上是正確的（該品項確實在特價中），
 *    且與 SingleProductPriceHook 在單品頁的既有行為一致。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\OverridePriceResolver;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontData;

/**
 * 前台 Cart 價格 Override Hook
 *
 * 在 add-to-cart / before-calculate-totals / session-restore 三個 WC 擴充點，
 * 將 ProfitShop 的 override 價格安全寫入 cart_item，並於計算總額前驗章 + 套用價格。
 */
final class CartPriceOverrideHook {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Cart_item meta 鍵：來源賣場 ID
	 *
	 * 🔴 以下六個常數一律是 `CartProfitMetaVerifier::META_*` 的**別名**，不是各自的字面值。
	 * 驗章邏輯已收斂到那個類別（見 `verify_item()`），若這裡重新寫一份字面值，寫入端
	 * （本 hook）與讀取端（verifier）就有兩個真相來源——只要有一個字母不同，驗章會恆失敗，
	 * 症狀是「購物車套了覆寫價、訂單卻沒有分潤紀錄」，而且沒有任何錯誤訊息。
	 *
	 * 值本身完全不變，既有 `self::META_*` 呼叫端零影響。
	 */
	private const META_SHOP_ID = CartProfitMetaVerifier::META_SHOP_ID;

	/**
	 * Cart_item meta 鍵：來源分潤夥伴 term ID
	 */
	private const META_PARTNER_TERM_ID = CartProfitMetaVerifier::META_PARTNER_TERM_ID;

	/**
	 * Cart_item meta 鍵：override 後的有效價格（normalize 後的字串）
	 */
	private const META_PRICE_OVERRIDE = CartProfitMetaVerifier::META_PRICE_OVERRIDE;

	/**
	 * Cart_item meta 鍵：override 後的原價（'' 表示不覆寫原價）
	 */
	private const META_REGULAR_OVERRIDE = CartProfitMetaVerifier::META_REGULAR_OVERRIDE;

	/**
	 * Cart_item meta 鍵：override 後的特價（'' 表示無特價）
	 */
	private const META_SALE_OVERRIDE = CartProfitMetaVerifier::META_SALE_OVERRIDE;

	/**
	 * Cart_item meta 鍵：簽章
	 */
	private const META_SIGNATURE = CartProfitMetaVerifier::META_SIGNATURE;

	/**
	 * 全部 meta（session restore / strip 用）
	 *
	 * Restore_from_session / strip_profit_meta 共用同一份常數，是刻意的：這些清單以前是
	 * 各自展開的字面陣列，新增 meta 時漏改任何一處都會造成「session 後 meta 遺失」或
	 * 「合法 cart 被誤判成 tampering」，而且完全靜默。
	 *
	 * ⚠️ 現在直接別名到 `CartProfitMetaVerifier::META_KEYS_ALL`——驗章端用哪一份清單判斷
	 * 「meta 齊不齊」，這裡就必須 strip / restore 哪一份，兩者分歧會讓 session 還原後少一筆
	 * meta 而被驗章端判成 tampering。
	 *
	 * @var string[]
	 */
	private const META_KEYS_ALL = CartProfitMetaVerifier::META_KEYS_ALL;

	/**
	 * 價格解析器（與 SingleProductPriceHook 共用同一份取價語意）
	 *
	 * @var OverridePriceResolver
	 */
	private readonly OverridePriceResolver $resolver;

	/**
	 * 驗章器（與 `OrderLineItemSettlementHook` 共用同一份驗章語意）
	 *
	 * 見 `verify_item()` 的 docblock：這兩個 hook 過去各自維護一份版本分派 + HMAC 驗證，
	 * 分歧時兩邊都「正常運作」卻沒有分潤紀錄。收斂成同一個類別後，分歧在結構上不可能發生。
	 *
	 * @var CartProfitMetaVerifier
	 */
	private readonly CartProfitMetaVerifier $verifier;

	/**
	 * 當次加購的一次性授權（閘門 ②）
	 *
	 * 生命週期 = **單次 WC_Cart::add_to_cart() 呼叫**：
	 *   開：add_cart_item_data() 尾端（四道閘門 + 簽章都完成之後），且只在
	 *       wc_get_product($target_id)->get_price('edit') === '' 時才開。
	 *   關：(1) add_cart_item_data() 第一行無條件清空 —— 這是 try/finally 的等價物，
	 *          涵蓋上一次 add 在 includes/class-wc-cart.php:1200-1318 之間拋例外的殘留
	 *          （sold-individually 分支、庫存檢查、第三方掛在 woocommerce_add_cart_item
	 *          的 filter），也涵蓋 grouped 商品在一個請求內多次加購。
	 *       (2) woocommerce_add_to_cart（includes/class-wc-cart.php:1338）priority 1，
	 *          成功路徑。
	 *       (3) wp_loaded / woocommerce_cart_emptied，worker-mode PHP 的跨請求不變量。
	 *
	 * 🔴 target_id 一律是 `variation_id ?: product_id`——那才是 WC 在
	 *    includes/class-wc-cart.php:1073 拿去 wc_get_product() 的 ID，也是 :1228
	 *    `! $product_data->is_purchasable()` 實際檢查的那個物件。用父商品 ID 比對會讓
	 *    同一父商品的**其他變體**一起被放行，等於重新製造一個小號的授權外溢。
	 *
	 * 🔴 為什麼它不可能被用來加購第二件商品：任何第二次 add_to_cart() 都必然先觸發
	 *    woocommerce_add_cart_item_data（:1191，早於 :1228 的 is_purchasable 檢查），
	 *    而本 hook 的 callback 第一行就把它清掉了。
	 *
	 * @var array{target_id: int, product_id: int, price: string, regular: string, sale: string}|null
	 */
	private ?array $pending_add = null;

	/**
	 * 重入守門：apply_override() 是否正在執行中
	 *
	 * 生命週期 = **單次 apply_override() 呼叫**，由該方法自己以 try/finally 開關。
	 * 存在理由與完整實測數字見 apply_override() 的行內註解：
	 * reset_to_original_price() 內的 remove_cart_item() 會經
	 * `woocommerce_cart_item_removed` → WC_Cart::calculate_totals() 遞迴回本 hook。
	 *
	 * ⚠️ 不是「效能旗標」，關不掉會直接造成錯誤定價——**改動時必須保留 try/finally**。
	 *
	 * @var bool
	 */
	private bool $applying = false;

	/**
	 * 建構子
	 *
	 * 在非純 admin context（front-end / admin-ajax）時自動註冊三個 WC filter / action。
	 *
	 * @param ProfitShopRepositoryInterface $shops      賣場 Repository（DIP，禁鴨子型別）
	 * @param CartPriceSignatureService     $signatures 簽章服務
	 * @param OverridePriceResolver|null    $resolver   價格解析器；null 時建立預設實例（純 Domain 物件、無狀態）
	 * @param CartProfitMetaVerifier|null   $verifier   驗章器；null 時由 $signatures 建立（無狀態）。
	 *                                                  ⚠️ 保持 optional 是刻意的——改成必填會讓既有
	 *                                                  測試與 Loader 的 `new` 全部 ArgumentCountError，
	 *                                                  而本次是純重構、不該要求呼叫端配合。
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shops,
		private readonly CartPriceSignatureService $signatures,
		?OverridePriceResolver $resolver = null,
		?CartProfitMetaVerifier $verifier = null
	) {
		$this->resolver = $resolver ?? new OverridePriceResolver();
		$this->verifier = $verifier ?? new CartProfitMetaVerifier( $signatures );

		// admin context guard：純後台（非 ajax）不掛 hook，避免影響後台 cart manipulation
		if ( \is_admin() && ! \wp_doing_ajax() ) {
			return;
		}

		\add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 10, 3 );
		\add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'restore_from_session' ], 10, 2 );
		\add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_override' ], 999 );

		/*
		* ═══ 無定價商品：per-cart-item 授權（2026-08 第 5 輪）═══
		*
		* 商品／變體自身沒有價格（`_price` 為空）、只靠賣場覆寫價出售時，WooCommerce 有
		* 四道**互相獨立**的閘門會擋下它，四者的條件都收斂到 `'' !== get_price()`：
		*   ① includes/class-wc-product-variable.php:335    get_available_variations()
		*      → variation_is_visible()（includes/class-wc-product-variation.php:593）
		*      ⇒ 顯示端，由 OverridePriceFilters 的 arm()/disarm() 處理，不在本檔
		*   ② includes/class-wc-cart.php:1228               add_to_cart() 的 is_purchasable()
		*   ③ includes/class-wc-cart-session.php:183        每次載入購物車，false 就**移除品項**
		*   ④ src/StoreApi/Utilities/CartController.php:270 / :688（Cart Block / Block 結帳）
		*
		* ═══ 🔴 授權粒度：綁在「一筆 cart item」，不是「一個 product ID」═══
		*
		* 支點是 WooCommerce 自己的一個性質：
		*   includes/class-wc-product-factory.php WC_Product_Factory::get_product()
		*   每次呼叫都 `return new $classname( $product_id )`，**不做任何實例快取**。
		* ⇒ 對「某一筆 cart item 自己的 WC_Product 實例」呼叫 set_price()，
		*   在**結構上不可能**外溢到同一請求內任何其他 wc_get_product() 的結果——
		*   一般單品頁 / 商店彙整頁 / 搜尋結果 / 相關商品拿到的都是另一個全新實例，
		*   價格仍是 ''、is_purchasable() 仍是 false。
		*
		* 這正是本輪要的隔離性，而且不需要任何旗標、快取或生命週期管理。
		* （第 4 輪曾用「product ID → 價格」的 request 級救援表 + 常駐全域價格 filter，
		*   等於宣告「這個商品在這個請求內到處都值 27 元」，於是同一個無定價商品在
		*   不帶 profit_shop_id 的一般單品頁也變成可購買 ⇒ 無分潤歸屬的銷售；
		*   而且守門是「逐欄位判空字串」，絕大多數正常商品的 get_sale_price() 本來就回 ''，
		*   於是正常商品的特價也被污染。整套已刪除，不要復活。）
		*
		* ⚠️ **不要把上一段讀成「現在已經沒有任何常駐全域 filter」**（S-8）——下方仍然註冊
		*    **6 個**常駐的全域價格 filter（filter_pending_price / _regular / _sale 各掛
		*    woocommerce_product_get_* 與 woocommerce_product_variation_get_* 兩組）。
		*    與第 4 輪的差別不在「有沒有註冊」，而在三件事：
		*      (a) 它們在 $pending_add === null 時是**完全惰性**的（pending_for() 第一行就 return null）；
		*      (b) $pending_add 的生命週期是**單次 add_to_cart() 呼叫**（三個關閉點見其 docblock）；
		*      (c) 比對鍵是**精確的單一商品 ID**（variation_id ?: product_id），不是父商品、更不是「所有商品」。
		*    第 4 輪那張救援表則是 request 級、以 product ID 為鍵、且沒有任何開關。
		*
		* ⇒ ③ 用 woocommerce_cart_item_is_purchasable：這是**全 WooCommerce 唯一**帶
		*   $key / $values 的 per-item purchasable hook（grep 全 plugin 只有
		*   includes/class-wc-cart-session.php:183 一處），因此是唯一能把授權綁在單一
		*   cart item 上的位置——$values 直接帶著該筆自己的 _profit_* meta 可供驗章。
		* ⇒ ④ **沒有** per-item filter（CartController::validate_cart_item() 只讀
		*   $cart_item['data']，:681-688）。但 ③ 的 callback 收到的 $product 正是
		*   class-wc-cart-session.php:234 存進 cart_contents['data'] 的**同一個物件實例**，
		*   所以在 ③ 就地 set_price 之後，④ 自動一起解決。
		*   第二層保險：Store API 結帳（src/StoreApi/Routes/V1/Checkout.php:425-436）
		*   的順序是 calculate_totals() → validate_cart()，前者會觸發 apply_override()(999)。
		* ⇒ ② 拿不到物件（woocommerce_add_cart_item_data 只給 id），因此使用一次性
		*   pending 授權，見 $pending_add 的 docblock。
		*
		* ⚠️ **為什麼不抵銷「驗章失敗 fallback 回原價」的保護**：③ 驗章失敗時回傳的是
		* **原始 $purchasable**（false），由 WooCommerce 自己在 :184-214 移除品項並顯示
		* 訊息（fail-closed）；驗章成功時 is_purchasable() 與 get_price() **同時**變有效，
		* 不存在「is_purchasable 為真但價格為空」這種 WC 從未預期的狀態。
		*/
		\add_filter( 'woocommerce_cart_item_is_purchasable', [ $this, 'authorize_cart_item' ], 10, 4 );
		\add_action( 'woocommerce_add_to_cart', [ $this, 'on_added_to_cart' ], 1, 4 );

		/*
		* 閘門 ② 的一次性授權 filter。
		*
		* 六個 hook 而非兩個：is_purchasable() 對 simple 商品走
		* woocommerce_product_get_price、對變體走 woocommerce_product_variation_get_*；
		* WC_Product_Variation::is_purchasable()（includes/class-wc-product-variation.php:562）
		* 同時委派 variation_is_visible()（同檔 :593）與
		* parent::is_purchasable()（includes/abstracts/abstract-wc-product.php:1678），兩者都讀
		* get_price()。regular / sale 一併掛上，是為了避免 :1338 之前若有第三方讀
		* get_price_html() 出現「原價空白、售價 27」的錯亂顯示。
		*/
		\add_filter( 'woocommerce_product_get_price', [ $this, 'filter_pending_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_variation_get_price', [ $this, 'filter_pending_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_get_regular_price', [ $this, 'filter_pending_regular' ], 10, 2 );
		\add_filter( 'woocommerce_product_variation_get_regular_price', [ $this, 'filter_pending_regular' ], 10, 2 );
		\add_filter( 'woocommerce_product_get_sale_price', [ $this, 'filter_pending_sale' ], 10, 2 );
		\add_filter( 'woocommerce_product_variation_get_sale_price', [ $this, 'filter_pending_sale' ], 10, 2 );

		// $pending_add 是 per-customer 可變狀態，明確宣告失效點（worker-mode PHP 的不變量）。
		\add_action( 'wp_loaded', [ $this, 'clear_pending_add' ], PHP_INT_MIN );

		/*
		* 🔴 N-3：加購**失敗**後的殘留上限（同一個請求內）。
		*
		* WC_Cart::add_to_cart() 整個包在 try/catch 內（includes/class-wc-cart.php:1347
		* 的 `} catch ( Exception $e ) {`），失敗時自己 wc_add_notice + return false，
		* **失敗路徑上沒有任何 hook 會觸發**（woocommerce_add_to_cart 在 :1343，只在成功
		* 路徑跑到）。因此 arm（:1192 的 woocommerce_add_cart_item_data）與 disarm（:1343）
		* 之間若因庫存不足 / sold-individually / 第三方 filter 而拋例外，旗標會留到請求結束，
		* 該請求內任何 wc_get_product($target_id) 都會拿到覆寫價且 is_purchasable() 為 true。
		*
		* 這一行把殘留上限收斂到「template_redirect 之前」，兩條落地路徑都涵蓋：
		*   - 表單加購：WC_Form_Handler::add_to_cart_action 掛 wp_loaded prio 20
		*     （includes/class-wc-form-handler.php:33），失敗後請求繼續往下走到
		*     template_redirect ⇒ 本行在**任何頁面渲染之前**清乾淨。
		*   - ?wc-ajax=add_to_cart：WC_AJAX::do_wc_ajax 掛 template_redirect prio 0
		*     （includes/class-wc-ajax.php:36），本行 PHP_INT_MIN 排在它**之前**，
		*     因此不會誤清當次加購的授權；而該路徑失敗後立刻 wp_send_json 結束、
		*     沒有任何渲染，殘留無從被消費。
		*
		* wp_loaded 與 template_redirect 之間（parse_request / send_headers / wp）沒有任何
		* 價格渲染，所以殘留視窗內不存在可觀察的效果。
		*/
		\add_action( 'template_redirect', [ $this, 'clear_pending_add' ], PHP_INT_MIN );

		\add_action( 'woocommerce_cart_emptied', [ $this, 'clear_pending_add' ] );
	}

	/**
	 * 加入購物車時：若 cart_item_data 帶 profit_shop_id 且商品在該 shop 內，
	 * 解析價格三元組 → sign → 寫入 6 筆 meta。
	 *
	 * @param array<string, mixed> $cart_item_data 既有 cart_item_data
	 * @param int                  $product_id     商品 ID
	 * @param int                  $variation_id   Variation ID（0 表示非變體）
	 *
	 * @return array<string, mixed> 增補後的 cart_item_data
	 */
	public function add_cart_item_data( array $cart_item_data, int $product_id, int $variation_id ): array {
		/*
		* 🔴 關閉點 (1)：每次進來先無條件清空。
		*
		* 上一次 add_to_cart() 若在 includes/class-wc-cart.php:1200-1318 之間拋例外
		* （sold-individually 分支、庫存檢查、第三方 filter），殘留的授權到此為止——
		* 這是我們在「arm 與 consume 之間是 WooCommerce 本體、無法用 try/finally 包住」
		* 這個限制下能取得的最強等價保證：授權的有效期上限是「到下一次
		* add_cart_item_data 為止」，而任何能利用它的操作都必須先經過這裡。
		*/
		$this->pending_add = null;

		$shop_id = isset( $cart_item_data['profit_shop_id'] ) ? (int) $cart_item_data['profit_shop_id'] : 0;

		if ( $shop_id <= 0 ) {
			return $cart_item_data;
		}

		$shop = $this->shops->find( $shop_id );

		if ( ! $shop instanceof ProfitShop ) {
			return $cart_item_data;
		}

		// 防護：草稿 / 未發佈狀態的 shop 不應套 override 價（攻擊者已知 shop_id 為 draft 仍可加入購物車並偷看 partner 的促銷預覽價）
		if ( 'publish' !== $shop->status() ) {
			return $cart_item_data;
		}

		/*
		* 🔴 S-3 / TD-59：販售期間閘門在此**重驗**，與閘門 1 / 2 / 4 對齊。
		*
		* AddToCartHook::inject_profit_shop_id() 開頭有 short-circuit（上游已帶
		* profit_shop_id 就 return，四道閘門全跳過）。本方法本來就自己重驗閘門 1
		* （shop 存在，上面的 find()）、2（publish，上一行）、4（item 存在於賣場，下一行）
		* ——**唯獨漏了閘門 3**，於是販售期間是四道中唯一單層的一道。
		*
		* 探針實測（已結束販售的賣場）：走 $_POST 的路徑正確拒絕；
		* 上游直接帶 profit_shop_id 的路徑則**完全放行**（price='888.00'、有分潤 meta）。
		*
		* 目前找不到 HTTP 可達的呼叫端（WooCommerce 核心不從請求填 $cart_item_data），
		* 但第三方外掛（訂閱續訂、贈品、bundle）正是靠 add_to_cart() 第 5 參數運作的。
		*/
		if ( ! ProfitShopFrontData::is_sale_window_open( $shop ) ) {
			return $cart_item_data;
		}

		$item = $shop->items[ $product_id ] ?? null;

		if ( ! $item instanceof OverrideItem ) {
			return $cart_item_data;
		}

		/*
		* 🔴 S-1 / TD-56：R-4 政策必須在**加購路徑**也生效。
		*
		* 這裡不能用 OverridePriceResolver 的回傳值判斷——它照 Phase 13 契約會 fallback
		* 到商品層覆寫，對「站長從未定價的半成品變體」一樣解得出價格，於是
		* arm_pending_add() 開旗標、閘門 ② 放行。判斷必須發生在 resolve **之前**，
		* 且與顯示層（OverridePriceFilters）共用同一份 PricelessRescuePolicy。
		*
		* $target_id 一律是 `variation_id ?: product_id`——那才是
		* includes/class-wc-cart.php:1073 拿去 wc_get_product() 的 ID，也是 :1229
		* `! $product_data->is_purchasable()` 實際檢查的那個物件。
		* 順手把載好的物件往下傳給 arm_pending_add()（它本來也要載入同一個），
		* 兩者因此必然看到**同一個實例**、判斷不可能不一致，且淨查詢數不變。
		*
		* 載入失敗（$target 非 WC_Product）時**放行**而非拒絕：這道政策只負責
		* 「半成品變體」這一種情形，載不出物件時我們無從判斷；而 WooCommerce 自己在
		* :1229 的 is_purchasable() 仍會擋下真正沒有價格的商品（fail-safe 落在 WC 那一層）。
		*/
		$target_id = $variation_id > 0 ? $variation_id : $product_id;
		$target    = $target_id > 0 ? \wc_get_product( $target_id ) : false;

		if ( $target instanceof \WC_Product
			&& ! PricelessRescuePolicy::should_rescue_priceless( $target, $item ) ) {
			return $cart_item_data;
		}

		$prices = $this->resolve_override_prices( $item, $variation_id );

		if ( null === $prices ) {
			return $cart_item_data;
		}

		// J-2 必修：sign 端 / verify 端 / set_price 端統一 normalize，避免任何路徑（例如 wc_format_decimal）
		// 將 '888' 漂移成 '888.00' 而導致簽章不一致 → 靜默 fallback 至原價的商家損失
		$normalized_price = self::normalize_price_for_signature( $prices['price'] );
		// 🔴 未覆寫的 regular / sale 必須走 normalize_nullable_price（null ⇒ ''），
		// 絕不可直接進 normalize_price_for_signature——那會把 null 轉成 '0.00'，
		// 購物車會顯示「原價 NT$0」的劃線價，且沒有任何錯誤訊息。
		$normalized_regular = self::normalize_nullable_price( $prices['regular'] );
		$normalized_sale    = self::normalize_nullable_price( $prices['sale'] );

		// ⚠️ 一律寫 ''、絕不寫 null：has_all_profit_meta() 用 isset() 判斷，
		// 而 isset(null) 為 false，寫 null 會讓自己產生的 cart item 被自己判成 tampering。
		$cart_item_data[ self::META_SHOP_ID ]          = $shop_id;
		$cart_item_data[ self::META_PARTNER_TERM_ID ]  = $shop->partner_term_id;
		$cart_item_data[ self::META_PRICE_OVERRIDE ]   = $normalized_price;
		$cart_item_data[ self::META_REGULAR_OVERRIDE ] = $normalized_regular; // '' = 不覆寫原價
		$cart_item_data[ self::META_SALE_OVERRIDE ]    = $normalized_sale;    // '' = 無特價
		// v3 起簽章綁定商品身分（product_id / variation_id），見 CartPriceSignatureService 的
		// class docblock：簽章現在同時是「不可購買的商品變不變成可購買」的授權來源。
		$cart_item_data[ self::META_SIGNATURE ] = $this->signatures->sign(
			$shop_id,
			$shop->partner_term_id,
			$normalized_price,
			$normalized_regular,
			$normalized_sale,
			$product_id,
			$variation_id
		);

		/*
		* 閘門 ② 的一次性授權。
		*
		* 本 filter 的觸發點是 includes/class-wc-cart.php:1191，而 is_purchasable() 的檢查
		* 在同一個方法的 :1228——也就是**本行之後**。此刻品項還沒進 cart_contents，
		* 也還沒有任何 cart item 可供驗章，所以只能靠這個一次性旗標讓它通過。
		*
		* 這裡是本檔 gate 最完整的位置：AddToCartHook(prio 5) 的四道閘門（賣場存在 /
		* status=publish / 販售期間內 / 商品屬於賣場）、本方法自己重驗的四道（同上，
		* 涵蓋上游 short-circuit 的路徑）、R-4 政策（PricelessRescuePolicy）、
		* resolver 有值、簽章已簽——全都已經在上面完成。
		*/
		$this->arm_pending_add( $target, $product_id, $target_id, $normalized_price, $normalized_regular, $normalized_sale );

		return $cart_item_data;
	}

	/**
	 * Session round-trip 後將 _profit_* meta 套回 cart_item
	 *
	 * 逐鍵 isset 判斷：改版前寫入的舊 cart_item 只有 4 筆（無 regular / sale），
	 * 缺的鍵自然跳過，之後在 apply_override() 走 v1 分支，行為與改版前完全相同。
	 *
	 * @param array<string, mixed> $cart_item 反序列化後的 cart_item（已由 WC 重建 product/variation）
	 * @param array<string, mixed> $values    session 中保存的原始 cart_item 陣列
	 *
	 * @return array<string, mixed> 補回 _profit_* meta 後的 cart_item
	 */
	public function restore_from_session( array $cart_item, array $values ): array {
		foreach ( self::META_KEYS_ALL as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$cart_item[ $key ] = $values[ $key ];
			}
		}
		return $cart_item;
	}

	// ========== 無定價商品：per-cart-item 授權（見建構子的說明） ==========

	/**
	 * 閘門 ③：每次載入購物車時，逐筆驗章決定該 cart item 是否仍可購買
	 *
	 * Hook: woocommerce_cart_item_is_purchasable
	 *       （includes/class-wc-cart-session.php:183，簽章 $purchasable, $key, $values, $product）
	 * 這是全 WooCommerce **唯一**帶 $key / $values 的 per-item purchasable hook
	 * （grep 全 plugin 只有那一處），因此也是唯一能把授權綁在單一 cart item 上的位置。
	 *
	 * 🔴 為什麼授權不會外溢到「同 request 內其他地方的同一個商品」：
	 *    本方法只對參數傳進來的 `$product` **這一個實例**呼叫 set_price()。而
	 *    includes/class-wc-product-factory.php 的 WC_Product_Factory::get_product()
	 *    每次都 `return new $classname( $product_id )`、**不做實例快取**，所以一般單品頁 /
	 *    商店彙整頁 / 搜尋結果 / 相關商品各自 wc_get_product() 拿到的是完全不同的物件，
	 *    價格仍是 ''、is_purchasable() 仍是 false ⇒ 無定價商品在沒有賣場情境時加不進購物車。
	 *    這個隔離性是 WooCommerce 免費提供的，不需要任何旗標或快取。
	 *
	 * 🔴 三道早退是作用範圍的收斂點，缺一不可：
	 *   (1) $purchasable 已為 true → 不介入。一般商品完全不進入後續邏輯。
	 *   (2) 商品自身有價格 → 不介入。它之所以不可購買是別的原因（下架 / 父商品未發佈…），
	 *       那些理由我們無權推翻。
	 *       ⚠️ 這一條是關鍵修正：第 4 輪的守門是「逐欄位判空字串」，而**絕大多數正常商品
	 *       的 get_sale_price() 本來就回 ''**，於是整組正常商品都被拖進救援邏輯、特價被賣場
	 *       覆寫價污染（一般單品頁出現「原價劃掉 → 同一個原價」、is_on_sale() 由 false 翻 true
	 *       導致帶 exclude_sale_items 的優惠券失效）。現在改成判斷「這個**商品**自身確實
	 *       沒有價格」，正常商品在第 (1)(2) 兩關就已經全部退出。
	 *   (3) 驗章不過 → 回傳**原始** $purchasable（false）→ WooCommerce 自己在
	 *       includes/class-wc-cart-session.php:184-214 移除品項並顯示訊息（fail-closed）。
	 *       **絕不回 true**——那會製造「is_purchasable 為真但 get_price() 是 ''」這種
	 *       WooCommerce 從未預期的狀態，而 reset_to_original_price() 對無定價商品會
	 *       set_price('') ⇒ 顧客拿到免費商品，把「竄改 ⇒ 付原價」倒轉成「竄改 ⇒ 免費」。
	 *
	 * 驗章通過才 set_price，而 $product 正是 includes/class-wc-cart-session.php:234
	 * 存進 cart_contents['data'] 的同一個物件實例，所以閘門 ④
	 * （src/StoreApi/Utilities/CartController.php:681-688 的 validate_cart_item() 讀
	 * $cart_item['data']）順帶一起解決。
	 *
	 * 最後回傳 $product->is_purchasable() 而非硬回 true：post_status、父商品是否發佈等
	 * 其他條件仍然交由 WooCommerce 自己判斷，我們只補上「價格」這一項。
	 *
	 * @param mixed $purchasable WC 算出的原始結果
	 * @param mixed $key         cart item key（未使用，WC 簽章要求）
	 * @param mixed $values      該筆 cart item 的 session 資料（含 _profit_* meta）
	 * @param mixed $product     該筆 cart item 的 WC_Product 實例
	 *
	 * @return mixed
	 */
	public function authorize_cart_item( $purchasable, $key = '', $values = [], $product = null ) {
		unset( $key );

		if ( $purchasable ) {
			return $purchasable;
		}

		if ( ! $product instanceof \WC_Product || ! \is_array( $values ) ) {
			return $purchasable;
		}

		/*
		* 'edit' context 不會跑 filter（includes/abstracts/abstract-wc-data.php:880 的
		* get_prop() 只在 'view' context 才 apply_filters），所以這行不會遞迴，
		* 也讀得到「商品自身真正的價格」而非任何 filter 過的值。
		*/
		if ( '' !== (string) $product->get_price( 'edit' ) ) {
			return $purchasable;
		}

		$prices = $this->verify_item( $values, $product );

		if ( null === $prices ) {
			return $purchasable;
		}

		/*
		* 🔴 S-5 / TD-61：只靠救援才有價的品項，簽章驗過**還不夠**，必須回查賣場仍然授權它。
		*
		* verify_item() 只證明「這組 meta 是我方在某個時間點簽的」，不證明「現在還算數」。
		* 對一般商品那沒關係（簽章決定的只是「標多少錢」）；但走到這一行的品項，
		* 簽章決定的是「**能不能買**」——賣場下架、商品移出賣場、販售期間結束之後仍然放行，
		* 等於站長除了刪商品之外沒有任何手段停掉那些訂單。
		*
		* ⚠️ 與 AddToCartHook「已在購物車內的品項不受販售期間閘門影響」**刻意不同**：
		*   那條紀律的前提是「加入購物車 = 價格已鎖定」，只在商品本來就有價格時成立。
		*   這裡的品項在賣場之外根本不是商品（它沒有價格）；而且移除**不是靜默的**——
		*   WooCommerce 自己在 includes/class-wc-cart-session.php:184-214 會加一則
		*   error notice（「%s has been removed from your cart because it can no longer
		*   be purchased.」）告知顧客。
		*
		* ⚠️ 只在這條窄路徑回查，**不要**擴大到有定價的品項：那會改變 Phase 3-D 既有的
		*   「published 期間加入的品項之後下架也不撤銷」邊界，blast radius 遠大於本次需求。
		*/
		if ( ! $this->rescue_still_authorized( $values, $product ) ) {
			return $purchasable;
		}

		$this->apply_prices_to_object( $product, $prices['price'], $prices['regular'], $prices['sale'] );

		return $product->is_purchasable();
	}

	/**
	 * 回查賣場是否仍授權「只靠救援才有價」的這一筆 cart item
	 *
	 * 🔴 查詢成本：**只有走到這裡才發生**，而走到這裡的前提是
	 *    「$purchasable 為 false」+「商品自身 get_price('edit') 為空」+「簽章驗過」。
	 *    一般商品在 authorize_cart_item() 的前兩道早退就退出了 ⇒ 一般購物車 **0 次查詢**。
	 *    同一台車有多筆這類品項時，CptProfitShopRepository::find() 走 get_post + get_post_meta，
	 *    兩者都吃 WP 物件快取 ⇒ 第 2 筆之後同樣 0 查詢。
	 *    **刻意不加自建 memo**：那會重新引入 per-request 可變狀態（第 4 輪被否決的設計 B-1），
	 *    而收益是「0 查詢換 0 查詢」。
	 *
	 * fail-closed：任何一項查不到 / 不成立都回 false，呼叫端據此回傳原始 $purchasable（false），
	 * 由 WooCommerce 自己移除品項並顯示訊息。**絕不回 true**——那會製造
	 * 「is_purchasable 為真但 get_price() 是 ''」這種 WC 從未預期的狀態。
	 *
	 * @param array<string, mixed> $values  cart item 的 session 陣列（含 _profit_* meta）
	 * @param \WC_Product          $product 該筆 cart item 自己的商品實例
	 *
	 * @return bool 仍被授權為 true
	 */
	private function rescue_still_authorized( array $values, \WC_Product $product ): bool {
		$shop_id = isset( $values[ self::META_SHOP_ID ] ) ? (int) $values[ self::META_SHOP_ID ] : 0;

		if ( $shop_id <= 0 ) {
			return false;
		}

		try {
			$shop = $this->shops->find( $shop_id );
		} catch ( \Throwable $e ) {
			// 過濾 \r \n \t 防 log injection（與 AddToCartHook / SingleProductPriceHook 一致）
			$safe_message = (string) preg_replace( "/[\r\n\t]/", ' ', $e->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				sprintf(
					'[ProfitShop][CartPriceOverrideHook] rescue re-check shop %d failed: %s',
					$shop_id,
					$safe_message
				)
			);
			return false;
		}

		// 賣場已刪除 / 已下架
		if ( ! $shop instanceof ProfitShop || 'publish' !== $shop->status() ) {
			return false;
		}

		// 販售期間已過（見呼叫端說明：為何與 AddToCartHook 的取捨刻意不同）
		if ( ! ProfitShopFrontData::is_sale_window_open( $shop ) ) {
			return false;
		}

		$parent_id = $product instanceof \WC_Product_Variation
		? (int) $product->get_parent_id()
		: (int) $product->get_id();

		$item = $shop->items[ $parent_id ] ?? null;

		// 商品已從賣場移除
		if ( ! $item instanceof OverrideItem ) {
			return false;
		}

		// 順帶清掉 S-1 修好之前已經進到購物車的半成品變體
		return PricelessRescuePolicy::should_rescue_priceless( $product, $item );
	}

	/**
	 * 加購成功後：關閉一次性授權，並把價格釘在該筆 cart item 自己的 product 實例上
	 *
	 * Hook: woocommerce_add_to_cart（includes/class-wc-cart.php:1338，此時品項已於 :1320
	 * 寫進 cart_contents），priority 1。
	 *
	 * 為什麼需要「釘在物件上」這一步：旗標關掉之後，該請求後續若尚未跑過
	 * calculate_totals()（例如 ?wc-ajax=get_refreshed_fragments 的 mini-cart、
	 * wc_add_to_cart_message()、Store API 回應），cart_contents[$key]['data'] 的價格
	 * 仍是 '' → 畫面會顯示 NT$0。在物件上釘一次即可，而這正是 per-item 授權的正確形態
	 * （只影響這一筆，不影響同請求任何其他 wc_get_product() 的結果）。
	 * apply_override()（priority 999）之後會用同一組值再套一次，冪等。
	 *
	 * @param mixed $cart_item_key cart item key
	 * @param mixed $product_id    商品 ID（未使用，WC 簽章要求）
	 * @param mixed $quantity      數量（未使用，WC 簽章要求）
	 * @param mixed $variation_id  變體 ID（未使用，WC 簽章要求）
	 *
	 * @return void
	 */
	public function on_added_to_cart( $cart_item_key = '', $product_id = 0, $quantity = 0, $variation_id = 0 ): void {
		unset( $product_id, $quantity, $variation_id );

		$pending = $this->pending_add;

		// 🔴 關閉點 (2)：成功路徑。無論後續有沒有拿到物件，授權都到此為止。
		$this->pending_add = null;

		if ( null === $pending || ! \is_string( $cart_item_key ) || '' === $cart_item_key ) {
			return;
		}

		if ( ! \function_exists( 'WC' ) ) {
			return;
		}

		$wc = \WC();
		if ( ! \is_object( $wc ) || ! isset( $wc->cart ) || ! $wc->cart instanceof \WC_Cart ) {
			return;
		}

		if ( ! isset( $wc->cart->cart_contents[ $cart_item_key ]['data'] ) ) {
			return;
		}

		$data = $wc->cart->cart_contents[ $cart_item_key ]['data'];
		if ( ! $data instanceof \WC_Product ) {
			return;
		}

		$this->apply_prices_to_object( $data, $pending['price'], $pending['regular'], $pending['sale'] );
	}

	/**
	 * 清空一次性授權（關閉點 (3)：worker-mode PHP 的跨請求不變量）
	 *
	 * 掛在 wp_loaded（PHP_INT_MIN）與 woocommerce_cart_emptied。本站是 PHP-FPM，
	 * 每個請求都是全新 process，理論上不會殘留；但把它寫成明確的不變量，
	 * 而不是依賴部署環境的假設（FrankenPHP / RoadRunner / Swoole 會重用 worker）。
	 *
	 * @return void
	 */
	public function clear_pending_add(): void {
		$this->pending_add = null;
	}

	/**
	 * 閘門 ② 的一次性授權 filter：補上 get_price()
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed
	 */
	public function filter_pending_price( $value, $product = null ) {
		$pending = $this->pending_for( $value, $product );
		return null === $pending ? $value : $pending['price'];
	}

	/**
	 * 閘門 ② 的一次性授權 filter：補上 get_regular_price()
	 *
	 * `regular === ''` 的語意是「不覆寫原價」，此時原樣回傳（對齊
	 * apply_prices_to_object() 與 SingleProductPriceHook::filter_regular_price()）。
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed
	 */
	public function filter_pending_regular( $value, $product = null ) {
		$pending = $this->pending_for( $value, $product );
		if ( null === $pending || '' === $pending['regular'] ) {
			return $value;
		}
		return $pending['regular'];
	}

	/**
	 * 閘門 ② 的一次性授權 filter：補上 get_sale_price()
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed
	 */
	public function filter_pending_sale( $value, $product = null ) {
		$pending = $this->pending_for( $value, $product );
		return null === $pending ? $value : $pending['sale'];
	}

	/**
	 * 三個 pending filter 的共同守門
	 *
	 * 三重收斂，任何一條都足以擋掉「授權外溢」：
	 *   1. 只在本來就沒有價格時介入（`'' !== (string) $value` 早退）
	 *   2. 比對鍵是**精確的單一商品 ID**（`variation_id ?: product_id`）——
	 *      同一父商品的其他變體、任何其他商品都不匹配
	 *   3. 要消費它必須有第二次 add_to_cart()，而第二次必然先觸發
	 *      add_cart_item_data()（includes/class-wc-cart.php:1191 早於 :1228），
	 *      該 callback 第一行就把旗標清掉 ⇒ 旗標在結構上無法被用來加購第二件商品
	 *
	 * 已知且有界的殘留：若 add_to_cart() 在 :1191（arm）與 :1338（disarm）之間拋例外
	 * （sold-individually / 庫存檢查 / 第三方 filter），旗標會殘留到該請求結束。
	 * 後果僅限於「該顧客剛剛以完全合法的賣場情境嘗試加購的那一個商品，在這個已經失敗的
	 * 請求裡顯示得出價格」——不產生任何 cart item、不影響任何其他商品。
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return array{target_id: int, product_id: int, price: string, regular: string, sale: string}|null
	 */
	private function pending_for( $value, $product ): ?array {
		if ( null === $this->pending_add ) {
			return null;
		}

		if ( '' !== (string) $value ) {
			return null;
		}

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		if ( (int) $product->get_id() !== $this->pending_add['target_id'] ) {
			return null;
		}

		return $this->pending_add;
	}

	/**
	 * 開啟一次性授權（關閉點見 $pending_add 的 docblock）
	 *
	 * 只在商品**自身確實沒有價格**時才 arm：全站 99.9% 的加購根本不進這個分支，
	 * 「一般商品的價格永遠不經過我方 filter」因此是可斷言的性質。
	 *
	 * ⚠️ $target 由呼叫端載好後傳入，**不在這裡重新 wc_get_product()**：
	 * 呼叫端的 PricelessRescuePolicy 守門與本方法的「自身有沒有價格」判斷必須看
	 * **同一個物件實例**。分兩次載入雖然目前結果相同，那是巧合而非保證，
	 * 而兩者不一致的後果是「政策拒絕、旗標卻開了」。
	 *
	 * @param mixed  $target     已載入的目標商品（`variation_id ?: product_id`）；非 WC_Product 時不 arm
	 * @param int    $product_id 父商品 ID（只為 error_log / 測試可讀性，不參與比對）
	 * @param int    $target_id  實際被定價的商品 ID（`variation_id ?: product_id`）
	 * @param string $price      已 normalize 的實際生效價格
	 * @param string $regular    已 normalize 的覆寫原價（'' = 不覆寫）
	 * @param string $sale       已 normalize 的覆寫特價（'' = 無特價）
	 *
	 * @return void
	 */
	private function arm_pending_add(
		$target,
		int $product_id,
		int $target_id,
		string $price,
		string $regular,
		string $sale
	): void {
		if ( $target_id <= 0 || '' === $price ) {
			return;
		}

		if ( ! $target instanceof \WC_Product ) {
			return;
		}

		// 'edit' context 不跑 filter（abstract-wc-data.php 的 get_prop()），讀得到商品自身真正的價格。
		if ( '' !== (string) $target->get_price( 'edit' ) ) {
			return;
		}

		$this->pending_add = [
			'target_id'  => $target_id,
			// 只為 error_log 與測試斷言的可讀性，**不參與比對**（見 $pending_add docblock）。
			'product_id' => $product_id,
			'price'      => $price,
			'regular'    => $regular,
			'sale'       => $sale,
		];
	}

	/**
	 * 逐筆驗章並解出價格三元組（**閘門 ③ 與 apply_override() 的唯一共用實作**）
	 *
	 * 🔴 兩個呼叫端必須用同一份程式碼，不可各寫一份：兩份靠人維持一致的驗證邏輯一定會
	 * 分歧，而分歧時**兩邊都「正常運作」、沒有任何錯誤訊息**（這正是 Phase 13 的
	 * Domain\Service\OverridePriceResolver 存在的理由）。這裡若分歧，症狀是
	 * 「③ 放行、999 判 tampering」⇒ 品項留在購物車卻被 reset 成空價格。
	 *
	 * 🔴 版本判斷一律用 payload_version()，**絕不可**用「regular/sale meta 在不在」判斷。
	 * 攻擊者可保留合法的 v1 簽章（驗得過）再額外注入一筆偽造的
	 * _profit_regular_price_override，若靠 meta 存在與否判版本，就會套用一個從未被簽章
	 * 保護過的假原價。因此 v1 分支**無條件忽略**任何 regular / sale meta。
	 *
	 * ═══ 驗章本體已委派 `CartProfitMetaVerifier`（2026-08）═══
	 *
	 * 本方法在此之前是**孿生實作**：`OrderLineItemSettlementHook::verify_cart_item()`
	 * 另外維護一份一模一樣的版本分派 + HMAC 驗證。兩者分歧的症狀是
	 * 「購物車套了覆寫價、訂單卻沒有分潤紀錄」——顧客付了分潤價、夥伴永遠拿不到結算，
	 * 而且兩邊都「正常運作」、零錯誤訊息（與 Phase 13 `OverridePriceResolver` 同型問題）。
	 *
	 * 現在兩個 hook 都委派同一個類別，分歧在結構上不可能發生。本方法只剩兩件事：
	 * 提供**自己那一套**身分比對 predicate（購物車比的是「被定價的那個物件」，
	 * 結帳比的是「落進訂單的那筆 line item」，兩者本來就不同），以及把結果轉成
	 * 既有呼叫端期待的陣列形狀。
	 *
	 * @param array<string, mixed> $item    cart item（session 陣列或 cart_contents 皆可）
	 * @param \WC_Product|null     $product 該筆 cart item 自己的商品實例；v3 用來與陣列欄位交叉比對
	 *
	 * @return array{price: string, regular: string|null, sale: string|null}|null 驗章不過回 null
	 */
	private function verify_item( array $item, ?\WC_Product $product = null ): ?array {
		/*
		* 身分比對 predicate（只有 v3 會被呼叫，見 CartProfitMetaVerifier）：
		* cart item 的**陣列欄位** vs **實際被定價的那個物件**。
		*
		* WooCommerce 自己是用 `wc_get_product( $values['variation_id'] ? $values['variation_id']
		* : $values['product_id'] )` 建出 cart_contents['data']
		* （includes/class-wc-cart-session.php 的 get_cart_from_session()），正常路徑必然一致；
		* 但授權現在決定「不可購買的商品變不變成可購買」，不該把一致性當成外部保證。
		*
		* ⚠️ `$product` 為 null 時傳 null predicate（= 不比對），與重構前
		* `if ( $product instanceof \WC_Product )` 的守門逐字等價：session restore 階段
		* 拿不到物件，此時身分仍受 v3 簽章的 HMAC 保護。
		*/
		$identity_matches = null;

		if ( $product instanceof \WC_Product ) {
			$identity_matches = static function ( int $product_id, int $variation_id ) use ( $product ): bool {
				$expected_target = $variation_id > 0 ? $variation_id : $product_id;
				return (int) $product->get_id() === $expected_target;
			};
		}

		$verified = $this->verifier->verify( $item, $identity_matches );

		if ( null === $verified ) {
			return null;
		}

		// v1 的 regular / sale 是 null（該版本從未簽過這兩欄），原樣傳遞：
		// apply_prices_to_product() 靠 null 判斷「完全不動商品原本的原價 / 特價」。
		return [
			'price'   => $verified->price,
			'regular' => $verified->regular,
			'sale'    => $verified->sale,
		];
	}

	/**
	 * 計算購物車總額前：對所有含 _profit_signature 的 cart_item 驗章，通過則套價，否則 fallback。
	 *
	 * Priority=999 確保晚於其他 plugin 的 set_price，最終價格不被覆蓋。
	 *
	 * ═══ 🔴 版本判斷必須用 payload_version()，不可用「meta 在不在」 ═══
	 *
	 * verify() 只回 bool，無法告訴呼叫端是哪個版本驗過的。若改用「regular meta 存不存在」
	 * 判斷要不要套 regular/sale，會出現這個漏洞：
	 *
	 *   攻擊者保留改版前的 v1 簽章（合法、驗得過），額外注入一筆偽造的
	 *   _profit_regular_price_override → 我方看到 meta 存在就以為是 v2 →
	 *   用 v2 payload 驗 → 失敗 → 落到 v1 分支 → 只驗 price → **通過** →
	 *   於是套用了一個從未被簽章保護的假原價。
	 *
	 * 因此一律先由 signature 前綴決定版本，且 **v1 分支無條件忽略任何 regular/sale meta**。
	 *
	 * @param mixed $cart WC_Cart 實例（簽章寬鬆以兼容 WC 內部呼叫）
	 *
	 * @return void
	 */
	public function apply_override( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		/*
		* 🔴 re-entrancy 守門：本方法會**從自己內部**被再次呼叫。
		*
		* 路徑（WooCommerce 10.1.4 逐行查證）：
		*   reset_to_original_price() 對無定價商品呼叫 WC_Cart::remove_cart_item()
		*   → includes/class-wc-cart.php:1372 `do_action( 'woocommerce_cart_item_removed', ... )`
		*   → 同檔 :131 `add_action( 'woocommerce_cart_item_removed', [ $this, 'calculate_totals' ], 20, 0 )`
		*   → calculate_totals() → `woocommerce_before_calculate_totals` → 本方法（priority 999）
		*
		* ⚠️ 本檔原本在 reset_to_original_price() 內斷言「remove_cart_item() **不會**呼叫
		*    calculate_totals()，所以不會遞迴回本 hook」——**那句話是錯的**，而且被當成
		*    已驗證事實沿用了一輪。
		*
		* 實測數字（探針掛在 woocommerce_before_calculate_totals 的 998 / 1000，
		* 並以 reflection 讀本旗標區分「hook 被呼叫」與「body 真的執行」；N = 同時存在的
		* 驗章失敗無定價品項數）：
		*
		*   修正前  N=2 → 巢狀深度 2 ／ N=6 → 6 ／ N=12 → 12   （與 N 1:1 成長）
		*   修正後  N=2 / 6 / 12 → calculate_totals 巢狀深度**恆為 2**、
		*                          **apply_override body 巢狀深度恆為 1**
		*                          （呼叫 2/6/12 次，其中 1/5/11 次被本旗標擋下）
		*
		* 也就是說：巢狀的那一層 calculate_totals 仍會發生（remove_cart_item 必然觸發，
		* 這是 WooCommerce 的行為，我們不改它），但它**不再堆疊**，而且本方法的 body
		* 不會重入。修正前每一層都是完整的 calculate_totals()（正式環境含稅金 / 優惠券 /
		* 運費），Xdebug 預設 256 frame 限制下大購物車會 fatal。
		* 觸發不需要攻擊者：輪替 AUTH_SALT 會讓全站簽章一次失效 ⇒ 所有這類品項同時走到這條路。
		*
		* 為什麼選旗標而不是「收集 key、迴圈後再刪」：後者同樣能把深度壓到 2，
		* 但**沒有消滅重入**——重入時本方法會對剩下每一筆品項重新驗一次 HMAC，
		* 成本 O(N×M)。旗標讓巢狀呼叫在第一行就 return，body 深度為 1，
		* 且不需要新增一份「待刪清單」狀態。
		*
		* 正確性：巢狀那一次 calculate_totals() 的結果會被外層覆蓋（外層跑完本方法後才
		* 繼續算總額），而此刻所有通過驗章的品項早已在本輪 foreach 內套好價格、
		* 釘在各自的 cart_contents[$key]['data'] 物件上，所以巢狀計算讀到的價格是正確的。
		*
		* try/finally 不可省略：旗標若因例外卡在 true，本 request 後續的 calculate_totals()
		* 會完全跳過套價 ⇒ 顧客被以錯誤價格結帳，且沒有任何錯誤訊息。
		*/
		if ( $this->applying ) {
			return;
		}

		$this->applying = true;

		try {
			$this->apply_override_pass( $cart );
		} finally {
			$this->applying = false;
		}
	}

	/**
	 * 驗章與套價的實際內容（已由 apply_override() 保證非重入）
	 *
	 * 抽出來只是為了讓 try/finally 保持單一職責，邏輯與抽出前逐字相同。
	 *
	 * @param \WC_Cart $cart cart 實例
	 *
	 * @return void
	 */
	private function apply_override_pass( \WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $key => $item ) {
			// 沒有任何 profit_* 痕跡 → 不是我方管理的 cart_item，略過
			if ( ! isset( $item[ self::META_SHOP_ID ] ) ) {
				continue;
			}

			$signature = isset( $item[ self::META_SIGNATURE ] ) ? (string) $item[ self::META_SIGNATURE ] : '';
			$version   = $this->signatures->payload_version( $signature );

			if ( $this->try_apply_for_version( $cart, $key, $item, $version, $signature ) ) {
				continue;
			}

			// 驗章失敗、meta 不完整、或簽章版本不明：清掉所有 profit_* meta 並把 product 還原回
			// 原始價格，避免前一輪 calculate_totals（例如 woocommerce_add_to_cart -> calculate_totals）
			// 已把 product 物件改成 override 價而留在記憶體中。
			$this->log_signature_mismatch( (int) ( $item[ self::META_SHOP_ID ] ?? 0 ), $key );
			$this->strip_profit_meta( $cart, $key );
			$this->reset_to_original_price( $cart, $key );
		}
	}

	/**
	 * 依簽章版本驗章並套價
	 *
	 * 驗章邏輯本身委派 verify_item()——那是**閘門 ③ 與本方法共用的唯一實作**。
	 * 兩處各寫一份會分歧，而分歧時兩邊都「正常運作」、沒有任何錯誤訊息，
	 * 症狀是「③ 放行、999 判 tampering」⇒ 品項留在購物車卻被 reset 成空價格。
	 *
	 * @param \WC_Cart             $cart      cart 實例
	 * @param string               $key       cart_item key
	 * @param array<string, mixed> $item      cart_item
	 * @param string|null          $version   payload 版本（未使用，版本判斷已收斂進 verify_item）
	 * @param string               $signature 簽章字串（未使用，同上）
	 *
	 * @return bool 驗章通過並已套價為 true；呼叫端據此決定是否走 fallback
	 */
	private function try_apply_for_version(
		\WC_Cart $cart,
		string $key,
		array $item,
		?string $version,
		string $signature
	): bool {
		unset( $version, $signature );

		// v3 的身分交叉比對需要該筆 cart item 自己的商品實例（cart_contents['data']）。
		$data   = $cart->cart_contents[ $key ]['data'] ?? null;
		$prices = $this->verify_item( $item, $data instanceof \WC_Product ? $data : null );

		if ( null === $prices ) {
			return false;
		}

		$this->apply_prices_to_product( $cart, $key, $prices['price'], $prices['regular'], $prices['sale'] );
		return true;
	}

	/**
	 * 把三元組價格套用到 cart_item 的 product 物件
	 *
	 * ⚠️ regular 與 sale 的空字串語意不同，這是本檔最容易寫錯的一段：
	 *   - regular：'' / null ⇒「不覆寫原價」⇒ 保留商品原本的原價
	 *     （對齊 SingleProductPriceHook::filter_regular_price()）
	 *   - sale：'' ⇒「無特價」⇒ **必須寫入**以清掉商品原本的特價
	 *     （對齊 SingleProductPriceHook::filter_sale_price()；不清會出現
	 *      「原價劃掉 → 特價」但實收是覆寫原價的矛盾）
	 *   - sale：null ⇒ v1 legacy 路徑，完全不碰 regular / sale
	 *
	 * @param \WC_Cart    $cart    cart 實例
	 * @param string      $key     cart_item key
	 * @param string      $price   已 normalize 的實際生效價格
	 * @param string|null $regular 已 normalize 的覆寫原價；'' 不覆寫、null 表示 v1 不處理
	 * @param string|null $sale    已 normalize 的覆寫特價；'' 清空特價、null 表示 v1 不處理
	 *
	 * @return void
	 */
	private function apply_prices_to_product(
		\WC_Cart $cart,
		string $key,
		string $price,
		?string $regular,
		?string $sale
	): void {
		if ( ! isset( $cart->cart_contents[ $key ]['data'] ) ) {
			return;
		}
		$data = $cart->cart_contents[ $key ]['data'];
		if ( ! $data instanceof \WC_Product ) {
			return;
		}

		$this->apply_prices_to_object( $data, $price, $regular, $sale );
	}

	/**
	 * 把三元組價格套用到**單一 WC_Product 實例**（per-item 授權的最小單位）
	 *
	 * 🔴 這個方法是本檔「授權綁在一筆 cart item 上」的落點：它只改傳進來的那一個物件。
	 * WC_Product_Factory::get_product()（includes/class-wc-product-factory.php）每次都
	 * `return new $classname( $product_id )`、**不做實例快取**，所以同一個請求裡其他地方
	 * 的 wc_get_product() 拿到的是另一個物件，價格完全不受影響——這是 WooCommerce 免費
	 * 提供的隔離性，不需要旗標或快取來維持。
	 *
	 * regular / sale 的空字串語意見 apply_prices_to_product() 的 docblock。
	 *
	 * @param \WC_Product $data    要被改動的商品實例（cart item 自己的 'data'）
	 * @param string      $price   已 normalize 的實際生效價格
	 * @param string|null $regular 已 normalize 的覆寫原價；'' 不覆寫、null 表示 v1 不處理
	 * @param string|null $sale    已 normalize 的覆寫特價；'' 清空特價、null 表示 v1 不處理
	 *
	 * @return void
	 */
	private function apply_prices_to_object(
		\WC_Product $data,
		string $price,
		?string $regular,
		?string $sale
	): void {
		if ( null !== $regular && '' !== $regular ) {
			$data->set_regular_price( $regular );
		}

		if ( null !== $sale ) {
			$data->set_sale_price( $sale );
		}

		// price 最後設：語意最明確（set_regular_price / set_sale_price 不會自動重算 price，
		// 兩個 setter 都只是 set_prop，所以順序其實不影響結果）。
		$data->set_price( $price );
	}

	/**
	 * 解析 OverrideItem + variation 的價格三元組
	 *
	 * 委派 Domain\Service\OverridePriceResolver，與 SingleProductPriceHook（單品頁）
	 * 共用同一份 fallback 語意。**不要**在這裡另外實作一份——本檔原本就有一份精簡版
	 * （只回傳單一價格），與單品頁的三元組版分歧，正是「購物車劃線原價顯示商品原始原價」
	 * 這個 bug 的成因。
	 *
	 * @param OverrideItem $item         覆寫項目
	 * @param int          $variation_id Variation ID（0 表示無變體）
	 *
	 * @return array{price: string, regular: string|null, sale: string|null}|null
	 *         該 item 完全沒有 override 時回 null（不寫 meta，讓商品走原價）
	 */
	private function resolve_override_prices( OverrideItem $item, int $variation_id ): ?array {
		return $this->resolver->resolve( $item, $variation_id > 0 ? $variation_id : null );
	}

	/**
	 * 將 cart_item 的 product 價格還原回商品原始價格（含 regular / sale）
	 *
	 * 用於：驗章失敗後，若 product 物件曾被前一次 calculate_totals 套過 override 價，
	 * 需要主動還原（WC 不會自己重置已經被 setter 改過的 product 物件）。
	 *
	 * ⚠️ 必須連 regular / sale 一起還原，不能只還原 price：同一個 request 內
	 * calculate_totals() 會被呼叫多次，若第一次驗章通過（已寫入覆寫 regular/sale）、
	 * 第二次因竄改而失敗，只還原 price 會讓錯誤的劃線價殘留在畫面上。
	 *
	 * @param \WC_Cart $cart cart 實例
	 * @param string   $key  cart_item key
	 *
	 * @return void
	 */
	private function reset_to_original_price( \WC_Cart $cart, string $key ): void {
		if ( ! isset( $cart->cart_contents[ $key ]['data'] ) ) {
			return;
		}
		$data = $cart->cart_contents[ $key ]['data'];
		if ( ! $data instanceof \WC_Product ) {
			return;
		}

		$product_id = (int) $data->get_id();
		$fresh      = \wc_get_product( $product_id );
		if ( ! $fresh instanceof \WC_Product ) {
			return;
		}

		$sale = (string) $fresh->get_sale_price( 'edit' );
		$reg  = (string) $fresh->get_regular_price( 'edit' );
		// 🔴 S-4：唯一權威的「商品原始價格」是 `_price`，不是由 regular/sale 推導出來的值。
		$own = (string) $fresh->get_price( 'edit' );

		/*
		* 🔴 無定價商品的特有情境（2026-08）：商品自身**真的沒有價格**時，
		* 「fallback 回原價」的那個原價**根本不存在**。若照舊 set_price('')，
		* WooCommerce 會在計算總額時把它當 0 ⇒ 顧客拿到免費商品，
		* 等於把「竄改 ⇒ 付原價」倒轉成「竄改 ⇒ 免費」。
		*
		* ⚠️ 守門必須用 get_price('edit')，**不可**用「sale 與 regular 皆空」（S-4 / TD-60）。
		*    兩者不等價：匯入 / 程式建立 / 部分商品型別常見**只寫 `_price`**——WooCommerce
		*    自己在 includes/data-stores/class-wc-product-data-store-cpt.php:818-823 的註解
		*    明寫「`_price` can also be set as a temporary active price for a product」，
		*    也就是說這是**合法狀態、不是資料損毀**。此時
		*    price='500' / regular='' / sale='' / is_purchasable()=true，是一個完全可正常
		*    販售的商品，舊守門卻會在驗章失敗時把它**直接刪出購物車**。
		*    觸發不需要攻擊者：輪替 AUTH_SALT（標準資安事件處置動作）會讓全站簽章一次失效
		*    ⇒ 所有這類品項從所有顧客的購物車靜默消失。
		*    這直接違反本檔核心不變量「驗章失敗一律 fallback 至商品原始價格」。
		*
		* 一般商品不會走到這裡（它們有價格），所以本分支只影響本次新支援的無定價商品。
		*
		* 🔴 在 apply_override() 的迴圈內移除，安全性靠**兩件事**，不是一件：
		*
		*   1. 迴圈跑的是 $cart->get_cart() 回傳的陣列**複本**，remove_cart_item()
		*      動的是 $cart->cart_contents ⇒ 不會有「邊迭代邊改集合」的問題。
		*
		*   2. remove_cart_item() **會**遞迴回本 hook，靠 apply_override() 的
		*      $applying 旗標擋下。⚠️ 這一句本檔原本寫成
		*      「WC_Cart::remove_cart_item() **不會**呼叫 calculate_totals()，
		*        所以不會遞迴回本 hook」——**與實際行為相反**，而且被當成已驗證事實沿用一輪。
		*      逐行查證（WooCommerce 10.1.4）：
		*        includes/class-wc-cart.php:1372 `do_action( 'woocommerce_cart_item_removed', ... )`
		*        includes/class-wc-cart.php:131  `add_action( 'woocommerce_cart_item_removed',
		*                                          [ $this, 'calculate_totals' ], 20, 0 )`
		*      實測巢狀深度（探針掛 priority 998/1000）：修正前 N 個失敗品項 ⇒ 深度 N
		*      （N=2/6/12 → 2/6/12）；加上旗標後 calculate_totals 深度恆為 2、
		*      **apply_override body 深度恆為 1**（完整數字見 apply_override() 的註解）。
		*
		* ⇒ 若日後移除 $applying 旗標，這裡就必須改成「收集 key、迴圈結束後再刪」，
		*   否則深度會立刻回到與失敗品項數 1:1 成長。
		*/
		if ( '' === $own ) {
			$cart->remove_cart_item( $key );

			if ( \function_exists( 'wc_add_notice' ) ) {
				\wc_add_notice( \__( '購物車中有商品已無法購買，已為您移除。', 'power_shop' ), 'error' );
			}

			return;
		}

		$data->set_regular_price( $reg );
		$data->set_sale_price( $sale );

		/*
		* 🔴 還原值同樣必須取 `_price`，理由與上面的守門完全相同：
		* `_price=500 / regular='' / sale=''` 的商品若用 `sale ?: reg` 推導，會得到空字串
		* ⇒ set_price('') ⇒ 品項變成無定價 ⇒ 下一個請求被閘門 ③ 判定不可購買而移除。
		* 只改守門、不改這一行，等於把 bug 從「立刻刪除」延後成「下一個請求刪除」，
		* 顧客看到的症狀完全一樣。
		*
		* 一般商品不受影響：它們的 `_price` 本來就等於 `sale ?: regular`
		* （WC_Product_Data_Store_CPT::handle_updated_props() 在 :825-833 維持這個不變量）。
		*/
		$data->set_price( $own );
	}

	/**
	 * 移除 cart_item 上的所有 _profit_* meta（驗章失敗時讓商品退回原價）
	 *
	 * @param \WC_Cart $cart cart 實例
	 * @param string   $key  cart_item key
	 *
	 * @return void
	 */
	private function strip_profit_meta( \WC_Cart $cart, string $key ): void {
		if ( ! isset( $cart->cart_contents[ $key ] ) ) {
			return;
		}
		foreach ( self::META_KEYS_ALL as $meta_key ) {
			unset( $cart->cart_contents[ $key ][ $meta_key ] );
		}
	}

	/**
	 * 將 nullable 價格標準化為簽章 / meta 用的字串
	 *
	 * 🔴 null 與空字串**絕不可**進 wc_format_decimal( $x, '' )：
	 *    該函式對空字串會回 **'0.00'**（不是 ''）——`$dp = ''` 會走 number_format 分支，
	 *    `floatval('')` 是 0.0，於是得到 '0.00'。若把「未覆寫原價」的 null 這樣轉換，
	 *    購物車會顯示「原價 NT$0」的劃線價，而且沒有任何錯誤訊息。
	 *
	 * 因此這裡**提前 return ''**，不進 WC 函式。`''` 在下游的語意是
	 * 「不覆寫原價」／「無特價」，見 apply_prices_to_product()。
	 *
	 * @param string|null $price 原始價格字串；null 表示未覆寫
	 *
	 * @return string 標準化後的價格字串；未覆寫時為空字串
	 */
	private static function normalize_nullable_price( ?string $price ): string {
		if ( null === $price || '' === $price ) {
			return '';
		}
		return self::normalize_price_for_signature( $price );
	}

	/**
	 * 將價格字串標準化為簽章用的標準形式
	 *
	 * 使用 wc_format_decimal( $price, '' )。**實際行為**（已實測，2026-08-03）：
	 *   '888'     → '888.00'
	 *   '888.0'   → '888.00'
	 *   '888.00'  → '888.00'
	 *   '888.5'   → '888.50'
	 *   '888.55'  → '888.55'
	 *
	 * 也就是說它會**補足到 wc_get_price_decimals() 位小數**，而不是 trim trailing zeros——
	 * 第二參數 `$dp = ''` 讓 wc_format_decimal 走 number_format 分支，而第三參數
	 * `$trim_zeros` 預設 false、本呼叫也沒有傳 true。
	 *
	 * ⚠️ **不要為了讓行為符合「trim trailing zeros」的直覺去改它。** 改了會讓所有既有 v1
	 *    簽章的 payload 價格字串從 '888.00' 變成 '888' → 既有購物車驗章全數失敗 →
	 *    strip meta + reset 原價 → **分潤價靜默消失**（沒有錯誤訊息，站長要對帳才會發現）。
	 *    產品端零影響：_profit_price_override 不對外顯示，set_price('888.00') 之後所有 WC
	 *    計算與 wc_price() 顯示都走數值比較，'888.00' 與 '888' 完全等價。
	 *
	 * 必須在 sign 端、verify 端、set_price 端、寫入 meta 端都統一套用，
	 * 避免任何路徑上的格式漂移造成簽章驗證失敗 → 靜默 fallback 至原價的商家損失。
	 *
	 * @param string $price 原始價格字串
	 *
	 * @return string 標準化後的價格字串
	 */
	private static function normalize_price_for_signature( string $price ): string {
		// 🔴 委派給驗章器，不在這裡重寫一份：sign 端（本 hook）與 verify 端
		// （CartProfitMetaVerifier）只要 normalize 形式差一個字元，驗章就會恆失敗，
		// 而症狀是「分潤價靜默消失」。兩份實作靠人維持一致正是本次重構要消滅的東西。
		return CartProfitMetaVerifier::normalize_price( $price );
	}

	/**
	 * 記錄簽章不一致警告（不洩漏 partner_term_id / 原始價格，只記 shop_id 與 cart_item key hash）
	 *
	 * 過濾換行字元防 log injection。
	 *
	 * @param int    $shop_id       來源賣場 ID
	 * @param string $cart_item_key cart_item key
	 *
	 * @return void
	 */
	private function log_signature_mismatch( int $shop_id, string $cart_item_key ): void {
		$safe_key = preg_replace( '/[^a-zA-Z0-9]/', '', $cart_item_key );
		$message  = sprintf(
			'[ProfitShop] cart signature mismatch shop_id=%d key=%s',
			$shop_id,
			(string) $safe_key
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log( $message );
	}
}
