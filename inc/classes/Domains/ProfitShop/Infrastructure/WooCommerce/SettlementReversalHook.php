<?php
/**
 * 退款 / 取消訂單時回沖分潤結算紀錄
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.3、§5.7、§7.9
 *          specs/features/profit-shop/reverse-settlement-on-refund.feature
 *
 * ═══ 這是結算鏈路的「反向」那一半 ═══
 *
 *   OrderLineItemSettlementHook（結帳寫入 pending）
 *     → 站長在 #/profit-settlements 撥款（pending → paid）
 *     → **本 hook**：顧客退款 / 訂單取消時把還沒撥出去的錢收回來
 *
 * 少了本 hook，站長會對一筆**已經把錢退還給顧客**的交易照樣付出分潤，
 * 而且結算頁上完全看不出異常——那筆紀錄看起來就是一筆正常的待撥款。
 *
 * ═══ 🔴 三條分岔規則，差異點才是重點（不可為了 DRY 併成一條）═══
 *
 * | 情境 | pending | paid |
 * |---|---|---|
 * | 全額退款（累計退完全部數量） | → `refunded` | **不動**，只寫稽核 + 後台通知 |
 * | 部分退款 | **重算 `_profit_amount`**，狀態維持 `pending` | **不動金額**，只寫稽核 + 後台通知 |
 * | 訂單取消 | → `cancelled` | **不動** |
 *
 * 1. **paid 一律不自動改（設計 §7.9）**。錢已經匯給夥伴了，程式自動把狀態改掉會讓站長
 *    失去「這筆需要處理」的線索；自動向夥伴追討更是程式無權決定的業務行為。
 *    改為寫入 `refund_after_paid` 稽核紀錄 + 後台待辦通知，由站長走
 *    `POST profit-settlements/reverse-paid` 人工沖回。
 *
 * 2. **部分退款要「重算」，不是「翻狀態」也不是「不動」**（決策 D-3）。顧客買 3 件退 1 件：
 *      - 整筆標成 refunded ⇒ 夥伴那 2 件的分潤被吃掉，**系統性少付夥伴**
 *      - 完全不動         ⇒ 站長要為已退款的那 1 件付分潤，**系統性多付**
 *    只有「重算成剩下 2 件的分潤」兩邊都不錯。
 *
 * 3. **`refunded` / `cancelled` 是終結狀態**，本 hook 一律不再改寫。
 *
 * ═══ 🔴 累計判定以「數量」為準 ═══
 *
 * 「全額 vs 部分」以 `WC_Order::get_qty_refunded_for_item()`（**跨全部退款單累加**）
 * 與原始 `$item->get_quantity()` 比較，**不看退款金額**。理由：`_profit_amount` 的計算
 * 基礎本來就是 `actual_price × qty × rate`，改用金額判定會讓「計算基礎」與「回沖基礎」
 * 不一致，在有折價券的訂單上產生方向固定的系統性偏差。
 *
 * ⚠️ 已知邊界（刻意接受，見交付紀錄）：只退金額不退數量的退款單
 *    （`qty = 0` 但 `total > 0`，例如部分金額補償）以數量判定**不會**觸發回沖。
 *
 * ⚠️ `get_qty_refunded_for_item()` 回傳的是**負數**（退款單的品項數量以負值儲存），
 *    所以一律取 `abs()`。WooCommerce 10.1.4：includes/class-wc-order.php:2281。
 *
 * ═══ 🔴 訂單型別必須過濾 ═══
 *
 * 訂閱商品結帳後，`_profit_*` 這組 line item meta 會**同時存在於** parent order
 * （`shop_order`）與 `shop_subscription` 兩筆記錄上。少了型別過濾，同一筆分潤會被
 * 回沖兩次。判準沿用 `OrderItemSettlementRepository::ORDER_POST_TYPE`（**唯一真相**，
 * 結算查詢的五處 SQL 用的是同一個常數）——`WC_Subscription::get_type()` 回
 * `'shop_subscription'`（WCS 6.1.0：class-wc-subscription.php:129），會在這道守門被擋下。
 *
 * ⚠️ 退款單本身走的是**前一道**守門：`WC_Order_Refund extends WC_Abstract_Order`
 *    （WooCommerce 10.1.4：includes/class-wc-order-refund.php:17），**不是** `WC_Order`
 *    的子類，所以它在 `instanceof \WC_Order` 就被擋下、根本走不到型別比對那一行。
 *    兩道守門各自負責不同的東西，不要以為留一道就夠。
 *
 * ═══ 🔴 不可加 `is_admin()` guard ═══
 *
 * cart 端的三個 hook 都有 `is_admin() && ! wp_doing_ajax()` 守門，**本 hook 絕對不能照抄**：
 * 退款與取消訂單**必然**發生在 wp-admin（或 admin-ajax），加了那道守門等於整個功能不會執行，
 * 而且沒有任何錯誤訊息。
 *
 * ═══ 狀態轉換一律走 CAS ═══
 *
 * 用 `SettlementStatusMutatorInterface::compare_and_set()` 而非 read-check-write：
 * 站長在結算頁按撥款的同時顧客的退款進來，read-check-write 兩邊都會覺得自己贏了。
 * CAS 失敗只代表「狀態已被別人改掉」，靜默略過即可（那正是我們想要的結果）。
 *
 * ⚠️ 行號一律以 **WooCommerce 10.1.4**（本機站台）為準；wp-env 容器裝的是另一個版本，
 *    行號會位移，對不上時先確認版本再懷疑註解。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditRecorder;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementStatusMutatorInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\ProfitCalculator;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;

/**
 * 退款 / 取消訂單時的分潤結算回沖
 */
final class SettlementReversalHook {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Hook priority
	 *
	 * 兩個掛載點（`woocommerce_order_refunded` / `woocommerce_order_status_cancelled`）
	 * 都沒有任何既有 power-shop hook 參與，10（預設）即可；本 hook 只讀 order item meta
	 * 與退款單，不依賴任何其他擴充的執行順序。
	 *
	 * @var int
	 */
	private const HOOK_PRIORITY = 10;

	/**
	 * 建構子
	 *
	 * @param OrderItemSettlementRepository    $settlements 結算 Repository（讀取結算快照）
	 * @param SettlementStatusMutatorInterface $mutator     狀態原子轉換器（CAS）
	 * @param SettlementAuditRecorder          $auditor     稽核紀錄寫入器（`_partner_settled_log`）
	 * @param ProfitCalculator                 $calculator  分潤金額計算（bcmath）
	 * @param MoneyFormatter                   $money       入庫金額格式（站台貨幣小數位）
	 * @param RefundAfterPaidNotice            $notice      「已撥款後退款」後台待辦
	 */
	public function __construct(
		private readonly OrderItemSettlementRepository $settlements,
		private readonly SettlementStatusMutatorInterface $mutator,
		private readonly SettlementAuditRecorder $auditor,
		private readonly ProfitCalculator $calculator,
		private readonly MoneyFormatter $money,
		private readonly RefundAfterPaidNotice $notice
	) {
		\add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], self::HOOK_PRIORITY, 2 );
		\add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order_cancelled' ], self::HOOK_PRIORITY, 2 );
	}

	/**
	 * 訂單發生退款（WooCommerce 10.1.4：includes/wc-order-functions.php:741）
	 *
	 * 🔴 整個 body 包在 try/catch(\Throwable)：這個 action 在 `wc_create_refund()` 內執行，
	 * 此時退款可能已經送到金流商。分潤記帳失敗**絕不可以**讓例外冒出去中斷退款流程
	 * ——那會變成「錢退了但 WooCommerce 這邊的退款單建不起來」，比「少回沖一筆分潤」
	 * 嚴重得多。失敗一律寫 error_log。
	 *
	 * @param mixed $order_id  訂單 ID
	 * @param mixed $refund_id 退款單 ID（未使用；金額與數量一律從訂單重新累計，
	 *                         這樣多次部分退款才會是冪等的）
	 *
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id = 0 ): void {
		unset( $refund_id );

		try {
			$this->handle_refund( (int) $order_id );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'on_order_refunded failed order=%d: %s', (int) $order_id, $e->getMessage() ) );
		}
	}

	/**
	 * 訂單被取消（WooCommerce 10.1.4：includes/class-wc-order.php:448）
	 *
	 * @param mixed $order_id 訂單 ID
	 * @param mixed $order    訂單物件（未使用；一律重新 `wc_get_order()` 取得最新狀態）
	 *
	 * @return void
	 */
	public function on_order_cancelled( $order_id, $order = null ): void {
		unset( $order );

		try {
			$this->handle_cancel( (int) $order_id );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'on_order_cancelled failed order=%d: %s', (int) $order_id, $e->getMessage() ) );
		}
	}

	/**
	 * 退款回沖的實際流程
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return void
	 */
	private function handle_refund( int $order_id ): void {
		$order = $this->resolve_shop_order( $order_id );

		if ( null === $order ) {
			return;
		}

		/** @var SettlementRecord[] $refund_after_paid 需要寫稽核 + 後台通知的紀錄 */
		$refund_after_paid = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			try {
				$record = $this->reverse_one_item( $order, (int) $item_id, $item );

				if ( null !== $record ) {
					$refund_after_paid[] = $record;
				}
			} catch ( \Throwable $e ) {
				// 逐筆隔離：一個品項的資料毀損不得阻斷同一張訂單的其他品項。
				$this->log(
					sprintf(
						'refund reversal failed order=%d item=%d: %s',
						$order_id,
						(int) $item_id,
						$e->getMessage()
					)
				);
			}
		}

		$this->report_refund_after_paid( $order_id, $refund_after_paid );
	}

	/**
	 * 對單一 line item 執行退款回沖
	 *
	 * @param \WC_Order $order   訂單
	 * @param int       $item_id 訂單品項 ID
	 * @param mixed     $item    訂單品項物件
	 *
	 * @return SettlementRecord|null 需要「已撥款後退款」後續處理的紀錄；否則 null
	 */
	private function reverse_one_item( \WC_Order $order, int $item_id, $item ): ?SettlementRecord {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null;
		}

		$record = $this->settlements->find_by_order_item( $item_id );

		// 一般商品（沒有分潤 meta）⇒ 靜默略過，絕大多數 line item 走這條。
		if ( null === $record ) {
			return null;
		}

		$ordered_qty = (int) $item->get_quantity();
		// 見 class docblock：跨全部退款單累加，且回傳負數。
		$refunded_qty = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );

		if ( $refunded_qty <= 0 ) {
			// 這筆品項本身沒有被退款（例如只退了同一張訂單的另一個品項）。
			return null;
		}

		// 終結狀態不再改寫。
		if ( SettlementRecord::STATUS_PENDING !== $record->status
			&& SettlementRecord::STATUS_PAID !== $record->status ) {
			return null;
		}

		$is_full = $ordered_qty <= 0 || $refunded_qty >= $ordered_qty;

		if ( SettlementRecord::STATUS_PAID === $record->status ) {
			// 全額或部分都一樣：狀態與金額都不動，交給站長人工判斷（紀律 1）。
			return $record;
		}

		if ( $is_full ) {
			$this->mutator->compare_and_set(
				$item_id,
				SettlementRecord::STATUS_PENDING,
				SettlementRecord::STATUS_REFUNDED
			);
			return null;
		}

		$this->recalculate_remaining_amount( $record, $ordered_qty - $refunded_qty );

		return null;
	}

	/**
	 * 部分退款：把 `_profit_amount` 重算成「剩餘數量」的分潤（決策 D-3）
	 *
	 * `_actual_price` 與 `_profit_rate` **不動**——它們是交易當下凍結的事實，
	 * 退掉幾件不會改變當初的成交單價與比例。
	 *
	 * ⚠️ 這裡刻意走 `OrderItemSettlementRepository::update_profit_amount()`（**只改寫一個欄位**
	 *    的窄寫入方法），而不是 `save()`：後者會連 `_settlement_status` / `_settled_at` /
	 *    `_settled_by` 一起寫回，若在這個瞬間有人剛把該筆標記成已撥款，就會被我們用讀取
	 *    當下的舊值**靜默覆寫回 pending**——那正是 D-4 引入 CAS 要消滅的「重複撥款」場景。
	 *
	 * ⚠️ 殘留競態（已知、刻意接受）：`current_status()` 與寫入之間若剛好被標記為 paid，
	 *    這筆的金額會被下修。後果是「已撥款金額與紀錄金額不一致」的對帳落差，
	 *    **不是**重複撥款；而要根治需要一個「條件式更新任意欄位」的新 mutator 方法。
	 *
	 * @param SettlementRecord $record        原始結算紀錄
	 * @param int              $remaining_qty 未被退款的數量
	 *
	 * @return void
	 */
	private function recalculate_remaining_amount( SettlementRecord $record, int $remaining_qty ): void {
		$item_id = $record->order_item_id;

		$amount = $this->money->to_store(
			$this->calculator->calculate( $record->actual_price, max( 0, $remaining_qty ), $record->rate )
		);

		if ( $amount === $record->profit_amount ) {
			return;
		}

		// 寫入前再確認一次狀態（直接讀 DB、不走快取）。
		if ( SettlementRecord::STATUS_PENDING !== $this->mutator->current_status( $item_id ) ) {
			return;
		}

		$this->settlements->update_profit_amount( $item_id, $amount );
	}

	/**
	 * 針對「已撥款後才退款」的紀錄留下三種痕跡
	 *
	 * 三者刻意不同語意，缺一不可（**不要**為了 DRY 併掉任何一個）：
	 *
	 * | 去處 | 語意 | 去重 |
	 * |---|---|---|
	 * | `_profit_refund_after_paid` order itemmeta | **這一筆的狀態**——結算頁靠它把品項標紅 | 冪等覆寫 |
	 * | `_partner_settled_log` 稽核紀錄 | **事件歷史**（對帳依據），每次退款各留一筆 | 不去重 |
	 * | `RefundAfterPaidNotice` 佇列 | **待辦清單**（站長看得到才會處理） | 依品項去重 |
	 *
	 * 只有 meta 的話站長不會知道要去看；只有稽核紀錄的話結算頁無從分辨這筆與正常已撥款
	 * 品項的差異；只有後台通知的話，通知一被清掉這件事就查不回來了。
	 *
	 * 🔴 旗標一律走 `flag_refund_after_paid()`（窄寫入），**不可**改成
	 * `$record->is_refund_after_paid = true; $this->settlements->save( $record )`
	 * ——`save()` 會把 `_settlement_status` 用讀取當下的舊值寫回，若此刻站長剛在結算頁
	 * 把它人工沖回成 `refunded`，就會被我們推回 `paid`（見 `recalculate_remaining_amount()`
	 * 的同一條紀律）。
	 *
	 * @param int                $order_id 訂單 ID
	 * @param SettlementRecord[] $records  已撥款卻發生退款的紀錄
	 *
	 * @return void
	 */
	private function report_refund_after_paid( int $order_id, array $records ): void {
		if ( empty( $records ) ) {
			return;
		}

		$this->auditor->record(
			$records,
			SettlementAuditRecorder::TYPE_REFUND_AFTER_PAID,
			\get_current_user_id()
		);

		foreach ( $records as $record ) {
			$this->settlements->flag_refund_after_paid( $record->order_item_id );

			$this->notice->add(
				$order_id,
				$record->order_item_id,
				$record->partner_term_id,
				$record->profit_amount
			);

			$this->log(
				sprintf(
					'refund after paid, manual review required. order=%d item=%d partner=%d amount=%s',
					$order_id,
					$record->order_item_id,
					$record->partner_term_id,
					$record->profit_amount
				)
			);
		}
	}

	/**
	 * 訂單取消回沖的實際流程
	 *
	 * 刻意**不**寫稽核紀錄：`_partner_settled_log` 記的是「站長把多少錢標記成撥給誰」，
	 * 而被取消的分潤從來沒有撥出去過，對帳上沒有東西需要扣抵。
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return void
	 */
	private function handle_cancel( int $order_id ): void {
		$order = $this->resolve_shop_order( $order_id );

		if ( null === $order ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			try {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$record = $this->settlements->find_by_order_item( (int) $item_id );

				if ( null === $record || SettlementRecord::STATUS_PENDING !== $record->status ) {
					continue;
				}

				$this->mutator->compare_and_set(
					(int) $item_id,
					SettlementRecord::STATUS_PENDING,
					SettlementRecord::STATUS_CANCELLED
				);
			} catch ( \Throwable $e ) {
				$this->log(
					sprintf(
						'cancel reversal failed order=%d item=%d: %s',
						$order_id,
						(int) $item_id,
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * 取得訂單並確認型別為 `shop_order`
	 *
	 * 型別過濾的完整推導見 class docblock 與
	 * `OrderItemSettlementRepository::ORDER_POST_TYPE` 的 docblock。
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return \WC_Order|null 非 shop_order 或找不到時回 null
	 */
	private function resolve_shop_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		if ( OrderItemSettlementRepository::ORDER_POST_TYPE !== $order->get_type() ) {
			return null;
		}

		return $order;
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
		\error_log( '[ProfitShop][SettlementReversalHook] ' . $safe );
	}
}
