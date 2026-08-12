<?php
/**
 * WooCommerce Subscriptions 的 fragments callback 錯字（TD-42）現況鎖
 *
 * 對應規格：specs/features/profit-shop/add-to-cart-from-modal.feature
 *   Rule: 已知落差（第三方）- 購物車已有訂閱商品時非同步加購可能回傳非預期格式
 *
 * ═══ 這是**第三方外掛的 bug**，power-shop 內修不掉 ═══
 *
 * `WC_Subscriptions_Cart_Validator`（subscriptions-core）在兩處掛 filter：
 *
 *     :71  add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'redirect_ajax_add_to_cart' ) );
 *     :110 add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'redirect_ajax_add_to_cart' ) );
 *
 * 但該 class 裡真正存在的方法叫 **`add_to_cart_ajax_redirect()`**（:148）——
 * 兩個詞組寫反了，而且沒有 `__callStatic` 接得住。
 * 該方法自己的 docblock 還寫著「Attached by ::maybe_empty_cart()」，
 * 可見**意圖是掛它**，只是掛錯名字。
 *
 * ═══ 觸發條件（三個要同時成立）═══
 *
 *   1. 購物車已有訂閱商品
 *   2. 站台的「允許同時購買訂閱與一般商品」為關閉（`..._multiple_purchase` = 'no'，**預設值**）
 *   3. 顧客加購一般商品，且走 **AJAX** 路徑
 *
 * 第 3 點是 power-shop 為何會踩到的原因：`woocommerce_add_to_cart_fragments`
 * 只在 AJAX 加購時被 apply，Phase 15 之前賣場 Modal 走整頁 POST，這條路徑碰不到。
 *
 * ═══ 為什麼不能叫使用者「再試一次」═══
 *
 * 🔴 掛上這個壞掉的 filter **發生在 `add_to_cart()` 成功之後**——商品其實已經
 * 在購物車裡了（WCS 的本意正是「先清掉訂閱、再把顧客導去購物車頁」）。
 * 回應壞掉只是後半段的呈現失敗。
 *
 * 因此前端把這種情況歸類為**狀態未知**、凍結按鈕並提示重新整理 / 查看購物車，
 * 而不是「請再試一次」——慫恿重試就是慫恿重複付款。
 *
 * ⚠️ 本測試是**現況鎖**：它斷言「這個 bug 還在」。
 *    WCS 哪天修好了它就會轉紅——那是好事，屆時請：
 *      1. 把 add-to-cart-from-modal.feature 的該 Rule 從「已知落差」改寫成正向規格
 *      2. 評估前端的「狀態未知」降級是否還需要保留（建議保留，那是通用的防呆）
 *    **不要**為了讓它綠而放寬斷言。
 *
 * @group profit_shop
 * @group woocommerce
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WooCommerce;

use Tests\Integration\TestCase;

/**
 * TD-42 現況鎖
 */
final class SubscriptionsFragmentsBugTest extends TestCase {

	/**
	 * 掛上去的（錯的）callback 名稱
	 */
	private const HOOKED_NAME = 'redirect_ajax_add_to_cart';

	/**
	 * 真正存在的方法名稱
	 */
	private const REAL_NAME = 'add_to_cart_ajax_redirect';

	/**
	 * 驗證器 class
	 */
	private const VALIDATOR = 'WC_Subscriptions_Cart_Validator';

	/**
	 * 沒裝 WCS 就跳過
	 *
	 * @return void
	 */
	private function require_subscriptions(): void {
		if ( ! class_exists( self::VALIDATOR ) ) {
			$this->markTestSkipped(
				'WooCommerce Subscriptions 未安裝（見 RenewalProfitHookTest 檔頭：'
				. '以 .wp-env.override.json 掛入本機的付費外掛）'
			);
		}
	}

	/**
	 * 🔴 掛上去的 callback 名稱**不存在**於該 class
	 *
	 * 這是 TD-42 的根因本體，兩個斷言缺一不可：
	 *   - 掛的那個名字查無此方法
	 *   - 而一個「詞組對調」的同義方法確實存在 ⇒ 證明是錯字，不是我們誤解了設計
	 *
	 * @test
	 * @group error
	 */
	public function test_hooked_callback_does_not_exist_but_the_swapped_name_does(): void {
		$this->require_subscriptions();

		$this->assertFalse(
			method_exists( self::VALIDATOR, self::HOOKED_NAME ),
			'WCS 已修好這個錯字 ⇒ 請依本檔檔頭的說明改寫規格與本測試'
		);

		$this->assertTrue(
			method_exists( self::VALIDATOR, self::REAL_NAME ),
			'真正的方法也不見了 ⇒ WCS 的實作換了，本測試的前提需要重新確認'
		);

		$this->assertFalse(
			method_exists( self::VALIDATOR, '__callStatic' ),
			'若有 __callStatic 就接得住錯字，TD-42 便不成立'
		);
	}

	/**
	 * 🔴 那兩處 add_filter 確實寫著錯的名字（不是我們讀錯行）
	 *
	 * 直接讀原始碼，避免「method 不存在」與「根本沒掛」被混為一談——
	 * 前者才是會炸的情況。
	 *
	 * @test
	 * @group error
	 */
	public function test_source_really_hooks_the_missing_callback_twice(): void {
		$this->require_subscriptions();

		$file = ( new \ReflectionClass( self::VALIDATOR ) )->getFileName();

		$this->assertIsString( $file );

		$source = (string) file_get_contents( (string) $file );

		$this->assertSame(
			2,
			substr_count( $source, "'woocommerce_add_to_cart_fragments', array( __CLASS__, '" . self::HOOKED_NAME . "' )" ),
			'掛載處的數量或寫法變了 ⇒ WCS 動過這段，TD-42 的描述需要重新確認'
		);
	}

	/**
	 * 🔴 filter 一旦被套用就會拋錯（這才是顧客看到的症狀）
	 *
	 * PHP 8 對「callable 指向不存在的方法」是丟 `TypeError`，不是靜默略過
	 * ⇒ AJAX 加購的回應變成 500 / 非 JSON。
	 *
	 * 本條**手動掛上與 WCS 完全相同的 callback**，不去重現「購物車已有訂閱商品」
	 * 那整套前置條件——那組條件涉及付款閘道能力查詢（`one_gateway_supports`），
	 * 在測試環境不穩定，而且**不是本測試要驗的東西**：
	 * 要驗的是「這個 callback 被套用時會發生什麼」。
	 *
	 * @test
	 * @group error
	 */
	public function test_applying_the_broken_filter_throws(): void {
		$this->require_subscriptions();

		$callback = [ self::VALIDATOR, self::HOOKED_NAME ];

		\add_filter( 'woocommerce_add_to_cart_fragments', $callback );

		try {
			$this->expectException( \TypeError::class );
			\apply_filters( 'woocommerce_add_to_cart_fragments', [] );
		} finally {
			\remove_filter( 'woocommerce_add_to_cart_fragments', $callback );
		}
	}
}
