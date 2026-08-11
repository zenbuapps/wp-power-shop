<?php
/**
 * 商品變體查詢（賣場編輯頁專用，取代 WooCommerce REST）
 *
 * @package J7\PowerShop
 */

declare( strict_types=1 );

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

/**
 * 取得單一商品的**全部**變體，供賣場編輯頁的變體覆寫表格使用
 *
 * ═══ 🔴 為什麼不繼續用 `wc/v3/products/{id}/variations` ═══
 *
 * WooCommerce REST 的 `per_page` 硬上限是 **100**
 * （`WC_REST_Controller::get_collection_params()` 的 `'maximum' => 100`），
 * 超過 100 個變體的商品在賣場編輯頁**只設定得到前 100 個**——那不是本專案的
 * 選擇，是那支 API 的限制（曾登記為 TD-16）。
 *
 * 而賣場的變體層價格覆寫是**以 variation_id 為鍵**逐筆設定的，第 101 個之後
 * 的變體站長根本點不到，等於那些 SKU 在賣場裡永遠只能賣原價。
 *
 * 本類直接走 `WC_Product_Variable::get_children()`（回傳全部變體 ID，**無上限**），
 * 因此不需要分頁、也沒有截斷。
 *
 * ═══ 為什麼是「自己撈」而不是「呼叫 WC REST 再拼接」 ═══
 *
 * 拼接多頁需要 N 次 HTTP round trip（或 N 次 `rest_do_request()`），每一次都會
 * 重跑一遍 WC REST controller 的權限檢查、schema 組裝與 response 序列化，
 * 而我們只需要其中 8 個欄位。直接讀 WC 的 PHP 物件層是同一份資料的更短路徑。
 *
 * ⚠️ 「不用 WooCommerce API」指的是**不再走它的 REST 端點**；
 *    server 端仍然透過 `wc_get_product()` 讀資料——那是 WooCommerce 儲存層的
 *    唯一正確入口，繞過它自己拼 SQL 會漏掉價格同步、快取與各種 filter。
 *
 * ═══ 回傳欄位刻意只有 8 個 ═══
 *
 * 對齊前端 `VariationOverrideEditor` 真正讀到的欄位。多回傳的欄位不會有人用，
 * 卻會讓 payload 在 500 個變體的商品上變得很肥。
 *
 * @see \J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest\V2Api::get_product_variations_with_id_callback()
 */
final class WpProductVariationQuery {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 取得指定商品的全部變體
	 *
	 * 找不到商品、或商品不是可變類型時一律回空陣列——賣場編輯頁只在
	 * `isVariable()` 為真時才展開，拿不到變體時的降級顯示由前端負責。
	 *
	 * @param int $product_id 父商品 ID
	 *
	 * @return array<int, array<string, mixed>> 變體資料列
	 */
	public function find_by_product( int $product_id ): array {
		if ( $product_id <= 0 || ! \function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$product = \wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product_Variable ) {
			return [];
		}

		$children = array_map( 'intval', $product->get_children() );

		if ( [] === $children ) {
			return [];
		}

		/*
		* 批次預熱 post cache：少了這行，下面每一次 `wc_get_product()` 都會各自
		* 打一次 DB 撈 post + postmeta（500 個變體 = 1000+ 次查詢）。
		*/
		\_prime_post_caches( $children, false, true );

		$rows = [];

		foreach ( $children as $variation_id ) {
			$variation = \wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$rows[] = $this->to_row( $variation );
		}

		return $rows;
	}

	/**
	 * 單一變體 → 前端資料列
	 *
	 * @param \WC_Product_Variation $variation 變體物件
	 *
	 * @return array<string, mixed>
	 */
	private function to_row( \WC_Product_Variation $variation ): array {
		return [
			'id'               => (int) $variation->get_id(),
			'sku'              => (string) $variation->get_sku(),
			'attributes'       => $this->to_attribute_rows( $variation ),

			/*
			 * ⚠️ 一律用 `'edit'` context 取價。
			 *
			 * `'view'`（預設）會跑 `woocommerce_product_variation_get_regular_price`
			 * 這類 filter——而本外掛的 `SingleProductPriceHook` 正是掛在那上面。
			 * 賣場編輯頁要顯示的是「商品**原本**的價格」（用來對照站長要覆寫成多少），
			 * 取到被覆寫後的值會讓站長看到自己設的覆寫價、誤以為原價被改掉了。
			 */
			'regular_price'    => (string) $variation->get_regular_price( 'edit' ),
			'sale_price'       => (string) $variation->get_sale_price( 'edit' ),

			'stock_status'     => (string) $variation->get_stock_status(),
			'stock_quantity'   => $variation->get_stock_quantity(),
			'low_stock_amount' => $this->to_nullable_int( $variation->get_low_stock_amount() ),
		];
	}

	/**
	 * 變體屬性 → `[{name, option}]`
	 *
	 * 形狀刻意與 WooCommerce REST 的 variation `attributes` 一致，
	 * 前端 `TShopVariationInfo` 因此不需要改型別。
	 *
	 * ⚠️ 值為空字串代表「任意（Any）」，**照實回傳空字串**，不要濾掉那一列——
	 * 站長需要看到「這個變體對這個屬性不設限」，濾掉會讓表格上少一欄、
	 * 看起來像資料壞了。
	 *
	 * @param \WC_Product_Variation $variation 變體物件
	 *
	 * @return array<int, array{name: string, option: string}>
	 */
	private function to_attribute_rows( \WC_Product_Variation $variation ): array {
		$rows = [];

		foreach ( $variation->get_attributes() as $taxonomy => $value ) {
			$rows[] = [
				'name'   => \wc_attribute_label( (string) $taxonomy, $variation ),
				'option' => (string) $value,
			];
		}

		return $rows;
	}

	/**
	 * 低庫存門檻正規化
	 *
	 * WooCommerce 對「沒設定」回空字串，而前端 `ProductStock` 期待 `number | null`
	 * ——空字串會被 `Number('')` 轉成 0，讓每個變體都顯示成「低庫存」。
	 *
	 * @param mixed $value 原始值
	 *
	 * @return int|null
	 */
	private function to_nullable_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}
}
