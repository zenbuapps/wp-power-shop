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
			[
				'colorPrimary',
				'showConfetti',
				'showStock',
				'showBuyerCount',
				'startTime',
				'endTime',
				'shopStatus',
				'beforeDisplay',
				'beforeText',
				'afterDisplay',
				'afterText',
				'showCountdown',
				'showDisplayText',
				'displayText',
				'showProducts',
				'countdownTarget',
			],
			\array_keys( $result ),
			'輸出的 key 集合必須穩定，前端型別才靠得住'
		);
	}

	/* ------------------------------------------------------------------ *
	 * 開啟 / 關閉時間的呈現方式
	 * ------------------------------------------------------------------ */

	/**
	 * smoke：沒設定呈現區塊時只顯示商品（＝功能上線前的既有行為）
	 *
	 * 這是本功能最重要的一條回歸鎖：既有賣場的 settings 裡沒有 beforeDisplay /
	 * afterDisplay 兩個 key，預設若不是「只顯示商品」，全站已排程的賣場會在
	 * 時間外突然變成空白頁。
	 *
	 * @group smoke
	 */
	public function test_display_parts_default_to_products_only(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop( [ 'startTime' => $this->ms_from_now( 3600 ) ] )
		);

		$this->assertSame( ProfitShopFrontData::DEFAULT_DISPLAY_PARTS, $result['beforeDisplay'] );
		$this->assertSame( ProfitShopFrontData::DEFAULT_DISPLAY_PARTS, $result['afterDisplay'] );
		$this->assertTrue( $result['showProducts'] );
		$this->assertFalse( $result['showCountdown'] );
		$this->assertFalse( $result['showDisplayText'] );
	}

	/**
	 * happy：三個區塊互相獨立，可任意組合
	 *
	 * @group happy
	 */
	public function test_display_parts_are_independent(): void {
		$start  = $this->ms_from_now( 3600 );
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'startTime'     => $start,
					'beforeDisplay' => [
						ProfitShopFrontData::PART_COUNTDOWN,
						ProfitShopFrontData::PART_PRODUCTS,
					],
					'beforeText'    => '8/10 中午 12:00 開賣',
				]
			)
		);

		$this->assertSame( 'coming', $result['shopStatus'] );
		$this->assertTrue( $result['showCountdown'], '勾了倒數就要顯示倒數' );
		$this->assertTrue( $result['showProducts'], '倒數與商品可以同時顯示' );
		$this->assertFalse( $result['showDisplayText'], '沒勾自訂文字就不顯示，即使文字有填' );
		$this->assertSame( '', $result['displayText'] );
		$this->assertSame( $start, $result['countdownTarget'], '尚未開賣時應倒數到開啟時間' );
	}

	/**
	 * happy：全部不勾＝什麼都不顯示（空陣列是合法值，不可被預設值蓋掉）
	 *
	 * 這條與上面的「缺 key 退回預設」是一體兩面：`empty([])` 為 true，若用它
	 * 判斷就會讓站長刻意取消的全部勾選被靜默還原成「顯示商品」。
	 *
	 * @group happy
	 * @group edge
	 */
	public function test_empty_display_parts_means_show_nothing(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'startTime'     => $this->ms_from_now( 3600 ),
					'beforeDisplay' => [],
					'beforeText'    => '不會顯示的文字',
				]
			)
		);

		$this->assertSame( [], $result['beforeDisplay'] );
		$this->assertFalse( $result['showCountdown'] );
		$this->assertFalse( $result['showDisplayText'] );
		$this->assertFalse( $result['showProducts'] );
	}

	/**
	 * happy：已結束時採用 afterDisplay / afterText，倒數目標歸零
	 *
	 * 已結束的賣場沒有「未來的目標時間」可倒數，盤面刻意全部歸零；
	 * 真正傳達訊息的是搭配的自訂文字。若日後要支援「下一檔期開賣倒數」，
	 * 必須新增獨立的設定欄位，而不是在這裡猜。
	 *
	 * @group happy
	 */
	public function test_closed_state_uses_after_settings_with_zero_countdown(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'startTime'     => $this->ms_from_now( -7200 ),
					'endTime'       => $this->ms_from_now( -3600 ),
					'beforeDisplay' => [ ProfitShopFrontData::PART_COUNTDOWN ],
					'beforeText'    => '即將開賣',
					'afterDisplay'  => [ ProfitShopFrontData::PART_TEXT ],
					'afterText'     => '活動已結束',
				]
			)
		);

		$this->assertSame( 'closed', $result['shopStatus'] );
		$this->assertFalse( $result['showCountdown'], '已結束時應採用 afterDisplay，不是 beforeDisplay' );
		$this->assertTrue( $result['showDisplayText'] );
		$this->assertFalse( $result['showProducts'] );
		$this->assertSame( '活動已結束', $result['displayText'] );
		$this->assertSame( 0, $result['countdownTarget'] );
	}

	/**
	 * edge：勾了「顯示自訂文字」但文字是空的 → 不顯示（避免印出空的 <p>）
	 *
	 * @group edge
	 */
	public function test_text_part_without_content_is_not_shown(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'startTime'     => $this->ms_from_now( 3600 ),
					'beforeDisplay' => [ ProfitShopFrontData::PART_TEXT ],
					'beforeText'    => '   ',
				]
			)
		);

		$this->assertFalse( $result['showDisplayText'] );
		$this->assertSame( '', $result['displayText'] );
	}

	/**
	 * happy：販售中一律只顯示商品，且不帶任何排程文字
	 *
	 * beforeDisplay / afterDisplay 只描述「時間區間之外」，不該影響開賣期間。
	 *
	 * @group happy
	 */
	public function test_published_state_always_shows_products_only(): void {
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'beforeDisplay' => [],
					'afterDisplay'  => [ ProfitShopFrontData::PART_COUNTDOWN ],
					'beforeText'    => '即將開賣',
					'afterText'     => '活動已結束',
				]
			)
		);

		$this->assertSame( 'published', $result['shopStatus'] );
		$this->assertTrue( $result['showProducts'], '販售中不受排程呈現設定影響' );
		$this->assertFalse( $result['showCountdown'] );
		$this->assertFalse( $result['showDisplayText'] );
		$this->assertSame( '', $result['displayText'] );
		$this->assertSame( 0, $result['countdownTarget'] );
	}

	/**
	 * security：未知 / 惡意的呈現區塊值一律被白名單濾掉
	 *
	 * 這個值決定「商品要不要印出來」，是實質的可見性開關。
	 * 非陣列（缺 key / 壞型別）退回預設；陣列內的未知字串直接丟棄。
	 *
	 * @group security
	 */
	public function test_unknown_display_parts_are_filtered(): void {
		// 非陣列 → 退回預設（只顯示商品）
		foreach ( [ null, 0, true, 1.5 ] as $value ) {
			$result = ProfitShopFrontData::settings(
				$this->build_shop(
					[
						'startTime'     => $this->ms_from_now( 3600 ),
						'beforeDisplay' => $value,
					]
				)
			);

			$this->assertSame(
				ProfitShopFrontData::DEFAULT_DISPLAY_PARTS,
				$result['beforeDisplay'],
				\sprintf( '非陣列值 %s 必須退回預設', \var_export( $value, true ) )
			);
		}

		// 陣列內的未知值 → 丟棄，只留白名單內的
		$mixed = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'startTime'     => $this->ms_from_now( 3600 ),
					'beforeDisplay' => [ 'PRODUCTS', 'hidden', '<script>', ProfitShopFrontData::PART_COUNTDOWN, 1 ],
				]
			)
		);

		$this->assertSame( [ ProfitShopFrontData::PART_COUNTDOWN ], $mixed['beforeDisplay'] );
		$this->assertFalse( $mixed['showProducts'], "'PRODUCTS' 大小寫不符，不可被當成 products" );
	}

	/**
	 * edge：舊版單選字串格式（開發過渡期資料）要能對映成等價的區塊組合
	 *
	 * 本設定最初是單選 `hidden` / `countdown` / `browse`；直接丟掉會讓站長
	 * 已經設好的倒數計時安靜消失。
	 *
	 * @group edge
	 */
	public function test_legacy_string_display_mode_is_migrated(): void {
		$cases = [
			'hidden'    => [],
			'countdown' => [ ProfitShopFrontData::PART_COUNTDOWN, ProfitShopFrontData::PART_TEXT ],
			'browse'    => ProfitShopFrontData::DEFAULT_DISPLAY_PARTS,
			'unknown'   => ProfitShopFrontData::DEFAULT_DISPLAY_PARTS,
		];

		foreach ( $cases as $legacy => $expected ) {
			$result = ProfitShopFrontData::settings(
				$this->build_shop(
					[
						'startTime'     => $this->ms_from_now( 3600 ),
						'beforeDisplay' => $legacy,
					]
				)
			);

			$this->assertSame(
				$expected,
				$result['beforeDisplay'],
				\sprintf( "舊格式 '%s' 未正確對映", $legacy )
			);
		}
	}

	/**
	 * edge：自訂文字非字串 → 空字串；超長 → 截斷；前後空白 → trim
	 *
	 * @group edge
	 */
	public function test_display_text_is_normalised(): void {
		$long   = \str_repeat( '長', ProfitShopFrontData::TEXT_MAX_LENGTH + 50 );
		$result = ProfitShopFrontData::settings(
			$this->build_shop(
				[
					'startTime'  => $this->ms_from_now( 3600 ),
					'beforeText' => '  即將開賣  ',
					'afterText'  => $long,
				]
			)
		);

		$this->assertSame( '即將開賣', $result['beforeText'], '前後空白應被 trim' );
		$this->assertSame(
			ProfitShopFrontData::TEXT_MAX_LENGTH,
			\mb_strlen( $result['afterText'], 'UTF-8' ),
			'超長文字應以 codepoint 為單位截斷，不可把中文切成半個字'
		);

		foreach ( [ null, [], true, new \stdClass() ] as $value ) {
			$normalised = ProfitShopFrontData::settings(
				$this->build_shop( [ 'beforeText' => $value ] )
			);
			$this->assertSame( '', $normalised['beforeText'] );
		}
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
