<?php
/**
 * LegacyShopTranslator 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7（POST profit-migration/import）
 *
 * 這個轉換器是「遷入舊版」能不能真的把商品搬過去的關鍵。它上線前，
 * ImportLegacyShop 的 items 是寫死的空陣列——匯入後的賣場前台顯示「此賣場暫無商品」，
 * 而且全程沒有任何錯誤訊息。
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group migration
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Migration;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\Support\LegacyShopTranslator;
use PHPUnit\Framework\TestCase;

/**
 * LegacyShopTranslator 測試
 */
final class LegacyShopTranslatorTest extends TestCase {

	/**
	 * 產生一份 legacy flat post_meta
	 *
	 * @param mixed $items    power_shop_meta 的值
	 * @param mixed $settings power_shop_settings 的值
	 *
	 * @return array<string, mixed>
	 */
	private function legacy_meta( mixed $items, mixed $settings = null ): array {
		$meta = [
			'power_shop_meta'            => is_string( $items ) ? $items : json_encode( $items ),
			'power_shop_report_password' => 'MWMwMDllMjYwMg==',
			'_edit_lock'                 => '1785405506:1',
			'_edit_last'                 => '1',
		];
		if ( null !== $settings ) {
			$meta['power_shop_settings'] = is_string( $settings ) ? $settings : json_encode( $settings );
		}
		return $meta;
	}

	/**
	 * happy：simple 商品逐欄對映到新版巢狀 items 契約
	 *
	 * @group happy
	 */
	public function test_translates_simple_products_to_nested_items(): void {
		$items = LegacyShopTranslator::to_items(
			$this->legacy_meta(
				[
					[
						'productId'       => 83,
						'productType'     => 'simple',
						'regularPrice'    => 20,
						'salesPrice'      => 18,
						'extraBuyerCount' => 7,
					],
				]
			)
		);

		$this->assertCount( 1, $items );
		$this->assertSame( 83, $items[0]['product_id'] );
		$this->assertSame( 7, $items[0]['inflated_count'] );
		$this->assertSame( '20', $items[0]['override']['regular_price'] );
		$this->assertSame( '18', $items[0]['override']['sale_price'] );
		$this->assertNull(
			$items[0]['override']['signup_fee'],
			'legacy 沒有訂閱註冊費概念，一律不覆寫'
		);
		$this->assertSame( [], $items[0]['variations'] );
	}

	/**
	 * 🔴 happy：salesPrice = 0 代表「沒有特價」，不是「賣 0 元」
	 *
	 * 這是本轉換器最容易寫錯、且寫錯會直接造成金錢損失的一條規則，
	 * 而且 PriceOverride 攔不到（0 是合法非負數，`0 <= 90` 也成立）。
	 *
	 * legacy 兩端都用 falsy 判斷（PHP `!empty($sales_price)`、React `!!salesPrice`），
	 * 因此 salesPrice=0 時實收的是 regularPrice。
	 *
	 * @group happy
	 */
	public function test_zero_sales_price_means_no_sale_not_free(): void {
		$items = LegacyShopTranslator::to_items(
			$this->legacy_meta(
				[
					[
						'productId'    => 69,
						'productType'  => 'simple',
						'regularPrice' => 90,
						'salesPrice'   => 0,
					],
				]
			)
		);

		$this->assertSame( '90', $items[0]['override']['regular_price'] );
		$this->assertNull(
			$items[0]['override']['sale_price'],
			'salesPrice=0 必須轉成 null（不覆寫特價）；轉成 "0" 會讓商品變成 0 元'
		);
	}

	/**
	 * edge：regularPrice = 0 同樣視為「不覆寫」
	 *
	 * @group edge
	 */
	public function test_zero_regular_price_is_treated_as_no_override(): void {
		$items = LegacyShopTranslator::to_items(
			$this->legacy_meta(
				[
					[
						'productId'    => 70,
						'regularPrice' => 0,
						'salesPrice'   => 0,
					],
				]
			)
		);

		$this->assertNull( $items[0]['override']['regular_price'] );
		$this->assertNull( $items[0]['override']['sale_price'] );
	}

	/**
	 * happy：variable 商品的 variations **陣列** → 以 variation_id 為鍵的 **map**
	 *
	 * 兩邊資料形狀不同：legacy 是 list（每項帶 variationId），新版是以 id 為鍵的 map。
	 *
	 * @group happy
	 */
	public function test_translates_variable_variations_array_into_keyed_map(): void {
		$items = LegacyShopTranslator::to_items(
			$this->legacy_meta(
				[
					[
						'productId'   => 729,
						'productType' => 'variable',
						'variations'  => [
							[
								'variationId'  => 730,
								'regularPrice' => 100,
								'salesPrice'   => 80,
							],
							[
								'variationId'  => 731,
								'regularPrice' => 120,
								'salesPrice'   => 0,
							],
						],
					],
				]
			)
		);

		$variations = $items[0]['variations'];
		$this->assertSame( [ 730, 731 ], array_keys( $variations ) );
		$this->assertSame( '100', $variations[730]['override']['regular_price'] );
		$this->assertSame( '80', $variations[730]['override']['sale_price'] );
		$this->assertSame( '120', $variations[731]['override']['regular_price'] );
		$this->assertNull(
			$variations[731]['override']['sale_price'],
			'變體的 salesPrice=0 同樣代表沒有特價'
		);
		$this->assertSame( 0, $variations[730]['inflated_count'] );
	}

	/**
	 * edge：小數價格與數字字串都要能轉，且一律去掉多餘的尾隨 0
	 *
	 * 尾隨 0 被去掉是**刻意**的：`'88.00'` 與 `'88'` 在定價上完全等價
	 * （`PriceOverride` 以 bccomp 比較、WC 顯示時自行格式化），統一格式可避免
	 * 同一個價格因為來源是 JSON number 還是字串而在 DB 內長得不一樣。
	 *
	 * @group edge
	 */
	public function test_handles_float_and_numeric_string_prices(): void {
		$items = LegacyShopTranslator::to_items(
			$this->legacy_meta(
				[
					[
						'productId'    => 1,
						'regularPrice' => 99.5,
						'salesPrice'   => '88.00',
					],
				]
			)
		);

		$this->assertSame( '99.5', $items[0]['override']['regular_price'] );
		$this->assertSame( '88', $items[0]['override']['sale_price'] );
	}

	/**
	 * edge：負數的 extraBuyerCount 正規化為 0
	 *
	 * @group edge
	 */
	public function test_negative_extra_buyer_count_is_normalized_to_zero(): void {
		$items = LegacyShopTranslator::to_items(
			$this->legacy_meta( [ [ 'productId' => 1, 'extraBuyerCount' => -5 ] ] )
		);

		$this->assertSame( 0, $items[0]['inflated_count'] );
	}

	/**
	 * edge：沒有 productId / 非陣列 / 無效 JSON 一律略過，不炸掉整筆匯入
	 *
	 * @group edge
	 */
	public function test_skips_malformed_rows_and_invalid_json(): void {
		$items = LegacyShopTranslator::to_items(
			$this->legacy_meta(
				[
					[ 'productType' => 'simple' ],
					'not-an-array',
					[ 'productId' => 0 ],
					[ 'productId' => 55 ],
				]
			)
		);

		$this->assertCount( 1, $items );
		$this->assertSame( 55, $items[0]['product_id'] );

		$this->assertSame( [], LegacyShopTranslator::to_items( $this->legacy_meta( '{ 壞掉的 json' ) ) );
		$this->assertSame( [], LegacyShopTranslator::to_items( [] ) );
	}

	/**
	 * 🔴 security：settings 只搬白名單欄位，舊版報表密碼與 WP 內部欄位一律丟棄
	 *
	 * 早期實作把整包 flat post_meta 塞進新賣場的 settings，於是
	 * `power_shop_report_password`（舊版報表密碼）與 `_edit_lock` 都被複製過去，
	 * 並經 `GET /profit-shops/{id}` 回傳。它們在新版沒有任何消費者。
	 *
	 * @group security
	 */
	public function test_settings_allowlist_drops_report_password_and_wp_internals(): void {
		$settings = LegacyShopTranslator::to_settings(
			$this->legacy_meta(
				[],
				[
					'btnColor'          => '#ff0000',
					'showStock'         => true,
					'showBuyerCount'    => false,
					'showConfetti'      => true,
					'startTime'         => 1700000000000,
					'endTime'           => 1800000000000,
					'enableVirtualList' => true,
				]
			)
		);

		$this->assertSame( '#ff0000', $settings['btnColor'] );
		$this->assertTrue( $settings['showStock'] );
		$this->assertFalse( $settings['showBuyerCount'] );
		$this->assertSame( 1700000000000, $settings['startTime'] );

		$this->assertArrayNotHasKey( 'power_shop_report_password', $settings );
		$this->assertArrayNotHasKey( '_edit_lock', $settings );
		$this->assertArrayNotHasKey( '_edit_last', $settings );
		$this->assertArrayNotHasKey(
			'enableVirtualList',
			$settings,
			'白名單以外的 legacy 設定（新版無消費者）不搬'
		);
	}

	/**
	 * edge：legacy 從未設定過 power_shop_settings → 回空陣列（而非整包 raw meta）
	 *
	 * @group edge
	 */
	public function test_settings_is_empty_when_legacy_never_configured_it(): void {
		$this->assertSame( [], LegacyShopTranslator::to_settings( $this->legacy_meta( [] ) ) );
	}

	/**
	 * happy：slug 推導——有 slug 用原值，沒有才回退 legacy-import-{id}
	 *
	 * @group happy
	 */
	public function test_resolve_slug_falls_back_when_empty(): void {
		$this->assertSame(
			'old-shop',
			LegacyShopTranslator::resolve_slug( [ 'id' => 2227, 'slug' => 'old-shop' ] )
		);
		$this->assertSame(
			'legacy-import-2227',
			LegacyShopTranslator::resolve_slug( [ 'id' => 2227, 'slug' => '' ] )
		);
		$this->assertSame(
			'legacy-import-2227',
			LegacyShopTranslator::resolve_slug( [ 'id' => 2227 ] )
		);
	}

	/**
	 * happy：count_items 與 to_items 的筆數一致
	 *
	 * @group happy
	 */
	public function test_count_items_matches_translated_items(): void {
		$meta = $this->legacy_meta(
			[
				[ 'productId' => 1 ],
				[ 'productId' => 2 ],
				[ 'productId' => 0 ],
			]
		);

		$this->assertSame( 2, LegacyShopTranslator::count_items( $meta ) );
		$this->assertCount( LegacyShopTranslator::count_items( $meta ), LegacyShopTranslator::to_items( $meta ) );
	}
}
