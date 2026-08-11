<?php
/**
 * 佈景主題範本清單端點整合測試（`GET /power-shop/theme-templates`）
 *
 * 對應規格：specs/features/profit-shop/render-shop-front.feature
 *          Rule: 前置（版型）- 獨立頁面可選擇佈景主題範本
 *
 * 對應實作：
 *   inc/classes/.../Rest/V2Api.php::get_theme_templates_callback()
 *   inc/classes/.../WordPress/ProfitShopRenderer.php::resolve_theme_template()
 *
 * 🔴 這份清單同時是**白名單的唯一來源**：`resolve_theme_template()` 只接受
 *    出現在 `wp_get_theme()->get_page_templates()` 裡的檔名，
 *    因此站長（或竄改過的請求）指不到任意 PHP 檔。
 *
 * ⚠️ 測試環境的佈景主題可能一個範本都沒登記，因此**不斷言清單非空**——
 *    那會變成「測佈景主題」而不是測這支端點。真正要鎖的是回應形狀與權限。
 *
 * @group profit_shop
 * @group rest
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use Tests\Integration\TestCase;

/**
 * 範本清單端點整合測試
 */
final class ThemeTemplatesEndpointTest extends TestCase {

	/**
	 * 測試用的假範本檔名
	 */
	private const FAKE_TEMPLATE = 'templates/ps-fake-template.php';

	/**
	 * 用 `theme_page_templates` filter 注入一個範本
	 *
	 * 🔴 這不是可有可無的裝飾。測試環境的佈景主題**一個範本都沒登記**，
	 * 不注入的話下面那些 `foreach ( $items )` 會跑零次 ⇒ 迴圈內的斷言
	 * 一次都不會執行 ⇒ 測試「通過」但什麼都沒驗到
	 * （PHPUnit 只會以 risky「did not perform any assertions」提醒一次，
	 * 而那行訊息很容易被當成雜訊略過）。
	 *
	 * @var \Closure|null
	 */
	private $filter = null;

	/**
	 * setUp
	 */
	public function set_up(): void {
		parent::set_up();

		$this->filter = static function ( $templates ) {
			$templates[ self::FAKE_TEMPLATE ] = '測試用範本';
			return $templates;
		};

		\add_filter( 'theme_page_templates', $this->filter );
	}

	/**
	 * tearDown
	 */
	public function tear_down(): void {
		if ( null !== $this->filter ) {
			\remove_filter( 'theme_page_templates', $this->filter );
			$this->filter = null;
		}

		parent::tear_down();
	}

	/**
	 * 回應形狀必須是 `{items, total}`，且 items 每筆有 value / label
	 *
	 * @test
	 * @group happy
	 */
	public function test_returns_items_and_total(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/power-shop/theme-templates' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = (array) ( ( (array) $response->get_data() )['data'] ?? [] );

		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertIsArray( $data['items'] );
		$this->assertSame( count( (array) $data['items'] ), $data['total'], 'total 必須等於實際筆數' );

		$this->assertNotSame(
			[],
			(array) $data['items'],
			'清單為空時下面的逐筆斷言會空轉——set_up() 注入的假範本必須出現在這裡'
		);

		foreach ( (array) $data['items'] as $item ) {
			$this->assertArrayHasKey( 'value', (array) $item, '缺 value ⇒ 下拉選了也送不出檔名' );
			$this->assertArrayHasKey( 'label', (array) $item, '缺 label ⇒ 下拉會是一排空白選項' );
		}
	}

	/**
	 * 🔴 未登入者不得讀取
	 *
	 * 範本檔名會洩漏佈景主題的檔案結構，屬於後台資訊。
	 *
	 * @test
	 * @group security
	 */
	public function test_guest_is_rejected(): void {
		\wp_set_current_user( 0 );

		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/power-shop/theme-templates' ) );

		$this->assertNotSame( 200, $response->get_status() );
	}

	/**
	 * 回傳的每個 value 都必須真的是佈景主題登記過的範本
	 *
	 * 這條把「端點回什麼」與「renderer 接受什麼」綁在一起：
	 * 兩者用同一個來源（`wp_get_theme()->get_page_templates()`），
	 * 端點若哪天改成回傳別的東西，站長就會選到一個 renderer 拒絕的值——
	 * 畫面上看起來像「設定沒生效」。
	 *
	 * @test
	 * @group security
	 */
	public function test_every_value_is_a_registered_theme_template(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$theme   = \wp_get_theme();
		$allowed = $theme->get_page_templates( null, 'page' ) + $theme->get_page_templates( null, 'post' );

		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/power-shop/theme-templates' ) );
		$data     = (array) ( ( (array) $response->get_data() )['data'] ?? [] );

		$this->assertNotSame( [], (array) $data['items'], '清單為空時本條會空轉' );

		foreach ( (array) $data['items'] as $item ) {
			$this->assertArrayHasKey(
				(string) $item['value'],
				$allowed,
				'端點回了一個佈景主題沒有登記的範本 ⇒ renderer 會拒絕它，站長會以為設定沒生效'
			);
		}
	}
}
