<?php

declare(strict_types=1);

namespace J7\PowerShopV2;

use J7\PowerShop\Plugin;
use function _\find;

/**
 * Class Cart
 */
final class Cart {
	use \J7\WpUtils\Traits\SingletonTrait;


	const ADD_CART_ACTION    = 'handle_add_cart';
	const REMOVE_CART_ACTION = 'handle_remove_cart';
	const GET_CART_ACTION    = 'handle_get_cart';

	/**
	 * Constructor
	 */
	public function __construct() {
		\add_action('woocommerce_before_calculate_totals', [ $this, 'price_refresh' ]);
		foreach ([
			self::ADD_CART_ACTION,
			self::REMOVE_CART_ACTION,
			self::GET_CART_ACTION,
		] as $action) {
			\add_action('wp_ajax_' . $action, [ $this, $action . '_callback' ]);
			\add_action('wp_ajax_nopriv_' . $action, [ $this, $action . '_callback' ]);
		}
		\add_action('woocommerce_checkout_create_order_line_item', [ $this, 'save_custom_data_to_order_meta' ], 10, 4);
	}

	/**
	 * Refresh price based on custom meta
	 *
	 * @param \WC_Cart $cart_object The cart object.
	 * @return void
	 */
	public function price_refresh( $cart_object ) {

		foreach ($cart_object->get_cart_contents() as $key => $item) {
			if (!empty($item[ Plugin::$snake . '_sales_price' ])) {
				$item['data']->set_price($item[ Plugin::$snake . '_sales_price' ]);
			}
			if (!empty($item[ Plugin::$snake . '_regular_price' ]) && empty($item[ Plugin::$snake . '_sales_price' ])) {
				$item['data']->set_price($item[ Plugin::$snake . '_regular_price' ]);
			}
		}
	}

	/**
	 * Handle add cart callback
	 *
	 * @return void
	 */
	public function handle_add_cart_callback() {
		// Security check
		// \check_ajax_referer(Plugin::$kebab, 'nonce');

		// global $woocommerce;
		$post_id = \sanitize_text_field( wp_unslash( $_POST['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if (empty($post_id)) {
			wp_send_json_error(
				[
					'message' => 'post_id is empty',
				]
				);
			return;
		}
		$product_id         = \sanitize_text_field( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$quantity           = \sanitize_text_field( wp_unslash( $_POST['quantity'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$variation_id       = \sanitize_text_field( wp_unslash( $_POST['variation_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$variation_stringfy = \sanitize_text_field( wp_unslash( $_POST['variation'] ?? '[]' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$shop_meta_string   = \get_post_meta($post_id, Plugin::$snake . '_meta', true) ?? '[]';

		try {
			$variation_obj_arr = json_decode(str_replace('\\', '', $variation_stringfy));
			$variation         = [];
			foreach ($variation_obj_arr as $variation_obj) {
				$variation[ $variation_obj->name ] = $variation_obj->value;
			}

			$shop_meta = ( json_decode($shop_meta_string, true) );
		} catch (\Throwable $th) {
			$variation = [];
			$shop_meta = [];
		}

		$the_product_meta = find($shop_meta, [ 'productId' => $product_id ]) ?? [];

		/**
		 * @throws \Exception Plugins can throw an exception to prevent adding to cart.
		 * @param int   $product_id contains the id of the product to add to the cart.
		 * @param int   $quantity contains the quantity of the item to add.
		 * @param int   $variation_id ID of the variation being added to the cart.
		 * @param array $variation attribute values.
		 * @param array $cart_item_data extra cart item data we want to pass into the item.
		 * @return string|bool $cart_item_key
		 * add_to_cart( $product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() )
		 */

		if (empty($variation_id)) {
			// 加入購物車 簡單商品
			\WC()->cart->add_to_cart(
				$product_id,
				$quantity,
				0,
				[],
				[
					Plugin::$snake . '_regular_price' => $the_product_meta['regularPrice'] ?? '',
					Plugin::$snake . '_sales_price'   => $the_product_meta['salesPrice'] ?? '',
					Plugin::$snake . '_post_id'       => $post_id,
				]
				);
		} else {
			// 加入購物車 可變商品
			$the_variations_meta = $the_product_meta['variations'] ?? [];
			$the_variation_meta  = find($the_variations_meta, [ 'variationId' => $variation_id ]) ?? [];
			// 找不到變體的賣場自訂價時留空，讓 Cart::add_cart_item_data 不覆寫價格，改用 WooCommerce 原價（issue #76）
			\WC()->cart->add_to_cart(
				$product_id,
				$quantity,
				$variation_id,
				$variation,
				[
					Plugin::$snake . '_regular_price' => $the_variation_meta['regularPrice'] ?? '',
					Plugin::$snake . '_sales_price'   => $the_variation_meta['salesPrice'] ?? '',
					Plugin::$snake . '_post_id'       => $post_id,
				]
				);
		}

		$totals = \WC()->cart->get_totals();

		$return = [
			'message' => 'success',
			'data'    => [
				'product_id'   => $product_id,
				'quantity'     => $quantity,
				'variation_id' => $variation_id,
				'variation'    => $variation,
			],
		];

		\wp_send_json($return);

		\wp_die();
	}

	/**
	 * Handle remove cart callback
	 *
	 * @return void
	 */
	public function handle_remove_cart_callback() {
		// Security check
		// \check_ajax_referer(Plugin::$kebab, 'nonce');

		$cart_item_key = \sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		\WC()->cart->remove_cart_item($cart_item_key);

		// NOTE 上線後刪除
		$return = [
			'message' => 'success',
			'data'    => [
				'variable' => \array_map( 'sanitize_text_field', wp_unslash( $_POST ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			],
		];

		\wp_send_json($return);

		\wp_die();
	}

	/**
	 * Handle get cart callback
	 *
	 * @return void
	 */
	public function handle_get_cart_callback() {
		// Security check
		// \check_ajax_referer(Plugin::$kebab, 'nonce');

		$totals = \WC()->cart->get_totals();

		// NOTE 上線後刪除
		$return = [
			'message' => 'success',
			'data'    => [
				'variable' => \array_map( 'sanitize_text_field', wp_unslash( $_POST ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'totals'   => $totals,
			],
		];

		\wp_send_json($return);

		\wp_die();
	}

	/**
	 * Save custom data to order meta
	 *
	 * @param \WC_Order_Item_Product $item          The order item.
	 * @param string                 $cart_item_key  The cart item key.
	 * @param array                  $values         The cart item values.
	 * @param \WC_Order              $order          The order object.
	 * @return void
	 */
	public function save_custom_data_to_order_meta( $item, $cart_item_key, $values, $order ) {
		$meta_key = Plugin::$snake . '_post_id';
		if (isset($values[ $meta_key ])) {
			$order->update_meta_data($meta_key, $values[ $meta_key ]);
		}
	}
}
