<?php
/**
 * 「已撥款後退款」後台待辦通知
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.3、§7.9
 *          specs/features/profit-shop/reverse-settlement-on-refund.feature
 *          （Rule「安全（已撥款）」的「系統應向站長發出『已撥款後退款』的後台通知」）
 *
 * ═══ 為什麼需要一個「待辦清單」而不是只寫 log ═══
 *
 * 錢已經匯給夥伴之後才發生退款，程式**不會**自動改狀態、也**不會**自動向夥伴追討
 * （那是業務決策，不是程式能決定的事，見 `SettlementReversalHook` 的紀律 2）。
 * 因此這件事必須有人看到——只寫進 `WC::logger` 等於沒有人會知道：
 * 站長不會每天去翻 `wc-logs`，而 `_partner_settled_log` 是**事件歷史**（唯讀對帳用），
 * 兩者都不是「這幾筆需要我處理」的待辦清單。
 *
 * ⇒ 本類別維護一份**可清空的待辦佇列**，並在 wp-admin 以 `admin_notices` 呈現。
 *
 * ═══ 與稽核紀錄的分工（兩者刻意不同語意，不可為了 DRY 併成一份）═══
 *
 * | 對象 | 語意 | 去重 |
 * |---|---|---|
 * | `_partner_settled_log`（`SettlementAuditRecorder`） | **事件歷史**：每一次退款事件各留一筆，是對帳依據 | 不去重 |
 * | 本類別的佇列 | **待辦清單**：同一筆品項只需要處理一次 | **依 order_item_id 去重，保留最新** |
 *
 * 把待辦也做成「不去重」會讓站長對同一張訂單做三次部分退款之後看到三條一模一樣的
 * 提示；把稽核也做成「去重」則會弄丟退款事件的次數與時間，對帳時對不上。
 *
 * ═══ 儲存位置：wp_options，autoload = false ═══
 *
 * 這份資料只有在 wp-admin 才會被讀，放進 autoload 會讓**每一個前台請求**都白白載入它。
 * 佇列有筆數上限（`MAX_ENTRIES`），超量時保留最新的——舊的待辦若一直沒被處理，
 * 它的稽核紀錄仍然在 `_partner_settled_log` 裡，不會真的遺失。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

/**
 * 以 wp_options 佇列 + `admin_notices` 呈現「已撥款後退款」待辦
 */
final class RefundAfterPaidNotice {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 佇列的 option key
	 *
	 * 宣告為 public 是為了讓整合測試能直接讀寫，避免測試去猜字面值。
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'power_shop_profit_refund_after_paid';

	/**
	 * 佇列保留上限（超量時保留最新）
	 *
	 * @var int
	 */
	private const MAX_ENTRIES = 200;

	/**
	 * 「標記為已處理」的 query var 與 nonce action
	 *
	 * @var string
	 */
	private const DISMISS_ACTION = 'power_shop_dismiss_refund_after_paid';

	/**
	 * 檢視 / 清除本通知所需的能力
	 *
	 * 與 V2Api 的結算 endpoint 一致：這是金流相關資訊，不給一般編輯看。
	 *
	 * @var string
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * 畫面上最多列出的筆數（其餘以「另有 N 筆」帶過）
	 *
	 * @var int
	 */
	private const MAX_RENDERED = 10;

	/** Constructor */
	public function __construct() {
		\add_action( 'admin_init', [ $this, 'maybe_dismiss' ] );
		\add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * 把一筆「已撥款後退款」加入待辦佇列
	 *
	 * 依 `order_item_id` 去重（保留最新）——理由見 class docblock 的分工表。
	 *
	 * @param int    $order_id        訂單 ID
	 * @param int    $order_item_id   訂單品項 ID
	 * @param int    $partner_term_id 分潤夥伴 term ID
	 * @param string $amount          該筆分潤金額（十進位字串）
	 *
	 * @return void
	 */
	public function add( int $order_id, int $order_item_id, int $partner_term_id, string $amount ): void {
		if ( $order_item_id <= 0 ) {
			return;
		}

		$entries = $this->all();

		// 去重：同一個品項只留最新一筆。
		$entries = array_values(
			array_filter(
				$entries,
				static fn( array $entry ): bool => (int) ( $entry['order_item_id'] ?? 0 ) !== $order_item_id
			)
		);

		$entries[] = [
			'order_id'        => $order_id,
			'order_item_id'   => $order_item_id,
			'partner_term_id' => $partner_term_id,
			'amount'          => $amount,
			'timestamp'       => time(),
		];

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		$this->persist( $entries );
	}

	/**
	 * 讀取待辦佇列
	 *
	 * 壞資料一律回空陣列——這是純展示用途，為它拋例外會讓整個 wp-admin 掛掉。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$raw = \get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) && isset( $entry['order_item_id'] ) ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * 清空待辦佇列
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->persist( [] );
	}

	/**
	 * 處理「標記為已處理」連結（掛在 admin_init）
	 *
	 * 三道守門缺一不可：query var 存在 / 使用者有能力 / nonce 有效。
	 * 少了 nonce，任何網站都能用一張圖片把站長的待辦清單清空（CSRF）。
	 *
	 * @return void
	 */
	public function maybe_dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::DISMISS_ACTION ] ) ) {
			return;
		}

		if ( ! \current_user_can( self::REQUIRED_CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['_wpnonce'] ) ? \sanitize_text_field( \wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! \wp_verify_nonce( $nonce, self::DISMISS_ACTION ) ) {
			return;
		}

		$this->clear();
	}

	/**
	 * 輸出後台通知（掛在 admin_notices）
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! \current_user_can( self::REQUIRED_CAP ) ) {
			return;
		}

		$entries = $this->all();

		if ( empty( $entries ) ) {
			return;
		}

		$total   = count( $entries );
		$visible = array_slice( $entries, -self::MAX_RENDERED );

		echo '<div class="notice notice-warning">';

		printf(
			'<p><strong>%s</strong>：%s</p>',
			\esc_html__( 'Power Shop 分潤結算', 'power_shop' ),
			\esc_html(
				sprintf(
					/* translators: %d: 筆數 */
					\__( '有 %d 筆分潤在「已撥款」之後才發生退款。系統不會自動變更狀態，請人工確認是否需要向夥伴追討。', 'power_shop' ),
					$total
				)
			)
		);

		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $visible as $entry ) {
			printf(
				'<li>%s</li>',
				\esc_html(
					sprintf(
						/* translators: 1: 訂單 ID, 2: 訂單品項 ID, 3: 分潤金額 */
						\__( '訂單 #%1$d／品項 #%2$d，分潤金額 %3$s', 'power_shop' ),
						(int) ( $entry['order_id'] ?? 0 ),
						(int) ( $entry['order_item_id'] ?? 0 ),
						(string) ( $entry['amount'] ?? '' )
					)
				)
			);
		}
		echo '</ul>';

		printf(
			'<p><a href="%1$s">%2$s</a> ｜ <a href="%3$s">%4$s</a></p>',
			\esc_url( \admin_url( 'admin.php?page=power-shop#/profit-settlements' ) ),
			\esc_html__( '前往分潤結算頁', 'power_shop' ),
			\esc_url( $this->dismiss_url() ),
			\esc_html__( '標記為已處理', 'power_shop' )
		);

		echo '</div>';
	}

	/**
	 * 組出帶 nonce 的「標記為已處理」網址
	 *
	 * @return string
	 */
	private function dismiss_url(): string {
		return \wp_nonce_url(
			\add_query_arg( self::DISMISS_ACTION, '1', \admin_url() ),
			self::DISMISS_ACTION
		);
	}

	/**
	 * 寫回 option（autoload = false，理由見 class docblock）
	 *
	 * @param array<int, array<string, mixed>> $entries 佇列內容
	 *
	 * @return void
	 */
	private function persist( array $entries ): void {
		\update_option( self::OPTION_KEY, $entries, false );
	}
}
