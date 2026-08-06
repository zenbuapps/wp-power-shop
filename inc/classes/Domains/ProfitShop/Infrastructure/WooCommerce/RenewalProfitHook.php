<?php
/**
 * 訂閱續訂單帶入分潤結算資訊
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.4、§5.7
 *          specs/features/profit-shop/carry-profit-to-renewal-order.feature
 *
 * ═══ 🔴 本 hook 的職責是「重置」，不是「複製」═══
 *
 * 直覺會以為續訂單需要我們把母訂單的分潤 meta 複製過去——**恰恰相反**。
 * WooCommerce Subscriptions 建立續訂單時**已經全部複製好了**：
 *
 *   wcs_create_renewal_order()
 *     → wcs_create_order_from_subscription()   （wcs-order-functions.php:165）
 *       → wcs_copy_order_item()                （同檔 :758-764）
 *         foreach ( $from_item->get_meta_data() as $meta_data ) { ... update_meta_data(...) }
 *         —— 只排除 `_reduced_stock`，**11 個 `_profit_*` 全部照抄**
 *
 * 也就是說續訂單一出生就可能帶著：
 *
 *   `_settlement_status = 'paid'`（母訂單那期的分潤早就撥過款了）
 *   `_settled_at` / `_settled_by`（上一期的撥款時間與操作者）
 *
 * ⇒ 站長會在結算頁看到一筆**已撥款**的新分潤，於是**永遠不會撥款給夥伴**；
 *    或者更糟：以為已經付過，實際上夥伴這期一毛都沒拿到。**全程沒有任何錯誤訊息。**
 *
 * ═══ 凍結 / 動態 / 重算 / 強制重置 ═══
 *
 * | 欄位 | 動作 | 為什麼 |
 * |---|---|---|
 * | `_profit_shop_id` / `_profit_partner_term_id` | **凍結**（沿用複製過來的值） | 歸屬在首期成立時就定了；賣場改名、下架、甚至刪除都不該改寫它 |
 * | `_profit_rate` | **重讀賣場 CPT**；賣場已刪 → fallback 用複製過來的凍結值 | 站長隨時可調整分潤比例，續訂用當下最新值（設計 §5.4 明訂） |
 * | `_actual_price` / `_profit_amount` | **重算** | 續訂價由 WooCommerce Subscriptions 決定，可能與首期不同 |
 * | `_settlement_status` | **強制 `pending`** | 見上方：這是本 hook 存在的主要理由 |
 * | `_settled_at` / `_settled_by` | **強制刪除** | 同上 |
 *
 * ═══ 🔴 掛載點是 filter，不是 action ═══
 *
 * `wcs_renewal_order_created` 以 `apply_filters()` 發出
 * （subscriptions-core/includes/wcs-renewal-functions.php:38），callback **必須回傳續訂單物件**。
 * 漏了 `return`，`wcs_create_renewal_order()` 會回傳 `null`
 * ⇒ **整條訂閱續訂鏈路壞掉**（顧客的訂閱停止續期），影響範圍遠大於分潤。
 * 因此本 class 所有分支——包含例外路徑——都以 `return $renewal_order` 收尾。
 *
 * ═══ 🔴 WooCommerce Subscriptions 是選用外掛 ═══
 *
 * 未安裝時**整段不掛 hook**（`is_supported()`）。無條件註冊並引用外掛函式會在未安裝的
 * 站台造成致命錯誤，**整個 power-shop 外掛掛掉**。守門同時放在本 class 的建構子與
 * `Loader`：前者讓「未安裝就不可能註冊」成為結構保證、與呼叫端寫法無關，
 * 後者讓 Loader 讀起來就看得出這是選用相依。
 *
 * ═══ 續訂單本身是 `shop_order`，會被結算查詢正確計入 ═══
 *
 * 結算的五處 SQL 都以 `OrderItemSettlementRepository::ORDER_POST_TYPE`（`shop_order`）
 * 過濾，用意是排除 `shop_subscription`（訂閱物件上的 `_profit_*` 是同一筆分潤的副本，
 * 不過濾會讓金額 ×2）。**續訂單不受影響**——`wcs_create_renewal_order()` 建立的是
 * 一張真正的 `shop_order`，本來就該被計入。不要因為「這是訂閱相關的東西」
 * 就去放寬那五處過濾。
 *
 * ═══ 沒有 meta key 字面值 ═══
 *
 * 本 class 讀寫一律經過 `OrderItemSettlementRepository`（`find_by_order_item()` /
 * `save()`），因此**完全不需要知道任何 order itemmeta 的名稱**。
 * `save()` 對 `settled_at` / `settled_by` 為 null 時會執行 `wc_delete_order_item_meta()`，
 * 剛好就是上表「強制刪除」要的行為。
 *
 * ⚠️ 行號以本機站台的 WooCommerce Subscriptions **6.1.0**（subscriptions-core）為準。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\ProfitCalculator;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;

/**
 * 訂閱續訂單的分潤結算重置
 */
final class RenewalProfitHook {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Hook priority
	 *
	 * 20 而非預設 10：WooCommerce Subscriptions 自己在 10 掛了續訂單的
	 * `add_order_note` 之類的處理，讓它先跑完再動 meta，行為比較可預期。
	 * 本 hook 不依賴任何其他擴充的輸出，20 只是保守選擇。
	 *
	 * @var int
	 */
	private const HOOK_PRIORITY = 20;

	/**
	 * 判斷 WooCommerce Subscriptions 是否可用
	 *
	 * 用 `wcs_get_subscriptions_for_renewal_order()` 當探針而非 `class_exists( 'WC_Subscriptions' )`：
	 * 我們依賴的是 subscriptions-core 的**續訂函式庫**，而那個 class 名稱在
	 * WCS / subscriptions-core 兩種安裝形態下不一定一致。
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		return \function_exists( 'wcs_get_subscriptions_for_renewal_order' );
	}

	/**
	 * 建構子
	 *
	 * @param ProfitShopRepositoryInterface $shops       賣場 Repository（重讀 `_profit_rate` 的唯一來源）
	 * @param OrderItemSettlementRepository $settlements 結算 Repository（讀寫 order itemmeta 的唯一出口）
	 * @param ProfitCalculator              $calculator  分潤金額計算（bcmath）
	 * @param MoneyFormatter                $money       入庫金額格式（站台貨幣小數位）
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shops,
		private readonly OrderItemSettlementRepository $settlements,
		private readonly ProfitCalculator $calculator,
		private readonly MoneyFormatter $money
	) {
		if ( ! self::is_supported() ) {
			return;
		}

		\add_filter( 'wcs_renewal_order_created', [ $this, 'carry_profit' ], self::HOOK_PRIORITY, 2 );
	}

	/**
	 * 把母訂單（訂閱物件）繼承來的分潤資訊重置成續訂單自己的那一期
	 *
	 * 🔴 這是 **filter**：所有分支都必須回傳 `$renewal_order` 原物件（見 class docblock）。
	 * 整個 body 因此包在 try/catch(\Throwable)——分潤記帳失敗絕不可以讓顧客的訂閱停止續期。
	 *
	 * @param mixed $renewal_order 續訂單（WC_Order；WCS 已 save 過，line item 都有 ID）
	 * @param mixed $subscription  來源訂閱物件（未使用；歸屬一律沿用複製過來的 meta）
	 *
	 * @return mixed 原封不動的 $renewal_order
	 */
	public function carry_profit( $renewal_order, $subscription = null ) {
		unset( $subscription );

		try {
			if ( $renewal_order instanceof \WC_Order ) {
				$this->reset_items( $renewal_order );
			}
		} catch ( \Throwable $e ) {
			$this->log(
				sprintf(
					'carry_profit failed order=%d: %s',
					$renewal_order instanceof \WC_Order ? (int) $renewal_order->get_id() : 0,
					$e->getMessage()
				)
			);
		}

		return $renewal_order;
	}

	/**
	 * 逐一重置續訂單上帶有分潤歸屬的品項
	 *
	 * @param \WC_Order $order 續訂單
	 *
	 * @return void
	 */
	private function reset_items( \WC_Order $order ): void {
		foreach ( $order->get_items() as $item_id => $item ) {
			try {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$this->reset_one_item( $order, (int) $item_id, $item );
			} catch ( \Throwable $e ) {
				// 逐筆隔離：一個品項的資料毀損不得阻斷同一張續訂單的其他品項。
				$this->log(
					sprintf(
						'renewal reset failed order=%d item=%d: %s',
						(int) $order->get_id(),
						(int) $item_id,
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * 重置單一品項
	 *
	 * @param \WC_Order              $order   續訂單（僅用於 log 定位）
	 * @param int                    $item_id 續訂單品項 ID
	 * @param \WC_Order_Item_Product $item    續訂單品項
	 *
	 * @return void
	 */
	private function reset_one_item( \WC_Order $order, int $item_id, \WC_Order_Item_Product $item ): void {
		// 沒有分潤歸屬（`_profit_shop_id` 不存在或 <= 0）⇒ 一般商品，完全不碰。
		$inherited = $this->settlements->find_by_order_item( $item_id );

		if ( null === $inherited ) {
			return;
		}

		$rate     = $this->resolve_rate( $inherited, $order );
		$quantity = max( 0, (int) $item->get_quantity() );
		$unit     = $this->renewal_unit_price( $item, $quantity );

		$amount = $this->money->to_store(
			$this->calculator->calculate( $unit, $quantity, $rate )
		);

		$this->settlements->save(
			new SettlementRecord(
				order_item_id: $item_id,
				shop_id: $inherited->shop_id,                 // 凍結
				partner_term_id: $inherited->partner_term_id, // 凍結
				rate: $rate,                                  // 動態（重讀賣場）
				actual_price: $unit,                          // 重算
				profit_amount: $amount,                       // 重算
				status: SettlementRecord::STATUS_PENDING,     // 🔴 強制重置
				settled_at: null,                             // 🔴 save() 會 delete meta
				settled_by: null
			)
		);
	}

	/**
	 * 取得這一期要套用的分潤比例
	 *
	 * 動態重讀賣場；賣場已被刪除時 fallback 用繼承來的凍結值，並寫一筆 error log。
	 *
	 * ⚠️ 這裡**不**檢查賣場的 `status`：賣場只是被下架（draft）時，這位夥伴的既有訂閱
	 *    仍然在收費、仍然該拿分潤。「還能不能被買」與「已經在收費的訂閱怎麼記帳」
	 *    是兩件事（與 `OrderLineItemSettlementHook::freeze()` 同一條紀律）。
	 *
	 * @param SettlementRecord $inherited 從母訂單繼承來的結算快照
	 * @param \WC_Order        $order     續訂單（僅用於 log 定位）
	 *
	 * @return ProfitRate
	 */
	private function resolve_rate( SettlementRecord $inherited, \WC_Order $order ): ProfitRate {
		$shop = $this->shops->find( $inherited->shop_id );

		if ( $shop instanceof ProfitShop ) {
			return $shop->rate;
		}

		$this->log(
			sprintf(
				'shop %d not found on renewal, falling back to inherited rate %d. order=%d item=%d',
				$inherited->shop_id,
				$inherited->rate->value(),
				(int) $order->get_id(),
				$inherited->order_item_id
			)
		);

		return $inherited->rate;
	}

	/**
	 * 這一期的實際單價（WooCommerce Subscriptions 算出來的續訂價）
	 *
	 * 取 `get_subtotal()`（折扣前的行小計）÷ 數量，而非 `get_total()`：
	 * 與 `OrderLineItemSettlementHook` 對首期的處理一致——那裡用的是簽章保護過的
	 * 賣場覆寫單價（也是折扣前）。站長另外發的折價券由站長吸收，不影響夥伴分潤。
	 * 兩邊若一個算折扣前、一個算折扣後，同一個訂閱的首期與續訂會出現無法解釋的落差。
	 *
	 * 除法一律走 `bcdiv`（scale 6 再交給 `MoneyFormatter` half-up 收斂），
	 * 禁止浮點——`/` 在 `599.97 / 3` 這種組合就會漂出尾數，而這個值會變成付給夥伴的錢。
	 *
	 * @param \WC_Order_Item_Product $item     續訂單品項
	 * @param int                    $quantity 數量
	 *
	 * @return string 十進位字串（已收斂到站台貨幣小數位）
	 */
	private function renewal_unit_price( \WC_Order_Item_Product $item, int $quantity ): string {
		$subtotal = (string) $item->get_subtotal();

		if ( '' === $subtotal || ! is_numeric( $subtotal ) || $quantity <= 0 ) {
			return $this->money->to_store( '0' );
		}

		return $this->money->to_store( bcdiv( $subtotal, (string) $quantity, 6 ) );
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
		\error_log( '[ProfitShop][RenewalProfitHook] ' . $safe );
	}
}
