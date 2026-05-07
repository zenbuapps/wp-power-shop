<?php
/**
 * Phase 4-C2：賣場前台 add-to-cart 注入 profit_shop_id 至 cart_item_data。
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md / Phase 4-C2
 *
 * 流程：
 *   /?add-to-cart={product_id}&profit_shop_id={shop_id}
 *     ↓ priority 5 注入 cart_item_data['profit_shop_id']
 *     ↓ priority 10（CartPriceOverrideHook）讀 profit_shop_id → 寫 4 筆 _profit_* meta + sign
 *     ↓ priority 999（CartPriceOverrideHook::apply_override）verify + set_price
 *
 * 安全規則（防偽造）：
 *   1. shop_id 必須存在
 *   2. shop status = 'publish'（draft 不允許）
 *   3. product_id 必須存在於 shop->items()（防止「持 shop_A token，加 shop_B 商品」）
 *   4. 任一條件失敗 → 不注入 profit_shop_id（cart 仍正常加入，走商品原價）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 從 query string `profit_shop_id` 注入到 cart_item_data
 *
 * 必須早於 CartPriceOverrideHook 執行，故使用 priority=5（< CartPriceOverrideHook 預設 10）。
 */
final class AddToCartHook {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 建構子（admin 純後台 context 不掛 hook，與 CartPriceOverrideHook 行為一致）
	 *
	 * @param ProfitShopRepositoryInterface $shops 賣場 Repository（DIP，禁鴨子型別）
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shops
	) {
		// admin context guard：純後台（非 ajax）不掛 hook
		if ( \is_admin() && ! \wp_doing_ajax() ) {
			return;
		}

		// priority 5：必須早於 CartPriceOverrideHook（priority 10）
		\add_filter( 'woocommerce_add_cart_item_data', [ $this, 'inject_profit_shop_id' ], 5, 3 );
	}

	/**
	 * 從 $_GET / $_REQUEST 抽取 profit_shop_id 並注入 cart_item_data
	 *
	 * @param array<string, mixed> $cart_item_data 既有 cart_item_data
	 * @param int                  $product_id     商品 ID
	 * @param int                  $variation_id   Variation ID（0 表示非變體）
	 *
	 * @return array<string, mixed> 注入後的 cart_item_data（命中時含 profit_shop_id）
	 *
	 * @phpstan-ignore-next-line
	 */
	public function inject_profit_shop_id( array $cart_item_data, int $product_id, int $variation_id ): array {
		// 若上游（例如 ajax payload）已帶 profit_shop_id，尊重之，不重覆注入
		if ( isset( $cart_item_data['profit_shop_id'] ) ) {
			return $cart_item_data;
		}

		$shop_id = $this->extract_shop_id_from_request();
		if ( $shop_id <= 0 ) {
			return $cart_item_data;
		}

		$shop = $this->try_find_shop( $shop_id );
		if ( null === $shop ) {
			return $cart_item_data;
		}

		// 防偽造：product_id 必須在 shop 的 items 內
		if ( ! $this->product_in_shop( $shop, $product_id ) ) {
			return $cart_item_data;
		}

		// 命中：寫入 profit_shop_id，下游 CartPriceOverrideHook（priority 10）會接手
		$cart_item_data['profit_shop_id'] = $shop->id;
		return $cart_item_data;
	}

	/**
	 * 從 $_GET / $_POST 抽出 profit_shop_id
	 *
	 * 涵蓋 GET（/?add-to-cart=X&profit_shop_id=Y）與 POST（form add-to-cart）兩種來源。
	 * 刻意排除 $_COOKIE：避免 attacker 透過 XSS 在受害者瀏覽器植入 profit_shop_id
	 * 進行分潤歸因竄改（雖下游 product_in_shop 仍會擋，但收斂攻擊面更安全）。
	 *
	 * @return int profit_shop_id；無或不合法回 0
	 */
	private function extract_shop_id_from_request(): int {
		// 依序檢查 $_GET → $_POST，命中即立即在取值表達式上 unslash + sanitize（PHPCS 要求緊貼取值點）。
		// nonce 已由 WC 既有 add-to-cart 流程處理（這只是擴增參數）。
		if ( isset( $_GET['profit_shop_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_array( $_GET['profit_shop_id'] ) ) {
				return 0;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$str = \sanitize_text_field( \wp_unslash( $_GET['profit_shop_id'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( isset( $_POST['profit_shop_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( is_array( $_POST['profit_shop_id'] ) ) {
				return 0;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$str = \sanitize_text_field( \wp_unslash( $_POST['profit_shop_id'] ) );
		} else {
			return 0;
		}

		if ( '' === $str || ! ctype_digit( $str ) ) {
			return 0;
		}

		$id = (int) $str;
		return $id > 0 ? $id : 0;
	}

	/**
	 * 嘗試取得 shop 並驗證 status
	 *
	 * @param int $shop_id 賣場 ID
	 *
	 * @return ProfitShop|null 找到且 publish 才回 ProfitShop；其餘回 null
	 */
	private function try_find_shop( int $shop_id ): ?ProfitShop {
		try {
			$shop = $this->shops->find( $shop_id );
		} catch ( \Throwable $e ) {
			// 過濾 \r \n \t 防 log injection
			$safe_message = (string) preg_replace( "/[\r\n\t]/", ' ', $e->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				sprintf(
					'[ProfitShop][AddToCartHook] find shop %d failed: %s',
					$shop_id,
					$safe_message
				)
			);
			return null;
		}

		if ( ! $shop instanceof ProfitShop ) {
			return null;
		}

		if ( 'publish' !== $shop->status() ) {
			return null;
		}

		return $shop;
	}

	/**
	 * 檢查 product_id 是否在 shop 的 items 集合內
	 *
	 * Phase 5-B：透過 ProfitShop::has_item() 封裝 O(1) lookup，
	 * 避免直接訪問 $shop->items[ $pid ]（DDD 純度）。
	 *
	 * @param ProfitShop $shop       賣場聚合根
	 * @param int        $product_id 商品 ID
	 *
	 * @return bool 屬於賣場為 true
	 */
	private function product_in_shop( ProfitShop $shop, int $product_id ): bool {
		return $shop->has_item( $product_id );
	}
}
