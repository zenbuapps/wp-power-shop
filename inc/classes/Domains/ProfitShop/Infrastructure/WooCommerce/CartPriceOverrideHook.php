<?php
/**
 * 前台 Cart 價格 Override Hook（Phase 3-D 最高風險元件）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3、§5、§6
 *
 * 安全性要點：
 *   - cart_item meta 攜帶 (_profit_shop_id, _profit_partner_term_id, _profit_price_override, _profit_signature)
 *   - signature 透過 CartPriceSignatureService（HMAC-SHA256 + wp_salt('auth')）驗證
 *   - 驗章失敗一律 fallback 至商品 regular_price，並寫 error_log（避免 oracle，不告知前端）
 *   - priority 999 確保我方 set_price 晚於其他 plugin（不被覆蓋）
 *   - admin 純後台 context（非 ajax）不掛 hook，admin-ajax 仍掛（讓 add_to_cart_ajax 正常）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 前台 Cart 價格 Override Hook
 *
 * 在 add-to-cart / before-calculate-totals / session-restore 三個 WC 擴充點，
 * 將 ProfitShop 的 override 價格安全寫入 cart_item，並於計算總額前驗章 + set_price。
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
	 * Cart_item meta 鍵：簽章
	 */
	private const META_SIGNATURE = '_profit_signature';

	/**
	 * 建構子
	 *
	 * 在非純 admin context（front-end / admin-ajax）時自動註冊三個 WC filter / action。
	 *
	 * @param ProfitShopRepositoryInterface $shops      賣場 Repository（DIP，禁鴨子型別）
	 * @param CartPriceSignatureService     $signatures 簽章服務
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shops,
		private readonly CartPriceSignatureService $signatures
	) {
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
	 * 計算 effective price（OverrideItem 的 sale_price/regular_price 之 fallback）→ sign → 寫入 4 筆 meta。
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

		$effective_price = $this->resolve_effective_price( $item, $product_id, $variation_id );

		if ( null === $effective_price ) {
			return $cart_item_data;
		}

		// J-2 必修：sign 端 / verify 端 / set_price 端統一 normalize，避免任何路徑（例如 wc_format_decimal）
		// 將 '888' 漂移成 '888.00' 而導致簽章不一致 → 靜默 fallback 至原價的商家損失
		$normalized_price = self::normalize_price_for_signature( $effective_price );

		$cart_item_data[ self::META_SHOP_ID ]         = $shop_id;
		$cart_item_data[ self::META_PARTNER_TERM_ID ] = $shop->partner_term_id;
		$cart_item_data[ self::META_PRICE_OVERRIDE ]  = $normalized_price;
		$cart_item_data[ self::META_SIGNATURE ]       = $this->signatures->sign(
			$shop_id,
			$shop->partner_term_id,
			$normalized_price
		);

		return $cart_item_data;
	}

	/**
	 * Session round-trip 後將 4 筆 _profit_* meta 套回 cart_item
	 *
	 * @param array<string, mixed> $cart_item 反序列化後的 cart_item（已由 WC 重建 product/variation）
	 * @param array<string, mixed> $values    session 中保存的原始 cart_item 陣列
	 *
	 * @return array<string, mixed> 補回 _profit_* meta 後的 cart_item
	 */
	public function restore_from_session( array $cart_item, array $values ): array {
		foreach (
			[
				self::META_SHOP_ID,
				self::META_PARTNER_TERM_ID,
				self::META_PRICE_OVERRIDE,
				self::META_SIGNATURE,
			] as $key
		) {
			if ( isset( $values[ $key ] ) ) {
				$cart_item[ $key ] = $values[ $key ];
			}
		}
		return $cart_item;
	}

	/**
	 * 計算購物車總額前：對所有含 _profit_signature 的 cart_item 驗章，通過則 set_price，否則 fallback。
	 *
	 * Priority=999 確保晚於其他 plugin 的 set_price，最終價格不被覆蓋。
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

			// 完整 4 筆 meta + 簽章驗證通過 → set_price 為 override
			if ( $this->has_all_profit_meta( $item ) ) {
				$shop_id         = (int) $item[ self::META_SHOP_ID ];
				$partner_term_id = (int) $item[ self::META_PARTNER_TERM_ID ];
				// J-2 必修：verify 端必須與 sign 端用同一 normalize 形式比對，避免格式漂移造成靜默 fallback
				$price     = self::normalize_price_for_signature( (string) $item[ self::META_PRICE_OVERRIDE ] );
				$signature = (string) $item[ self::META_SIGNATURE ];

				if ( $this->signatures->verify( $shop_id, $partner_term_id, $price, $signature ) ) {
					if (
						isset( $cart->cart_contents[ $key ]['data'] )
						&& $cart->cart_contents[ $key ]['data'] instanceof \WC_Product
					) {
						$cart->cart_contents[ $key ]['data']->set_price( $price );
					}
					continue;
				}

				$this->log_signature_mismatch( $shop_id, $key );
			} else {
				// 部分 meta 被刪 / 不一致：視為 tampering，記錄後 fallback
				$this->log_signature_mismatch(
					(int) ( $item[ self::META_SHOP_ID ] ?? 0 ),
					$key
				);
			}

			// 驗章失敗或 meta 不完整：清掉所有 profit_* meta 並把 product 還原回原價，
			// 避免前一輪 calculate_totals（例如 woocommerce_add_to_cart -> calculate_totals）
			// 已透過 set_price 將 product 物件改成 override 價而留在記憶體中。
			$this->strip_profit_meta( $cart, $key );
			$this->reset_to_original_price( $cart, $key );
		}
	}

	/**
	 * 計算 OverrideItem + variation 的 effective price
	 *
	 * Fallback chain（精簡版，以 cart 寫入時可知資訊為主）：
	 *   1. variation override sale_price
	 *   2. variation override regular_price
	 *   3. parent override sale_price
	 *   4. parent override regular_price
	 *   5. null（無 override 設定 → 不寫 meta，照原價走）
	 *
	 * @param OverrideItem $item         覆寫項目
	 * @param int          $product_id   商品 ID（保留為將來擴充用）
	 * @param int          $variation_id Variation ID（0 表示無變體）
	 *
	 * @return string|null 有效 override 價格字串；若該 item 完全沒有 override 則回 null（讓商品走原價）
	 *
	 * @phpstan-ignore-next-line
	 */
	private function resolve_effective_price( OverrideItem $item, int $product_id, int $variation_id ): ?string {
		if ( $variation_id > 0 ) {
			$variation_override = $item->get_variation_override( $variation_id );
			if ( null !== $variation_override ) {
				if ( null !== $variation_override->sale_price ) {
					return $variation_override->sale_price;
				}
				if ( null !== $variation_override->regular_price ) {
					return $variation_override->regular_price;
				}
			}
		}

		if ( null !== $item->override->sale_price ) {
			return $item->override->sale_price;
		}
		if ( null !== $item->override->regular_price ) {
			return $item->override->regular_price;
		}

		return null;
	}

	/**
	 * 將 cart_item 的 product 價格還原回商品原價
	 *
	 * 用於：驗章失敗後，若 product 物件曾被前一次 calculate_totals 呼叫 set_price() 改成
	 * override 價，需要主動還原（WC 不會自己重置已經被 set_price 過的 product）。
	 *
	 * 還原優先順序：sale_price（若有） → regular_price → 直接讀 get_price()
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
		$data->set_price( '' !== $sale ? $sale : $reg );
	}

	/**
	 * 檢查 cart_item 是否完整含 4 筆 _profit_* meta
	 *
	 * @param array<string, mixed> $item cart_item
	 *
	 * @return bool 完整為 true
	 */
	private function has_all_profit_meta( array $item ): bool {
		foreach (
			[
				self::META_SHOP_ID,
				self::META_PARTNER_TERM_ID,
				self::META_PRICE_OVERRIDE,
				self::META_SIGNATURE,
			] as $key
		) {
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
		foreach (
			[
				self::META_SHOP_ID,
				self::META_PARTNER_TERM_ID,
				self::META_PRICE_OVERRIDE,
				self::META_SIGNATURE,
			] as $meta_key
		) {
			unset( $cart->cart_contents[ $key ][ $meta_key ] );
		}
	}

	/**
	 * 將價格字串標準化為簽章用的標準形式
	 *
	 * 使用 wc_format_decimal( $price, '' ) 統一形式（trim trailing zeros、不固定小數位）：
	 *   '888'     → '888'
	 *   '888.0'   → '888'
	 *   '888.00'  → '888'
	 *   '888.50'  → '888.5'
	 *   '888.55'  → '888.55'
	 *
	 * 必須在 sign 端、verify 端、set_price 端、寫入 _profit_price_override 端都統一套用，
	 * 避免任何路徑上的格式漂移造成簽章驗證失敗 → 靜默 fallback 至原價的商家損失。
	 *
	 * @param string $price 原始價格字串
	 *
	 * @return string 標準化後的價格字串
	 */
	private static function normalize_price_for_signature( string $price ): string {
		// wc_format_decimal 第二參數空字串 = 不固定小數位，自動 trim trailing zeros
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
