<?php
/**
 * 結帳寫入分潤結算快照（cart item meta → order itemmeta）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5（order itemmeta 欄位表）、
 *          §5.2（結帳價格套用 + 寫入 order_itemmeta）
 *
 * ═══ 這是整條分潤結算鏈路的第一環 ═══
 *
 *   賣場前台加購
 *     → AddToCartHook(5)：四道閘門，注入 profit_shop_id
 *     → CartPriceOverrideHook(10)：寫 6 筆 _profit_* cart meta + HMAC 簽章
 *     → CartPriceOverrideHook(999)：結帳前驗章 + set_price
 *     → **本 hook**：把驗過章的快照凍結進 order itemmeta
 *     → OrderItemSettlementRepository：夥伴報表 / 站長撥款的唯一資料來源
 *
 * 少了本 hook，訂單成立後 `wc_order_itemmeta` 完全沒有分潤紀錄：顧客照樣付了分潤價、
 * 夥伴報表卻永遠是空的、站長無從撥款，而且**沒有任何錯誤訊息**。
 *
 * ═══ 🔴 cart 與 order 的 meta key 命名不同，不是直接複製 ═══
 *
 * | cart item meta（CartPriceOverrideHook 寫）| order itemmeta（本 hook 寫）| 來源 |
 * |---|---|---|
 * | `_profit_shop_id`                | `_profit_shop_id`         | 同名，直接搬 |
 * | `_profit_partner_term_id`        | `_profit_partner_term_id` | 同名，直接搬 |
 * | （cart 沒有）                     | `_profit_rate`            | **讀賣場 CPT** |
 * | `_profit_regular_price_override` | `_override_regular_price` | **改名** |
 * | `_profit_sale_price_override`    | `_override_sale_price`    | **改名** |
 * | （cart 沒有）                     | `_override_signup_fee`    | 恆 ''，見下方說明 |
 * | `_profit_price_override`         | `_actual_price`           | **改名** |
 * | （cart 沒有）                     | `_profit_amount`          | **計算**：actual × qty × rate / 100 |
 * | （cart 沒有）                     | `_settlement_status`      | 恆 'pending' |
 * | （cart 沒有）                     | `_settled_at` / `_settled_by` | 尚未撥款 ⇒ 不寫 |
 *
 * 寫錯對映**不會有任何錯誤訊息**，只會產生金額錯誤的結算列——所以 order 端的 key 名稱
 * 一律留在 `OrderItemSettlementRepository::save_to_item()` 內，本檔只認得 cart 端的名稱。
 *
 * ═══ 為什麼是 `woocommerce_checkout_create_order_line_item` ═══
 *
 * 它是**唯一**同時拿得到「cart item 陣列（含 _profit_* meta 與簽章）」與「對應 line item」
 * 的擴充點。WooCommerce 10.1.4（本機站台）逐行查證：
 *
 *   - includes/class-wc-checkout.php:571
 *     `do_action( 'woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $values, $order )`
 *   - src/StoreApi/Utilities/OrderController.php:789（Block 結帳 / Store API）
 *     `wc()->checkout->create_order_line_items( $order, $cart )` ⇒ 走上面同一個方法
 *   - `grep -rn "woocommerce_checkout_create_order_line_item" src/ includes/` 只有兩處命中
 *
 * ⇒ 傳統結帳、Block 結帳、Store API 結帳**三條路徑共用同一個 hook**，不需要三份實作。
 *
 * ⚠️ 「共用同一個方法」是 WooCommerce 目前的**實作細節**，不是它承諾的契約。
 *    `OrderLineItemSettlementHookTest` 因此對傳統結帳與 Store API **各留一條測試**，
 *    日後 WooCommerce 若把兩條路徑拆開，會立刻紅燈而不是靜默漏寫。
 *
 * ⚠️ 行號一律以 **WooCommerce 10.1.4**（本機站台）為準；wp-env 容器裝的是另一個版本
 *    （實測 11.0.0-rc.1），行號會位移，對不上時先確認版本再懷疑註解。
 *
 * ═══ priority 20 的理由 ═══
 *
 * 1. 與既有四個 hook **零衝突**：AddToCartHook 掛 `woocommerce_add_cart_item_data`(5)、
 *    CartPriceOverrideHook 掛同一個 filter(10) 與 `woocommerce_before_calculate_totals`(999)、
 *    SingleProductPriceHook 掛 `template_redirect`(20)——沒有任何一個掛在本 action 上。
 * 2. 晚於絕大多數用預設 priority 10 的擴充（例如 WooCommerce Subscriptions 寫訂閱 meta），
 *    因此 `$item->get_quantity()` 讀到的是它們調整後的最終數量。
 * 3. 不用 `PHP_INT_MAX`：那會擠掉「想在我方之後讀分潤 meta」的擴充的空間；
 *    20 之後仍有 30 / 100 可用。本 action 內的先後順序不影響落地——
 *    WooCommerce 在整個 action 跑完之後才 `$order->add_item( $item )`。
 *
 * ═══ 安全：沒驗過章的一律不寫 ═══
 *
 * cart item meta 是可以被竄改的（session 內容、第三方外掛、直接改 `cart_contents`）。
 * `_profit_*` 一組六筆之中只有 `_profit_signature` 能證明「這組值確實出自我方定價鏈路」，
 * 所以本 hook **一律先驗 HMAC 才寫**——否則往 cart item 塞幾筆假 meta 就能憑空生出
 * 一筆帶金額的結算列，等於繞過整條定價鏈路偽造分潤。
 *
 * ⚠️ 驗章邏輯與 `CartPriceOverrideHook::verify_item()` 是**孿生實作**（該方法是 private，
 *    而該檔案是已驗證的安全核心、本次不得改動）。兩者若分歧，症狀是「購物車套了覆寫價、
 *    訂單卻沒有分潤紀錄」，而且兩邊都「正常運作」、沒有錯誤訊息。
 *    後續應抽成共用的 verifier 讓兩邊委派同一份程式碼，見
 *    specs/plan/2026-08-06-settlement-hook-notes.md。
 *
 * ═══ 金額一律 bcmath，禁浮點 ═══
 *
 * `_profit_amount` 是實際要付給夥伴的錢。整條計算走 `ProfitCalculator`（bcmul / bcdiv 字串運算）
 * → `MoneyFormatter::to_store()` → `WcDecimalRounding`（bcmath half-up），全程不出現 float——
 * 浮點尾數（`69.99930000000001`）會被 `WpSettlementSummaryProvider` 的 `bcadd` 聚合
 * 一路帶進撥款金額。
 *
 * ═══ 賣場在結帳當下已被刪除：寫歸屬、rate 記 0 ═══
 *
 * **不是**「完全不寫」。`CartPriceOverrideHook::apply_override()`（priority 999）不重讀賣場，
 * 只驗簽章並對 frozen 價格套價 ⇒ 顧客**確實已經付了分潤價**。此時零紀錄就會製造
 * 「顧客付了分潤價、系統查無此筆」——正是本 hook 存在的目的要消滅的東西。
 * 完整推導與 `WC::logger` 告警見 `freeze()` 內的行內註解。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\ProfitCalculator;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;

/**
 * 結帳時把驗過章的分潤快照凍結進 order itemmeta
 */
final class OrderLineItemSettlementHook {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Hook priority（理由見 class docblock）
	 */
	private const HOOK_PRIORITY = 20;

	/**
	 * Cart item meta 鍵：來源賣場 ID（order 端同名）
	 *
	 * 🔴 以下六個常數一律是 `CartProfitMetaVerifier::META_*` 的**別名**，不是各自的字面值。
	 * 驗章已收斂到那個類別（見 `verify_cart_item()`），這裡若重新寫一份字面值，就又有兩個
	 * 真相來源——只要一個字母不同，驗章會恆失敗，症狀是「購物車套了覆寫價、訂單卻沒有
	 * 分潤紀錄」，而且沒有任何錯誤訊息。
	 *
	 * ⚠️ 這裡指的是 **cart 端**的 key。落進 order itemmeta 時會改名
	 *   （`_profit_price_override` → `_actual_price` 等），那份對映由
	 *   `OrderItemSettlementRepository::save_to_item()` 獨佔，見本類別 docblock 的對映表。
	 */
	private const CART_META_SHOP_ID = CartProfitMetaVerifier::META_SHOP_ID;

	/**
	 * Cart item meta 鍵：分潤夥伴 term ID（order 端同名）
	 */
	private const CART_META_PARTNER_TERM_ID = CartProfitMetaVerifier::META_PARTNER_TERM_ID;

	/**
	 * Cart item meta 鍵：實際生效價格（order 端叫 `_actual_price`）
	 */
	private const CART_META_PRICE_OVERRIDE = CartProfitMetaVerifier::META_PRICE_OVERRIDE;

	/**
	 * Cart item meta 鍵：覆寫原價（order 端叫 `_override_regular_price`）
	 */
	private const CART_META_REGULAR_OVERRIDE = CartProfitMetaVerifier::META_REGULAR_OVERRIDE;

	/**
	 * Cart item meta 鍵：覆寫特價（order 端叫 `_override_sale_price`）
	 */
	private const CART_META_SALE_OVERRIDE = CartProfitMetaVerifier::META_SALE_OVERRIDE;

	/**
	 * Cart item meta 鍵：HMAC 簽章（不落進 order itemmeta——訂單快照不需要再被驗證）
	 */
	private const CART_META_SIGNATURE = CartProfitMetaVerifier::META_SIGNATURE;

	/**
	 * 驗章器（與 `CartPriceOverrideHook` 共用同一份驗章語意）
	 *
	 * 見 `verify_cart_item()` 的 docblock：這兩個 hook 過去各自維護一份版本分派 + HMAC
	 * 驗證，分歧時「購物車有覆寫價、訂單沒有分潤紀錄」且零錯誤訊息。
	 *
	 * @var CartProfitMetaVerifier
	 */
	private readonly CartProfitMetaVerifier $verifier;

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shops       賣場 Repository（讀 `_profit_rate` 的唯一來源）
	 * @param OrderItemSettlementRepository $settlements 結算 Repository（order itemmeta 的唯一寫入出口）
	 * @param CartPriceSignatureService     $signatures  簽章服務（v1 / v2 / v3 皆由它判版與驗證）
	 * @param ProfitCalculator              $calculator  分潤金額計算（bcmath）
	 * @param MoneyFormatter                $money       入庫金額格式（收斂到站台貨幣小數位）
	 * @param CartProfitMetaVerifier|null   $verifier    驗章器（與 `CartPriceOverrideHook` 共用同一份）；
	 *                                                   null 時由 $signatures 建立（無狀態）。
	 *                                                   ⚠️ 保持 optional 是刻意的——改成必填會讓既有
	 *                                                   Loader 與測試的 `new` 全部 ArgumentCountError，
	 *                                                   而本次是純重構、不該要求呼叫端配合。
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shops,
		private readonly OrderItemSettlementRepository $settlements,
		private readonly CartPriceSignatureService $signatures,
		private readonly ProfitCalculator $calculator,
		private readonly MoneyFormatter $money,
		?CartProfitMetaVerifier $verifier = null
	) {
		// 用 $this->signatures（而非參數）：promoted property 在 constructor body 執行前
		// 就已賦值，這樣寫可讓「本 hook 仍持有 signatures」這件事在靜態分析下顯而易見。
		$this->verifier = $verifier ?? new CartProfitMetaVerifier( $this->signatures );

		\add_action(
			'woocommerce_checkout_create_order_line_item',
			[ $this, 'freeze_settlement' ],
			self::HOOK_PRIORITY,
			4
		);
	}

	/**
	 * 把該筆 cart item 的分潤快照寫進對應的 order line item
	 *
	 * 🔴 整個 body 包在 try/catch(\Throwable)：本 hook 在 `WC_Checkout::create_order_line_items()`
	 * 內執行，此刻顧客的款項流程已經啟動。結算記帳失敗**絕不可以**讓例外冒出去中斷建單
	 * ——那會變成「顧客付不了款」，比「少一筆結算紀錄」嚴重得多。失敗一律寫 error_log。
	 *
	 * @param mixed $item          WC_Order_Item_Product（尚未儲存，`get_id()` 為 0）
	 * @param mixed $cart_item_key cart item key（未使用，WC 簽章要求）
	 * @param mixed $values        該筆 cart item 陣列（含 _profit_* meta）
	 * @param mixed $order         訂單（僅用於 error_log 定位）
	 *
	 * @return void
	 */
	public function freeze_settlement( $item, $cart_item_key = '', $values = [], $order = null ): void {
		unset( $cart_item_key );

		try {
			$this->freeze( $item, $values, $order );
		} catch ( \Throwable $e ) {
			$this->log(
				sprintf(
					'freeze_settlement failed order=%d product=%d: %s',
					$order instanceof \WC_Order ? (int) $order->get_id() : 0,
					$item instanceof \WC_Order_Item_Product ? (int) $item->get_product_id() : 0,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * 實際的凍結流程（已由 freeze_settlement() 保證不會拋例外出去）
	 *
	 * @param mixed $item   order line item
	 * @param mixed $values cart item 陣列
	 * @param mixed $order  訂單（僅用於 error_log）
	 *
	 * @return void
	 */
	private function freeze( $item, $values, $order ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! \is_array( $values ) ) {
			return;
		}

		// 一般商品：cart item 完全沒有分潤痕跡 ⇒ 靜默略過（絕大多數 line item 走這條）
		if ( ! isset( $values[ self::CART_META_SHOP_ID ] ) ) {
			return;
		}

		$verified = $this->verify_cart_item( $values, $item );

		if ( null === $verified ) {
			// 驗章失敗＝這組 meta 不是我方定價鏈路產生的（或已被竄改）⇒ 不寫任何分潤 meta。
			// 不告知前端（避免 oracle），但必須留下 log 讓站長可追查。
			$this->log(
				sprintf(
					'signature rejected, no settlement written. order=%d product=%d variation=%d',
					$order instanceof \WC_Order ? (int) $order->get_id() : 0,
					(int) $item->get_product_id(),
					(int) $item->get_variation_id()
				)
			);
			return;
		}

		$shop = $this->shops->find( $verified['shop_id'] );

		/*
		* 🔴 賣場查不到 ⇒ **仍然寫入完整歸屬**，只是 rate 記 0、金額記 '0.00'。
		*
		* 為什麼不是「完全不寫」：CartPriceOverrideHook::apply_override()（priority 999）
		* **不重讀賣場**，它只驗簽章、對 frozen 的 `_profit_price_override` 套價
		* ⇒ 走到這裡時**顧客確實已經付了分潤價**。此時不寫任何 meta，就會製造出
		* 「顧客付了分潤價、系統零紀錄」——那正是本任務要消滅的東西。
		*
		* 為什麼 rate 記 0 而不是猜一個值：`_profit_rate` 只存在於賣場 CPT
		* （cart meta 沒有它、HMAC 也沒保護它；spec §5.1 原始設計曾想放進 cart_item_data，
		*  實作從未這麼做，而且就算放了也不可信）。賣場消失就沒有可信的比例來源，
		* 猜任何值都可能少付或多付。記 0 是**保守且明顯異常**的值，配合下面的
		* WC::logger error 讓結算頁可以標紅「賣場已刪除，需人工設定比例」。
		*
		* ⚠️ 賣場只是被**下架**（draft）時走正常路徑：交易在賣場還上架時就成立了，
		*    rate 也仍然讀得到；此時歸零等於讓「下架賣場」變成「抹掉夥伴已成交的分潤」。
		*    這與 CartPriceOverrideHook::rescue_still_authorized() 要求 publish 不衝突——
		*    那條路徑決定的是「還能不能買」，本 hook 處理的是「已經買完了怎麼記帳」。
		*/
		$rate = $shop instanceof ProfitShop
		? $shop->rate
		: new ProfitRate( 0 );

		if ( ! $shop instanceof ProfitShop ) {
			\J7\WpUtils\Classes\WC::logger(
				sprintf(
					'賣場 %d 在結帳當下已不存在，分潤比例以 0 記錄，需人工設定比例',
					$verified['shop_id']
				),
				'error',
				[
					'shop_id'         => $verified['shop_id'],
					'partner_term_id' => $verified['partner_term_id'],
					'order_id'        => $order instanceof \WC_Order ? (int) $order->get_id() : 0,
					'product_id'      => (int) $item->get_product_id(),
					'variation_id'    => (int) $item->get_variation_id(),
					'actual_price'    => $verified['price'],
				],
				'profit_shop'
			);
		}

		$quantity = max( 0, (int) $item->get_quantity() );

		/*
		* `_actual_price` 取**簽章保護過的單價**，不是 `$item->get_total() / qty`。
		*
		* 三個理由：
		*   1. 只有這個值有 HMAC 保護；用未受保護的衍生值等於讓 cart 操作影響撥款金額。
		*   2. 它就是夥伴賣場當時對外標示的價格，也是這筆分潤的成交依據。
		*   3. spec §5.2 的資料流本來就寫「從 cart_item 取 _profit_*，寫入 …_actual_price」。
		*
		* ⚠️ 已知取捨：站長另外發的折價券會讓顧客實付低於此價，而分潤仍以此價計算。
		*    折價券是站長端的促銷變數、夥伴無從控制，因此目前視為站長吸收；
		*    若日後要改成「以實付金額計算」，必須連同「未受簽章保護」這個風險一起處理。
		*/
		$actual_price = $verified['price'];

		// ProfitCalculator 內部固定回 4 位小數；MoneyFormatter 再 half-up 收斂到站台貨幣位數，
		// 讓「單筆顯示」與「WpSettlementSummaryProvider 的 bcadd 加總」同精度（見該 class docblock）。
		$profit_amount = $this->money->to_store(
			$this->calculator->calculate( $actual_price, $quantity, $rate )
		);

		$record = new SettlementRecord(
			order_item_id: (int) $item->get_id(), // 尚未儲存，必為 0；save_to_item() 會忽略它
			shop_id: $verified['shop_id'],
			partner_term_id: $verified['partner_term_id'],
			rate: $rate,
			actual_price: $actual_price,
			profit_amount: $profit_amount,
			status: SettlementRecord::STATUS_PENDING
		);

		/*
		* `_override_signup_fee` 恆寫空字串（= 未覆寫）：
		*   - cart meta 沒有它，HMAC 也沒簽過它 ⇒ 沒有可信來源；
		*   - 從賣場 OverrideItem 重讀需要複製 OverridePriceResolver 的「變體優先、整組採用」
		*     分層邏輯，兩份實作分歧的代價是靜默寫錯欄位（本 codebase 已為此吃過虧）；
		*   - 訂閱分潤（`wcs_renewal_order_created`）是獨立工作項，屆時連同續訂價一起處理
		*     才有完整語意。
		* 欄位仍照 spec §2.5 寫出來，保持訂單快照的欄位完整性。
		*/
		$this->settlements->save_to_item(
			$item,
			$record,
			$verified['regular'],
			$verified['sale'],
			''
		);
	}

	/**
	 * 驗證 cart item 的 _profit_* meta 並解出快照值
	 *
	 * 🔴 版本判斷一律用 `payload_version()`，**絕不可**用「regular/sale meta 在不在」判斷：
	 * 攻擊者可以保留合法的 v1 簽章再額外注入一筆偽造的 `_profit_regular_price_override`，
	 * 靠 meta 存在與否判版本就會套用一組從未被簽章保護的值。因此 v1 分支**無條件忽略**
	 * 任何 regular / sale meta（與 CartPriceOverrideHook::verify_item() 同一條紀律）。
	 *
	 * v3 額外把「簽章裡的商品身分」與「實際落進訂單的那一筆 line item」交叉比對，
	 * 擋掉「把 A 商品的整組 _profit_* meta 搬到 B 商品的 cart item 上」。
	 * v1 / v2 **不做**這個比對——那兩版的 payload 從未包含商品身分，簽章沒有保護過它，
	 * 比對零安全增益卻會誤殺既有購物車（與 CartPriceOverrideHook 的取捨一致）。
	 *
	 * ═══ 驗章本體已委派 `CartProfitMetaVerifier`（2026-08）═══
	 *
	 * 本方法在此之前與 `CartPriceOverrideHook::verify_item()` 是**孿生實作**：兩份各自
	 * 維護的版本分派 + HMAC 驗證。兩者一旦分歧，症狀是「購物車套了覆寫價、訂單卻沒有
	 * 分潤紀錄」——顧客付了分潤價、夥伴永遠拿不到那筆結算，而且**兩邊都『正常運作』、
	 * 沒有任何錯誤訊息**（與 Phase 13 `OverridePriceResolver` 是同型問題）。
	 *
	 * 現在兩個 hook 委派同一個類別，分歧在結構上不可能發生。本方法只剩兩件事：提供
	 * **本端**的身分比對 predicate（見下），以及把 v1 的 `null` 轉成 order itemmeta 需要
	 * 的空字串。
	 *
	 * @param array<string, mixed>   $values cart item 陣列
	 * @param \WC_Order_Item_Product $item   對應的 order line item
	 *
	 * @return array{shop_id: int, partner_term_id: int, price: string, regular: string, sale: string}|null
	 *         驗章不過回 null
	 */
	private function verify_cart_item( array $values, \WC_Order_Item_Product $item ): ?array {
		/*
		* 身分比對 predicate（只有 v3 會被呼叫，見 CartProfitMetaVerifier）：
		* 簽章裡的身分 vs **實際落進訂單的那一筆 line item**。
		*
		* WooCommerce 在 includes/class-wc-checkout.php:557-566 用 cart item 的 product 物件
		* 設定 line item 的 product_id / variation_id，正常路徑必然一致；
		* 不一致代表這組 meta 不屬於這筆 line item ⇒ fail-closed。
		*
		* ⚠️ 這裡比對**兩個欄位**，而 CartPriceOverrideHook 比對的是單一「被定價物件」的 id
		*    ——兩邊手上的物件不同，比對方式本來就不同，不可硬統一（那會改變行為）。
		*/
		$identity_matches = static function ( int $product_id, int $variation_id ) use ( $item ): bool {
			return (int) $item->get_product_id() === $product_id
			&& (int) $item->get_variation_id() === $variation_id;
		};

		$verified = $this->verifier->verify( $values, $identity_matches );

		if ( null === $verified ) {
			return null;
		}

		/*
		* v1 的 regular / sale 是 null（該版本從未簽過這兩欄）→ 轉成空字串。
		* order itemmeta 的 `_override_regular_price` / `_override_sale_price` 是字串欄位，
		* 空字串即「未覆寫」，與重構前 v1 分支寫死 '' 的行為逐字一致。
		*/
		return [
			'shop_id'         => $verified->shop_id,
			'partner_term_id' => $verified->partner_term_id,
			'price'           => $verified->price,
			'regular'         => $verified->regular ?? '',
			'sale'            => $verified->sale ?? '',
		];
	}

	/**
	 * 寫入 error_log（過濾 \r \n \t 防 log injection，與其他 hook 一致）
	 *
	 * @param string $message 訊息
	 *
	 * @return void
	 */
	private function log( string $message ): void {
		$safe = (string) preg_replace( "/[\r\n\t]/", ' ', $message );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log( '[ProfitShop][OrderLineItemSettlementHook] ' . $safe );
	}
}
