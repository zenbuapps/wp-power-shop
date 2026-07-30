<?php
/**
 * 賣場前台購物車 REST API
 *
 * Phase 10：把前台購物車的讀寫全部收斂到本外掛自有的 REST namespace。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest;

use J7\WpUtils\Classes\ApiBase;

/**
 * 賣場前台購物車 API（`/wp-json/power-shop/profit-shop-cart*`）
 *
 * ## 為何走自訂 REST，而不是 admin-ajax / WooCommerce Store API
 *
 * Phase 9 的前台是「寫入走 admin-ajax（`FrontAjax`）、讀取與選運費走 WC Store
 * API」的雙通道，代價是同一個購物車有兩套回應形狀、兩套錯誤碼、兩套 nonce
 * 機制（`power_shop_front` 與 `x-wc-store-api-nonce`），前端得各寫一份處理。
 *
 * 本 class 讓兩邊合而為一：
 *   - 回應一律是 `{ code, message, data }`，`data` 一律是同一顆完整購物車物件，
 *     mutation 後前端不需要再補一次 GET
 *   - 認證一律是 WordPress 的 `wp_rest` nonce（`X-WP-Nonce`），與外掛其他
 *     endpoint 一致
 *   - 金額一律是「最小單位整數字串」（沿用 Store API 慣例，前端除以
 *     `10 ** currency_minor_unit` 還原），避免浮點誤差
 *
 * ## REST context 下 `WC()->cart` 不會自動就緒
 *
 * WooCommerce 只在 frontend 與 `DOING_AJAX` context 呼叫 `wc_load_cart()`；
 * 且 `WC_Cart_Session::init()` 是把 `get_cart_from_session()` 掛在 `wp_loaded`
 * 上，而 REST callback 執行時 `wp_loaded` 早已跑完，掛上去也不會再被觸發。
 * 因此每個 callback 都必須先走 `ensure_cart_ready()`：`wc_load_cart()` 之後
 * 再主動呼叫 `WC_Cart::get_cart()`（其內部會補跑 `get_cart_from_session()`），
 * 與 WooCommerce 自己的 `StoreApi\Utilities\CartController::load_cart()` 同法。
 *
 * ## 為何必須把 `profit_shop_id` 橋接進 `$_POST`（最關鍵）
 *
 * 分潤價格的唯一守門是既有的 hook 鏈，本 class **絕不自行寫入價格或分潤 meta**：
 *
 *   woocommerce_add_cart_item_data
 *     ├─ priority 5   AddToCartHook          三道閘門（賣場存在／status=publish／
 *     │                                       product_id ∈ shop items）
 *     └─ priority 10  CartPriceOverrideHook  寫 4 筆 _profit_* meta + HMAC 簽章
 *   woocommerce_before_calculate_totals
 *     └─ priority 999 CartPriceOverrideHook  hash_equals 驗章 + set_price
 *
 * `AddToCartHook::extract_shop_id_from_request()` 只讀 `$_GET` / `$_POST`，而 PHP
 * 在 `Content-Type: application/json` 時**不會**填 `$_POST` —— 直接呼叫
 * `add_to_cart()` 會讓 shop_id 取不到，靜默走商品原價、分潤失效，且沒有任何錯誤
 * 訊息。所以 `add_to_cart_with_shop_context()` 在呼叫前把 REST param 橋接進
 * `$_POST`，呼叫後（含例外路徑）以 `finally` 還原。
 *
 * 兩條**禁止**的替代做法：
 *   1. 用 `add_to_cart()` 的第 5 個參數 `$cart_item_data` 傳
 *      `[ 'profit_shop_id' => $id ]` —— `AddToCartHook` 開頭有 short-circuit
 *      （`isset( $cart_item_data['profit_shop_id'] )` 就 return），那會**跳過全部
 *      三道閘門**，變成任何人都能對任意商品套任意賣場的分潤價。
 *   2. 在本 class 內複製三閘門驗證邏輯 —— 違反 DRY 與單一真相，兩份規則遲早
 *      走鐘。
 *
 * ## hook 的 admin guard 在 REST context 會通過
 *
 * `AddToCartHook` / `CartPriceOverrideHook` / `CartItemMetaDisplay` 的 guard 都是
 * `is_admin() && ! wp_doing_ajax()`。REST request 走 `/wp-json/`，`is_admin()` 回
 * false，guard 因此不觸發、三個 hook 全部正常掛載 —— 本 class 不需要（也不應該）
 * 為此改動任何 hook 檔案。
 *
 * ## CSRF：nonce 必須「自己驗」，不能只靠 WordPress
 *
 * `rest_cookie_check_errors()` 只在「送了**錯的** nonce」時回 403；
 * 「**完全不送**」時它是 `wp_set_current_user( 0 ); return true;` —— 請求會通過，
 * 只是被降級成訪客。若把 `permission_callback` 全設 `'__return_true'`，攻擊頁面
 * 只要不送 nonce 就能繞過整條 CSRF 防線（WordPress 對**任意** Origin 都回
 * `Access-Control-Allow-Origin` + `Allow-Credentials: true`）。對已登入顧客後果
 * 最嚴重：降級成 uid=0 之後 `WC_Session_Handler::is_session_cookie_valid()` 會判定
 * 「session 屬於已登出使用者」→ `destroy_session()` → **購物車被清空**。
 *
 * 因此除了 `/nonce` 之外的 5 個 endpoint 一律走 `verify_front_nonce()`，明確要求
 * `X-WP-Nonce` 存在且有效。訪客的 `wp_rest` nonce 以 uid=0 驗證即可通過（action
 * `wp_rest` 不以 `woocommerce` 開頭，不受 WC `nonce_user_logged_out` filter 影響），
 * 匿名購物完全不受影響。
 *
 * ## `/nonce` 為何必須擋跨網域來源
 *
 * `/nonce` 沒有（也不能有）nonce 可驗，卻會回傳**全站通用**的 `wp_rest` nonce。
 * 配合 WordPress 無白名單的 CORS 反射，那等於一個「任何網站都讀得到受害者 nonce」
 * 的 oracle，拿到後可打 `wp/v2/*` / `wc/v3/*` 與本外掛 admin endpoint。故該 endpoint
 * 以 `is_allowed_http_origin()` 只服務同源請求（同源 GET 不送 `Origin`，前端的
 * `refreshNonce()` 不受影響）。
 */
final class FrontCartApi extends ApiBase {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 單次加購 / 單項數量上限（防止一次塞爆庫存檢查）
	 *
	 * @var int
	 */
	private const MAX_QUANTITY = 9999;

	/**
	 * 購物車項目 key 的格式（WooCommerce 產生的 md5 hash）
	 *
	 * @var string
	 */
	private const CART_ITEM_KEY_PATTERN = '/^[a-f0-9]{32}$/';

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'power-shop';

	/**
	 * APIs
	 *
	 * ⚠ 註冊順序：`profit-shop-cart/*` 子路徑必須排在裸 `profit-shop-cart` 之前，
	 * 避免被前者攔截。
	 *
	 * 權限一律**不**走 capability（賣場前台是 customer-facing，未登入訪客必須能加購），
	 * 而是：
	 *   - `/nonce`：`'__return_true'` + callback 內的同源檢查（它不可能有 nonce 可驗）
	 *   - 其餘 5 個：`verify_front_nonce()`，明確要求有效的 `X-WP-Nonce`
	 *
	 * 業務層的防護仍在既有 hook 鏈的三道閘門（見本檔類別 docblock）。
	 *
	 * @var array{endpoint: string, method: string, permission_callback: ?callable}[]
	 */
	protected $apis;

	/** Constructor */
	public function __construct() {
		$front_nonce = [ $this, 'verify_front_nonce' ];

		$this->apis = [
			[
				'endpoint' => 'profit-shop-cart/nonce',
				'method' => 'get',
				'permission_callback' => '__return_true',
			],
			[
				'endpoint' => 'profit-shop-cart/add-item',
				'method' => 'post',
				'permission_callback' => $front_nonce,
			],
			[
				'endpoint' => 'profit-shop-cart/update-item',
				'method' => 'post',
				'permission_callback' => $front_nonce,
			],
			[
				'endpoint' => 'profit-shop-cart/remove-item',
				'method' => 'post',
				'permission_callback' => $front_nonce,
			],
			[
				'endpoint' => 'profit-shop-cart/select-shipping-rate',
				'method' => 'post',
				'permission_callback' => $front_nonce,
			],
			[
				'endpoint' => 'profit-shop-cart',
				'method' => 'get',
				'permission_callback' => $front_nonce,
			],
		];

		parent::__construct();
	}

	// ========== Permission callbacks ==========

	/**
	 * 前台購物車的 nonce 驗證（除 `/nonce` 外的所有 endpoint 共用）
	 *
	 * WordPress 的 `rest_cookie_check_errors()` 只擋「錯的 nonce」，不擋「沒送
	 * nonce」（後者被降級成訪客後照樣放行），因此必須自己要求 nonce 必然存在。
	 *
	 * 錯誤碼刻意沿用 core 的 `rest_cookie_invalid_nonce`：前端 `api.ts` 的
	 * `isInvalidNonceError()` 認的就是這個碼，「403 → 換新 nonce 重試一次」的
	 * 自我修復（對應 Phase 5-B 的 CDN 快取優化）因此不需要任何改動就能接上。
	 *
	 * @param \WP_REST_Request $request Request。
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return true|\WP_Error 通過回 true；缺少或無效回 403 WP_Error
	 */
	public function verify_front_nonce( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( \is_string( $nonce ) && '' !== $nonce && \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_cookie_invalid_nonce',
			\__( '驗證碼已失效，請重新整理頁面後再試', 'power_shop' ),
			[ 'status' => 403 ]
		);
	}

	// ========== Endpoint callbacks ==========

	/**
	 * GET /profit-shop-cart/nonce
	 *
	 * 回傳新鮮的 `wp_rest` nonce。賣場頁在 `status=publish` 時刻意不送 nocache
	 * header（Phase 5-B 的 CDN 快取優化），HTML 內的 nonce 可能是幾小時前快取
	 * 的，因此前端在收到 403 `rest_cookie_invalid_nonce` 時要能自救。
	 *
	 * ⚠ 死鎖與解法：nonce 過期時來打這支 endpoint，因為沒帶有效 nonce，
	 * `rest_cookie_check_errors()` 會 `wp_set_current_user( 0 )` 把請求當成訪客，
	 * `wp_create_nonce( 'wp_rest' )` 於是產出 uid=0 的 nonce —— 對已登入使用者
	 * 完全無效，換完還是 403，永遠換不回來。故此處先從 logged_in cookie 還原
	 * 身分再產 nonce（與 WordPress 自己的 `wp_refresh_nonces()` 同一手法）。
	 *
	 * ⚠ 但「還原身分再簽 nonce」讓這支 endpoint 成為一個公開的 nonce oracle：
	 * WordPress 對**任意** Origin 都回 `Access-Control-Allow-Origin` +
	 * `Access-Control-Allow-Credentials: true`（`rest_send_cors_headers()` 沒有任何
	 * 白名單），跨站 JS 便能 `credentials: 'include'` 讀走受害者的**全站通用**
	 * `wp_rest` nonce，轉頭去打 `wp/v2/*` / `wc/v3/*` 與本外掛的 admin endpoint。
	 * core 之所以敢無白名單反射 CORS，前提正是「跨站 JS 拿不到 nonce」——
	 * 連 `admin-ajax.php?action=rest-nonce` 都走 `send_origin_headers()` 的同源檢查。
	 * 因此這裡比照 core：`Origin` 存在且不在 `get_allowed_http_origins()` 內就 403。
	 * 同源 GET 不會送 `Origin`，前端 `refreshNonce()` 完全不受影響。
	 *
	 * @param \WP_REST_Request $request Request（本 endpoint 不讀任何參數）。
	 *
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_shop_cart_nonce_callback( $request ): \WP_REST_Response {
		unset( $request );

		$origin = \get_http_origin();

		if ( \is_string( $origin ) && '' !== $origin && ! \is_allowed_http_origin( $origin ) ) {
			return self::error( 'forbidden_origin', \__( '來源不被允許', 'power_shop' ), 403 );
		}

		if ( ! \is_user_logged_in() ) {
			$user_id = \wp_validate_auth_cookie( '', 'logged_in' );
			if ( $user_id ) {
				\wp_set_current_user( (int) $user_id );
			}
		}

		\nocache_headers();

		return self::ok(
			[ 'nonce' => \wp_create_nonce( 'wp_rest' ) ],
			\__( '已取得新的驗證碼', 'power_shop' )
		);
	}

	/**
	 * GET /profit-shop-cart
	 *
	 * @param \WP_REST_Request $request Request（本 endpoint 不讀任何參數）。
	 *
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_shop_cart_callback( $request ): \WP_REST_Response {
		unset( $request );

		$not_ready = $this->ensure_cart_ready();
		if ( null !== $not_ready ) {
			return $not_ready;
		}

		$this->cart()->calculate_totals();

		return self::ok( $this->build_cart_payload(), \__( '已取得購物車', 'power_shop' ) );
	}

	/**
	 * POST /profit-shop-cart/add-item
	 *
	 * Body：`{ profit_shop_id, id, quantity, variation_id?, variation? }`
	 *
	 * @param \WP_REST_Request $request Request。
	 *
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_shop_cart_add_item_callback( $request ): \WP_REST_Response {
		$not_ready = $this->ensure_cart_ready();
		if ( null !== $not_ready ) {
			return $not_ready;
		}

		$body       = self::read_body( $request );
		$product_id = self::read_non_negative_int( $body, 'id' );

		if ( $product_id <= 0 ) {
			return self::error( 'invalid_product', \__( '缺少商品 ID', 'power_shop' ), 400 );
		}

		$variation_id = self::read_non_negative_int( $body, 'variation_id' );
		$shop_id      = self::read_non_negative_int( $body, 'profit_shop_id' );
		$variation    = self::read_variation( $body );

		// 缺 quantity 視為 1；有給但解析不出正整數（小數 / 負數 / 非數字）一律 400，
		// 不可靜默折成 1 —— 那會讓使用者以為買了 N 件、實際只加購 1 件。
		if ( ! self::field_provided( $body, 'quantity' ) ) {
			$quantity = 1;
		} else {
			$raw_quantity = self::read_int_or_null( $body, 'quantity' );

			if ( null === $raw_quantity || $raw_quantity < 1 ) {
				return self::error( 'invalid_quantity', \__( '數量格式不正確', 'power_shop' ), 400 );
			}

			$quantity = min( $raw_quantity, self::MAX_QUANTITY );
		}

		try {
			$cart_item_key = $this->add_to_cart_with_shop_context(
				$shop_id,
				$product_id,
				$quantity,
				$variation_id,
				$variation
			);
		} catch ( \Throwable $e ) {
			return self::error(
				'add_to_cart_failed',
				self::safe_message( $e->getMessage(), \__( '加入購物車失敗', 'power_shop' ) ),
				400
			);
		}

		if ( ! \is_string( $cart_item_key ) || '' === $cart_item_key ) {
			return self::error(
				'add_to_cart_failed',
				self::take_wc_error( \__( '加入購物車失敗', 'power_shop' ) ),
				400
			);
		}

		$this->cart()->calculate_totals();

		$payload = $this->build_cart_payload();
		// mutation 專屬欄位：與購物車物件同層，供前端樂觀更新對帳。
		$payload['cart_item_key'] = $cart_item_key;
		$payload['product_id']    = $product_id;
		$payload['variation_id']  = $variation_id;
		$payload['quantity']      = $quantity;

		return self::ok( $payload, \__( '已加入購物車', 'power_shop' ) );
	}

	/**
	 * POST /profit-shop-cart/update-item
	 *
	 * Body：`{ cart_item_key, quantity }`（**明確**傳 0 等同移除）
	 *
	 * ⚠ 「解析不出整數」與「使用者要求移除」不可折成同一個值：兩者都當成 0 的話，
	 * 使用者在數量欄鍵入 `2.5` 會直接把該商品從購物車刪掉，而且沒有任何錯誤訊息。
	 * 因此格式錯誤一律 400，只有**明確**收到 0 才走移除。
	 *
	 * ⚠ `WC_Cart::set_quantity()` **不做任何驗證**（只有 `<= 0` 會轉成移除），所以
	 * 「單獨銷售」與庫存上限必須由本層擋 —— 被取代的 WC Store API 是在
	 * `CartController::set_cart_item_quantity()` 呼叫 `QuantityLimits` 驗的。
	 *
	 * @param \WP_REST_Request $request Request。
	 *
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_shop_cart_update_item_callback( $request ): \WP_REST_Response {
		$not_ready = $this->ensure_cart_ready();
		if ( null !== $not_ready ) {
			return $not_ready;
		}

		$body          = self::read_body( $request );
		$cart_item_key = self::read_cart_item_key( $body );

		if ( '' === $cart_item_key ) {
			return self::error( 'invalid_cart_item', \__( '購物車項目不存在', 'power_shop' ), 400 );
		}

		$cart      = $this->cart();
		$cart_item = $cart->get_cart_item( $cart_item_key );

		if ( [] === $cart_item ) {
			return self::error( 'cart_item_not_found', \__( '購物車項目不存在', 'power_shop' ), 404 );
		}

		if ( ! self::field_provided( $body, 'quantity' ) ) {
			return self::error( 'invalid_quantity', \__( '缺少數量', 'power_shop' ), 400 );
		}

		$quantity = self::read_int_or_null( $body, 'quantity' );

		if ( null === $quantity ) {
			return self::error( 'invalid_quantity', \__( '數量格式不正確', 'power_shop' ), 400 );
		}

		if ( 0 === $quantity ) {
			// 明確傳 0 才等同移除，與 WooCommerce set_quantity 的語意一致。
			$cart->remove_cart_item( $cart_item_key );
		} else {
			$quantity = min( $quantity, self::MAX_QUANTITY );

			$invalid = self::validate_quantity_against_stock( $cart_item, $quantity );
			if ( null !== $invalid ) {
				return $invalid;
			}

			$cart->set_quantity( $cart_item_key, $quantity, true );
		}

		$cart->calculate_totals();

		$payload                  = $this->build_cart_payload();
		$payload['cart_item_key'] = $cart_item_key;
		$payload['quantity']      = $quantity;

		return self::ok( $payload, \__( '已更新購物車', 'power_shop' ) );
	}

	/**
	 * POST /profit-shop-cart/remove-item
	 *
	 * Body：`{ cart_item_key }`
	 *
	 * @param \WP_REST_Request $request Request。
	 *
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_shop_cart_remove_item_callback( $request ): \WP_REST_Response {
		$not_ready = $this->ensure_cart_ready();
		if ( null !== $not_ready ) {
			return $not_ready;
		}

		$body          = self::read_body( $request );
		$cart_item_key = self::read_cart_item_key( $body );

		if ( '' === $cart_item_key ) {
			return self::error( 'invalid_cart_item', \__( '購物車項目不存在', 'power_shop' ), 400 );
		}

		$cart = $this->cart();

		if ( ! $cart->remove_cart_item( $cart_item_key ) ) {
			return self::error( 'cart_item_not_found', \__( '購物車項目不存在', 'power_shop' ), 404 );
		}

		$cart->calculate_totals();

		$payload                  = $this->build_cart_payload();
		$payload['cart_item_key'] = $cart_item_key;

		return self::ok( $payload, \__( '已從購物車移除', 'power_shop' ) );
	}

	/**
	 * POST /profit-shop-cart/select-shipping-rate
	 *
	 * Body：`{ package_id, rate_id }`
	 *
	 * `rate_id` 必須確實存在於該 package 已計算出來的費率中，否則直接 400 ——
	 * 否則 `chosen_shipping_methods` 會被寫進一個不存在的方式，WooCommerce
	 * 後續會靜默退回第一個可用費率，使用者看到的選取狀態與實際運費不一致。
	 *
	 * @param \WP_REST_Request $request Request。
	 *
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_shop_cart_select_shipping_rate_callback( $request ): \WP_REST_Response {
		$not_ready = $this->ensure_cart_ready();
		if ( null !== $not_ready ) {
			return $not_ready;
		}

		$body       = self::read_body( $request );
		$package_id = self::read_non_negative_int( $body, 'package_id' );
		$rate_id    = self::read_text( $body, 'rate_id' );

		if ( '' === $rate_id ) {
			return self::error( 'invalid_shipping_rate', \__( '缺少運送方式', 'power_shop' ), 400 );
		}

		$cart = $this->cart();

		// 先算一次總額，讓 WC_Shipping 產生 packages 與各 package 的 rates。
		$cart->calculate_totals();

		$packages = self::shipping_packages();

		if ( ! isset( $packages[ $package_id ] ) ) {
			return self::error( 'invalid_shipping_package', \__( '運送包裹不存在', 'power_shop' ), 400 );
		}

		$rates = [];
		if ( isset( $packages[ $package_id ]['rates'] ) && \is_array( $packages[ $package_id ]['rates'] ) ) {
			$rates = $packages[ $package_id ]['rates'];
		}

		if ( ! isset( $rates[ $rate_id ] ) ) {
			return self::error( 'invalid_shipping_rate', \__( '運送方式不存在', 'power_shop' ), 400 );
		}

		$session = \WC()->session;
		if ( null === $session ) {
			return self::error( 'cart_unavailable', \__( '購物車暫時無法使用', 'power_shop' ), 503 );
		}

		$chosen                = (array) $session->get( 'chosen_shipping_methods', [] );
		$chosen[ $package_id ] = $rate_id;
		$session->set( 'chosen_shipping_methods', $chosen );

		// 再算一次，讓新的運送方式反映到總額。
		$cart->calculate_totals();

		$payload               = $this->build_cart_payload();
		$payload['package_id'] = $package_id;
		$payload['rate_id']    = $rate_id;

		return self::ok( $payload, \__( '已更新運送方式', 'power_shop' ) );
	}

	// ========== 加購（$_POST 橋接） ==========

	/**
	 * 呼叫 `WC()->cart->add_to_cart()`，並在期間讓 `$_POST['profit_shop_id']` 可讀
	 *
	 * 這是本 class 最關鍵的一段：`AddToCartHook`（priority 5）只從 `$_GET` /
	 * `$_POST` 取 shop_id，而 REST 的 JSON body 不會填 `$_POST`。橋接後既有的
	 * 三道閘門（賣場存在／status=publish／product_id ∈ shop items）才會生效。
	 *
	 * 用 `finally` 還原原值：即使 `add_to_cart()` 拋例外，也不能把 `$_POST` 留在
	 * 被污染的狀態給同一 request 的後續 hook 讀到。
	 *
	 * @param int                   $shop_id      分潤賣場 ID（<= 0 表示不帶賣場情境）。
	 * @param int                   $product_id   商品 ID。
	 * @param int                   $quantity     數量。
	 * @param int                   $variation_id 變體 ID（0 表示非變體）。
	 * @param array<string, string> $variation    變體屬性。
	 *
	 * @return string|false 成功回 cart_item_key，失敗回 false
	 */
	private function add_to_cart_with_shop_context(
		int $shop_id,
		int $product_id,
		int $quantity,
		int $variation_id,
		array $variation
	) {
		$cart = $this->cart();

		if ( $shop_id <= 0 ) {
			return $cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$had_original = isset( $_POST['profit_shop_id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$original = $had_original ? $_POST['profit_shop_id'] : null;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['profit_shop_id'] = (string) $shop_id;

		try {
			return $cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
		} finally {
			if ( $had_original ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$_POST['profit_shop_id'] = $original;
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				unset( $_POST['profit_shop_id'] );
			}
		}
	}

	// ========== 數量守門 ==========

	/**
	 * 依 WooCommerce 自己的規則驗證「改量」是否合法
	 *
	 * 加購路徑有 `WC_Cart::add_to_cart()` 把關（強制 sold-individually 為 1、檢查
	 * `is_in_stock()` / `has_enough_stock()`），但 `set_quantity()` 完全不驗，因此改量
	 * 路徑必須自己補上，否則「限購 1 件」的商品可以被調到任意數量並成立訂單。
	 *
	 * 規則一律從商品物件推導，**不另造新規則**。
	 *
	 * @param array<string, mixed> $cart_item 購物車項目（需含 `data`）。
	 * @param int                  $quantity  欲設定的數量（已夾在 MAX_QUANTITY 內）。
	 *
	 * @return \WP_REST_Response|null 合法回 null，否則回 400 錯誤回應
	 */
	private static function validate_quantity_against_stock( array $cart_item, int $quantity ): ?\WP_REST_Response {
		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		if ( ! $product->is_in_stock() ) {
			return self::error( 'insufficient_stock', \__( '此商品目前無庫存', 'power_shop' ), 400 );
		}

		$max = self::max_purchase_quantity( $product );

		if ( $max >= 0 && $quantity > $max ) {
			return self::error(
				'insufficient_stock',
				\sprintf(
					/* translators: %d: 此商品單筆訂單可購買的最大數量 */
					\__( '此商品最多只能購買 %d 件', 'power_shop' ),
					$max
				),
				400
			);
		}

		if ( ! $product->has_enough_stock( $quantity ) ) {
			return self::error( 'insufficient_stock', \__( '商品庫存不足', 'power_shop' ), 400 );
		}

		return null;
	}

	/**
	 * 單筆訂單可購買的最大數量（`-1` 表示無上限）
	 *
	 * 邏輯與 `WC_Product::get_max_purchase_quantity()` 完全相同（含
	 * `woocommerce_quantity_input_max` filter），但**不直接呼叫**它 ——
	 * 該方法 WooCommerce 10.1.0 才加入，而本外掛支援 7.6.0+。
	 *
	 * @param \WC_Product $product 商品物件（變體時為變體物件）。
	 *
	 * @return int
	 */
	private static function max_purchase_quantity( \WC_Product $product ): int {
		if ( $product->is_sold_individually() ) {
			$max = 1;
		} elseif ( $product->backorders_allowed() || ! $product->managing_stock() ) {
			$max = -1;
		} else {
			$max = (int) $product->get_stock_quantity();
		}

		/** This filter is documented in woocommerce/includes/abstracts/abstract-wc-product.php */
		return (int) \apply_filters( 'woocommerce_quantity_input_max', $max, $product );
	}

	// ========== Cart 就緒 ==========

	/**
	 * 確認 WooCommerce 購物車在 REST context 下就緒
	 *
	 * WooCommerce 只在 frontend 與 `DOING_AJAX` context 呼叫 `wc_load_cart()`；
	 * 且 `WC_Cart_Session::init()` 把 `get_cart_from_session()` 掛在 `wp_loaded`，
	 * REST callback 執行時該 hook 早已跑完。因此除了 `wc_load_cart()`，還必須
	 * 主動呼叫 `WC_Cart::get_cart()`（內部會補跑 `get_cart_from_session()`），
	 * 否則購物車會是空的。
	 *
	 * @return \WP_REST_Response|null 就緒回 null；否則回 503 錯誤回應
	 */
	private function ensure_cart_ready(): ?\WP_REST_Response {
		if ( ! \function_exists( 'WC' ) || ! \function_exists( 'wc_load_cart' ) ) {
			return self::error( 'cart_unavailable', \__( '購物車暫時無法使用', 'power_shop' ), 503 );
		}

		if ( ! \did_action( 'woocommerce_load_cart_from_session' ) ) {
			\wc_load_cart();
		}

		$cart = \WC()->cart;

		if ( ! $cart instanceof \WC_Cart ) {
			return self::error( 'cart_unavailable', \__( '購物車暫時無法使用', 'power_shop' ), 503 );
		}

		// 觸發 session 還原（REST context 已錯過 wp_loaded）。
		$cart->get_cart();

		return null;
	}

	/**
	 * 取得已就緒的購物車實例
	 *
	 * 呼叫前必須先通過 `ensure_cart_ready()`。
	 *
	 * @return \WC_Cart
	 */
	private function cart(): \WC_Cart {
		/** @var \WC_Cart $cart 已由 ensure_cart_ready() 保證非 null */
		$cart = \WC()->cart;

		return $cart;
	}

	// ========== Payload 組裝 ==========

	/**
	 * 完整購物車 payload
	 *
	 * 形狀沿用 WooCommerce Store API 的欄位命名（金額為最小單位整數字串），
	 * 讓前端從 Store API 切換過來時不需要改動任何顯示邏輯。
	 *
	 * @return array<string, mixed>
	 */
	private function build_cart_payload(): array {
		$cart     = $this->cart();
		$contents = $cart->get_cart();
		$items    = [];

		// 先把所有商品圖片的 object cache 一次 prime 起來，第二輪就全部命中。
		self::prime_item_image_caches( $contents );

		foreach ( $contents as $cart_item_key => $cart_item ) {
			if ( ! \is_array( $cart_item ) ) {
				continue;
			}
			$item = $this->build_cart_item( (string) $cart_item_key, $cart_item );
			if ( [] !== $item ) {
				$items[] = $item;
			}
		}

		return [
			'items'                   => $items,
			'items_count'             => (int) $cart->get_cart_contents_count(),
			'items_weight'            => self::cart_weight( $cart ),
			'needs_shipping'          => (bool) $cart->needs_shipping(),
			// 語意對齊 Store API 的 CartSchema：`has_calculated_shipping()` 是
			// 「`calculate_shipping()` 實際跑完」的結果旗標，`show_shipping()` 則只是
			// 「顧客資料是否足以計算運費」的前置條件（全虛擬商品的購物車會回 true，
			// 與同一份 payload 的 needs_shipping=false / shipping_rates=[] 自相矛盾）。
			// 本方法的所有 caller 都已在呼叫前 calculate_totals()，讀取時機成立。
			'has_calculated_shipping' => (bool) $cart->has_calculated_shipping(),
			'shipping_rates'          => $this->build_shipping_packages(),
			'totals'                  => $this->build_totals(),
			'coupons'                 => $this->build_coupons(),
			'fees'                    => $this->build_fees(),
			'errors'                  => [],
		];
	}

	/**
	 * 單一購物車項目
	 *
	 * @param string               $cart_item_key 購物車項目 key。
	 * @param array<string, mixed> $cart_item     WooCommerce cart item。
	 *
	 * @return array<string, mixed> 找不到商品物件時回空陣列（由 caller 略過）
	 */
	private function build_cart_item( string $cart_item_key, array $cart_item ): array {
		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof \WC_Product ) {
			return [];
		}

		$include_tax = self::display_prices_including_tax();

		$line_subtotal     = (float) ( $cart_item['line_subtotal'] ?? 0 );
		$line_subtotal_tax = (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
		$line_total        = (float) ( $cart_item['line_total'] ?? 0 );
		$line_total_tax    = (float) ( $cart_item['line_tax'] ?? 0 );

		if ( $include_tax ) {
			$line_subtotal += $line_subtotal_tax;
			$line_total    += $line_total_tax;
		}

		$regular_price = (string) $product->get_regular_price();
		$sale_price    = (string) $product->get_sale_price();

		return [
			'key'               => $cart_item_key,
			'id'                => (int) $product->get_id(),
			'quantity'          => (int) ( $cart_item['quantity'] ?? 0 ),
			'name'              => (string) $product->get_name(),
			'short_description' => \wpautop( \wp_kses_post( (string) $product->get_short_description() ) ),
			'description'       => \wpautop( \wp_kses_post( (string) $product->get_description() ) ),
			'sku'               => (string) $product->get_sku(),
			'permalink'         => (string) $product->get_permalink(),
			'type'              => (string) $product->get_type(),
			'images'            => self::build_item_images( $product ),
			'variation'         => self::build_item_variation( $cart_item ),
			'prices'            => array_merge(
				[
					'price'         => self::to_minor( self::display_price( $product, (string) $product->get_price() ) ),
					'regular_price' => self::to_minor( self::display_price( $product, $regular_price ) ),
					'sale_price'    => self::to_minor(
						self::display_price( $product, '' !== $sale_price ? $sale_price : $regular_price )
					),
				],
				self::currency_fields()
			),
			'totals'            => array_merge(
				[
					'line_subtotal'     => self::to_minor( $line_subtotal ),
					'line_subtotal_tax' => self::to_minor( $line_subtotal_tax ),
					'line_total'        => self::to_minor( $line_total ),
					'line_total_tax'    => self::to_minor( $line_total_tax ),
				],
				self::currency_fields_short()
			),
		];
	}

	/**
	 * 購物車項目的圖片清單（主圖固定在索引 0）
	 *
	 * @param \WC_Product $product WooCommerce 商品（變體時為變體物件）。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_item_images( \WC_Product $product ): array {
		$images = [];

		foreach ( self::collect_image_ids( $product ) as $attachment_id ) {
			$src = \wp_get_attachment_image_url( $attachment_id, 'woocommerce_single' );
			if ( ! \is_string( $src ) || '' === $src ) {
				continue;
			}

			$thumbnail = \wp_get_attachment_image_url( $attachment_id, 'woocommerce_thumbnail' );

			$images[] = [
				'id'        => $attachment_id,
				'src'       => $src,
				'thumbnail' => ( \is_string( $thumbnail ) && '' !== $thumbnail ) ? $thumbnail : $src,
				'alt'       => (string) \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'name'      => (string) \get_the_title( $attachment_id ),
			];
		}

		return $images;
	}

	/**
	 * 單一商品的圖片 attachment id（主圖固定在索引 0，已去重）
	 *
	 * @param \WC_Product $product WooCommerce 商品（變體時為變體物件）。
	 *
	 * @return array<int, int>
	 */
	private static function collect_image_ids( \WC_Product $product ): array {
		$ids  = [];
		$main = (int) $product->get_image_id();

		if ( $main > 0 ) {
			$ids[] = $main;
		}

		foreach ( (array) $product->get_gallery_image_ids() as $gallery_id ) {
			$gallery_id = (int) $gallery_id;
			if ( $gallery_id > 0 ) {
				$ids[] = $gallery_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * 一次性 prime 購物車所有商品圖片的 object cache
	 *
	 * 少了這一步，`build_item_images()` 對每個 attachment 的
	 * `wp_get_attachment_image_url()` × 2 + `get_post_meta()` + `get_the_title()`
	 * 都各自產生 `get_post` 與 postmeta 查詢 —— 8 項商品 × 5 張圖 ≈ 80 次額外往返，
	 * 而 `build_cart_payload()` 是 6 個 endpoint 中 5 個都會跑的熱路徑（使用者每按一次
	 * `+` / `-` / 移除 / 選運費都會重跑）。
	 *
	 * 與 `ProfitShopFrontData::build_products()` 同一手法（同樣的兩輪處理）。
	 *
	 * @param array<string, mixed> $contents 購物車內容（`WC_Cart::get_cart()`）。
	 *
	 * @return void
	 */
	private static function prime_item_image_caches( array $contents ): void {
		if ( ! \function_exists( '_prime_post_caches' ) ) {
			return;
		}

		$ids = [];

		foreach ( $contents as $cart_item ) {
			if ( ! \is_array( $cart_item ) ) {
				continue;
			}

			$product = $cart_item['data'] ?? null;

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$ids = array_merge( $ids, self::collect_image_ids( $product ) );
		}

		if ( [] === $ids ) {
			return;
		}

		\_prime_post_caches( array_unique( $ids ), false, true );
	}

	/**
	 * 購物車項目的變體標記（`{ attribute: '顏色', value: '紅' }`）
	 *
	 * 屬性 slug 會盡量翻成 taxonomy label 與 term name，與 WooCommerce 購物車頁
	 * 顯示的文字一致。
	 *
	 * @param array<string, mixed> $cart_item WooCommerce cart item。
	 *
	 * @return array<int, array{attribute: string, value: string}>
	 */
	private static function build_item_variation( array $cart_item ): array {
		$raw = $cart_item['variation'] ?? [];

		if ( ! \is_array( $raw ) ) {
			return [];
		}

		$result = [];

		foreach ( $raw as $name => $value ) {
			$name     = (string) $name;
			$value    = \is_scalar( $value ) ? (string) $value : '';
			$taxonomy = \wc_attribute_taxonomy_name(
				str_replace( 'attribute_pa_', '', \urldecode( $name ) )
			);

			if ( \taxonomy_exists( $taxonomy ) ) {
				$term = \get_term_by( 'slug', $value, $taxonomy );
				if ( $term instanceof \WP_Term && '' !== $term->name ) {
					$value = (string) $term->name;
				}
				$label = (string) \wc_attribute_label( $taxonomy );
			} else {
				$label = (string) \wc_attribute_label( str_replace( 'attribute_', '', $name ) );
			}

			$result[] = [
				'attribute' => \wp_kses_post( $label ),
				'value'     => \wp_kses_post( $value ),
			];
		}

		return $result;
	}

	/**
	 * 運送包裹與費率
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_shipping_packages(): array {
		$cart = $this->cart();

		if ( ! $cart->needs_shipping() ) {
			return [];
		}

		$session = \WC()->session;
		$chosen  = ( null === $session ) ? [] : (array) $session->get( 'chosen_shipping_methods', [] );
		$result  = [];

		foreach ( self::shipping_packages() as $package_id => $package ) {
			$package_id = (int) $package_id;
			$rates      = [];

			$raw_rates = ( isset( $package['rates'] ) && \is_array( $package['rates'] ) ) ? $package['rates'] : [];

			foreach ( $raw_rates as $rate ) {
				if ( ! $rate instanceof \WC_Shipping_Rate ) {
					continue;
				}

				$rate_id = (string) $rate->get_id();

				$rates[] = array_merge(
					[
						'rate_id'       => $rate_id,
						'name'          => (string) $rate->get_label(),
						'description'   => '',
						'delivery_time' => '',
						'price'         => self::to_minor( $rate->get_cost() ),
						'taxes'         => self::to_minor( array_sum( (array) $rate->get_taxes() ) ),
						'instance_id'   => (int) $rate->get_instance_id(),
						'method_id'     => (string) $rate->get_method_id(),
						'meta_data'     => self::build_rate_meta_data( $rate ),
						'selected'      => isset( $chosen[ $package_id ] ) && (string) $chosen[ $package_id ] === $rate_id,
					],
					self::currency_fields_short()
				);
			}

			$result[] = [
				'package_id'     => $package_id,
				'name'           => \__( '運送方式', 'power_shop' ),
				'destination'    => self::build_package_destination( $package ),
				'items'          => self::build_package_items( $package ),
				'shipping_rates' => $rates,
			];
		}

		return $result;
	}

	/**
	 * 運送費率的 meta data
	 *
	 * @param \WC_Shipping_Rate $rate 運送費率。
	 *
	 * @return array<int, array{key: string, value: string}>
	 */
	private static function build_rate_meta_data( \WC_Shipping_Rate $rate ): array {
		$result = [];

		foreach ( (array) $rate->get_meta_data() as $key => $value ) {
			$result[] = [
				'key'   => (string) $key,
				'value' => \is_scalar( $value ) ? (string) $value : '',
			];
		}

		return $result;
	}

	/**
	 * 運送包裹的收件地址
	 *
	 * @param array<string, mixed> $package WooCommerce shipping package。
	 *
	 * @return array<string, string>
	 */
	private static function build_package_destination( array $package ): array {
		$destination = [];
		if ( isset( $package['destination'] ) && \is_array( $package['destination'] ) ) {
			$destination = $package['destination'];
		}

		$keys   = [ 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];
		$result = [];

		foreach ( $keys as $key ) {
			$value          = $destination[ $key ] ?? '';
			$result[ $key ] = \is_scalar( $value ) ? (string) $value : '';
		}

		return $result;
	}

	/**
	 * 運送包裹內含的商品
	 *
	 * @param array<string, mixed> $package WooCommerce shipping package。
	 *
	 * @return array<int, array{key: string, name: string, quantity: int}>
	 */
	private static function build_package_items( array $package ): array {
		$contents = ( isset( $package['contents'] ) && \is_array( $package['contents'] ) ) ? $package['contents'] : [];
		$result   = [];

		foreach ( $contents as $key => $item ) {
			if ( ! \is_array( $item ) ) {
				continue;
			}

			$product = $item['data'] ?? null;

			$result[] = [
				'key'      => (string) $key,
				'name'     => $product instanceof \WC_Product ? (string) $product->get_name() : '',
				'quantity' => (int) ( $item['quantity'] ?? 0 ),
			];
		}

		return $result;
	}

	/**
	 * 購物車總計
	 *
	 * @return array<string, mixed>
	 */
	private function build_totals(): array {
		$cart = $this->cart();

		return array_merge(
			[
				'total_items'        => self::to_minor( $cart->get_subtotal() ),
				'total_items_tax'    => self::to_minor( $cart->get_subtotal_tax() ),
				'total_fees'         => self::to_minor( $cart->get_fee_total() ),
				'total_fees_tax'     => self::to_minor( $cart->get_fee_tax() ),
				'total_discount'     => self::to_minor( $cart->get_discount_total() ),
				'total_discount_tax' => self::to_minor( $cart->get_discount_tax() ),
				'total_shipping'     => self::to_minor( $cart->get_shipping_total() ),
				'total_shipping_tax' => self::to_minor( $cart->get_shipping_tax() ),
				'total_tax'          => self::to_minor( $cart->get_total_tax() ),
				'total_price'        => self::to_minor( $cart->get_total( 'edit' ) ),
			],
			self::currency_fields()
		);
	}

	/**
	 * 已套用的優惠券
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_coupons(): array {
		$cart   = $this->cart();
		$result = [];

		foreach ( (array) $cart->get_applied_coupons() as $code ) {
			$code   = (string) $code;
			$coupon = new \WC_Coupon( $code );

			$result[] = [
				'code'          => $code,
				'discount_type' => (string) $coupon->get_discount_type(),
				'totals'        => array_merge(
					[
						'total_discount'     => self::to_minor( $cart->get_coupon_discount_amount( $code ) ),
						'total_discount_tax' => self::to_minor( $cart->get_coupon_discount_tax_amount( $code ) ),
					],
					self::currency_fields_short()
				),
			];
		}

		return $result;
	}

	/**
	 * 額外費用
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_fees(): array {
		$result = [];

		foreach ( (array) $this->cart()->get_fees() as $fee ) {
			if ( ! \is_object( $fee ) ) {
				continue;
			}

			$result[] = [
				'id'     => (string) ( $fee->id ?? '' ),
				'name'   => (string) ( $fee->name ?? '' ),
				'totals' => array_merge(
					[
						'total'     => self::to_minor( $fee->total ?? 0 ),
						'total_tax' => self::to_minor( $fee->tax ?? 0 ),
					],
					self::currency_fields_short()
				),
			];
		}

		return $result;
	}

	/**
	 * 購物車總重（克）
	 *
	 * @param \WC_Cart $cart 購物車。
	 *
	 * @return float
	 */
	private static function cart_weight( \WC_Cart $cart ): float {
		$weight = (float) $cart->get_cart_contents_weight();

		if ( \function_exists( 'wc_get_weight' ) ) {
			return (float) \wc_get_weight( $weight, 'g' );
		}

		return $weight;
	}

	// ========== 金額與幣別 ==========

	/**
	 * 目前幣別的最小單位位數
	 *
	 * @return int
	 */
	private static function minor_unit(): int {
		return \function_exists( 'wc_get_price_decimals' ) ? (int) \wc_get_price_decimals() : 2;
	}

	/**
	 * 金額轉為「最小單位整數字串」
	 *
	 * 照 WooCommerce Store API 慣例：`1234.56` 且 minor unit 為 2 時輸出 `'123456'`。
	 * 前端一律以 `Number(x) / 10 ** currency_minor_unit` 還原 —— 形狀弄錯價格會
	 * 差 100 倍，且不會有任何錯誤訊息。
	 *
	 * @param mixed $value 金額（以「元」為單位）。
	 *
	 * @return string
	 */
	private static function to_minor( $value ): string {
		if ( ! \is_numeric( $value ) ) {
			$value = 0;
		}

		$factor = 10 ** self::minor_unit();

		return (string) (int) round( (float) $value * $factor );
	}

	/**
	 * 購物車是否以含稅價顯示
	 *
	 * @return bool
	 */
	private static function display_prices_including_tax(): bool {
		return 'incl' === \get_option( 'woocommerce_tax_display_cart', 'excl' );
	}

	/**
	 * 依購物車稅務顯示設定換算單價
	 *
	 * @param \WC_Product $product 商品物件。
	 * @param string      $price   原始價格（空字串視為 0）。
	 *
	 * @return string
	 */
	private static function display_price( \WC_Product $product, string $price ): string {
		if ( '' === $price ) {
			$price = '0';
		}

		$args = [
			'qty'   => 1,
			'price' => $price,
		];

		if ( self::display_prices_including_tax() ) {
			return (string) \wc_get_price_including_tax( $product, $args );
		}

		return (string) \wc_get_price_excluding_tax( $product, $args );
	}

	/**
	 * 完整幣別欄位（供 `prices` 與購物車 `totals` 使用）
	 *
	 * @return array<string, mixed>
	 */
	private static function currency_fields(): array {
		$symbol = '';
		if ( \function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = (string) \get_woocommerce_currency_symbol();
		}

		$position = (string) \get_option( 'woocommerce_currency_pos', 'left' );

		$decimal_separator = '.';
		if ( \function_exists( 'wc_get_price_decimal_separator' ) ) {
			$decimal_separator = (string) \wc_get_price_decimal_separator();
		}

		$thousand_separator = ',';
		if ( \function_exists( 'wc_get_price_thousand_separator' ) ) {
			$thousand_separator = (string) \wc_get_price_thousand_separator();
		}

		$prefix = '';
		$suffix = '';

		if ( 'right' === $position ) {
			$suffix = $symbol;
		} elseif ( 'right_space' === $position ) {
			$suffix = ' ' . $symbol;
		} elseif ( 'left_space' === $position ) {
			$prefix = $symbol . ' ';
		} else {
			$prefix = $symbol;
		}

		return [
			'currency_code'               => \function_exists( 'get_woocommerce_currency' )
				? (string) \get_woocommerce_currency()
				: '',
			'currency_symbol'             => $symbol,
			'currency_minor_unit'         => self::minor_unit(),
			'currency_decimal_separator'  => $decimal_separator,
			'currency_thousand_separator' => $thousand_separator,
			'currency_prefix'             => $prefix,
			'currency_suffix'             => $suffix,
		];
	}

	/**
	 * 精簡幣別欄位（供 line totals / 運費費率使用）
	 *
	 * @return array{currency_code: string, currency_minor_unit: int}
	 */
	private static function currency_fields_short(): array {
		return [
			'currency_code'       => \function_exists( 'get_woocommerce_currency' )
				? (string) \get_woocommerce_currency()
				: '',
			'currency_minor_unit' => self::minor_unit(),
		];
	}

	/**
	 * 目前已計算出來的運送包裹
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	private static function shipping_packages(): array {
		if ( ! \function_exists( 'wc_shipping_enabled' ) || ! \wc_shipping_enabled() ) {
			return [];
		}

		$shipping = \WC()->shipping();

		if ( ! $shipping instanceof \WC_Shipping ) {
			return [];
		}

		return (array) $shipping->get_packages();
	}

	// ========== 輸入解析 ==========

	/**
	 * 讀取 request body（JSON 優先，退回 form body）
	 *
	 * @param \WP_REST_Request $request Request。
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return array<string, mixed>
	 */
	private static function read_body( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		if ( ! \is_array( $body ) ) {
			$body = $request->get_body_params();
		}

		if ( ! \is_array( $body ) ) {
			$body = $request->get_params();
		}

		return \is_array( $body ) ? $body : [];
	}

	/**
	 * 該欄位是否有被明確提供（缺 key / null / 空字串都算沒提供）
	 *
	 * 用來把「使用者沒給」與「給了但格式錯」分開 —— 兩者若都折成 0，`quantity`
	 * 的 0 又代表「移除」，使用者鍵入 `2.5` 就會靜默刪掉購物車項目。
	 *
	 * @param array<string, mixed> $body 已解析的 request body。
	 * @param string               $key  欄位名。
	 *
	 * @return bool
	 */
	private static function field_provided( array $body, string $key ): bool {
		if ( ! \array_key_exists( $key, $body ) ) {
			return false;
		}

		$raw = $body[ $key ];

		return null !== $raw && '' !== $raw;
	}

	/**
	 * 讀取非負整數欄位（無法解析時回 null）
	 *
	 * 用 `ctype_digit` 嚴格檢查（拒絕 `'01'` / `'+1'` / 浮點 / 負數），與
	 * `AddToCartHook` 對 `profit_shop_id` 的檢查方式一致。
	 *
	 * @param array<string, mixed> $body 已解析的 request body。
	 * @param string               $key  欄位名。
	 *
	 * @return int|null 缺值或不合法回 null
	 */
	private static function read_int_or_null( array $body, string $key ): ?int {
		$raw = $body[ $key ] ?? '';

		if ( ! \is_scalar( $raw ) || \is_bool( $raw ) ) {
			return null;
		}

		$value = \sanitize_text_field( (string) $raw );

		if ( '' === $value || ! \ctype_digit( $value ) ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * 讀取非負整數欄位（缺值或不合法一律回 0）
	 *
	 * 適用於「0 與缺值語意相同」的欄位（`id` / `variation_id` / `profit_shop_id` /
	 * `package_id`）。`quantity` **不可**用這個 —— 它的 0 代表「移除」，見
	 * `field_provided()`。
	 *
	 * @param array<string, mixed> $body 已解析的 request body。
	 * @param string               $key  欄位名。
	 *
	 * @return int
	 */
	private static function read_non_negative_int( array $body, string $key ): int {
		return self::read_int_or_null( $body, $key ) ?? 0;
	}

	/**
	 * 讀取一般文字欄位
	 *
	 * @param array<string, mixed> $body 已解析的 request body。
	 * @param string               $key  欄位名。
	 *
	 * @return string
	 */
	private static function read_text( array $body, string $key ): string {
		$raw = $body[ $key ] ?? '';

		if ( ! \is_scalar( $raw ) || \is_bool( $raw ) ) {
			return '';
		}

		return \sanitize_text_field( (string) $raw );
	}

	/**
	 * 讀取並驗證 cart_item_key
	 *
	 * Cart item key 是 WooCommerce 產生的 md5 hash（32 位十六進位）。格式不符
	 * 直接回空字串，避免把任意字串傳進 cart API。
	 *
	 * @param array<string, mixed> $body 已解析的 request body。
	 *
	 * @return string 格式不符回空字串
	 */
	private static function read_cart_item_key( array $body ): string {
		$key = self::read_text( $body, 'cart_item_key' );

		return ( 1 === \preg_match( self::CART_ITEM_KEY_PATTERN, $key ) ) ? $key : '';
	}

	/**
	 * 讀取變體屬性
	 *
	 * 契約格式是物件陣列 `[{ "name": "attribute_pa_color", "value": "red" }]`，
	 * 轉成 WooCommerce `add_to_cart()` 需要的 `[ 'attribute_pa_color' => 'red' ]`。
	 * 同時容忍舊版前端送 JSON 字串的形式（Phase 9 的 admin-ajax 契約）。
	 *
	 * @param array<string, mixed> $body 已解析的 request body。
	 *
	 * @return array<string, string>
	 */
	private static function read_variation( array $body ): array {
		$raw = $body['variation'] ?? [];

		if ( \is_string( $raw ) ) {
			$raw = \json_decode( $raw, true );
		}

		if ( ! \is_array( $raw ) ) {
			return [];
		}

		$variation = [];

		foreach ( $raw as $pair ) {
			if ( ! \is_array( $pair ) ) {
				continue;
			}

			$name  = self::read_text( $pair, 'name' );
			$value = self::read_text( $pair, 'value' );

			if ( '' === $name ) {
				continue;
			}

			$variation[ $name ] = $value;
		}

		return $variation;
	}

	// ========== 回應 helper ==========

	/**
	 * 200 成功回應
	 *
	 * @param mixed  $data    回應資料。
	 * @param string $message 成功訊息。
	 *
	 * @return \WP_REST_Response
	 */
	private static function ok( $data, string $message ): \WP_REST_Response {
		\nocache_headers();

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => $message,
				'data'    => $data,
			],
			200
		);
	}

	/**
	 * 失敗回應
	 *
	 * @param string $code    錯誤代碼（前端用來分支處理）。
	 * @param string $message 繁中錯誤訊息。
	 * @param int    $status  HTTP status code。
	 *
	 * @return \WP_REST_Response
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		\nocache_headers();

		return new \WP_REST_Response(
			[
				'code'    => $code,
				'message' => $message,
			],
			$status
		);
	}

	/**
	 * 取出 WooCommerce 佇列中的錯誤通知作為訊息
	 *
	 * `add_to_cart()` 失敗時 WooCommerce 會把原因（庫存不足、變體未選等）塞進
	 * notice 佇列而非丟例外，撈出來才能給使用者可行動的訊息。
	 *
	 * @param string $fallback 佇列為空時的預設訊息。
	 *
	 * @return string
	 */
	private static function take_wc_error( string $fallback ): string {
		if ( ! \function_exists( 'wc_get_notices' ) ) {
			return $fallback;
		}

		$notices = \wc_get_notices( 'error' );
		\wc_clear_notices();

		if ( ! \is_array( $notices ) || [] === $notices ) {
			return $fallback;
		}

		$first = \reset( $notices );
		$text  = \is_array( $first ) ? (string) ( $first['notice'] ?? '' ) : (string) $first;

		return self::safe_message( $text, $fallback );
	}

	/**
	 * 清理要回給前端的訊息（去標籤、去換行）
	 *
	 * @param string $message  原始訊息。
	 * @param string $fallback 清理後為空時的替代訊息。
	 *
	 * @return string
	 */
	private static function safe_message( string $message, string $fallback ): string {
		$text = \trim( (string) \preg_replace( "/[\r\n\t]+/", ' ', \wp_strip_all_tags( $message ) ) );

		return '' === $text ? $fallback : $text;
	}
}
