<?php
/**
 * Phase 4-C2：Cart UI 顯示「來自賣場 XXX」分潤標記。
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md / Phase 4-C2
 *
 * 涵蓋面：mini-cart / cart page / checkout 一致顯示
 *   （woocommerce_get_item_data 是這三個 UI 的共同 filter）
 *
 * 流程：
 *   1. 從 $cart_item['profit_shop_id'] 取 shop_id
 *   2. find shop（已刪 / not publish → 靜默不顯示，cart 仍可結帳）
 *   3. 加入 item_data：[ key => '來自賣場', value => shop title, display => 連結 HTML ]
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;

/**
 * Cart UI 分潤賣場標記
 */
final class CartItemMetaDisplay {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 建構子
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

		\add_filter( 'woocommerce_get_item_data', [ $this, 'add_profit_shop_label' ], 10, 2 );
	}

	/**
	 * 為含 profit_shop_id 的 cart_item 加上「來自賣場」顯示項
	 *
	 * @param array<int, array{key: string, value: string, display?: string}> $item_data 既有顯示資料
	 * @param array<string, mixed>                                            $cart_item cart_item
	 *
	 * @return array<int, array{key: string, value: string, display?: string}> 增補後的顯示資料
	 *
	 * @phpstan-ignore-next-line
	 */
	public function add_profit_shop_label( array $item_data, array $cart_item ): array {
		$shop_id = isset( $cart_item['profit_shop_id'] ) ? (int) $cart_item['profit_shop_id'] : 0;
		if ( $shop_id <= 0 ) {
			return $item_data;
		}

		$shop = $this->try_find_shop( $shop_id );
		if ( null === $shop ) {
			// shop 已刪除或非 publish → 靜默不顯示（cart 仍可結帳，價格由 _profit_signature 保障）
			return $item_data;
		}

		$shop_url = \home_url( '/' . RewriteRules::SHOP_REWRITE_PREFIX . '/' . $shop->slug . '/' );

		$item_data[] = [
			'key'     => \__( '來自賣場', 'power_shop' ),
			'value'   => $shop->title,
			'display' => sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				\esc_url( $shop_url ),
				\esc_html( $shop->title )
			),
		];

		return $item_data;
	}

	/**
	 * 嘗試取得 shop（含 publish 狀態檢查）
	 *
	 * @param int $shop_id 賣場 ID
	 *
	 * @return ProfitShop|null 找到且 publish 才回 ProfitShop；其餘回 null
	 */
	private function try_find_shop( int $shop_id ): ?ProfitShop {
		try {
			$shop = $this->shops->find( $shop_id );
		} catch ( \Throwable $e ) {
			$safe_message = (string) preg_replace( "/[\r\n\t]/", ' ', $e->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				sprintf(
					'[ProfitShop][CartItemMetaDisplay] find shop %d failed: %s',
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
}
