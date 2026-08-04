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
	 */
	private const META_SHOP_ID = '_profit_shop_id';

	/**
	 * Cart_item meta 鍵：來源分潤夥伴 term ID
	 */
	private const META_PARTNER_TERM_ID = '_profit_partner_term_id';

	/**
	 * Cart_item meta 鍵：override 後的有效價格（normalize 後的字串）
	 */
	private const META_PRICE_OVERRIDE = '_profit_price_override';

	/**
	 * Cart_item meta 鍵：override 後的原價（'' 表示不覆寫原價）
	 */
	private const META_REGULAR_OVERRIDE = '_profit_regular_price_override';

	/**
	 * Cart_item meta 鍵：override 後的特價（'' 表示無特價）
	 */
	private const META_SALE_OVERRIDE = '_profit_sale_price_override';

	/**
	 * Cart_item meta 鍵：簽章
	 */
	private const META_SIGNATURE = '_profit_signature';

	/**
	 * V1 / v2 共同必備的 meta
	 *
	 * @var string[]
	 */
	private const META_KEYS_BASE = [
		self::META_SHOP_ID,
		self::META_PARTNER_TERM_ID,
		self::META_PRICE_OVERRIDE,
		self::META_SIGNATURE,
	];

	/**
	 * 只有 v2 簽章才必備的 meta
	 *
	 * @var string[]
	 */
	private const META_KEYS_V2 = [
		self::META_REGULAR_OVERRIDE,
		self::META_SALE_OVERRIDE,
	];

	/**
	 * 全部 meta（session restore / strip 用）
	 *
	 * 三處（restore_from_session / strip_profit_meta / has_all_profit_meta）共用同一份
	 * 常數，是刻意的：這三份清單以前是各自展開的字面陣列，新增 meta 時漏改任何一處都會
	 * 造成「session 後 meta 遺失」或「合法 cart 被誤判成 tampering」，而且完全靜默。
	 *
	 * @var string[]
	 */
	private const META_KEYS_ALL = [
		...self::META_KEYS_BASE,
		...self::META_KEYS_V2,
	];

	/**
	 * 價格解析器（與 SingleProductPriceHook 共用同一份取價語意）
	 *
	 * @var OverridePriceResolver
	 */
	private readonly OverridePriceResolver $resolver;

	/**
	 * 建構子
	 *
	 * 在非純 admin context（front-end / admin-ajax）時自動註冊三個 WC filter / action。
	 *
	 * @param ProfitShopRepositoryInterface $shops      賣場 Repository（DIP，禁鴨子型別）
	 * @param CartPriceSignatureService     $signatures 簽章服務
	 * @param OverridePriceResolver|null    $resolver   價格解析器；null 時建立預設實例（純 Domain 物件、無狀態）
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shops,
		private readonly CartPriceSignatureService $signatures,
		?OverridePriceResolver $resolver = null
	) {
		$this->resolver = $resolver ?? new OverridePriceResolver();

		// admin context guard：純後台（非 ajax）不掛 hook，避免影響後台 cart manipulation
		if ( \is_admin() && ! \wp_doing_ajax() ) {
			return;
		}

		\add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 10, 3 );
		\add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'restore_from_session' ], 10, 2 );
		\add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_override' ], 999 );
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

		$item = $shop->items[ $product_id ] ?? null;

		if ( ! $item instanceof OverrideItem ) {
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
		$cart_item_data[ self::META_SIGNATURE ]        = $this->signatures->sign(
			$shop_id,
			$shop->partner_term_id,
			$normalized_price,
			$normalized_regular,
			$normalized_sale
		);

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
	 * @param \WC_Cart             $cart      cart 實例
	 * @param string               $key       cart_item key
	 * @param array<string, mixed> $item      cart_item
	 * @param string|null          $version   payload 版本（'v2' / 'v1' / null）
	 * @param string               $signature 簽章字串
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
		if ( null === $version ) {
			return false;
		}

		$requires_v2_meta = ( 'v2' === $version );

		if ( ! $this->has_all_profit_meta( $item, $requires_v2_meta ) ) {
			return false;
		}

		$shop_id         = (int) $item[ self::META_SHOP_ID ];
		$partner_term_id = (int) $item[ self::META_PARTNER_TERM_ID ];
		// J-2 必修：verify 端必須與 sign 端用同一 normalize 形式比對，避免格式漂移造成靜默 fallback
		$price = self::normalize_price_for_signature( (string) $item[ self::META_PRICE_OVERRIDE ] );

		if ( ! $requires_v2_meta ) {
			// 🔴 v1 legacy 相容路徑：v1 payload 從未包含 regular/sale，簽章也就沒有保護過它們。
			// 即使 cart_item 上出現這兩筆 meta（可能是攻擊者注入），一律**忽略**，只套 price。
			// 這裡刻意不把「有 regular meta 的 v1 cart」判成 tampering——那會讓改版前的
			// 合法舊購物車在某些 plugin 順手加 meta 的情況下被誤殺、分潤價靜默消失。
			if ( ! $this->signatures->verify( $shop_id, $partner_term_id, $price, $signature ) ) {
				return false;
			}

			$this->apply_prices_to_product( $cart, $key, $price, null, null );
			return true;
		}

		$regular = (string) $item[ self::META_REGULAR_OVERRIDE ];
		$sale    = (string) $item[ self::META_SALE_OVERRIDE ];

		if ( ! $this->signatures->verify( $shop_id, $partner_term_id, $price, $signature, $regular, $sale ) ) {
			return false;
		}

		$this->apply_prices_to_product( $cart, $key, $price, $regular, $sale );
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

		$data->set_regular_price( $reg );
		$data->set_sale_price( $sale );
		$data->set_price( '' !== $sale ? $sale : $reg );
	}

	/**
	 * 檢查 cart_item 是否完整含必備的 _profit_* meta
	 *
	 * ⚠️ 新的 regular / sale 兩筆 meta **依簽章版本條件必填**，不可無條件必填：
	 * 改版前寫入的 v1 cart_item 只有 4 筆 meta，判成必填會讓**所有既有購物車**
	 * 一次被當成 tampering → strip + reset → 分潤價靜默消失。
	 *
	 * @param array<string, mixed> $item        cart_item
	 * @param bool                 $require_v2  是否額外要求 v2 專屬的 regular / sale meta
	 *
	 * @return bool 完整為 true
	 */
	private function has_all_profit_meta( array $item, bool $require_v2 ): bool {
		$required = $require_v2 ? self::META_KEYS_ALL : self::META_KEYS_BASE;

		foreach ( $required as $key ) {
			if ( ! isset( $item[ $key ] ) ) {
				return false;
			}
		}
		return true;
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
		// wc_format_decimal 第二參數空字串 = 走 number_format 分支（補足預設小數位）
		return (string) \wc_format_decimal( $price, '' );
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
