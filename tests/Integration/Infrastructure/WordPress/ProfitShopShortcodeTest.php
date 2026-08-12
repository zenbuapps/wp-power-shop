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
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\VariationOverride;
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

	// ========== 無定價變體（2026-08 修法）==========

	/**
	 * 【回歸鎖】自身無定價、但賣場設了變體覆寫價的可變商品，兩條路徑都要印出價格與變體資料
	 *
	 * 修法前：`get_available_variations()` 會在
	 * woocommerce/includes/class-wc-product-variable.php:335 把無定價變體全部過濾掉
	 * （`variation_is_visible()` 的條件含 `'' !== get_price()`），於是 `variations` 是空陣列——
	 * 卡片連價格節點都不印、`ps-variations-data` 是 `[]`、Modal 永遠停在「此組合無法購買」，
	 * 而且畫面與 console 都沒有任何線索。
	 *
	 * @test
	 * @group happy
	 * @group smoke
	 */
	public function test_priceless_variations_with_override_render_prices_on_both_paths(): void {
		$product = $this->createVariableProduct(
			[
				'name'       => '無定價變體商品',
				'variations' => [
					[ 'regular_price' => '' ],
					[ 'regular_price' => '' ],
				],
			]
		);
		$ids     = array_values( array_map( 'intval', $product->get_children() ) );
		$this->assertCount( 2, $ids, '測試前置：應建立兩個變體' );

		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$ids[0] => new VariationOverride( new PriceOverride( '30', '27', null ), new InflatedCount( 0 ) ),
						$ids[1] => new VariationOverride( new PriceOverride( '50', '45', null ), new InflatedCount( 0 ) ),
					]
				),
			]
		);
		$shop    = $this->repo->find( $shop_id );
		$this->assertInstanceOf( ProfitShop::class, $shop, '測試前置：賣場應可讀回' );

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( '無定價變體商品', $html, '防空斷言：商品應被渲染' );
		$this->assertStringContainsString( '27', $html, '卡片應印出覆寫後的最低價' );
		$this->assertStringContainsString( '45', $html, '卡片應印出覆寫後的最高價' );
		$this->assertStringNotContainsString( '暫無可購買規格', $html, '有覆寫價時不得落進「無可購買規格」分支' );
		$this->assertStringContainsString(
			'"id":' . $ids[0],
			$html,
			'Modal 的 ps-variations-data 必須含被覆寫的變體（修法前這裡是空陣列）'
		);

		// 兩條渲染路徑必須逐字相同——修法在 build_variations()，兩條路徑共用同一份 payload。
		$this->assertSame(
			$this->render_template_as_renderer( $shop ),
			$html,
			'獨立頁面與 shortcode 兩條路徑的 DOM 必須完全相同'
		);
	}

	/**
	 * 【誠實狀態】完全沒有可購買規格時，卡片必須明說，且不得印出可送出的加購表單
	 *
	 * 這是修法後**唯一**仍然「不可購買」的合法組合（變體沒定價、賣場也沒設覆寫價）。
	 * 沒有這段文案的話，站長只會看到一張沒有價格的卡片，點進去才發現按鈕永遠停用。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_variable_product_without_any_purchasable_variation_says_so(): void {
		$product = $this->createVariableProduct(
			[
				'name'       => '全無定價商品',
				'variations' => [
					[ 'regular_price' => '' ],
					[ 'regular_price' => '' ],
				],
			]
		);

		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( '全無定價商品', $html, '防空斷言：卡片本身仍要印出來' );
		$this->assertStringContainsString( '暫無可購買規格', $html, '必須據實告知，不可留一塊空白' );
		$this->assertStringContainsString(
			'data-ps-disabled="1"',
			$html,
			'卡片必須停用，避免使用者點開一個必然失敗的 Modal'
		);
		/*
		* 卡片上不得留下任何可觸發的動作元素。
		*
		* `data-ps-js-label` 只出現在卡片的加購 form / 連結上（見 profit-shop-card.php
		* 的兩條可動作分支），停用分支印的是一顆純粹的 disabled button。
		*
		* ⚠️ 這裡刻意**不**斷言整份輸出沒有 `name="add-to-cart"`：賣場仍在販售期間，
		* 商品 Modal 照常印出（Modal 的 gate 是 `$is_shop_open`，本次未動）。那張表單
		* 的按鈕本來就因為「variable 且尚未選定變體」而 disabled，就算被手動送出，
		* WooCommerce 自己也會在 class-wc-cart.php:1229 擋下（父商品無可見子變體
		* ⇒ 價格為空 ⇒ is_purchasable() 為 false）。與「閉店」情境不同——那裡的販售
		* 起訖時間是 WooCommerce 完全不知道的規則，所以才必須整段不印。
		*/
		$this->assertStringNotContainsString(
			'data-ps-js-label',
			$html,
			'沒有可購買規格時，卡片不得留下可送出的表單或可點擊的連結'
		);
	}

	/**
	 * 【F-2】`variable-subscription` 且無任何可購買變體 → 卡片必須停用並據實告知
	 *
	 * 舊版 `'variable' === $card_type` 的嚴格字串比較讓這個型別完全落在守門之外：
	 * 價格區塊印成**空的 `<p>`**、`data-ps-disabled="0"` 可點、Modal 開得起來卻沒有任何
	 * 屬性選項，而加入購物車按鈕是啟用的——按下去整頁 reload、什麼都沒發生、
	 * **沒有任何錯誤訊息**（站台商品 #742「K8S 開戰」實測）。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_variable_subscription_without_purchasable_variation_renders_disabled_card(): void {
		if ( ! class_exists( '\WC_Product_Variable_Subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未啟用，無法建立 variable-subscription 商品' );
		}

		$parent = new \WC_Product_Variable_Subscription();
		$parent->set_name( 'F-2 無定價訂閱商品' );
		$parent->set_status( 'publish' );
		$parent->save();

		foreach ( [ 1, 2 ] as $ignored ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( '' );
			$variation->save();
		}

		$shop_id = $this->create_shop( [ $this->item_for( $parent->get_id() ) ] );
		$html    = $this->render( $shop_id );

		$this->assertStringContainsString( 'F-2 無定價訂閱商品', $html, '防空斷言：卡片本身仍要印出來' );
		$this->assertStringContainsString( '暫無可購買規格', $html, 'variable-subscription 也必須據實告知' );
		$this->assertStringContainsString( 'data-ps-disabled="1"', $html, '卡片必須停用' );
		$this->assertStringNotContainsString(
			'name="add-to-cart"',
			$html,
			'不得留下任何可送出的加購欄位'
		);
	}

	/**
	 * 【回歸鎖】閉店賣場即使商品有可購買的變體，仍然整段不印 Modal
	 *
	 * `AddToCartHook` 的三道閘門**不檢查販售起訖時間**，接不住「把 disabled 屬性拔掉
	 * 再送出」，所以唯一可靠的做法是連表單都不要印。無定價變體的救援不得鬆動這條。
	 *
	 * @test
	 * @group security
	 */
	public function test_closed_shop_never_renders_modal_for_rescued_variations(): void {
		$product = $this->createVariableProduct(
			[
				'name'       => '閉店無定價變體商品',
				'variations' => [ [ 'regular_price' => '' ] ],
			]
		);
		$ids     = array_values( array_map( 'intval', $product->get_children() ) );

		$shop_id = $this->create_shop(
			[
				new OverrideItem(
					product_id: $product->get_id(),
					override: new PriceOverride( null, null, null ),
					inflated_count: new InflatedCount( 0 ),
					variations: [
						$ids[0] => new VariationOverride( new PriceOverride( '30', '27', null ), new InflatedCount( 0 ) ),
					]
				),
			],
			'publish',
			[ 'endTime' => ( \time() - 86400 ) * 1000 ]
		);

		$html = $this->render( $shop_id );

		$this->assertStringContainsString( '閉店無定價變體商品', $html, '防空斷言：閉店仍會印卡片' );
		$this->assertStringContainsString( '27', $html, '防空斷言：變體確實被救回來了（否則本測試等於沒驗到東西）' );
		$this->assertStringNotContainsString( '<dialog', $html, '閉店賣場不得印出任何商品 Modal' );
		$this->assertStringNotContainsString( 'name="add-to-cart"', $html, '閉店賣場不得印出可送出的加購表單' );
	}

	/**
	 * 🔒 現況鎖：短碼模式的賣場**公開網址仍然開得起來**
	 *
	 * 規格 Rule「已知落差（存取）- 短碼模式的賣場公開網址仍然開得起來」（TD-13）。
	 *
	 * `ProfitShopRenderer::maybe_render` **完全不看 mode**，只檢查 slug 與 status。
	 * 站長把賣場設成 shortcode 模式並不會讓 `/profit-shop/{slug}/` 失效——
	 * 它照樣渲染、照樣被搜尋引擎索引。`BasicTab.tsx` 那句據實告知的警語
	 * 就是為此存在，**不可**因為列表頁有了「複製短碼」按鈕就把它清掉。
	 *
	 * ⚠️ 轉紅代表 Renderer 開始檢查 mode 了。那會讓**既有已上線**的 shortcode
	 * 模式賣場網址突然變 404（blast radius 遠大於單一功能），若真的要做，
	 * 請同步更新 TD-13、該 Rule 敘述與 BasicTab 的警語。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_known_gap_shortcode_mode_shop_still_renders_via_public_url(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '短碼模式商品',
				'regular_price' => '500',
			]
		);
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );

		// create_shop() 建立的就是 ShopMode::SHORTCODE
		$shop = $this->repo->find( $shop_id );
		$this->assertNotNull( $shop );
		$this->assertSame( 'shortcode', $shop->mode->value, '前置：本測試需要 shortcode 模式的賣場' );

		// 走「獨立頁面」那條渲染路徑（= 公開網址會看到的內容）
		$html = $this->render_template_as_renderer( $shop );

		$this->assertStringContainsString(
			'短碼模式商品',
			$html,
			'現況：shortcode 模式的賣場，公開網址一樣渲染得出商品（TD-13）'
		);
	}

	/**
	 * 🔒 現況鎖：呈現區塊全部不勾時，**公開網址仍回得了內容**（只是沒有商品）
	 *
	 * 規格 Rule「已知落差（存取）- 全部不勾只藏內容，公開網址仍然開得起來」（TD-46）。
	 *
	 * 「什麼都不顯示」是**呈現設定**，不是存取控制——賣場標題與站長自訂內容照印。
	 * 改成 404 會讓兩條渲染路徑的輸出分歧，直接打破
	 * `test_renderer_and_shortcode_paths_render_identical_dom`
	 * （shortcode 情境不可能對宿主頁面回 404）。
	 *
	 * ⚠️ 轉紅代表有人把它改成擋存取了，請同步更新 TD-46 與該 Rule 敘述。
	 *
	 * @test
	 * @group edge
	 */
	public function test_known_gap_empty_display_parts_still_render_page_shell(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '不該出現的商品',
				'regular_price' => '500',
			]
		);
		$shop_id = $this->create_shop(
			[ $this->item_for( $product->get_id() ) ],
			'publish',
			[
				// 已結束販售 + 三個呈現區塊全部不勾
				'endTime'      => 1000000000000,
				'afterDisplay' => [],
			]
		);

		$shop = $this->repo->find( $shop_id );
		$this->assertNotNull( $shop );
		$html = $this->render_template_as_renderer( $shop );

		// 商品不印（呈現設定生效）
		$this->assertStringNotContainsString( '不該出現的商品', $html, '全部不勾時不得印出商品' );
		// 但頁面殼仍在（沒有變成 404 / 空字串）
		$this->assertNotSame( '', trim( $html ), '現況：全部不勾只是不顯示內容，頁面本身仍然渲染（TD-46）' );
		$this->assertStringContainsString( 'ps-profit-shop-front', $html, '賣場容器仍應存在' );
	}

	/**
	 * 🔒 現況鎖：變體層灌水購買人數**沒有任何前台顯示點**
	 *
	 * 規格 Rule「已知落差（前台）- 變體層灌水購買人數目前沒有任何前台顯示點」（TD-15）。
	 *
	 * 商品層的 `inflated_count` 會加進卡片的「已售 N 件」，變體層的則是
	 * 「存得進去、讀得出來、沒人用」——賣場前台只渲染父商品卡片，
	 * 變體選擇整段發生在 Modal 內，而 Modal 沒有對應的顯示位置。
	 *
	 * ⚠️ 轉紅代表補上顯示點了，請同步更新 TD-15 與該 Rule 敘述。
	 *
	 * @test
	 * @group edge
	 */
	public function test_known_gap_variation_inflated_count_has_no_frontend_display(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '變體灌水測試商品',
				'regular_price' => '500',
			]
		);

		// 商品層灌水 0、變體層灌水 999——若前台有顯示點，999 會出現在輸出裡
		$item    = new OverrideItem(
			product_id: $product->get_id(),
			override: new PriceOverride( null, null, null ),
			inflated_count: new InflatedCount( 0 ),
			variations: [
				12345 => new VariationOverride(
					new PriceOverride( null, null, null ),
					new InflatedCount( 999 )
				),
			]
		);
		$shop_id = $this->create_shop( [ $item ] );

		$shop = $this->repo->find( $shop_id );
		$this->assertNotNull( $shop );
		$html = $this->render_template_as_renderer( $shop );

		// ⚠️ 不可用裸數字 '999' 斷言——toast 的 style="z-index:9999" 會誤中。
		// 顯示點若存在，會以「已售 N 件」的形式出現（與商品層同一個文案）。
		$this->assertStringNotContainsString(
			'已售 999',
			$html,
			'現況：變體層灌水購買人數在前台沒有任何顯示點（TD-15）'
		);
		// 商品層灌水為 0 且無真實銷量 ⇒ 整個「已售」區塊都不該出現
		$this->assertStringNotContainsString(
			'已售',
			$html,
			'商品層灌水 0 + 無真實銷量時不印已售區塊；變體層的 999 也不該從任何管道漏出來'
		);
	}

	// ====================================================================
	//  一頁多賣場：容器邊界必須把兩個賣場的內容完全隔開
	// ====================================================================

	/**
	 * 🔴 同一份輸出內放兩個不同賣場的短碼，兩者不得互相污染
	 *
	 * ── 為什麼這條重要 ────────────────────────────────────────
	 * 前台 JS 的查找一律限定在**最近的 `.ps-profit-shop-front` 祖先內**，
	 * 「一頁多賣場」正是那個設計要解決的問題——早期以 `wp_localize_script`
	 * 輸出的全域資料物件會互相覆蓋，第二個賣場的資料會蓋掉第一個。
	 *
	 * 這個保證若破了，症狀是點 A 賣場的卡片開出 B 賣場的商品 Modal、
	 * 加購表單帶著 B 賣場的 `profit_shop_id` 送出 ⇒ **分潤歸錯夥伴**，
	 * 而且畫面看起來完全正常、零錯誤訊息。錢會進到錯的人手上。
	 *
	 * ── 為什麼只測 shortcode 路徑 ─────────────────────────────
	 * 多賣場**在結構上只可能發生在 shortcode 路徑**：獨立頁面路徑
	 * （`ProfitShopRenderer`）由網址的單一 slug 決定賣場、渲染完即 `exit`，
	 * 一個請求不可能輸出兩個賣場。因此這條不需要、也無法有 renderer 版本。
	 *
	 * ── 三個斷言主體 ─────────────────────────────────────────
	 *   1. 兩個各自帶不同 `data-ps-shop` 的容器，各恰好一個
	 *   2. 各容器內的卡片與 Modal 只屬於自己的賣場
	 *   3. 各容器內加購表單的 `profit_shop_id` 是自己的賣場
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_two_shops_on_one_page_do_not_leak_into_each_other(): void {
		$product_a = $this->createSimpleProduct(
			[
				'name'          => '多賣場商品 Alpha',
				'regular_price' => '1000',
			]
		);
		$product_b = $this->createSimpleProduct(
			[
				'name'          => '多賣場商品 Bravo',
				'regular_price' => '2000',
			]
		);

		$shop_a = $this->create_shop( [ $this->item_for( $product_a->get_id() ) ] );
		$shop_b = $this->create_shop( [ $this->item_for( $product_b->get_id() ) ] );

		$this->assertNotSame( $shop_a, $shop_b, '測試前置：兩個賣場必須是不同的 ID' );

		// 真實情境：兩個短碼並存於同一份 post_content
		$html = \do_shortcode(
			'[' . ProfitShopShortcode::TAG . ' id="' . $shop_a . '"]'
			. '[' . ProfitShopShortcode::TAG . ' id="' . $shop_b . '"]'
		);

		// 防空斷言：輸出為空時，下面所有 NotContains 都會假綠燈
		$this->assertStringContainsString( '多賣場商品 Alpha', $html, '防空斷言：A 賣場應有輸出' );
		$this->assertStringContainsString( '多賣場商品 Bravo', $html, '防空斷言：B 賣場應有輸出' );

		// ── 斷言 1：兩個容器，各恰好一個 ──────────────────────
		$this->assertSame(
			2,
			substr_count( $html, '<div class="ps-profit-shop-front' ),
			'一頁兩個短碼應輸出兩個賣場容器'
		);
		$this->assertSame(
			1,
			substr_count( $html, 'data-ps-shop="' . $shop_a . '"' ),
			'A 賣場的容器標記應恰好出現一次'
		);
		$this->assertSame(
			1,
			substr_count( $html, 'data-ps-shop="' . $shop_b . '"' ),
			'B 賣場的容器標記應恰好出現一次'
		);

		// ── 斷言 2：各容器內的卡片與 Modal 只屬於自己 ──────────
		$segment_a = $this->segment_for_shop( $html, $shop_a );
		$segment_b = $this->segment_for_shop( $html, $shop_b );

		$card_a  = 'data-ps-product-id="' . $product_a->get_id() . '"';
		$card_b  = 'data-ps-product-id="' . $product_b->get_id() . '"';
		$modal_a = 'data-ps-modal-for="' . $product_a->get_id() . '"';
		$modal_b = 'data-ps-modal-for="' . $product_b->get_id() . '"';

		$this->assertStringContainsString( $card_a, $segment_a, 'A 容器內應有 A 商品的卡片' );
		$this->assertStringContainsString( $modal_a, $segment_a, 'A 容器內應有 A 商品的 Modal' );
		$this->assertStringNotContainsString( $card_b, $segment_a, '🔴 A 容器內不得出現 B 賣場的商品卡片' );
		$this->assertStringNotContainsString( $modal_b, $segment_a, '🔴 A 容器內不得出現 B 賣場的商品 Modal' );

		$this->assertStringContainsString( $card_b, $segment_b, 'B 容器內應有 B 商品的卡片' );
		$this->assertStringContainsString( $modal_b, $segment_b, 'B 容器內應有 B 商品的 Modal' );
		$this->assertStringNotContainsString( $card_a, $segment_b, '🔴 B 容器內不得出現 A 賣場的商品卡片' );
		$this->assertStringNotContainsString( $modal_a, $segment_b, '🔴 B 容器內不得出現 A 賣場的商品 Modal' );

		// ── 斷言 3：加購表單的 profit_shop_id 是自己的賣場 ──────
		$hidden_a = 'name="profit_shop_id" value="' . $shop_a . '"';
		$hidden_b = 'name="profit_shop_id" value="' . $shop_b . '"';

		$this->assertStringContainsString( $hidden_a, $segment_a, 'A 容器的加購表單應帶 A 賣場的 profit_shop_id' );
		$this->assertStringNotContainsString(
			$hidden_b,
			$segment_a,
			'🔴 A 容器的加購表單不得帶 B 賣場的 profit_shop_id——那會讓分潤歸錯夥伴'
		);

		$this->assertStringContainsString( $hidden_b, $segment_b, 'B 容器的加購表單應帶 B 賣場的 profit_shop_id' );
		$this->assertStringNotContainsString(
			$hidden_a,
			$segment_b,
			'🔴 B 容器的加購表單不得帶 A 賣場的 profit_shop_id——那會讓分潤歸錯夥伴'
		);
	}

	// ========== Helpers ==========

	/**
	 * 從「一頁多賣場」的輸出中，切出指定賣場的容器區段
	 *
	 * 以 `data-ps-shop="{id}"` 為起點，切到下一個 `data-ps-shop=` 或字串結尾為止。
	 * 這正是前台 JS 用「最近的 `.ps-profit-shop-front` 祖先」所界定的同一個範圍，
	 * 因此對這個區段做 NotContains 斷言，等同於驗證 JS 查找不可能撈到別的賣場。
	 *
	 * @param string $html    完整輸出
	 * @param int    $shop_id 賣場 ID
	 *
	 * @return string 該賣場的容器區段
	 */
	private function segment_for_shop( string $html, int $shop_id ): string {
		$marker = 'data-ps-shop="' . $shop_id . '"';
		$start  = strpos( $html, $marker );

		$this->assertNotFalse( $start, "輸出中應能找到賣場 {$shop_id} 的容器標記" );

		$next = strpos( $html, 'data-ps-shop="', (int) $start + strlen( $marker ) );

		$segment = false === $next
		? substr( $html, (int) $start )
		: substr( $html, (int) $start, $next - (int) $start );

		$this->assertNotSame( '', $segment, "賣場 {$shop_id} 的容器區段不應為空" );

		return $segment;
	}

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
	/**
	 * 已售件數 = WC 真實銷量 + 賣場設定的灌水購買人數
	 *
	 * ⚠️ 這條在 Phase 9 只做過真實站台人工驗證（實測「已售 880 件」＝ 873 + 7），
	 * 從來沒有自動化測試守著。B7 盤點時被標為真缺口，於此補上。
	 *
	 * 兩個相加的來源刻意取不同數值（7 / 3），避免「只加了其中一項」也剛好通過。
	 *
	 * @test
	 * @group happy
	 */
	public function test_sold_count_is_real_sales_plus_inflated_count(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '已售件數商品',
				'regular_price' => '500',
			]
		);
		// WC 真實銷量
		$product->set_total_sales( 7 );
		$product->save();

		$item = new OverrideItem(
			product_id: $product->get_id(),
			override: new PriceOverride( null, null, null ),
			inflated_count: new InflatedCount( 3 )
		);

		$html = $this->render( $this->create_shop( [ $item ] ) );

		$this->assertStringContainsString( '已售 10 件', $html, '已售件數應為真實銷量 7 + 灌水 3 = 10' );
		$this->assertStringNotContainsString( '已售 7 件', $html, '不得只印真實銷量（漏加灌水數）' );
		$this->assertStringNotContainsString( '已售 3 件', $html, '不得只印灌水數（漏加真實銷量）' );
	}

	/**
	 * 兩者皆為 0 時完全不印「已售」區塊
	 *
	 * `> 0` 守門：全新商品印「已售 0 件」反而是負面訊號。
	 *
	 * @test
	 * @group edge
	 */
	public function test_sold_count_block_is_omitted_when_zero(): void {
		$product = $this->createSimpleProduct(
			[
				'name'          => '零銷量商品',
				'regular_price' => '500',
			]
		);

		$html = $this->render( $this->create_shop( [ $this->item_for( $product->get_id() ) ] ) );

		$this->assertStringNotContainsString( '已售', $html, '真實銷量與灌水數皆為 0 時不得印出已售區塊' );
	}

	/**
	 * 賣場頁必須自行輸出 WooCommerce notices
	 *
	 * 賣場頁不是 WooCommerce 的 template（`is_woocommerce()` 為 false），佈景主題掛在
	 * `woocommerce_before_main_content` 上的 `woocommerce_output_all_notices` **不會**被觸發。
	 * 少了這段，使用者按下「加入購物車」之後畫面除了重新載入之外沒有任何變化，
	 * 功能看起來像是壞掉的。
	 *
	 * 本測試同時鎖住容器 class（`ps-shop-notices`）——那是 template 自印的識別，
	 * 不是 WooCommerce 給的。
	 *
	 * @test
	 * @group happy
	 */
	public function test_shop_page_prints_woocommerce_notices_itself(): void {
		if ( ! \function_exists( 'wc_add_notice' ) || null === \WC()->session ) {
			$this->markTestSkipped( 'WC session 未就緒，無法寫入 notice' );
		}

		$product = $this->createSimpleProduct(
			[
				'name'          => 'Notice 測試商品',
				'regular_price' => '500',
			]
		);

		\wc_add_notice( '已成功加入購物車測試訊息', 'success' );

		$html = $this->render( $this->create_shop( [ $this->item_for( $product->get_id() ) ] ) );

		$this->assertStringContainsString(
			'ps-shop-notices',
			$html,
			'賣場頁必須自印 notices 容器；少了它加購結果對使用者不可見'
		);
		$this->assertStringContainsString(
			'已成功加入購物車測試訊息',
			$html,
			'WC session 內的 notice 必須被印出'
		);
	}

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
	// ====================================================================
	//  WooCommerce 通知：必須由 renderer 先搶先收（TD-49）
	// ====================================================================

	/**
	 * 🔴 renderer 預先渲染好的通知必須被印出來
	 *
	 * TD-49 的根因是「等到 template 才呼叫 `wc_print_notices()` 已經太遲」：
	 * 佈景主題 / 頁面建構器的頁首在 `get_header()` 期間就呼叫過一次，
	 * 而該函式印完會**清空** session ⇒ 執行到 template 時通知已經是空的。
	 * 真實站台實測（Blocksy + Elementor）：get_header 前 1 則、template 內 0 則。
	 *
	 * renderer 因此改在 `get_header()` **之前**把通知渲染成字串收下來，
	 * 再交給 template 印。本條鎖住 template 這一端真的會印。
	 *
	 * @test
	 * @group happy
	 */
	public function test_template_prints_notices_captured_by_renderer(): void {
		$shop_id = $this->create_shop( [] );
		$shop    = CptProfitShopRepository::instance()->find( $shop_id );

		$this->assertInstanceOf( ProfitShop::class, $shop );

		$html = $this->render_template_with_notices( $shop, '<ul class="woocommerce-message">已加入您的購物車</ul>' );

		$this->assertStringContainsString( 'ps-shop-notices', $html, '必須印出通知容器' );
		$this->assertStringContainsString( '已加入您的購物車', $html, 'renderer 收下來的通知必須被印出' );
	}

	/**
	 * 沒有通知時不印空容器
	 *
	 * 空的 `<div class="woocommerce">` 會被佈景主題的 WooCommerce 樣式撐出一段
	 * 莫名其妙的空白，看起來像版面壞掉。
	 *
	 * @test
	 * @group edge
	 */
	public function test_template_prints_no_container_when_captured_notices_are_empty(): void {
		$shop_id = $this->create_shop( [] );
		$shop    = CptProfitShopRepository::instance()->find( $shop_id );

		$this->assertInstanceOf( ProfitShop::class, $shop );

		$html = $this->render_template_with_notices( $shop, '' );

		$this->assertStringNotContainsString( 'ps-shop-notices', $html );
	}

	/**
	 * 🔴 shortcode 路徑（沒有 renderer 預收的通知）必須維持原本行為
	 *
	 * 宿主頁面的 `get_header()` 早就跑完了，搶不到——這條確保那個退路還在，
	 * 而不是被改成「只有 renderer 路徑才會印通知」。
	 *
	 * @test
	 * @group edge
	 */
	public function test_shortcode_path_falls_back_to_wc_print_notices(): void {
		if ( ! \function_exists( 'wc_add_notice' ) || null === \WC()->session ) {
			$this->markTestSkipped( 'WC session 未就緒，無法寫入 notice' );
		}

		$shop_id = $this->create_shop( [] );
		$shop    = CptProfitShopRepository::instance()->find( $shop_id );

		$this->assertInstanceOf( ProfitShop::class, $shop );

		\wc_add_notice( '短碼路徑的通知', 'success' );

		// 不傳 $ps_notices_html ⇒ 走 template 內建的 wc_print_notices()
		$html = $this->render_template_as_renderer( $shop );

		$this->assertStringContainsString( '短碼路徑的通知', $html );
	}

	/**
	 * 以「renderer 已預收通知」的方式渲染 template
	 *
	 * @param ProfitShop $shop          賣場
	 * @param string     $notices_html  預先渲染好的通知 HTML
	 *
	 * @return string
	 */
	private function render_template_with_notices( ProfitShop $shop, string $notices_html ): string {
		$shop_for_template = $shop;
		$front_settings    = ProfitShopFrontData::settings( $shop );
		$ps_notices_html   = $notices_html;

		$class_dir = \dirname( (string) ( new \ReflectionClass( ProfitShopShortcode::class ) )->getFileName() );

		\ob_start();
		require $class_dir . '/templates/profit-shop-front.php';

		return (string) \ob_get_clean();
	}

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
