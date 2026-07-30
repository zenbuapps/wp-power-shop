<?php
/**
 * Phase 9：WC 單品頁套用分潤賣場覆寫價（含變體）
 *
 * 對應規格：specs/plan/2026-07-30-profit-shop-variation-override-plan.md 第六-B 階段（R-1）
 *
 * 解決的問題：
 *   賣場前台卡片顯示 override 價 → 顧客點「前往選購」進 WC 單品頁 →
 *   單品頁顯示「商品原價」→ 加入購物車後又變回 override 價。
 *   這個「價格跳動」在開放變體覆寫 UI 之後會變成顧客可見的信任問題。
 *
 * 作用範圍（刻意收斂）：
 *   只在 `is_product()` 為真、且網址帶 `?profit_shop_id={id}` 的單品頁請求註冊 filter。
 *   購物車 / 結帳 / 商品列表頁一律不掛，避免干擾 CartPriceOverrideHook 的簽章與
 *   驗章失敗時的 fallback 保護。
 *
 * 安全規則（沿用 AddToCartHook 的三道閘門）：
 *   1. shop 必須存在
 *   2. shop status = 'publish'（draft 預覽價不得外洩）
 *   3. 當前商品必須存在於 shop->items()
 *   任一條件失敗 → 完全不註冊 filter，單品頁走商品原價。
 *
 * ⚠️ 價格語意必須與 CartPriceOverrideHook::resolve_effective_price() 完全一致，
 *    否則「單品頁顯示的價」與「購物車實收的價」會對不起來——那正是本類要消滅的問題。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 單品頁價格 override hook
 */
final class SingleProductPriceHook {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 命中的賣場 ID（未命中為 0）
	 *
	 * @var int
	 */
	private int $shop_id = 0;

	/**
	 * 命中的父商品 ID（未命中為 0）
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
	 * 建構子
	 *
	 * 掛 template_redirect priority 20：
	 * - 晚於 ProfitShopRenderer 的 9（賣場頁自己 exit，不會走到這裡）
	 * - 此時主查詢已解析完成，is_product() 才可靠
	 *
	 * @param ProfitShopRepositoryInterface $shops 賣場 Repository（DIP，禁鴨子型別）
	 */
	public function __construct(
		private readonly ProfitShopRepositoryInterface $shops
	) {
		// admin context guard：純後台（非 ajax）不掛 hook，與其餘 WooCommerce hook 一致
		if ( \is_admin() && ! \wp_doing_ajax() ) {
			return;
		}

		\add_action( 'template_redirect', [ $this, 'maybe_register_filters' ], 20 );
	}

	/**
	 * 判斷當前請求是否為「帶 profit_shop_id 的單品頁」，是才註冊價格 filter
	 *
	 * @return void
	 */
	public function maybe_register_filters(): void {
		if ( ! \function_exists( 'is_product' ) || ! \is_product() ) {
			return;
		}

		$shop_id = $this->extract_shop_id_from_query();
		if ( $shop_id <= 0 ) {
			return;
		}

		$shop = $this->try_find_published_shop( $shop_id );
		if ( null === $shop ) {
			return;
		}

		$product_id = (int) \get_queried_object_id();
		if ( $product_id <= 0 || ! $shop->has_item( $product_id ) ) {
			return;
		}

		$item = $shop->items[ $product_id ] ?? null;
		if ( ! $item instanceof OverrideItem ) {
			return;
		}

		$this->shop_id    = $shop_id;
		$this->product_id = $product_id;
		$this->item       = $item;

		// 一般商品 / 變體商品父層（view context 才會觸發，'edit' context 不受影響）
		\add_filter( 'woocommerce_product_get_price', [ $this, 'filter_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_get_regular_price', [ $this, 'filter_regular_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_get_sale_price', [ $this, 'filter_sale_price' ], 10, 2 );

		// 已選定的單一變體（get_available_variations 的 display_price 走這裡）
		\add_filter( 'woocommerce_product_variation_get_price', [ $this, 'filter_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_variation_get_regular_price', [ $this, 'filter_regular_price' ], 10, 2 );
		\add_filter( 'woocommerce_product_variation_get_sale_price', [ $this, 'filter_sale_price' ], 10, 2 );

		// 變體價格區間（WC 以 'edit' context 讀值後才套這三個 filter，故必須另外掛）
		\add_filter( 'woocommerce_variation_prices_price', [ $this, 'filter_variation_prices_price' ], 10, 3 );
		\add_filter( 'woocommerce_variation_prices_regular_price', [ $this, 'filter_variation_prices_regular_price' ], 10, 3 );
		\add_filter( 'woocommerce_variation_prices_sale_price', [ $this, 'filter_variation_prices_sale_price' ], 10, 3 );

		// ⚠️ 關鍵：WC 把變體價格陣列存在 transient，hash 只含上面三個 filter 的「callback 名稱」，
		// 不含賣場 ID。不加這條的話「賣場 A」與「賣場 B」會算出同一個 hash → 互相讀到對方的
		// 快取價格。加入 shop_id 讓每個賣場各自一個快取分桶。
		\add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'filter_variation_prices_hash' ], 10, 3 );
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
	 * 見上方註冊處的說明：不加這條會讓不同賣場共用同一份快取價格。
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

		if ( $product instanceof \WC_Product_Variation ) {
			if ( (int) $product->get_parent_id() !== $this->product_id ) {
				return null;
			}
			return $this->resolve_prices( (int) $product->get_id() );
		}

		if ( (int) $product->get_id() !== $this->product_id ) {
			return null;
		}

		return $this->resolve_prices( null );
	}

	/**
	 * 解析價格三元組
	 *
	 * ⚠️ Fallback 語意必須與 CartPriceOverrideHook::resolve_effective_price() 逐字一致：
	 * 變體只要有任一覆寫欄位，就整組採用變體層，**不與商品層逐欄混用**。
	 * 若這裡改成逐欄 fallback，會出現「單品頁顯示 A 價、購物車實收 B 價」。
	 *
	 * @param int|null $variation_id 變體 ID；null 表示父商品 / 一般商品
	 *
	 * @return array{price: string, regular: string|null, sale: string|null}|null 無覆寫時回 null
	 */
	private function resolve_prices( ?int $variation_id ): ?array {
		if ( ! $this->item instanceof OverrideItem ) {
			return null;
		}

		if ( null !== $variation_id ) {
			$variation_override = $this->item->get_variation_override( $variation_id );
			if (
				null !== $variation_override
				&& ( null !== $variation_override->sale_price || null !== $variation_override->regular_price )
			) {
				return [
					'price'   => (string) ( $variation_override->sale_price ?? $variation_override->regular_price ),
					'regular' => $variation_override->regular_price,
					'sale'    => $variation_override->sale_price,
				];
			}
		}

		$parent_override = $this->item->override;
		if ( null !== $parent_override->sale_price || null !== $parent_override->regular_price ) {
			return [
				'price'   => (string) ( $parent_override->sale_price ?? $parent_override->regular_price ),
				'regular' => $parent_override->regular_price,
				'sale'    => $parent_override->sale_price,
			];
		}

		return null;
	}

	/**
	 * 從 $_GET 抽出 profit_shop_id
	 *
	 * 刻意只讀 $_GET：單品頁的分潤入口一律是賣場前台產生的連結（query string），
	 * 不讀 $_COOKIE / $_POST 以收斂攻擊面（與 AddToCartHook 同一條紀律）。
	 *
	 * @return int profit_shop_id；無或不合法回 0
	 */
	private function extract_shop_id_from_query(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['profit_shop_id'] ) || is_array( $_GET['profit_shop_id'] ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = \sanitize_text_field( \wp_unslash( $_GET['profit_shop_id'] ) );

		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			return 0;
		}

		$id = (int) $raw;
		return $id > 0 ? $id : 0;
	}

	/**
	 * 取得賣場並驗證 status
	 *
	 * @param int $shop_id 賣場 ID
	 *
	 * @return ProfitShop|null 找到且為 publish 才回傳；其餘一律 null
	 */
	private function try_find_published_shop( int $shop_id ): ?ProfitShop {
		try {
			$shop = $this->shops->find( $shop_id );
		} catch ( \Throwable $e ) {
			// 過濾 \r \n \t 防 log injection
			$safe_message = (string) preg_replace( "/[\r\n\t]/", ' ', $e->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				sprintf(
					'[ProfitShop][SingleProductPriceHook] find shop %d failed: %s',
					$shop_id,
					$safe_message
				)
			);
			return null;
		}

		if ( ! $shop instanceof ProfitShop ) {
			return null;
		}

		// draft 賣場的促銷預覽價不得洩漏給一般顧客
		if ( 'publish' !== $shop->status() ) {
			return null;
		}

		return $shop;
	}
}
