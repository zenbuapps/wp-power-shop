<?php
/**
 * `ProfitShopRenderer` 的「賣場佈景主題範本」路徑整合測試
 *
 * 對應規格：specs/features/profit-shop/render-shop-front.feature
 *          Rule: 前置（版型）- 獨立頁面可選擇佈景主題範本
 *
 * 對應實作（三塊在本檔之前是零覆蓋）：
 *   ProfitShopRenderer::resolve_theme_template()   ← 站長可寫入欄位的 fail-closed 守門
 *   ProfitShopRenderer::render_via_theme_template() ← 附加 / get_the_ID() / static $done 三道守門
 *   ProfitShopRenderer::resolve_template_path()     ← `power_shop_profit_shop_template` filter 與其 fallback
 *
 * ═══ 為什麼走 Reflection 而不是端到端渲染（先讀這段再改本檔）═══
 *
 * 端到端（`maybe_render()`）在這支 renderer 上**結構性不可行**：它每一條會輸出畫面的
 * 路徑（404 / 429 / 正常渲染）結尾都是 `exit`，而 `exit` 無法被 PHPUnit 攔截——同一個
 * 理由已寫在 `PartnerPortalRendererTest` 的檔頭。往上一層的 `render_shop()` 雖然不 exit，
 * 但它會呼叫 `get_header()` / `get_footer()` 把整個佈景主題 chrome 拉進測試輸出，
 * 讓斷言的訊號被主題實作淹沒，且測試結果會隨測試環境的佈景主題漂移。
 *
 * 因此本檔對三個 private method 直接用 Reflection 呼叫。
 *
 * 涵蓋得到：
 *   - `settings.pageTemplate` → 範本實體路徑的完整解析（正向 + 反向，含相對路徑）
 *   - 佈景主題範本跑主查詢 loop 時，賣場內容是**附加**在 `the_content` 之後而非取代
 *   - 範本對「別篇 post」跑 `the_content` 時不得附加（`get_the_ID()` 守門）
 *   - 同一篇被跑兩次時只附加一次（`static $done` 守門）
 *   - 渲染結束後 `the_content` filter 必須被拆掉（不得污染後續請求）
 *   - `$ps_notices_html` / `$ps_skip_post_content` 兩個變數真的有傳進內建 template
 *   - `power_shop_profit_shop_template` filter 的覆寫與三種 fallback
 *
 * 涵蓋不到（誠實標註，不要誤以為這檔全包了）：
 *   - `maybe_render()` 的 slug / rate-limit / 404 分支（`exit`，語言層限制）
 *   - `render_shop()` 內「`capture_wc_notices()` 必須排在 `$theme_template` 分支之前」
 *     這個順序本身——它只在「佈景主題的 `get_header()` 會先吃掉通知」的真實環境下
 *     才會顯現，測試環境沒有那個佈景主題行為可以重現。本檔只能鎖住下半段：
 *     renderer 收下來的字串確實有交給 template 印（見 `test_captured_notices_...`）。
 *   - 「以頁面建構器排版的賣場實際長什麼樣」——需要真實站台，見 renderer docblock。
 *
 * 🔴 本檔**不修改任何 production 程式碼**，全部斷言都是對現況行為的鎖定。
 *
 * @group profit_shop
 * @group infrastructure
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopFrontData;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopRenderer;
use Tests\Integration\TestCase;

/**
 * 賣場佈景主題範本渲染整合測試
 */
final class ProfitShopRendererThemeTemplateTest extends TestCase {

	/**
	 * 測試用「佈景主題範本」檔名
	 *
	 * set_up() 會在佈景主題目錄真的建立這個檔案，並用 `theme_page_templates` filter
	 * 把它登記成可選範本——兩者缺一，`ThemeTemplateWhitelist` 都會把它濾掉
	 * （登記走 `get_page_templates()`、載入走 `locate_template()`，兩個定義域不同）。
	 */
	private const THEME_TEMPLATE = 'ps-renderer-theme-template-fixture.php';

	/**
	 * `power_shop_profit_shop_template` filter 覆寫測試用的檔案
	 *
	 * 只需要「存在」，不需要被登記成佈景主題範本——那條 filter 走的是
	 * `resolve_template_path()`，只判斷 `file_exists()`。
	 */
	private const OVERRIDE_TEMPLATE = 'ps-renderer-template-override-fixture.php';

	/**
	 * 賣場 post_content 內的識別字串
	 *
	 * 用來分辨「站長排的內容」與「附加上去的商品 grid」誰在前誰在後、各出現幾次。
	 */
	private const SHOP_CONTENT_MARKER = 'PS_SHOP_CONTENT_MARKER';

	/**
	 * 賣場容器的輸出特徵（每渲染一次內建 template 就出現一次）
	 *
	 * 刻意連 `class="` 一起比對：同一個字串在 template 內另有兩處**PHP 註解**提及，
	 * 只比對 class 名稱會把註解算進去——但註解不會進輸出，所以其實不影響；
	 * 帶上 `class="` 只是讓「數出現次數」這件事在肉眼上也毫無歧義。
	 */
	private const GRID_MARKER = 'class="ps-profit-shop-front';

	/**
	 * 遞增用的 slug 序號，避免同一次測試 run 內 slug 撞號
	 *
	 * @var int
	 */
	private static int $slug_seq = 0;

	/**
	 * `theme_page_templates` filter（tear_down 要拆掉）
	 *
	 * @var \Closure|null
	 */
	private $templates_filter = null;

	/**
	 * 已建立的 fixture 檔案路徑（tear_down 要刪掉）
	 *
	 * @var string[]
	 */
	private array $fixture_paths = [];

	/**
	 * 進入測試前的全域查詢狀態（tear_down 要還原）
	 *
	 * `setup_shop_post_context()` 會改寫 `$wp_query` 與 `$post`，而 `go_to()` 更是
	 * 直接把 `$wp_query` / `$wp_the_query` 換成新的物件。不還原會讓後續測試
	 * 在一個被竄改過的主查詢上執行。
	 *
	 * @var array{query: mixed, the_query: mixed, post: mixed}
	 */
	private array $globals_backup = [
		'query'     => null,
		'the_query' => null,
		'post'      => null,
	];

	/**
	 * setUp：建立 fixture 檔案 + 登記範本 + 備份全域查詢狀態
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->globals_backup = [
			'query'     => $GLOBALS['wp_query'] ?? null,
			'the_query' => $GLOBALS['wp_the_query'] ?? null,
			'post'      => $GLOBALS['post'] ?? null,
		];

		$theme_dir = \trailingslashit( \get_stylesheet_directory() );

		$theme_template_path = $theme_dir . self::THEME_TEMPLATE;
		\file_put_contents( $theme_template_path, $this->theme_template_source() );
		$this->fixture_paths[] = $theme_template_path;

		$override_path = $theme_dir . self::OVERRIDE_TEMPLATE;
		\file_put_contents( $override_path, "<?php // power-shop test fixture: template override\n" );
		$this->fixture_paths[] = $override_path;

		$this->templates_filter = static function ( $templates ) {
			$templates[ self::THEME_TEMPLATE ] = '測試用佈景主題範本';
			return $templates;
		};
		\add_filter( 'theme_page_templates', $this->templates_filter );

		\wp_set_current_user( 0 );
	}

	/**
	 * tearDown：拆 filter、刪 fixture、還原全域查詢狀態
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->templates_filter ) {
			\remove_filter( 'theme_page_templates', $this->templates_filter );
			$this->templates_filter = null;
		}

		foreach ( $this->fixture_paths as $path ) {
			if ( \file_exists( $path ) ) {
				\unlink( $path );
			}
		}
		$this->fixture_paths = [];

		unset( $GLOBALS['ps_fixture_mode'], $GLOBALS['ps_fixture_foreign_post_id'] );

		$GLOBALS['wp_query']     = $this->globals_backup['query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $this->globals_backup['the_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post']         = $this->globals_backup['post']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		\wp_set_current_user( 0 );

		parent::tear_down();
	}

	// ====================================================================
	//  resolve_theme_template()：站長可寫入欄位的 fail-closed 守門
	// ====================================================================

	/**
	 * 🔴 正向：白名單內的範本必須解析成實體路徑
	 *
	 * 這條與下面那批拒絕測試是一組的，**不可以只留拒絕那半邊**——守門若被寫成
	 * 「一律回空字串」，拒絕測試會全綠，而站長選了範本卻永遠靜默 fallback 回
	 * 內建版型、畫面與沒選一模一樣、零錯誤訊息（真實站台已經吃過一次這個虧，
	 * 見 `ThemeTemplateWhitelist` 的類別 docblock）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_resolve_theme_template_returns_real_path_for_whitelisted_slug(): void {
		$shop = $this->make_shop_entity( [ 'pageTemplate' => self::THEME_TEMPLATE ] );

		$resolved = $this->invoke_private( 'resolve_theme_template', $shop );

		$this->assertNotSame( '', $resolved, '白名單內的範本被拒絕 ⇒ 站長選了等於沒選' );
		$this->assertSame(
			\locate_template( self::THEME_TEMPLATE ),
			$resolved,
			'renderer 回的路徑必須就是 locate_template() 找到的那一個'
		);
		$this->assertFileExists( $resolved, '回了一個不存在的路徑 ⇒ require 會 fatal' );
	}

	/**
	 * 🔴 未登記的檔名一律回空字串
	 *
	 * `settings.pageTemplate` 是站長經 `PUT /profit-shops/{id}` 可自由寫入的欄位，
	 * 這裡是它與檔案系統之間唯一的守門。
	 *
	 * @test
	 * @group security
	 */
	public function test_resolve_theme_template_rejects_unregistered_slug(): void {
		$shop = $this->make_shop_entity( [ 'pageTemplate' => 'ps-never-registered.php' ] );

		$this->assertSame( '', $this->invoke_private( 'resolve_theme_template', $shop ) );
	}

	/**
	 * 🔴 相對路徑不得被解析出來
	 *
	 * `locate_template()` 內部是 `file_exists( $path . '/' . $name )` 的字串串接，
	 * `../` 會被它如實解析——白名單比對必須擋在它之前。
	 *
	 * @test
	 * @group security
	 */
	public function test_resolve_theme_template_rejects_path_traversal(): void {
		foreach ( [ '../../../wp-config.php', '../functions.php', './index.php' ] as $evil ) {
			$shop = $this->make_shop_entity( [ 'pageTemplate' => $evil ] );

			$this->assertSame(
				'',
				$this->invoke_private( 'resolve_theme_template', $shop ),
				\sprintf( '相對路徑「%s」不得解析成實體路徑', $evil )
			);
		}
	}

	/**
	 * 未設定 / 空字串 / 保留值 `default` 一律回空字串（走內建版型）
	 *
	 * 「沒有這個鍵」是絕大多數既有賣場的狀態——這條同時是「本功能上線不得改變
	 * 既有賣場行為」的回歸鎖。
	 *
	 * @test
	 * @group edge
	 */
	public function test_resolve_theme_template_returns_empty_for_absent_empty_and_reserved(): void {
		$cases = [
			'完全沒有 pageTemplate 鍵'   => [],
			'空字串（前端清空下拉）'      => [ 'pageTemplate' => '' ],
			'保留值 default（使用預設）' => [ 'pageTemplate' => 'default' ],
		];

		foreach ( $cases as $label => $settings ) {
			$shop = $this->make_shop_entity( $settings );

			$this->assertSame(
				'',
				$this->invoke_private( 'resolve_theme_template', $shop ),
				$label . ' 應走內建版型'
			);
		}
	}

	/**
	 * 非字串的設定值不得讓守門失效
	 *
	 * `settings` 是自由 array，經 REST 往返後型別不保證。整數 `0` 特別值得測：
	 * 它是 falsy，守門若寫成 `if ( ! $slug )` 之類的真值判斷會與 `''` 混為一談，
	 * 而 `0` 應該是「一個沒登記過的檔名」而不是「未設定」——兩者在本案剛好
	 * 都回空字串，但理由不同，寫死斷言可以擋住「把兩者合併」的改動改壞另一邊。
	 *
	 * ⚠️ 這裡刻意不測「值是陣列」的情況，見交付報告的 blockers。
	 *
	 * @test
	 * @group edge
	 */
	public function test_resolve_theme_template_handles_non_string_setting(): void {
		foreach ( [ 0, 123, true ] as $weird ) {
			$shop = $this->make_shop_entity( [ 'pageTemplate' => $weird ] );

			$this->assertSame(
				'',
				$this->invoke_private( 'resolve_theme_template', $shop ),
				\sprintf( '非字串設定值（%s）必須被守門擋下', \var_export( $weird, true ) )
			);
		}
	}

	// ====================================================================
	//  resolve_template_path()：`power_shop_profit_shop_template` filter
	// ====================================================================

	/**
	 * 沒有人掛 filter 時回內建 template
	 *
	 * @test
	 * @group smoke
	 */
	public function test_template_path_defaults_to_the_builtin_template(): void {
		$path = $this->invoke_private( 'resolve_template_path' );

		$this->assertFileExists( $path );
		$this->assertStringEndsWith( 'templates/profit-shop-front.php', \str_replace( '\\', '/', $path ) );
	}

	/**
	 * filter 回傳存在的檔案時必須被採用
	 *
	 * 正向這半邊不能少：fallback 若被寫成「一律回內建」，下面三條 fallback 測試
	 * 會全綠，而 filter 變成一個永遠不生效的擴充點。
	 *
	 * @test
	 * @group happy
	 */
	public function test_filter_can_override_the_template_path(): void {
		$override = \trailingslashit( \get_stylesheet_directory() ) . self::OVERRIDE_TEMPLATE;
		$this->assertFileExists( $override, '測試前置：覆寫用 fixture 應已建立' );

		$filter = static fn() => $override;
		\add_filter( 'power_shop_profit_shop_template', $filter );

		try {
			$this->assertSame( $override, $this->invoke_private( 'resolve_template_path' ) );
		} finally {
			\remove_filter( 'power_shop_profit_shop_template', $filter );
		}
	}

	/**
	 * 🔴 filter 回了不存在的路徑時必須退回內建 template
	 *
	 * 少了這道 fallback，一個手誤的路徑會讓 `require` 直接 fatal ⇒ 整個賣場頁白畫面。
	 *
	 * @test
	 * @group error
	 */
	public function test_filter_falls_back_when_the_path_does_not_exist(): void {
		$default = $this->invoke_private( 'resolve_template_path' );

		$filter = static fn() => '/definitely/not/here/ps-missing-template.php';
		\add_filter( 'power_shop_profit_shop_template', $filter );

		try {
			$this->assertSame(
				$default,
				$this->invoke_private( 'resolve_template_path' ),
				'不存在的覆寫路徑必須退回內建 template，不可讓 require 炸掉'
			);
		} finally {
			\remove_filter( 'power_shop_profit_shop_template', $filter );
		}
	}

	/**
	 * filter 回空字串（或非字串被轉成空字串）時同樣退回內建 template
	 *
	 * `''` 走的是 `'' !== $template` 那半個條件，與上一條的 `file_exists()`
	 * 是兩個不同的判斷，各測一條才不會被「只留一個條件」的改動繞過。
	 *
	 * @test
	 * @group edge
	 */
	public function test_filter_falls_back_when_returning_empty_string(): void {
		$default = $this->invoke_private( 'resolve_template_path' );

		$filter = static fn() => '';
		\add_filter( 'power_shop_profit_shop_template', $filter );

		try {
			$this->assertSame( $default, $this->invoke_private( 'resolve_template_path' ) );
		} finally {
			\remove_filter( 'power_shop_profit_shop_template', $filter );
		}
	}

	/**
	 * filter 回了一個「目錄」時退回內建 template
	 *
	 * `file_exists()` 對目錄回 true，`is_file()` 才不會。這條記錄的是**現況**：
	 * 目前守門用的是 `file_exists()`，所以目錄會通過並被 `require`。
	 *
	 * ⚠️ 因此本條刻意**不**斷言「目錄被擋下」——那是還沒發生的行為。
	 *    它斷言的是「傳目錄進去時 resolve 不會回內建路徑」，把現況釘住；
	 *    日後若把守門改成 `is_file()`，這條會紅燈，正好提醒要一起更新。
	 *
	 * @test
	 * @group edge
	 */
	public function test_filter_currently_accepts_a_directory_path(): void {
		$dir = \get_stylesheet_directory();
		$this->assertDirectoryExists( $dir, '測試前置：佈景主題目錄應存在' );

		$filter = static fn() => $dir;
		\add_filter( 'power_shop_profit_shop_template', $filter );

		try {
			$this->assertSame(
				$dir,
				$this->invoke_private( 'resolve_template_path' ),
				'現況：守門是 file_exists()，目錄會通過。改成 is_file() 時本條要一起改。'
			);
		} finally {
			\remove_filter( 'power_shop_profit_shop_template', $filter );
		}
	}

	// ====================================================================
	//  render_via_theme_template()：三道守門
	// ====================================================================

	/**
	 * 🔴 賣場內容必須**附加**在 `the_content` 之後，不可取代
	 *
	 * 站長選佈景主題範本，要的就是「用建構器排的版型包住賣場」。取代掉 `the_content`
	 * 等於把那份排版整個丟掉——而畫面上不會有任何錯誤，只是站長排的東西不見了。
	 *
	 * 四個斷言各鎖一件事，缺一都會讓某種壞法溜過去：
	 *   1. 範本自己的輸出還在  ⇒ `require $template` 真的發生了
	 *   2. post_content 還在   ⇒ 沒有被取代
	 *   3. 商品 grid 在        ⇒ 有附加
	 *   4. grid 在 content 之後 ⇒ 是「附加」而不是「插在前面」
	 *
	 * @test
	 * @group happy
	 */
	public function test_shop_grid_is_appended_after_post_content(): void {
		$shop = $this->create_shop_with_product();
		$html = $this->render_theme_template( $shop, 'loop' );

		$this->assertStringContainsString( 'ps-fixture-chrome', $html, '範本自己的輸出不見了 ⇒ require 沒發生' );
		$this->assertStringContainsString( self::SHOP_CONTENT_MARKER, $html, 'post_content 被取代掉了 ⇒ 站長的排版會消失' );
		$this->assertStringContainsString( self::GRID_MARKER, $html, '商品 grid 沒有被附加上去' );

		$content_at = \strpos( $html, self::SHOP_CONTENT_MARKER );
		$grid_at    = \strpos( $html, self::GRID_MARKER );

		$this->assertIsInt( $content_at );
		$this->assertIsInt( $grid_at );
		$this->assertGreaterThan( $content_at, $grid_at, '商品 grid 必須接在站長內容後面' );
	}

	/**
	 * 🔴 範本對同一篇跑兩次 `the_content()` 時，商品 grid 只能附加一次
	 *
	 * `static $done` 守門。範本把「摘要 + 內文」都跑一次是常見寫法，
	 * 少了這道守門會出現兩份一模一樣的商品 grid ⇒ 顧客看到重複的商品清單，
	 * 而每一份都是可以送出的加購表單。
	 *
	 * @test
	 * @group edge
	 */
	public function test_shop_grid_is_appended_only_once_when_the_content_runs_twice(): void {
		$shop = $this->create_shop_with_product();
		$html = $this->render_theme_template( $shop, 'double' );

		$this->assertSame(
			2,
			\substr_count( $html, self::SHOP_CONTENT_MARKER ),
			'測試前置失效：fixture 範本應該真的把 the_content() 跑了兩次'
		);
		$this->assertSame(
			1,
			\substr_count( $html, self::GRID_MARKER ),
			'商品 grid 被附加了不只一次 ⇒ static $done 守門失效'
		);
	}

	/**
	 * 🔴 範本對「別篇 post」跑 `the_content` 時不得附加商品 grid
	 *
	 * `get_the_ID() !== $shop_id` 守門。範本渲染側邊欄 / 相關文章時會對其他 post
	 * 跑 `the_content`，接錯地方會讓商品 grid 在整頁到處冒出來。
	 *
	 * 🔴 **fixture 刻意讓「別篇」排在自己的 loop 之前**，這個順序是本條的全部價值：
	 *    守門若被拿掉，第一次（別篇）就會把 `static $done` 吃掉 ⇒ 賣場自己的內容
	 *    反而**一個 grid 都沒有**。所以本條同時能抓到「附加到錯的地方」與
	 *    「因此賣場自己沒有」兩種後果；把順序對調就只剩前者測得到。
	 *
	 * @test
	 * @group security
	 */
	public function test_shop_grid_is_not_appended_to_a_foreign_post(): void {
		$shop       = $this->create_shop_with_product();
		$foreign_id = (int) self::factory()->post->create(
			[
				'post_title'   => '不相干的文章',
				'post_content' => 'PS_FOREIGN_POST_BODY',
				'post_status'  => 'publish',
			]
		);

		$html = $this->render_theme_template( $shop, 'foreign-then-loop', '', $foreign_id );

		$this->assertStringContainsString( 'PS_FOREIGN_CONTENT_MARKER', $html, '測試前置失效：fixture 沒有對別篇跑 the_content' );
		$this->assertSame(
			1,
			\substr_count( $html, self::GRID_MARKER ),
			'商品 grid 應該只出現一次（附加在賣場自己的內容之後）'
		);

		$foreign_end_at = \strpos( $html, 'PS_FOREIGN_BLOCK_END' );
		$grid_at        = \strpos( $html, self::GRID_MARKER );

		$this->assertIsInt( $foreign_end_at );
		$this->assertIsInt( $grid_at );
		$this->assertGreaterThan(
			$foreign_end_at,
			$grid_at,
			'商品 grid 落在「別篇 post」的內容裡了 ⇒ get_the_ID() 守門失效'
		);
	}

	/**
	 * 🔴 渲染結束後必須把 `the_content` filter 拆掉
	 *
	 * 留著的話這個請求後續任何一次 `the_content`（其他外掛、fragments、REST）
	 * 都還掛著一個持有賣場狀態的閉包。
	 *
	 * 斷言用「priority 20 上的 callback 數量」而不是「再跑一次 apply_filters
	 * 有沒有附加」——後者會被 `static $done` 遮蔽（它已經是 true 了），
	 * 就算 filter 根本沒被拆掉也照樣綠燈。
	 *
	 * 🔴 **三段量測缺一不可，中間那段是防假綠燈的關鍵**：只比對「render 前 == render 後」
	 *    的話，萬一 production 改成掛在別的 priority，兩次都會量到同一個數字（例如 0 == 0），
	 *    測試照樣全綠、卻什麼都沒驗到。多一個「render **當下**必須恰好多一個」的探針
	 *    （掛 priority 30，在我們要驗的那個 callback 之後執行），才同時鎖住
	 *    「有掛上」與「有拆掉」兩件事。
	 *
	 * @test
	 * @group security
	 */
	public function test_the_content_filter_is_removed_after_rendering(): void {
		$shop = $this->create_shop_with_product();

		$before   = $this->count_the_content_callbacks_at_20();
		$observed = -1;

		// 探針掛在 30，會在賣場那個 priority 20 的 callback 之後、同一次
		// apply_filters 內執行 ⇒ 量到的是「渲染當下」的掛載狀態。
		$probe = function ( $content ) use ( &$observed ) {
			$observed = $this->count_the_content_callbacks_at_20();
			return $content;
		};
		\add_filter( 'the_content', $probe, 30 );

		try {
			$this->render_theme_template( $shop, 'loop' );
		} finally {
			\remove_filter( 'the_content', $probe, 30 );
		}

		$after = $this->count_the_content_callbacks_at_20();

		$this->assertSame(
			$before + 1,
			$observed,
			'渲染當下 the_content priority 20 沒有多出賣場的 callback ⇒ 本測試量錯了地方，下面那條會變成假綠燈'
		);
		$this->assertSame(
			$before,
			$after,
			'渲染後 the_content priority 20 上多了殘留的 callback ⇒ remove_filter 沒生效'
		);
	}

	/**
	 * 🔴 renderer 預收的 WooCommerce 通知必須傳進 template 並印出來
	 *
	 * 這是 TD-49 在「佈景主題範本」這條路徑上的回歸鎖：`render_via_theme_template()`
	 * 少把 `$ps_notices_html` 用 `use` 帶進閉包的話，template 會落回自己呼叫
	 * `wc_print_notices()` 的退路——而那時範本的 `get_header()` 早已把通知吃光，
	 * 症狀就是「加購成功卻看不到任何提示」，且沒有任何錯誤訊息。
	 *
	 * @test
	 * @group happy
	 */
	public function test_captured_notices_are_passed_into_the_builtin_template(): void {
		$shop   = $this->create_shop_with_product();
		$notice = '<ul class="woocommerce-message">PS_NOTICE_MARKER</ul>';

		$html = $this->render_theme_template( $shop, 'loop', $notice );

		$this->assertStringContainsString( 'ps-shop-notices', $html, '通知容器沒印出來' );
		$this->assertStringContainsString( 'PS_NOTICE_MARKER', $html, 'renderer 收下來的通知沒有傳進 template' );
	}

	/**
	 * 🔴 內建 template 不得把 post_content 再印一次
	 *
	 * `$ps_skip_post_content = true` 的傳遞。範本自己已經用 `the_content()` 印過
	 * 同一篇了，template 再印一次會**整段重複**——重複的是站長自己排版的內容，
	 * 一眼就看得出來壞掉。
	 *
	 * @test
	 * @group edge
	 */
	public function test_builtin_template_does_not_print_post_content_again(): void {
		$shop = $this->create_shop_with_product();
		$html = $this->render_theme_template( $shop, 'loop' );

		$this->assertSame(
			1,
			\substr_count( $html, self::SHOP_CONTENT_MARKER ),
			'post_content 出現不只一次 ⇒ $ps_skip_post_content 沒有傳進 template'
		);
	}

	// ====================================================================
	//  Helper
	// ====================================================================

	/**
	 * 以 Reflection 呼叫 `ProfitShopRenderer` 的 private method
	 *
	 * 刻意用 `instance()`（Loader 已在 bootstrap 建好的那一個）而不是自己 `new`：
	 * 重新 `new` 會再掛一次 `template_redirect`，污染同一輪的其他測試。
	 *
	 * @param string $method 方法名
	 * @param mixed  ...$args 參數
	 *
	 * @return mixed
	 */
	private function invoke_private( string $method, ...$args ) {
		$ref = new \ReflectionMethod( ProfitShopRenderer::class, $method );
		$ref->setAccessible( true );

		return $ref->invoke( ProfitShopRenderer::instance(), ...$args );
	}

	/**
	 * 跑一次 `render_via_theme_template()` 並回傳輸出
	 *
	 * 兩個前置條件，順序不可對調：
	 *
	 * 1. **`go_to( '/' )`：先讓 `$wp_query` 成為一個真正解析過的主查詢。**
	 *    正式環境的 `template_redirect` 一定發生在 `WP::main()` 之後，主查詢早已跑過
	 *    `parse_query()` → `fill_query_vars()`；而測試環境的 `$GLOBALS['wp_query']`
	 *    可能只是一個 `new WP_Query()`，`query_vars` 是**空陣列**。
	 *    `WP_Query::the_post()`（WordPress 6.9.5，`class-wp-query.php:3764`）第一行就
	 *    無條件讀 `$this->query_vars['fields']` ⇒ 少了這一步，fixture 範本的
	 *    `the_post()` 會炸在 `Undefined array key "fields"`（實測，2026-08-11）。
	 *    這是**測試環境的前提缺口，不是 production 缺陷**——正式環境不可能出現空的
	 *    `query_vars`。用 `/` 也剛好重現 renderer docblock 記載的修正前狀態（`is_home=true`）。
	 *
	 * 2. **`setup_shop_post_context()`：把主查詢換成這篇賣場。**
	 *    佈景主題範本的 `while ( have_posts() )` 讀的就是主查詢，沒有它 loop 會跑零次、
	 *    整組斷言空轉。
	 *
	 * @param ProfitShop $shop            賣場聚合根
	 * @param string     $mode            fixture 範本行為模式（loop / double / foreign-then-loop）
	 * @param string     $notices         模擬 renderer 預收的 WooCommerce 通知
	 * @param int        $foreign_post_id foreign-then-loop 模式要拿來跑 the_content 的別篇 post
	 *
	 * @return string
	 */
	private function render_theme_template(
		ProfitShop $shop,
		string $mode,
		string $notices = '',
		int $foreign_post_id = 0
	): string {
		$GLOBALS['ps_fixture_mode']            = $mode;
		$GLOBALS['ps_fixture_foreign_post_id'] = $foreign_post_id;

		$this->go_to( '/' );

		$this->invoke_private( 'setup_shop_post_context', $shop );

		$this->assertTrue(
			\have_posts(),
			'測試前置失效：主查詢裡沒有東西可以 loop，範本會跑零次、斷言全部空轉'
		);

		$template = \locate_template( self::THEME_TEMPLATE );
		$this->assertNotSame( '', $template, '測試前置失效：fixture 範本沒被 locate_template() 找到' );

		\ob_start();
		$this->invoke_private(
			'render_via_theme_template',
			$shop,
			ProfitShopFrontData::settings( $shop ),
			$template,
			$notices
		);

		return (string) \ob_get_clean();
	}

	/**
	 * 數 `the_content` priority 20 上掛了幾個 callback
	 *
	 * @return int
	 */
	private function count_the_content_callbacks_at_20(): int {
		global $wp_filter;

		if ( ! isset( $wp_filter['the_content'] ) || ! $wp_filter['the_content'] instanceof \WP_Hook ) {
			return 0;
		}

		$callbacks = $wp_filter['the_content']->callbacks;

		return isset( $callbacks[20] ) ? \count( (array) $callbacks[20] ) : 0;
	}

	/**
	 * 建一個只存在於記憶體的賣場（`resolve_theme_template()` 只讀 settings）
	 *
	 * @param array<string, mixed> $settings 賣場設定
	 *
	 * @return ProfitShop
	 */
	private function make_shop_entity( array $settings ): ProfitShop {
		++self::$slug_seq;

		return new ProfitShop(
			id: 0,
			title: '範本測試賣場',
			slug: 'renderer-theme-template-' . self::$slug_seq,
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: 1,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: $settings
		);
	}

	/**
	 * 建立一個真的存在於 DB、含一項商品與可識別 post_content 的賣場
	 *
	 * @return ProfitShop
	 */
	private function create_shop_with_product(): ProfitShop {
		$product = $this->createSimpleProduct(
			[
				'name'          => '範本測試商品',
				'regular_price' => '1000',
			]
		);

		++self::$slug_seq;

		$repo = CptProfitShopRepository::instance();
		$id   = $repo->save(
			new ProfitShop(
				id: 0,
				title: '範本測試賣場',
				slug: 'renderer-theme-template-shop-' . self::$slug_seq,
				status: 'publish',
				mode: ShopMode::PAGE,
				partner_term_id: 1,
				rate: new ProfitRate( 10 ),
				items: [
					new OverrideItem(
						product_id: $product->get_id(),
						override: new PriceOverride( null, null, null ),
						inflated_count: new InflatedCount( 0 )
					),
				],
				settings: [ 'pageTemplate' => self::THEME_TEMPLATE ]
			)
		);

		$this->assertGreaterThan( 0, $id, '測試前置：賣場應建立成功' );

		\wp_update_post(
			[
				'ID'           => $id,
				'post_content' => self::SHOP_CONTENT_MARKER,
			]
		);

		$saved = $repo->find( $id );
		$this->assertInstanceOf( ProfitShop::class, $saved, '測試前置：賣場應讀得回來' );

		return $saved;
	}

	/**
	 * 產生 fixture 佈景主題範本的原始碼
	 *
	 * 模擬三種真實範本形狀，由 `$GLOBALS['ps_fixture_mode']` 切換：
	 *   loop              一般範本：跑主查詢 loop + the_content()
	 *   double            同一篇跑兩次 the_content()（摘要 + 內文）
	 *   foreign-then-loop 先對別篇 post 跑 the_content()，再跑自己的 loop
	 *
	 * 刻意不呼叫 `get_header()` / `get_footer()`：那會把整個佈景主題 chrome 拉進
	 * 測試輸出，讓斷言的訊號被淹沒，而且結果會隨測試環境的佈景主題漂移。
	 * 本檔要驗的是 renderer 掛上去的那個 `the_content` filter，不是佈景主題。
	 *
	 * 🔴 **刻意不寫 `Template Name:` 檔頭**（第一版寫了，已移除）。本檔案是靠
	 *    `theme_page_templates` filter 登記的，檔頭對登記毫無幫助，卻會讓
	 *    `WP_Theme::get_post_templates()` 的**目錄掃描**也認得它；而那份掃描結果
	 *    是進物件快取的（`WP_Theme::cache_add()`），有機會活得比本類別的
	 *    `tear_down()` 久 ⇒ 檔案已刪、清單裡卻還留著它，下一個測試類別
	 *    （`ThemeTemplatesEndpointTest` 會逐筆 `resolve()` 每個列出的範本）就會
	 *    拿到一個解析不出路徑的項目而紅燈。少一個檔頭就少一條跨測試污染路徑。
	 *    `locate_template()` 只做 `file_exists()`、不讀檔頭，所以移除它不影響本檔。
	 *
	 * @return string
	 */
	private function theme_template_source(): string {
		return <<<'PHP'
<?php
/**
 * 測試用佈景主題範本 fixture（由 ProfitShopRendererThemeTemplateTest 於 set_up 產生）
 */

echo '<div class="ps-fixture-chrome">';

$ps_fixture_mode = isset( $GLOBALS['ps_fixture_mode'] ) ? (string) $GLOBALS['ps_fixture_mode'] : 'loop';

if ( 'foreign-then-loop' === $ps_fixture_mode ) {
	$ps_foreign = get_post( (int) ( $GLOBALS['ps_fixture_foreign_post_id'] ?? 0 ) );

	if ( $ps_foreign instanceof WP_Post ) {
		$GLOBALS['post'] = $ps_foreign;
		setup_postdata( $ps_foreign );
		echo apply_filters( 'the_content', 'PS_FOREIGN_CONTENT_MARKER' );
		wp_reset_postdata();
	}

	echo 'PS_FOREIGN_BLOCK_END';
}

while ( have_posts() ) :
	the_post();
	the_content();

	if ( 'double' === $ps_fixture_mode ) {
		the_content();
	}
endwhile;

echo '</div>';
PHP;
	}
}
