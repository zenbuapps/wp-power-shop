<?php
/**
 * 佈景主題範本清單端點整合測試（`GET /power-shop/theme-templates`）
 *
 * 對應規格：specs/features/profit-shop/render-shop-front.feature
 *          Rule: 前置（版型）- 獨立頁面可選擇佈景主題範本
 *
 * 對應實作：
 *   inc/classes/.../WordPress/ThemeTemplateWhitelist.php  ← 白名單的唯一真相來源
 *   inc/classes/.../Rest/V2Api.php::get_theme_templates_callback()
 *   inc/classes/.../WordPress/ProfitShopRenderer.php::resolve_theme_template()
 *
 * 🔴 端點與 renderer 都委派 `ThemeTemplateWhitelist`，本測試**不再自行重算**一份白名單——
 *    舊版那樣寫等於拿端點的資料來源跟它自己比對，驗不到真正的缺口（見
 *    `test_every_value_is_resolvable_by_the_renderer` 的 docblock）。
 *
 * @group profit_shop
 * @group rest
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ThemeTemplateWhitelist;
use Tests\Integration\TestCase;

/**
 * 範本清單端點整合測試
 */
final class ThemeTemplatesEndpointTest extends TestCase {

	/**
	 * 有實體檔案的範本檔名（set_up 會真的把檔案建出來）
	 *
	 * 代表「佈景主題自己的 file-based 範本」，必須出現在清單裡。
	 */
	private const REAL_TEMPLATE = 'ps-real-template.php';

	/**
	 * 只存在於 filter、**沒有**實體檔案的範本檔名
	 *
	 * 這正是頁面建構器（Elementor 等）註冊的虛擬範本的形狀，必須被濾掉。
	 */
	private const VIRTUAL_TEMPLATE = 'templates/ps-virtual-template.php';

	/**
	 * 用 `theme_page_templates` filter 注入兩個測試範本
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
	 * REAL_TEMPLATE 的實體路徑（tear_down 要刪掉）
	 *
	 * @var string
	 */
	private string $real_template_path = '';

	/**
	 * setUp
	 */
	public function set_up(): void {
		parent::set_up();

		// 在佈景主題目錄真的放一個檔案，讓 locate_template() 找得到。
		// 不借用佈景主題既有的檔案：不同測試環境的佈景主題內容不一樣，
		// 自己建立才不會讓這份測試的前提隨環境漂移。
		$this->real_template_path = \trailingslashit( \get_stylesheet_directory() ) . self::REAL_TEMPLATE;
		\file_put_contents( $this->real_template_path, "<?php // power-shop test fixture\n" );

		$this->filter = static function ( $templates ) {
			$templates[ self::REAL_TEMPLATE ]    = '測試用範本（有實體檔案）';
			$templates[ self::VIRTUAL_TEMPLATE ] = '測試用虛擬範本（無實體檔案）';
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

		if ( '' !== $this->real_template_path && \file_exists( $this->real_template_path ) ) {
			\unlink( $this->real_template_path );
		}
		$this->real_template_path = '';

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
			'清單為空時下面的逐筆斷言會空轉——set_up() 注入的測試範本必須出現在這裡'
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
	 * 🔴 端點回的每個 value，renderer 都必須解析得出實體路徑
	 *
	 * 這條才是「端點回什麼」與「renderer 載得動什麼」的綁定。
	 *
	 * ⚠️ 舊版這條是拿 `wp_get_theme()->get_page_templates()` 自行重算一份白名單來比對，
	 * 那**驗不到真正的缺口**——那正是端點當時用的同一個來源，等於拿它跟自己比。
	 * 實際的 P0 缺口在下游：白名單用 `get_page_templates()`（會套 `theme_page_templates`
	 * filter ⇒ 收得到外掛註冊的虛擬範本），載入卻用 `locate_template()`（只找實體檔案），
	 * 兩者定義域不同。實測（turbo.local，2026-08-11，blocksy + Elementor）下拉列出的
	 * `elementor_canvas` / `elementor_header_footer` / `elementor_theme` 三個選項，
	 * `locate_template()` **全部**回空字串 ⇒ 站長選了任何一個都靜默 fallback 回內建版型、
	 * 畫面與沒選一模一樣、零錯誤訊息。
	 *
	 * 所以斷言必須打在「解析得出路徑」上，而不是「在某份清單裡」。
	 * `ThemeTemplateWhitelist::resolve()` 正是 renderer 用的那一支。
	 *
	 * @test
	 * @group security
	 */
	public function test_every_value_is_resolvable_by_the_renderer(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/power-shop/theme-templates' ) );
		$data     = (array) ( ( (array) $response->get_data() )['data'] ?? [] );

		$this->assertNotSame( [], (array) $data['items'], '清單為空時本條會空轉' );

		foreach ( (array) $data['items'] as $item ) {
			$slug = (string) ( (array) $item )['value'];

			$this->assertNotSame(
				'',
				ThemeTemplateWhitelist::resolve( $slug ),
				sprintf(
					'端點列出了 renderer 載不動的範本「%s」⇒ 站長選了會靜默 fallback，看起來像設定沒生效',
					$slug
				)
			);
		}
	}

	/**
	 * 🔴 有實體檔案的範本必須留下、無實體檔案的虛擬範本必須被濾掉
	 *
	 * 兩個方向要一起驗：只驗「濾掉虛擬的」，把過濾寫成「一律回空陣列」也會通過。
	 * 少了這條，把 `locate_template()` 那道過濾拿掉的改動不會轉紅。
	 *
	 * @test
	 * @group security
	 */
	public function test_only_templates_with_a_real_file_are_listed(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// 前提自檢：兩個 fixture 的「檔案存不存在」必須真的相反，否則本條測不到東西。
		$this->assertNotSame(
			'',
			\locate_template( self::REAL_TEMPLATE ),
			'前提失效：set_up() 建的實體檔案沒有被 locate_template() 找到'
		);
		$this->assertSame(
			'',
			\locate_template( self::VIRTUAL_TEMPLATE ),
			'前提失效：這個虛擬範本竟然找得到實體檔案'
		);

		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/power-shop/theme-templates' ) );
		$data     = (array) ( ( (array) $response->get_data() )['data'] ?? [] );

		$values = array_map(
			static fn( $item ) => (string) ( (array) $item )['value'],
			(array) $data['items']
		);

		$this->assertContains(
			self::REAL_TEMPLATE,
			$values,
			'載得動的範本被濾掉了 ⇒ 站長根本選不到任何範本'
		);
		$this->assertNotContains(
			self::VIRTUAL_TEMPLATE,
			$values,
			'虛擬範本（無實體檔案）流進了下拉 ⇒ 站長選得到、renderer 卻載不動'
		);

		// renderer 那一側必須做出同樣的判斷——兩側同源才不會再分歧。
		$this->assertNotSame( '', ThemeTemplateWhitelist::resolve( self::REAL_TEMPLATE ) );
		$this->assertSame( '', ThemeTemplateWhitelist::resolve( self::VIRTUAL_TEMPLATE ) );
	}

	/**
	 * 未選 / 保留值一律回空字串（走內建版型）
	 *
	 * 這是 renderer 的 fail-closed 守門：`settings.pageTemplate` 是站長可寫入的
	 * 自由欄位，沒登記過的檔名（含相對路徑）都不得解析成路徑。
	 *
	 * @test
	 * @group security
	 */
	public function test_resolve_rejects_unregistered_and_reserved_values(): void {
		$this->assertSame( '', ThemeTemplateWhitelist::resolve( '' ), '未選範本' );
		$this->assertSame( '', ThemeTemplateWhitelist::resolve( 'default' ), '保留值：使用內建版型' );
		$this->assertSame( '', ThemeTemplateWhitelist::resolve( 'not-registered.php' ), '未登記的檔名' );
		$this->assertSame(
			'',
			ThemeTemplateWhitelist::resolve( '../../../wp-config.php' ),
			'🔴 白名單必須擋在 locate_template() 之前，否則相對路徑會被解析出來'
		);
	}
}
