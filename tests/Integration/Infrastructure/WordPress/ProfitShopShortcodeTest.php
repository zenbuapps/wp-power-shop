<?php
/**
 * ProfitShopShortcode 整合測試（TD-13 前半）
 *
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/WordPress/ProfitShopShortcode.php
 * 對應技術債：specs/tech-debt.md TD-13
 *
 * 驗證重點：
 *   1. shortcode tag 真的被註冊（Loader 有把 singleton 串起來）
 *   2. publish 賣場渲染出商品內容
 *   3. 外層標籤是 <div> 而非 <main>（巢狀 main 是無效 HTML，回歸鎖）
 *   4. 不注入 SEO meta（<title>），避免與宿主頁面雙 title
 *   5. id 嚴格驗證（非數字 / 負數 / 前導零 / 浮點 / 缺參數）
 *   6. status 可見性與 ProfitShopRenderer 同一條紀律（draft 僅有權限者可預覽）
 *   7. 資訊揭露邊界：一般訪客一律空字串，只有 edit_posts 能力者看得到除錯提示
 *   8. **獨立頁面與 shortcode 兩條路徑渲染逐字相同的 DOM**（統一渲染的核心回歸鎖）
 *   9. shortcode 路徑必須套用販售起訖時間（閉店賣場不得印出可送出的加購表單）
 *
 * @group profit_shop
 * @group infrastructure
 * @group shortcode
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
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopShortcode;
use Tests\Integration\TestCase;

/**
 * 賣場 shortcode 整合測試
 */
final class ProfitShopShortcodeTest extends TestCase {

	private CptProfitShopRepository $repo;

	/**
	 * 受測 shortcode（刻意用 singleton，順帶驗證 Loader 已注入 Repository）
	 *
	 * @var ProfitShopShortcode
	 */
	private ProfitShopShortcode $shortcode;

	/**
	 * 遞增用的 slug 序號，避免同一次測試 run 內 slug 撞號
	 *
	 * @var int
	 */
	private static int $slug_seq = 0;

	public function set_up(): void {
		parent::set_up();

		$this->repo      = CptProfitShopRepository::instance();
		$this->shortcode = ProfitShopShortcode::instance();

		// 預設以「未登入訪客」身分測試；需要權限的案例各自 wp_set_current_user()。
		\wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		\wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ========== Smoke：註冊與端到端 ==========

	/**
	 * shortcode tag 必須已被註冊到 WP 全域 $shortcode_tags
	 *
	 * 這條同時鎖住 Loader 的註冊（singleton 已帶 Repository 建立），
	 * 沒註冊的話前端「複製 shortcode」按鈕複製出來的字串會是死的。
	 *
	 * @test
	 * @group smoke
	 */
	public function test_shortcode_tag_is_registered(): void {
		$this->assertSame( 'power_shop_profit_shop', ProfitShopShortcode::TAG, 'tag 是已定案契約，改動會讓既有頁面上的 shortcode 全部失效' );
		$this->assertTrue(
			\shortcode_exists( ProfitShopShortcode::TAG ),
			'Loader 應已註冊 shortcode；未註冊時 [power_shop_profit_shop] 會被原樣輸出成文字'
		);
	}

	/**
	 * 端到端：do_shortcode 走真實 WP shortcode API 渲染出商品
	 *
	 * @test
	 * @group smoke
	 * @group happy
	 */
	public function test_do_shortcode_renders_shop_content(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => 'E2E 短碼商品',
				'regular_price' => '1000',
			]
		);
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		$html = \do_shortcode( '[' . ProfitShopShortcode::TAG . ' id="' . $shop_id . '"]' );

		$this->assertStringContainsString( 'E2E 短碼商品', $html, 'do_shortcode 應渲染出賣場商品名稱' );
		$this->assertStringContainsString( 'ps-profit-shop-front', $html, '應輸出賣場容器' );
	}

	// ========== Happy：正常渲染 ==========

	/**
	 * publish 賣場：渲染出商品名稱與賣場標題
	 *
	 * @test
	 * @group happy
	 */
	public function test_publish_shop_renders_product_name(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '短碼測試商品 Alpha',
				'regular_price' => '1000',
			]
		);
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( '短碼測試商品 Alpha', $html, '商品名稱應出現在輸出中' );
		$this->assertStringContainsString( '短碼測試賣場', $html, '賣場標題應出現在輸出中' );
		$this->assertStringContainsString( 'add-to-cart', $html, 'simple 商品應有加入購物車表單' );
		$this->assertStringContainsString( 'profit_shop_id', $html, '加購路徑必須帶 profit_shop_id，否則分潤歸因會斷掉' );
	}

	/**
	 * 覆寫價生效：賣場設定的特價會顯示，不是商品原價
	 *
	 * @test
	 * @group happy
	 */
	public function test_price_override_is_rendered(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( '900', '850', null ),
					inflated_count: new InflatedCount( 0 )
				),
			]
		);

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( '850', $html, '覆寫特價應顯示' );
		$this->assertStringContainsString( '<del', $html, '有原價 / 特價落差時應顯示劃線原價' );
	}

	// ========== Edge：巢狀 main 與 SEO meta 回歸鎖 ==========

	/**
	 * 【回歸鎖】shortcode 輸出必須用 <div> 外層，不得出現 <main>
	 *
	 * shortcode 嵌在宿主頁面內容裡，宿主頁面已有自己的 <main>；
	 * HTML spec 規定一份文件內只能有一個非 hidden 的 main，巢狀是無效 HTML。
	 *
	 * @test
	 * @group edge
	 * @group smoke
	 */
	public function test_output_uses_div_wrapper_and_never_main(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		$html = $this->render( $shop_id );

		$this->assertNotSame( '', $html, '防空斷言：publish 賣場應有輸出，否則下面兩條會假綠燈' );
		$this->assertStringContainsString(
			'<div class="ps-profit-shop-front',
			$html,
			'外層應為 <div>'
		);
		$this->assertStringNotContainsString( '<main', $html, '輸出不得含 <main>（巢狀 main 是無效 HTML）' );
		$this->assertStringNotContainsString( '</main>', $html, '輸出不得含 </main>' );
	}

	/**
	 * shortcode 不得注入 SEO meta：宿主頁面自己有 <title>，注入會變雙 title
	 *
	 * @test
	 * @group edge
	 */
	public function test_output_contains_no_seo_meta(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		$html = $this->render( $shop_id );

		$this->assertNotSame( '', $html, '防空斷言：publish 賣場應有輸出' );
		$this->assertStringNotContainsString( '<title', $html, '不得注入 <title>（宿主頁面已有）' );
		$this->assertStringNotContainsString( 'rel="canonical"', $html, '不得注入 canonical（宿主頁面已有）' );
	}

	/**
	 * 【回歸鎖】獨立頁面路徑與 shortcode 路徑必須渲染**完全相同**的 DOM
	 *
	 * 這是「統一渲染 DOM」的核心斷言。兩條路徑歷史上分歧過兩次，且兩次都不是外觀問題：
	 *
	 *   1. `$is_shortcode_context` 旗標讓外層標籤在 `<main>` / `<div>` 之間切換
	 *   2. **更嚴重**：早期只有 ProfitShopRenderer 會傳 `$front_settings`，shortcode
	 *      路徑因此拿到空陣列，`shopStatus` 一律 fallback 成 `'published'`——
	 *      已結束販售（或尚未開賣）的賣場用 shortcode 嵌入時**照樣能加購**，
	 *      這是商業邏輯漏洞而不只是版型不一致
	 *
	 * 因此這裡不只比對外層標籤，而是逐字比對整份輸出：任何一邊多傳 / 少傳變數，
	 * 或 template 又長出情境分支，都會在這裡被抓到。
	 *
	 * @test
	 * @group edge
	 * @group smoke
	 */
	public function test_renderer_and_shortcode_paths_render_identical_dom(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '雙路徑一致性商品',
				'regular_price' => '1000',
			]
		);
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );
		$shop    = $this->repo->find( $shop_id );
		$this->assertInstanceOf( ProfitShop::class, $shop, '測試前置：賣場應可讀回' );

		$shortcode_html = $this->render( $shop_id );
		$renderer_html  = $this->render_template_as_renderer( $shop );

		$this->assertNotSame( '', $shortcode_html, '防空斷言：shortcode 應有輸出' );
		$this->assertNotSame( '', $renderer_html, '防空斷言：renderer 路徑應有輸出' );

		$this->assertSame(
			$renderer_html,
			$shortcode_html,
			'兩條渲染路徑的 DOM 必須完全相同；不同代表 template 又長出情境分支，或某一邊漏傳變數'
		);

		// 外層一律 <div>：獨立頁面走 theme 的 header.php 時同樣可能已經有 <main>，
		// 兩條路徑統一用 <div> 才是唯一安全的選擇。
		$this->assertStringContainsString( '<div class="ps-profit-shop-front', $renderer_html );
		$this->assertStringNotContainsString( '<main', $renderer_html );
	}

	/**
	 * 【回歸鎖】shortcode 路徑必須套用賣場的販售狀態
	 *
	 * 早期 shortcode 路徑不傳 `$front_settings`，`shopStatus` 永遠 fallback 成
	 * `'published'`——已結束販售的賣場嵌進頁面後照樣能加購。這條直接鎖住那個漏洞。
	 *
	 * @test
	 * @group security
	 * @group edge
	 */
	public function test_shortcode_respects_closed_shop_status(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );

		// endTime 設在過去 → resolve_shop_status() 回 'closed'
		$shop_id = $this->create_shop(
			[ $this->item_for( $product->get_id() ) ],
			'publish',
			[ 'endTime' => ( \time() - 86400 ) * 1000 ]
		);

		$html = $this->render( $shop_id );

		$this->assertNotSame( '', $html, '防空斷言：賣場應有輸出' );
		$this->assertStringContainsString( '商店已關閉', $html, '已結束販售的賣場必須顯示狀態文案' );
		$this->assertStringContainsString( 'disabled', $html, '已結束販售時加購按鈕必須停用' );
		$this->assertStringNotContainsString(
			'name="add-to-cart"',
			$html,
			'已結束販售時不得印出任何可送出的加購表單欄位'
		);
		$this->assertStringNotContainsString(
			'data-ps-toast',
			$html,
			'閉店賣場不會有 AJAX 加購，成功通知容器同樣不該印出'
		);
	}

	/**
	 * 【回歸鎖】開放中的賣場必須印出 AJAX 加購所需的完整 DOM 契約
	 *
	 * `inc/assets/js/profit-shop-front.js` 靠這幾個屬性找元素；任一被改名或刪除，
	 * AJAX 加購會**靜默退回整頁送出、loading 與成功通知完全不出現**，而且不會有
	 * 任何錯誤訊息。同時鎖住 `profit_shop_id` 仍在表單內——那是分潤三閘門的生命線。
	 *
	 * @test
	 * @group smoke
	 * @group happy
	 */
	public function test_open_shop_renders_ajax_dom_contract(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		$html = $this->render( $shop_id );

		$this->assertNotSame( '', $html, '防空斷言：賣場應有輸出' );

		$contract = [
			'data-ps-form'              => 'JS 攔截 submit 的錨點',
			'data-ps-submit-spinner'    => '加購按鈕內的 loading 圈',
			'data-ps-submit-label'      => '加購按鈕文字',
			'data-ps-submit-error'      => 'Modal 內就地錯誤列',
			'data-ps-submit-error-label' => '錯誤文字容器',
			'data-ps-toast'             => '成功通知容器',
			'data-ps-toast-body'        => '通知本體（圖示切換的 host）',
			'data-ps-toast-label'       => '通知文字容器',
		];

		foreach ( $contract as $attribute => $why ) {
			$this->assertStringContainsString(
				$attribute,
				$html,
				\sprintf( '缺少 %s（%s）——AJAX 加購會靜默失效', $attribute, $why )
			);
		}

		$this->assertStringContainsString(
			'name="profit_shop_id"',
			$html,
			'分潤三閘門只從 \$_GET / \$_POST 讀 profit_shop_id，漏印會靜默走商品原價'
		);
	}

	// ========== 開啟 / 關閉時間的呈現區塊組合 ==========

	/**
	 * 【回歸鎖】沒設定呈現區塊的閉店賣場 = 功能上線前的既有行為
	 *
	 * `beforeDisplay` / `afterDisplay` 是後加的欄位，既有賣場的 settings 裡沒有它們。
	 * 預設若不是「只顯示商品」，全站已排程的賣場會在時間外突然變空白——這條鎖住那個方向。
	 *
	 * @test
	 * @group smoke
	 * @group edge
	 */
	public function test_closed_shop_without_display_setting_keeps_browse_behaviour(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '預設呈現商品',
				'regular_price' => '1000',
			]
		);
		$shop_id = $this->create_shop(
			[ $this->item_for( $product->get_id() ) ],
			'publish',
			[ 'endTime' => ( \time() - 86400 ) * 1000 ]
		);

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( '預設呈現商品', $html, '預設應照常顯示商品（browse）' );
		$this->assertStringContainsString( '商店已關閉', $html, '按鈕仍應顯示關閉文案' );
		$this->assertStringNotContainsString(
			'data-ps-schedule',
			$html,
			'browse 且未填自訂文字時不得多印排程區塊，否則既有賣場的 DOM 會被改動'
		);
		$this->assertStringNotContainsString( 'data-ps-countdown', $html, 'browse 模式不印倒數盤' );
	}

	/**
	 * 全部不勾：閉店後整個商品區不印，連空狀態訊息也不印
	 *
	 * @test
	 * @group happy
	 */
	public function test_closed_shop_with_no_parts_renders_nothing(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '應被藏起來的商品',
				'regular_price' => '1000',
			]
		);
		$shop_id = $this->create_shop(
			[ $this->item_for( $product->get_id() ) ],
			'publish',
			[
				'endTime'      => ( \time() - 86400 ) * 1000,
				'afterDisplay' => [],
				'afterText'    => '這段文字沒有勾選顯示，不該出現',
			]
		);

		$html = $this->render( $shop_id );

		$this->assertNotSame( '', $html, '防空斷言：賣場容器與標題仍應輸出' );
		$this->assertStringContainsString( 'ps-profit-shop-front', $html, '賣場容器仍在' );

		$this->assertStringNotContainsString( '應被藏起來的商品', $html, '沒勾商品就不得印出商品' );
		$this->assertStringNotContainsString( 'data-ps-product-id', $html, '不得印出任何商品卡片' );
		$this->assertStringNotContainsString( 'data-ps-schedule', $html, '沒勾任何區塊時連排程容器都不該印' );
		$this->assertStringNotContainsString(
			'這段文字沒有勾選顯示',
			$html,
			'沒勾「顯示自訂文字」時，即使文字有填也不得印出'
		);
		$this->assertStringNotContainsString(
			'此賣場暫無商品',
			$html,
			'刻意藏起來與「真的沒商品」是兩件事，跳空狀態訊息會誤導成資料出錯'
		);
		$this->assertStringNotContainsString( 'name="add-to-cart"', $html, '不得印出任何可送出的加購欄位' );
	}

	/**
	 * 只勾倒數計時 + 自訂文字：未開賣時印出倒數盤與文字，且不印商品
	 *
	 * @test
	 * @group happy
	 */
	public function test_coming_shop_with_countdown_renders_countdown_block(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '尚未開賣商品',
				'regular_price' => '1000',
			]
		);
		$start   = ( \time() + 7200 ) * 1000;
		$shop_id = $this->create_shop(
			[ $this->item_for( $product->get_id() ) ],
			'publish',
			[
				'startTime'     => $start,
				'beforeDisplay' => [
					ProfitShopFrontData::PART_COUNTDOWN,
					ProfitShopFrontData::PART_TEXT,
				],
				'beforeText'    => '8/10 中午 12:00 準時開賣',
			]
		);

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( 'data-ps-schedule', $html, '應印出排程區塊' );
		$this->assertStringContainsString( 'data-ps-countdown', $html, '應印出倒數容器（JS 每秒遞減的錨點）' );
		$this->assertStringContainsString(
			'data-ps-countdown-target="' . $start . '"',
			$html,
			'倒數目標必須是開啟時間，JS 才算得出正確剩餘秒數'
		);
		$this->assertStringContainsString( 'pc-countdown', $html, '應使用 daisyUI countdown 元件' );
		$this->assertStringContainsString( 'data-ps-cd-unit="days"', $html, '四個時間單位都要印出來' );
		$this->assertStringContainsString( 'data-ps-cd-plain="days"', $html, '> 99 天時的純文字退路必須一起印出' );
		$this->assertStringContainsString( '8/10 中午 12:00 準時開賣', $html, '自訂文字應顯示' );

		$this->assertStringNotContainsString( '尚未開賣商品', $html, '沒勾商品就不得印出商品' );
		$this->assertStringNotContainsString( 'name="add-to-cart"', $html, '不得印出任何可送出的加購欄位' );
	}

	/**
	 * 倒數計時 + 商品並存：三個區塊互相獨立，可任意組合
	 *
	 * @test
	 * @group happy
	 * @group edge
	 */
	public function test_countdown_and_products_can_coexist(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '預熱曝光商品',
				'regular_price' => '1000',
			]
		);
		$shop_id = $this->create_shop(
			[ $this->item_for( $product->get_id() ) ],
			'publish',
			[
				'startTime'     => ( \time() + 7200 ) * 1000,
				'beforeDisplay' => [
					ProfitShopFrontData::PART_COUNTDOWN,
					ProfitShopFrontData::PART_PRODUCTS,
				],
				'beforeText'    => '沒勾顯示，不該出現',
			]
		);

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( 'data-ps-countdown', $html, '倒數盤應印出' );
		$this->assertStringContainsString( '預熱曝光商品', $html, '商品應同時印出（利於 SEO 預熱）' );
		$this->assertStringContainsString( '尚未開始販售', $html, '按鈕仍應停用並顯示狀態文案' );
		$this->assertStringNotContainsString( '沒勾顯示，不該出現', $html, '未勾選的自訂文字不得印出' );
		$this->assertStringNotContainsString( 'name="add-to-cart"', $html, '仍不得印出可送出的加購欄位' );
	}

	/**
	 * 已結束 + 倒數：盤面歸零（沒有未來目標可倒數）
	 *
	 * @test
	 * @group edge
	 */
	public function test_closed_shop_with_countdown_renders_zeroed_board(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop(
			[ $this->item_for( $product->get_id() ) ],
			'publish',
			[
				'endTime'      => ( \time() - 86400 ) * 1000,
				'afterDisplay' => [ ProfitShopFrontData::PART_COUNTDOWN ],
			]
		);

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( 'data-ps-countdown-target="0"', $html, '已結束時倒數目標為 0（全零盤面）' );
		$this->assertStringContainsString( '--value:0;', $html, '盤面數值應全部歸零' );
	}

	/**
	 * 【安全】任何呈現區塊組合都不得印出商品 Modal
	 *
	 * Modal 內含一張真正的 `<form method="post">`，`AddToCartHook` 的三道閘門
	 * **不檢查販售起訖時間**，接不住「把 disabled 拔掉再送出」。
	 *
	 * @test
	 * @group security
	 */
	public function test_no_display_combination_ever_renders_modal_when_closed(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );

		$combinations = [
			[],
			[ ProfitShopFrontData::PART_COUNTDOWN ],
			[ ProfitShopFrontData::PART_TEXT ],
			[ ProfitShopFrontData::PART_PRODUCTS ],
			[
				ProfitShopFrontData::PART_COUNTDOWN,
				ProfitShopFrontData::PART_TEXT,
				ProfitShopFrontData::PART_PRODUCTS,
			],
		];

		foreach ( $combinations as $parts ) {
			$shop_id = $this->create_shop(
				[ $this->item_for( $product->get_id() ) ],
				'publish',
				[
					'endTime'      => ( \time() - 86400 ) * 1000,
					'afterDisplay' => $parts,
					'afterText'    => '已結束',
				]
			);

			$html  = $this->render( $shop_id );
			$label = '[' . \implode( ',', $parts ) . ']';

			$this->assertStringNotContainsString(
				'data-ps-modal-for',
				$html,
				\sprintf( '%s 組合在閉店時仍印出了商品 Modal', $label )
			);
			$this->assertStringNotContainsString(
				'name="add-to-cart"',
				$html,
				\sprintf( '%s 組合在閉店時印出了可送出的加購欄位', $label )
			);
		}
	}

	// ========== Error：id 參數嚴格驗證 ==========

	/**
	 * 不存在的 id → 訪客拿到空字串
	 *
	 * @test
	 * @group error
	 */
	public function test_nonexistent_id_returns_empty_string_for_visitor(): void {
		$this->assertSame( '', $this->render( 9999999 ) );
	}

	/**
	 * 非數字 id → 空字串
	 *
	 * @test
	 * @group error
	 */
	public function test_non_numeric_id_returns_empty_string(): void {
		$this->assertSame( '', $this->shortcode->render( [ 'id' => 'abc' ] ) );
		$this->assertSame( '', $this->shortcode->render( [ 'id' => '' ] ) );
	}

	/**
	 * 負數 / 零 id → 空字串
	 *
	 * @test
	 * @group error
	 */
	public function test_negative_or_zero_id_returns_empty_string(): void {
		$this->assertSame( '', $this->shortcode->render( [ 'id' => '-1' ] ), '負數 id 不合法' );
		$this->assertSame( '', $this->shortcode->render( [ 'id' => '0' ] ), '0 不是合法 post ID' );
	}

	/**
	 * 缺 id 屬性 → 空字串，且不得拋錯
	 *
	 * WP 在 shortcode 完全沒有屬性時傳入的是空字串（不是陣列），
	 * 若 callback 直接把它丟給 shortcode_atts() 會 TypeError 把整個頁面弄壞。
	 *
	 * @test
	 * @group error
	 * @group edge
	 */
	public function test_missing_atts_does_not_error(): void {
		$this->assertSame( '', $this->shortcode->render( '' ), 'WP 無屬性時傳空字串，必須安全處理' );
		$this->assertSame( '', $this->shortcode->render( [] ) );
		$this->assertSame(
			'',
			\do_shortcode( '[' . ProfitShopShortcode::TAG . ']' ),
			'完全沒帶屬性時也不得產生輸出或錯誤'
		);
	}

	/**
	 * 「看起來像數字」但不合法的 id 一律拒絕
	 *
	 * 與 AddToCartHook::extract_shop_id_from_request 同一條 ctype_digit 紀律：
	 * 不讓 (int) 把 '1.9' 悄悄轉成 1、把 '+1' 轉成 1。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_numeric_looking_but_invalid_ids_are_rejected(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		// 先證明這個 shop_id 本身是可渲染的，否則下面全部會是假綠燈。
		$this->assertNotSame( '', $this->render( $shop_id ), '防空斷言：基準 id 應可渲染' );

		$invalid = [
			'0' . $shop_id,        // 前導零
			'+' . $shop_id,        // 正號
			$shop_id . '.0',       // 浮點
			$shop_id . 'abc',      // 尾綴雜訊
			(string) $shop_id . ' 1', // 內含空白
		];

		foreach ( $invalid as $raw ) {
			$this->assertSame(
				'',
				$this->shortcode->render( [ 'id' => $raw ] ),
				\sprintf( 'id="%s" 應被拒絕', $raw )
			);
		}
	}

	// ========== Security：status 可見性與資訊揭露邊界 ==========

	/**
	 * draft 賣場 → 一般訪客拿到空字串
	 *
	 * @test
	 * @group security
	 */
	public function test_draft_shop_returns_empty_string_for_visitor(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ], 'draft' );

		$this->assertSame( '', $this->render( $shop_id ), 'draft 賣場不得對訪客顯示' );
	}

	/**
	 * draft 賣場 → 有 edit_post 能力者可預覽（與 ProfitShopRenderer 同一條紀律）
	 *
	 * @test
	 * @group happy
	 * @group edge
	 */
	public function test_draft_shop_renders_for_privileged_user(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '草稿賣場商品',
				'regular_price' => '1000',
			]
		);
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ], 'draft' );

		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( '草稿賣場商品', $html, '有權限者應看得到草稿賣場內容' );
		$this->assertStringContainsString( '草稿預覽', $html, '應顯示草稿預覽提示，避免站長誤判已上線' );
	}

	/**
	 * trashed 賣場 → 空字串（Repository::find 對 trash 回 null）
	 *
	 * @test
	 * @group security
	 */
	public function test_trashed_shop_returns_empty_string(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		\wp_trash_post( $shop_id );

		$this->assertSame( '', $this->render( $shop_id ), '已刪除的賣場不得渲染' );

		// 連 admin 也不該渲染已刪除的賣場（Repository 層就擋掉，與權限無關）。
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->render( $shop_id );
		$this->assertStringNotContainsString( 'ps-profit-shop-front', $html, '已刪除賣場即使對 admin 也不得渲染商品列表' );
	}

	/**
	 * 【資訊揭露邊界】除錯提示只給 edit_posts 能力者，訪客一律空字串
	 *
	 * @test
	 * @group security
	 * @group smoke
	 */
	public function test_debug_hint_is_only_visible_to_editors(): void {
		// 訪客：不論 id 非法或賣場不存在，都是空字串（不洩漏賣場存在性）
		$this->assertSame( '', $this->shortcode->render( [ 'id' => 'abc' ] ) );
		$this->assertSame( '', $this->render( 9999999 ) );

		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$bad_id_hint = $this->shortcode->render( [ 'id' => 'abc' ] );
		$missing     = $this->render( 9999999 );

		$this->assertStringContainsString( 'ps-profit-shop-shortcode-notice', $bad_id_hint, 'id 非法時應給站長提示' );
		$this->assertStringContainsString( 'ps-profit-shop-shortcode-notice', $missing, '賣場不存在時應給站長提示' );
		$this->assertStringContainsString( '9999999', $missing, '提示應帶上找不到的 ID，方便對照' );
	}

	/**
	 * 未發佈且無預覽權限時，訊息不得與「不存在」有差異
	 *
	 * 只有 edit_posts（例如 Author）但對該 post 無 edit_post 能力的使用者，
	 * 不應能藉由訊息差異推斷出某個 ID 的草稿賣場是否存在。
	 *
	 * @test
	 * @group security
	 */
	public function test_draft_and_missing_shop_give_indistinguishable_hints(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$draft_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ], 'draft' );

		// author 有 edit_posts，但對別人建立的 powershop post 沒有 edit_post
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$draft_hint   = $this->render( $draft_id );
		$missing_hint = $this->render( 9999999 );

		$this->assertNotSame( '', $draft_hint, '有 edit_posts 能力者應看到提示（而非空白）' );
		$this->assertStringNotContainsString( 'ps-profit-shop-front', $draft_hint, '無 edit_post 能力者不得看到草稿賣場內容' );

		// 兩則訊息只有 ID 不同，句型必須一致。
		// 抹掉所有數字串（而非只抹 ID）才不會受 inline style 內的數字影響——
		// 若賣場 ID 恰好是 6 之類的短數字，逐字 replace 會連 CSS 的 6px 一起換掉。
		$shape = static fn( string $html ): string => (string) preg_replace( '/\d+/', 'N', $html );

		$this->assertSame(
			$shape( $draft_hint ),
			$shape( $missing_hint ),
			'「草稿無權限」與「不存在」的提示必須無法區分，否則會洩漏草稿賣場的存在性'
		);
	}

	// ========== Helpers ==========

	/**
	 * 呼叫受測 shortcode
	 *
	 * @param int $shop_id 賣場 ID
	 *
	 * @return string shortcode 輸出
	 */
	private function render( int $shop_id ): string {
		return $this->shortcode->render( [ 'id' => (string) $shop_id ] );
	}

	/**
	 * 建立一個含指定 items 的賣場並回傳 ID
	 *
	 * @param OverrideItem[]       $items    商品覆寫項目
	 * @param string               $status   賣場狀態
	 * @param array<string, mixed> $settings 前台顯示設定（`startTime` / `endTime` / `showStock`…）
	 *
	 * @return int 賣場 ID
	 */
	private function create_shop( array $items, string $status = 'publish', array $settings = [] ): int {
		++self::$slug_seq;

		$shop = new ProfitShop(
			id: 0,
			title: '短碼測試賣場',
			slug: 'shortcode-test-shop-' . self::$slug_seq,
			status: $status,
			mode: ShopMode::SHORTCODE,
			partner_term_id: 1,
			rate: new ProfitRate( 10 ),
			items: $items,
			settings: $settings
		);

		$id = $this->repo->save( $shop );
		$this->assertGreaterThan( 0, $id, '測試前置：賣場應建立成功' );

		return $id;
	}

	/**
	 * 產生一筆無覆寫的 OverrideItem
	 *
	 * @param int $product_id 商品 ID
	 *
	 * @return OverrideItem
	 */
	private function item_for( int $product_id ): OverrideItem {
		return new OverrideItem(
			product_id: $product_id,
			override: new PriceOverride( null, null, null ),
			inflated_count: new InflatedCount( 0 )
		);
	}

	/**
	 * 以 ProfitShopRenderer 的變數環境直接 require template（獨立頁面路徑）
	 *
	 * 刻意複製 `ProfitShopRenderer::render_shop()` 傳給 template 的**完整**變數集合
	 * （`$shop_for_template` + `$front_settings`）。若日後 renderer 多傳了什麼而這裡
	 * 沒跟上，`test_renderer_and_shortcode_paths_render_identical_dom` 會直接紅燈，
	 * 正好提醒兩條路徑又分歧了。
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 *
	 * @return string template 輸出
	 */
	private function render_template_as_renderer( ProfitShop $shop ): string {
		$shop_for_template = $shop;
		$front_settings    = ProfitShopFrontData::settings( $shop );

		// 以 class 檔案位置反推 template 路徑，避免寫死相對層數（測試檔搬家就會壞）
		$class_dir = \dirname( (string) ( new \ReflectionClass( ProfitShopShortcode::class ) )->getFileName() );

		\ob_start();
		require $class_dir . '/templates/profit-shop-front.php';

		return (string) \ob_get_clean();
	}
}
