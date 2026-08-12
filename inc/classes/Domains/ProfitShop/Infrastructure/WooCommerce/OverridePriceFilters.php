<?php
/**
 * 賣場覆寫價的 WooCommerce 價格 filter 組（可 arm / disarm）
 *
 * 存在理由：把「要掛哪些 WC filter、怎麼把 OverridePriceResolver 的三元組餵回 WC」
 * 這段語意收斂成單一實作，供兩個**生命週期完全不同**的使用端共用：
 *   - SingleProductPriceHook：單品頁，命中後整個請求都不解除
 *   - ProfitShopFrontData::build_variations()：只包住一次 get_available_variations()，
 *     以 try/finally 保證解除
 *
 * 不共用的話 build_variations() 就得自己再寫一份取價 + filter 對映，那會是本專案第四份
 * fallback chain。Phase 13 的教訓是：兩份靠人維持一致的取價邏輯一定會分歧，而且分歧時
 * **兩邊都「正常運作」、沒有任何錯誤訊息**（見 Domain\Service\OverridePriceResolver 的
 * class docblock）。
 *
 * ═══ 🔴 為什麼「讓變體真的有價格」是唯一正確的修法 ═══
 *
 * WooCommerce 有四道**互相獨立**的閘門會擋下「自身沒有價格」的商品／變體：
 *
 *   ① includes/class-wc-product-variable.php:335
 *      get_available_variations() 內 `! $variation->variation_is_visible()` → continue
 *      ⇒ 影響「顯示」：變體根本不會出現在清單裡
 *   ② includes/class-wc-cart.php:1229
 *      WC_Cart::add_to_cart() 內 `! $product_data->is_purchasable()` → throw
 *      ⇒ 影響「加購」
 *   ③ includes/class-wc-cart-session.php:183
 *      每個載入購物車的請求都會跑，`! apply_filters('woocommerce_cart_item_is_purchasable', …)`
 *      → **把品項從購物車移除**並顯示「已無法購買」
 *      ⇒ 影響「加完之後還留不留得住」
 *   ④ src/StoreApi/Utilities/CartController.php:270 / :688
 *      `! $product->is_purchasable()` → NotPurchasableException
 *      ⇒ 影響 Cart Block / Store API / Block 結帳
 *
 * 這四道的判斷式最後都收斂到**同一個條件** `'' !== $this->get_price()`：
 *   - variation_is_visible()：includes/class-wc-product-variation.php:593
 *   - WC_Product_Variation::is_purchasable()：同檔 :562（委派 variation_is_visible + parent）
 *   - WC_Product::is_purchasable()：includes/abstracts/abstract-wc-product.php:1678
 * 而 `get_price()` 走 view context（includes/abstracts/abstract-wc-data.php:882 的
 * `apply_filters( $this->get_hook_prefix() . $prop, … )`），會經過本類註冊的 filter。
 *
 * ⇒ **改價格 = 四道全開；改任何一道閘門 = 只開一道**，而且會讓
 *   「is_purchasable() 為 true 但 get_price() 仍是 ''」這種 WooCommerce 從未預期的狀態成立
 *   （驗章失敗時 CartPriceOverrideHook::reset_to_original_price() 會 set_price('')
 *   ⇒ 顧客拿到免費商品，把「竄改 ⇒ 付原價」倒轉成「竄改 ⇒ 免費」）。
 *
 * ═══ 作用範圍如何收斂（為什麼不會抵銷 CartPriceOverrideHook 的保護）═══
 *
 * ⚠️ 這一段曾經寫成「購物車 / 結帳**請求**不會走到這兩條路徑，所以 CartPriceOverrideHook
 *    的保護完全不受影響」。**那個論證是錯的**，前提是「請求類型」——但購物車與商品頁
 *    在**同一個 request 裡並存**：SingleProductPriceHook 在 template_redirect prio 20
 *    掛上 filter 之後**整個 request 都不解除**，佈景主題 / 外掛（mini-cart fragments、
 *    免運進度條、幣別切換器）只要在渲染期再呼叫一次 WC_Cart::calculate_totals()，
 *    一筆**完全沒有 _profit_* meta、沒有簽章**的 cart item 就會被套上覆寫價
 *    （探針實測：商品原價 1000、賣場覆寫 27 ⇒ filter 未掛時 total=1000.00、
 *    掛上後 total=27.00），而 apply_override() 根本沒碰它（`isset(META_SHOP_ID)`
 *    就 continue）⇒ 整條 HMAC 保護被繞過；且 woocommerce_after_calculate_totals
 *    prio 1000 的 set_session() 會把被污染的 line_total 寫進 session。
 *    （specs/tech-debt.md TD-62。這與 TD-20 / TD-54 是同一種失效模式：寫進註解的宣稱
 *    與實際行為相反，而且 lint / test / type check 一個都攔不到。）
 *
 * 現在的收斂**不靠註冊時機，靠一條不變量**：
 *
 *   🔴 本類永遠不影響任何一筆 cart item 的價格。
 *      cart item 的價格由 CartPriceOverrideHook + HMAC 簽章獨佔決定。
 *
 * 執行點是 resolve_for_product() 開頭的 is_cart_item_object() 身分比對，
 * 判準是 WC_Product_Factory::get_product() 的無實例快取性質（見該方法的註解）。
 *
 * 註冊時機仍然照舊收斂，但那是**縱深防禦的第二層、不是唯一一層**：
 *   - SingleProductPriceHook：is_product() + `?profit_shop_id=` + 四道閘門（賣場存在 /
 *     status=publish / 販售期間內 / 商品屬於該賣場），且只在 template_redirect 判定
 *     命中後才 add_filter
 *   - build_variations()：只在 renderer / shortcode 已驗過賣場的渲染流程內 arm，
 *     且用 try/finally 保證在 get_available_variations() 回來的當下立刻 disarm
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\OverridePriceResolver;

/**
 * 賣場覆寫價 filter 組
 */
final class OverridePriceFilters {

	/**
	 * Filter 註冊表：hook => [ callback 方法名, accepted_args ]
	 *
	 * 與 SingleProductPriceHook::maybe_register_filters() 的註冊清單逐字對應
	 * （該類刻意保留自己的 add_filter 清單與公開 callback，讓 SingleProductPriceHookTest
	 * 的 has_filter 斷言與 unregister_filters() 對照表零修改，重構正確性因此有直接證據）。
	 *
	 * 前三組是 view context 的單一價格；`woocommerce_variation_prices_*` 另外一組是因為
	 * WC 讀變體價格區間時走 'edit' context（includes/class-wc-product-variable-data-store-cpt.php
	 * 的 read_price_data()），前者對它無效。
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	private const FILTER_MAP = [
		'woocommerce_product_get_price'                   => [ 'filter_price', 2 ],
		'woocommerce_product_get_regular_price'           => [ 'filter_regular_price', 2 ],
		'woocommerce_product_get_sale_price'              => [ 'filter_sale_price', 2 ],
		'woocommerce_product_variation_get_price'         => [ 'filter_price', 2 ],
		'woocommerce_product_variation_get_regular_price' => [ 'filter_regular_price', 2 ],
		'woocommerce_product_variation_get_sale_price'    => [ 'filter_sale_price', 2 ],
		'woocommerce_variation_prices_price'              => [ 'filter_variation_prices_price', 3 ],
		'woocommerce_variation_prices_regular_price'      => [ 'filter_variation_prices_regular_price', 3 ],
		'woocommerce_variation_prices_sale_price'         => [ 'filter_variation_prices_sale_price', 3 ],
		'woocommerce_get_variation_prices_hash'           => [ 'filter_variation_prices_hash', 3 ],
	];

	/**
	 * 命中的賣場 ID（未設定為 0）
	 *
	 * @var int
	 */
	private int $shop_id = 0;

	/**
	 * 命中的父商品 ID（未設定為 0）
	 *
	 * @var int
	 */
	private int $product_id = 0;

	/**
	 * 命中的覆寫項目
	 *
	 * @var OverrideItem|null
	 */
	private ?OverrideItem $item = null;

	/**
	 * 是否已由 arm() 註冊 filter
	 *
	 * @var bool
	 */
	private bool $armed = false;

	/**
	 * 建構子
	 *
	 * @param OverridePriceResolver $resolver 價格解析器（唯一取價真相，見 class docblock）
	 */
	public function __construct( private readonly OverridePriceResolver $resolver ) {}

	/**
	 * 設定作用情境，但**不**註冊 filter
	 *
	 * 給「自己管理 add_filter 清單」的呼叫端用（SingleProductPriceHook）。
	 *
	 * @param int          $shop_id    賣場 ID
	 * @param int          $product_id 父商品 ID
	 * @param OverrideItem $item       覆寫項目
	 *
	 * @return void
	 */
	public function set_context( int $shop_id, int $product_id, OverrideItem $item ): void {
		$this->shop_id    = $shop_id;
		$this->product_id = $product_id;
		$this->item       = $item;
	}

	/**
	 * 設定情境並註冊全部 filter
	 *
	 * ⚠️ 必須與 disarm() 成對呼叫，且呼叫端應以 try/finally 保證解除——filter 殘留到
	 * 同一個 request 的購物車階段，等於讓一組未經簽章保護的覆寫價常駐生效。
	 *
	 * ⚠️ 刻意不支援巢狀 arm：情境是單一組狀態欄位，巢狀會讓外層 disarm 之後內層的
	 * shop_id / item 仍殘留在物件上。因此開頭先無條件 disarm()。
	 *
	 * @param int          $shop_id    賣場 ID
	 * @param int          $product_id 父商品 ID
	 * @param OverrideItem $item       覆寫項目
	 *
	 * @return void
	 */
	public function arm( int $shop_id, int $product_id, OverrideItem $item ): void {
		$this->disarm();
		$this->set_context( $shop_id, $product_id, $item );

		foreach ( self::FILTER_MAP as $hook => $spec ) {
			\add_filter( $hook, [ $this, $spec[0] ], 10, $spec[1] );
		}

		$this->armed = true;
	}

	/**
	 * 解除註冊並清空情境（冪等，未 arm 時呼叫無副作用）
	 *
	 * @return void
	 */
	public function disarm(): void {
		if ( $this->armed ) {
			foreach ( self::FILTER_MAP as $hook => $spec ) {
				\remove_filter( $hook, [ $this, $spec[0] ], 10 );
			}
			$this->armed = false;
		}

		$this->shop_id    = 0;
		$this->product_id = 0;
		$this->item       = null;
	}

	/**
	 * 是否處於 arm 狀態（測試與除錯用）
	 *
	 * @return bool
	 */
	public function is_armed(): bool {
		return $this->armed;
	}

	// ========== Filter callbacks：一般 / 變體商品物件 ==========

	/**
	 * 覆寫 get_price()（實際結帳價，與購物車一致）
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed 覆寫後的價格字串；未命中時原樣回傳
	 */
	public function filter_price( $value, $product ) {
		$resolved = $this->resolve_for_product( $product );
		return null === $resolved ? $value : $resolved['price'];
	}

	/**
	 * 覆寫 get_regular_price()
	 *
	 * 覆寫項目未設定原價時保留商品原本的原價（只換特價的情境）。
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed
	 */
	public function filter_regular_price( $value, $product ) {
		$resolved = $this->resolve_for_product( $product );
		if ( null === $resolved || null === $resolved['regular'] ) {
			return $value;
		}
		return $resolved['regular'];
	}

	/**
	 * 覆寫 get_sale_price()
	 *
	 * 覆寫存在但未設定特價時回空字串（＝沒有特價）。
	 * 若保留商品原本的特價，畫面會出現「原價劃掉 → 特價」但實收卻是覆寫原價的矛盾。
	 *
	 * @param mixed $value   原始值
	 * @param mixed $product WC_Product 實例
	 *
	 * @return mixed
	 */
	public function filter_sale_price( $value, $product ) {
		$resolved = $this->resolve_for_product( $product );
		if ( null === $resolved ) {
			return $value;
		}
		return $resolved['sale'] ?? '';
	}

	// ========== Filter callbacks：變體價格區間（三參數簽章） ==========

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
		unset( $parent );
		return $this->filter_price( $value, $variation );
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
		unset( $parent );
		return $this->filter_regular_price( $value, $variation );
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
		unset( $parent );
		return $this->filter_sale_price( $value, $variation );
	}

	/**
	 * 把 shop_id 併入 WC 變體價格快取的 hash
	 *
	 * WC 把變體價格陣列存在 transient，hash 只含掛在 `woocommerce_variation_prices_*` 上的
	 * **callback 名稱**、不含賣場 ID（includes/abstracts/abstract-wc-product-data-store-cpt.php
	 * 的 get_price_hash()）。不加這條的話「賣場 A」與「賣場 B」會算出同一把 key →
	 * 互相讀到對方的快取價格。
	 *
	 * @param mixed $price_hash  WC 組出的 hash 來源陣列
	 * @param mixed $product     父商品
	 * @param mixed $for_display 是否為顯示用（未使用，WC 簽章要求）
	 *
	 * @return mixed 併入 shop_id 後的 hash 來源
	 */
	public function filter_variation_prices_hash( $price_hash, $product, $for_display = false ) {
		unset( $for_display );

		if ( ! $product instanceof \WC_Product || (int) $product->get_id() !== $this->product_id ) {
			return $price_hash;
		}

		if ( ! is_array( $price_hash ) ) {
			$price_hash = [ $price_hash ];
		}

		$price_hash['power_shop_profit_shop_id'] = $this->shop_id;

		/*
		* 🔴 F-1：只放 shop_id 不夠。
		*
		* WC 把變體價格陣列存在 transient（`_transient_wc_var_prices_{product_id}`），
		* hash 來源只有「掛在 woocommerce_variation_prices_* 上的 callback 名稱」
		* （includes/abstracts/abstract-wc-product-data-store-cpt.php 的 get_price_hash()）；
		* 而站長改的是**賣場** post 的 _profit_shop_items，不會觸發 WooCommerce 對**商品**
		* transient 的任何清除。⇒ 改完覆寫價後，單品頁的價格區間會繼續顯示已不存在的舊價格
		* （實測：清掉某變體的覆寫後，卡片與 data_product_variations 都正確，只有單品頁的
		* 區間仍是舊的 NT$27 – NT$45；手動 DELETE 該 transient 後立刻恢復）。
		*
		* Phase 9 起就有這個缺口，但當時覆寫只是「平移既有區間」；本次改動後覆寫決定
		* **變體存不存在**，後果從「數字不對」升級成「幽靈商品」。
		*
		* 併入覆寫內容本身的指紋 ⇒ 值一變就落到新的快取分桶。舊分桶不刪除、只是不再被讀到
		* （WC transient 有 TTL，商品儲存時也會清）。
		*/
		if ( $this->item instanceof OverrideItem ) {
			$price_hash['power_shop_override_fingerprint'] = md5( (string) \wp_json_encode( $this->item->to_array() ) );
		}

		return $price_hash;
	}

	// ========== Helpers ==========

	/**
	 * 依 WC_Product 實例解析出應套用的價格三元組
	 *
	 * @param mixed $product WC_Product 實例
	 *
	 * @return array{price: string, regular: string|null, sale: string|null}|null
	 *         未命中本賣場 / 無任何覆寫時回 null（代表不介入）
	 */
	private function resolve_for_product( $product ): ?array {
		if ( ! $this->item instanceof OverrideItem || ! $product instanceof \WC_Product ) {
			return null;
		}

		/*
		* 🔴 S-6 / TD-62：本類（顯示情境）**永遠不得影響任何一筆 cart item 的價格**。
		* 完整成因與實測數字見 class docblock；此處只記判準為何是「物件身分」。
		*
		* 判準用**物件身分**而非 product ID，因為那正是本套設計的既有支點：
		* includes/class-wc-product-factory.php 的 WC_Product_Factory::get_product()
		* 每次都 `return new $classname( $product_id )`、**不做實例快取**
		* ⇒ 購物車裡的實例（includes/class-wc-cart-session.php:234 存進
		* cart_contents['data'] 的那一個）與單品頁 / get_available_variations() 拿到的
		* 實例必然是不同物件 ⇒ `===` 比對零誤判：同一個商品在單品頁照樣套覆寫價，
		* 只有「購物車裡的那一份」被排除。
		* 用 product ID 比對會把單品頁一起排掉、直接廢掉 Phase 9。
		*/
		if ( self::is_cart_item_object( $product ) ) {
			return null;
		}

		if ( $product instanceof \WC_Product_Variation ) {
			if ( (int) $product->get_parent_id() !== $this->product_id ) {
				return null;
			}

			$variation_id = (int) $product->get_id();

			/*
			* 🔴 R-4：站長從未定價的「半成品變體」，只有**變體層**覆寫能讓它成為商品。
			*
			* 判斷本身已抽成 PricelessRescuePolicy——**加購路徑（CartPriceOverrideHook）
			* 必須用同一份**。原本這裡是 30 行行內判斷、加購層完全沒有，於是前台顯示
			* 「暫無可購買規格」的半成品變體仍可用手工 POST 加購成功（TD-56）。
			* 留兩份「get_price('edit') === '' && null === get_variation_override()」
			* 就是再造一次「靠人維持兩份邏輯一致」——那正是 OverridePriceResolver
			* 當初存在要消滅的失敗模式。完整推導見該 class 的 docblock。
			*/
			if ( ! PricelessRescuePolicy::should_rescue_priceless( $product, $this->item ) ) {
				return null;
			}

			return $this->resolve_prices( $variation_id );
		}

		if ( (int) $product->get_id() !== $this->product_id ) {
			return null;
		}

		return $this->resolve_prices( null );
	}

	/**
	 * 這個實例是否正是購物車裡某一筆 cart item 的商品物件
	 *
	 * 讀 `cart_contents` 而非 `get_cart()`：後者在
	 * `woocommerce_cart_loaded_from_session` 尚未跑完時會反過來呼叫
	 * `get_cart_from_session()`，可能遞迴；`cart_contents` 是 WC_Cart 的 public 屬性，
	 * 純讀取無副作用（CartPriceOverrideHook 也是這樣用的）。
	 *
	 * 成本：cart 為空 / 未載入時 0 次比較；否則 N 次 `===`（N = 購物車品項數，
	 * 實務上個位數），且**無任何查詢**——cart_contents 在 wp_loaded 就已載入完畢。
	 *
	 * @param \WC_Product $product 要判斷的商品實例
	 *
	 * @return bool 是購物車品項自己的物件為 true
	 */
	private static function is_cart_item_object( \WC_Product $product ): bool {
		if ( ! \function_exists( 'WC' ) ) {
			return false;
		}

		$wc = \WC();
		if ( ! \is_object( $wc ) || ! isset( $wc->cart ) || ! $wc->cart instanceof \WC_Cart ) {
			return false;
		}

		foreach ( $wc->cart->cart_contents as $cart_item ) {
			if ( isset( $cart_item['data'] ) && $cart_item['data'] === $product ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 解析價格三元組（委派 Domain\Service\OverridePriceResolver）
	 *
	 * ⚠️ Fallback 語意刻意**不**在本類實作：cart 端（CartPriceOverrideHook）與本類必須
	 * 用同一份規則，兩邊各自複製一份就是價格分歧的成因。要調整取價語意請改
	 * OverridePriceResolver，所有路徑會一起變。
	 *
	 * @param int|null $variation_id 變體 ID；null 表示父商品 / 一般商品
	 *
	 * @return array{price: string, regular: string|null, sale: string|null}|null 無覆寫時回 null
	 */
	private function resolve_prices( ?int $variation_id ): ?array {
		if ( ! $this->item instanceof OverrideItem ) {
			return null;
		}

		return $this->resolver->resolve( $this->item, $variation_id );
	}
}
