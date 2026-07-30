<?php
/**
 * ProfitShopFrontData::settings() 正規化契約測試
 *
 * 測試對象：
 *   inc/classes/Domains/ProfitShop/Infrastructure/WordPress/ProfitShopFrontData.php
 *
 * 為什麼這支測試值得存在（TD-22）：
 * `settings()` 的輸入是 `ProfitShop::$settings`——一個從 post meta JSON 反序列化、
 * 又可能經 REST 往返過的自由 array。REST 會把 `true` 變 `'1'`、`false` 變 `''`、
 * 整數變字串，所以「型別正規化」不是防禦性冗餘，而是這個方法的**主要職責**：
 *
 *   - 弄錯 bool → 站長關掉的「已有 N 人購買」會繼續顯示（或反之）
 *   - 弄錯 int  → 開賣時間判斷失效，未開賣的賣場直接開賣
 *   - 弄錯 color → `btnColor` 直接進 inline `<style>`，是 CSS injection 面
 *
 * `settings()` 刻意不呼叫任何 WordPress 函式（只用 `time()`），因此能放在
 * Unit testsuite 純 PHP 執行；`build_products()` 需要 WooCommerce，屬 IT 範圍。
 *
 * ⚠ 依 `.claude/rules/wordpress.rule.md` §11.5：每個 test method 都必須各自標
 * `smoke|happy|error|edge|security` 其中之一，class-level group 不會被 include。
 *
 * @group profit_shop
 * @group infrastructure
 * @group front_data
 */

declare( strict_types=1 );

namespace Tests\Unit\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontData;
use PHPUnit\Framework\TestCase;

/**
 * 前台顯示設定正規化測試
 */
final class ProfitShopFrontDataSettingsTest extends TestCase {

	/**
	 * smoke：settings 全空時回傳 legacy 的預設行為
	 *
	 * 這是絕大多數賣場的實際狀態（後台目前沒有設定 UI，見 TD-15），
	 * 因此「空設定 = legacy 預設」是最需要鎖死的一條。
	 *
	 * @group smoke
	 */
	public function test_empty_settings_falls_back_to_legacy_defaults(): void {
		$result = ProfitShopFrontData::settings( $this->build_shop( [] ) );

		$this->assertSame( ProfitShopFrontData::DEFAULT_COLOR_PRIMARY, $result['colorPrimary'] );
		$this->assertTrue( $result['showConfetti'] );
		$this->assertTrue( $result['showStock'] );
		$this->assertTrue( $result['showBuyerCount'] );
		$this->assertSame( 0, $result['startTime'] );
		$this->assertSame( 0, $result['endTime'] );
		$this->assertSame( 'published', $result['shopStatus'] );
	}

	/**
	 * happy：布林旗標可被關閉
	 *
	 * @group happy
	 */
	public function test_boolean_flags_can_be_turned_off(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'showConfetti'   => false,
					'showStock'      => false,
					'showBuyerCount' => false,
				]
			)
		);

		$this->assertFalse( $result['showConfetti'] );
		$this->assertFalse( $result['showStock'] );
		$this->assertFalse( $result['showBuyerCount'] );
	}

	/**
	 * edge：REST 往返後的 bool 字串（`'1'` / `''` / `'0'`）要被正確解讀
	 *
	 * WordPress REST + post meta 會把 `true` 序列化成 `'1'`、`false` 成 `''`；
	 * 若直接 `if ( $settings['showStock'] )` 判斷，`'0'` 這種手動寫入的值
	 * 在某些寫法下會被誤判為 true。
	 *
	 * @group edge
	 */
	public function test_rest_roundtripped_boolean_strings_are_normalised(): void {
		$on = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'showStock'      => '1',
					'showBuyerCount' => 1,
					'showConfetti'   => 'yes',
				]
			)
		);

		$this->assertTrue( $on['showStock'], "'1' 應視為開啟" );
		$this->assertTrue( $on['showBuyerCount'], '整數 1 應視為開啟' );
		$this->assertTrue( $on['showConfetti'], '非空字串應視為開啟' );

		$off = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'showStock'      => '',
					'showBuyerCount' => '0',
					'showConfetti'   => 0,
				]
			)
		);

		$this->assertFalse( $off['showStock'], "空字串應視為關閉" );
		$this->assertFalse( $off['showBuyerCount'], "'0' 應視為關閉" );
		$this->assertFalse( $off['showConfetti'], '整數 0 應視為關閉' );
	}

	/**
	 * security：btnColor 只接受 hex 白名單，阻擋 CSS injection
	 *
	 * 這個值會被直接印進 template 的 inline `<style>`（`--ps-primary`）。
	 * legacy 是 `echo $btn_color` 毫無驗證（`ShortCode.php:69`），
	 * 新版必須擋掉任何能跳出 property 值的輸入。
	 *
	 * @group security
	 */
	public function test_btn_color_rejects_non_hex_values(): void {
		$malicious = [
			'red; } body { display: none } .x {',
			'url(javascript:alert(1))',
			'#12345',
			'#gggggg',
			'1677ff',
			'',
			'expression(alert(1))',
		];

		foreach ( $malicious as $value ) {
			$result = ProfitShopFrontData::settings( $this->build_shop( [ 'btnColor' => $value ] ) );
			$this->assertSame(
				ProfitShopFrontData::DEFAULT_COLOR_PRIMARY,
				$result['colorPrimary'],
				\sprintf( '非法色值 %s 必須退回預設', \var_export( $value, true ) )
			);
		}
	}

	/**
	 * happy：合法 hex 色值（3 碼與 6 碼）被保留
	 *
	 * @group happy
	 */
	public function test_btn_color_accepts_valid_hex(): void {
		foreach ( [ '#fff', '#FFF', '#1677ff', '#1677FF', '#000000' ] as $value ) {
			$result = ProfitShopFrontData::settings( $this->build_shop( [ 'btnColor' => $value ] ) );
			$this->assertSame( $value, $result['colorPrimary'] );
		}
	}

	/**
	 * happy：起訖時間都未設定時視為永久開賣
	 *
	 * @group happy
	 */
	public function test_shop_status_is_published_without_schedule(): void {
		$result = ProfitShopFrontData::settings( $this->build_shop( [] ) );

		$this->assertSame( 'published', $result['shopStatus'] );
	}

	/**
	 * happy：開賣時間未到 → coming
	 *
	 * @group happy
	 */
	public function test_shop_status_is_coming_before_start_time(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop( [ 'startTime' => $this->ms_from_now( 3600 ) ] )
		);

		$this->assertSame( 'coming', $result['shopStatus'] );
	}

	/**
	 * happy：結束時間已過 → closed
	 *
	 * @group happy
	 */
	public function test_shop_status_is_closed_after_end_time(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop( [ 'endTime' => $this->ms_from_now( -3600 ) ] )
		);

		$this->assertSame( 'closed', $result['shopStatus'] );
	}

	/**
	 * happy：落在起訖區間內 → published
	 *
	 * @group happy
	 */
	public function test_shop_status_is_published_within_window(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'startTime' => $this->ms_from_now( -3600 ),
					'endTime'   => $this->ms_from_now( 3600 ),
				]
			)
		);

		$this->assertSame( 'published', $result['shopStatus'] );
	}

	/**
	 * edge：時間戳為 REST 往返後的字串仍可正確判斷
	 *
	 * 毫秒時間戳超過 32-bit 整數上限，經 JSON / post meta 往返後常是字串型別。
	 *
	 * @group edge
	 */
	public function test_timestamp_strings_are_cast_to_int(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop( [ 'endTime' => (string) $this->ms_from_now( -60 ) ] )
		);

		$this->assertIsInt( $result['endTime'] );
		$this->assertSame( 'closed', $result['shopStatus'] );
	}

	/**
	 * error：時間戳為非數字 / 負數時視為未設定，不可讓賣場意外關閉
	 *
	 * 若把垃圾值當成「已過期的結束時間」，整個賣場會直接變成「商店已關閉」，
	 * 是最嚴重的誤判方向，因此明確鎖住「壞資料 → 當作沒設定」。
	 *
	 * @group error
	 */
	public function test_invalid_timestamps_are_treated_as_unset(): void {
		foreach ( [ 'not-a-number', '', null, [], -1, '-99999' ] as $value ) {
			$result = ProfitShopFrontData::settings( $this->build_shop( [ 'endTime' => $value ] ) );

			$this->assertSame( 0, $result['endTime'], '壞資料應正規化為 0（未設定）' );
			$this->assertSame(
				'published',
				$result['shopStatus'],
				\sprintf( '壞的 endTime %s 不可讓賣場關閉', \var_export( $value, true ) )
			);
		}
	}

	/**
	 * edge：未知的額外 settings key 不影響輸出形狀
	 *
	 * `settings` 是自由 array，未來後台可能塞入本方法不認識的 key
	 * （或 legacy 匯入帶進 `enableVirtualList` 這類已廢棄設定）。
	 *
	 * @group edge
	 */
	public function test_unknown_setting_keys_are_ignored(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'enableVirtualList' => true,
					'someFutureFlag'    => 'whatever',
				]
			)
		);

		$this->assertSame(
			[ 'colorPrimary', 'showConfetti', 'showStock', 'showBuyerCount', 'startTime', 'endTime', 'shopStatus' ],
			\array_keys( $result ),
			'輸出的 key 集合必須穩定，前端型別才靠得住'
		);
	}

	/**
	 * 建立測試用的賣場聚合根
	 *
	 * @param array<string, mixed> $settings 要注入的 settings。
	 *
	 * @return ProfitShop
	 */
	private function build_shop( array $settings ): ProfitShop {
		return new ProfitShop(
			id: 123,
			title: '測試賣場',
			slug: 'test-shop',
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: 7,
			rate: new ProfitRate( 20 ),
			items: [],
			settings: $settings
		);
	}

	/**
	 * 取得相對於現在的毫秒時間戳
	 *
	 * @param int $offset_seconds 正數為未來、負數為過去。
	 *
	 * @return int
	 */
	private function ms_from_now( int $offset_seconds ): int {
		return ( \time() + $offset_seconds ) * 1000;
	}
}
