<?php

declare(strict_types=1);

namespace J7\PowerShopV2;

use J7\PowerShop\Plugin;

/**
 * Class ShortCode
 */
final class ShortCode {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Constructor
	 */
	public function __construct() {
		\add_shortcode( Plugin::$snake . '_products', [ $this, 'products_shortcode_callback' ] );
		\add_shortcode( Plugin::$snake . '_countdown', [ $this, 'countdown_shortcode_callback' ] );
	}

	/**
	 * Products shortcode callback
	 *
	 * @return string
	 */
	public function products_shortcode_callback(): string {
		// 每次進到頁面先清空購物車 #45
		// if (!is_admin()) {
		// try {
		// \WC()->cart->empty_cart();
		// } catch (\Throwable $th) {
		// throw $th;
		// do nothing
		// }
		// }

		// 如果不是 power shop 頁面，就不 render
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( strpos( $request_uri, Plugin::$kebab ) === false ) {
			return 'Power Shop 只能在 Power Shop 頁面使用';
		}

		// 取得 power shop meta 資料
		global $post;
		$meta_string         = \get_post_meta( $post->ID, Plugin::$snake . '_meta', true );
		$shop_meta           = Functions::json_parse( $meta_string, [], true );
		$settings_string     = \get_post_meta( $post->ID, Plugin::$snake . '_settings', true );
		$settings            = Functions::json_parse( $settings_string, [], true );
		$enable_virtual_list = $settings['enableVirtualList'] ?? false;

		if ( ! empty( $settings['endTime'] ) ) {
			$is_shop_closed = $settings['endTime'] < ( time() * 1000 );
		} else {
			$is_shop_closed = false;
		}

		$btn_color = $settings['btnColor'] ?? '#1677ff';

		$handled_shop_meta = $this->handle_shop_meta( $shop_meta );

		$html = '';
		ob_start();
		?>
		<?php if ( ! $enable_virtual_list ) : ?>
			<style>
				:root {
					--ps-primary: <?php echo $btn_color; ?>;
				}
			</style>
			<div class="power-shop-products">
				<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-8">
			<?php
			foreach ( $handled_shop_meta as $meta ) {
				$product_id = $meta['productId'] ?? 0;
				$product    = \wc_get_product( $product_id );
				if ( ! ( $product instanceof \WC_Product ) ) {
					// 商品已被刪除，略過避免 fatal error
					continue;
				}
				$product_type      = $product->get_type();
				$default_image_src = Plugin::$url . '/legacy/js/src/assets/images/defaultImage.jpg';
				switch ( $product_type ) {
					case 'variable':
						\load_template(
							Plugin::$dir . '/legacy/inc/templates/single-product/variable.php',
							false,
							[
								'product'           => $product,
								'meta'              => $meta,
								'default_image_src' => $default_image_src,
								'is_shop_closed'    => $is_shop_closed,
							]
						);
						break;
					case 'simple':
						\load_template(
							Plugin::$dir . '/legacy/inc/templates/single-product/simple.php',
							false,
							[
								'product'           => $product,
								'meta'              => $meta,
								'default_image_src' => $default_image_src,
								'is_shop_closed'    => $is_shop_closed,
							]
						);
						break;
					default:
						\load_template(
							Plugin::$dir . '/legacy/inc/templates/single-product/simple.php',
							false,
							[
								'product'           => $product,
								'meta'              => $meta,
								'default_image_src' => $default_image_src,
								'is_shop_closed'    => $is_shop_closed,
							]
						);
						break;
				}
			}
			?>
				</div>
			</div>
		<?php endif; ?>
		<div id="<?php echo Bootstrap::RENDER_ID_3; ?>"></div>
		<?php
		$html .= ob_get_clean();

		return $html;
	}

	/**
	 * 檢查 shop_meta 裡面的商品與 woocommerce 裡面的商品是否一致
	 * 不一致（type 改變、或可變商品缺變體資料）就更新 shop_meta 裡面的 data
	 *
	 * @param array $shop_meta The shop meta data.
	 * @return array
	 */
	private function handle_shop_meta( array $shop_meta ): array {
		$need_update = false;
		// 檢查當前的 shop_meta 裡面的商品與 woocommerce 裡面的商品是否 type 一致
		foreach ( $shop_meta as $key => $meta ) {
			$meta_product_type = $meta['productType'] ?? '';
			if ( empty( $meta_product_type ) ) {
				// 如果舊版本用戶沒有存到 productType，就判斷給個預設值
				$is_variable_product = ! empty( $meta['variations'] );
				$meta_product_type   = $is_variable_product ? 'variable' : 'simple';
			}

			$product_id = $meta['productId'] ?? 0;
			$product    = \wc_get_product( $product_id );
			if ( ! ( $product instanceof \WC_Product ) ) {
				// 商品已被刪除，略過避免 fatal error
				continue;
			}
			$product_type = $product->get_type();

			$is_type_mismatch = $meta_product_type !== $product_type;
			// type 相符、但可變商品缺 variations 時也必須重建 (issue #76)
			// 編輯器存檔時若變體 API 尚未回應，會寫入 productType = variable 卻沒有 variations，
			// 舊條件只比對 type，這種資料永遠不會被自癒，導致前台無價格、無法下單
			$is_variations_missing = $product_type === 'variable' && empty( $meta['variations'] );

			if ( ! $is_type_mismatch && ! $is_variations_missing ) {
				continue;
			}

			if ( $product_type === 'simple' ) {
				$shop_meta[ $key ] = [
					'productId'    => $product_id,
					'productType'  => $product_type,
					'regularPrice' => $product->get_regular_price(),
					'salesPrice'   => $product->get_sale_price(),
				];
				$need_update       = true;
				continue;
			}

			if ( $product_type === 'variable' ) {
				$formatted_variations = Functions::format_variations( $product );

				// 沒抓到變體就不寫回，避免「沒有變體的可變商品」每次載入頁面都重複寫入 post meta
				if ( ! $is_type_mismatch && empty( $formatted_variations ) ) {
					continue;
				}

				$rebuilt_meta = [
					'productId'   => $product_id,
					'productType' => $product_type,
					'variations'  => $formatted_variations,
				];

				// 型態改變時整筆重建，捨棄已失效的舊欄位（例如 simple 時期的 regularPrice）
				// 型態沒變、只是補回缺失的 variations 時用合併，保留該筆其他自訂欄位（例如團購設定）
				$shop_meta[ $key ] = $is_type_mismatch ? $rebuilt_meta : array_merge( $meta, $rebuilt_meta );
				$need_update       = true;
				continue;
			}

			// 其他型態（grouped / external…）只同步 productType
			$shop_meta[ $key ]['productType'] = $product_type;
			$need_update                      = true;
		}

		if ( $need_update ) {
			// 更新 post_meta
			global $post;
			\update_post_meta( $post->ID, Plugin::$snake . '_meta', \wp_json_encode( $shop_meta ) );
		}

		return $shop_meta;
	}

	/**
	 * Countdown shortcode callback
	 *
	 * @return string
	 */
	public function countdown_shortcode_callback(): string {

		// 如果不是 power shop 頁面，就不 render
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( strpos( $request_uri, Plugin::$kebab ) === false ) {
			return 'Power Shop 只能在 Power Shop 頁面使用';
		}

		// 取得 power shop meta 資料
		global $post;
		if ( ! ( $post instanceof \WP_Post ) ) {
			return '找不到 $post 物件';
		}

		$settings_string = \get_post_meta( $post->ID, Plugin::$snake . '_settings', true );
		$settings        = Functions::json_parse( $settings_string, [], true );
		$start_time      = '';
		if ( ! empty( $settings['startTime'] ) ) {
			$start_time = $settings['startTime'] / 1000; // in seconds
		}

		$end_time = '';
		if ( ! empty( $settings['endTime'] ) ) {
			$end_time = $settings['endTime'] / 1000; // in seconds
		}

		$html = '';
		ob_start();
		?>
		<div class="w-full flex justify-center">
			<div id="flipdown" class="flipdown" data-start-time="<?php echo $start_time;//phpcs:ignore ?>" data-end-time="<?php echo $end_time;//phpcs:ignore ?>"></div>
		</div>
		<script>
			(function($){
				const startTime = $('#flipdown').data('start-time');
				const endTime = $('#flipdown').data('end-time');
				const current = Math.floor(Date.now() / 1000);

				if(current < startTime){
					new FlipDown(startTime, {
						headings: ["天", "時", "分", "秒"],
					}).start();
				}else if (current > startTime && current < endTime){
					new FlipDown(endTime, {
						headings: ["天", "時", "分", "秒"],
					}).start();
				}
			})(jQuery)
		</script>
		<?php
		$html .= ob_get_clean();

		return $html;
	}
}
