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
 *   8. 旗標未定義時 template 仍用 <main>（ProfitShopRenderer 路徑零變化）
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
			'<div class="ps-profit-shop-front"',
			$html,
			'shortcode 情境外層應為 <div>'
		);
		$this->assertStringNotContainsString( '<main', $html, 'shortcode 輸出不得含 <main>（巢狀 main 是無效 HTML）' );
		$this->assertStringNotContainsString( '</main>', $html, 'shortcode 輸出不得含 </main>' );
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
	 * 【回歸鎖】$is_shortcode_context 未定義時，template 仍必須用 <main>
	 *
	 * 這條保護 ProfitShopRenderer（獨立頁面路徑）的輸出不因 shortcode 旗標而改變。
	 * 刻意直接 require template、不設旗標，模擬 renderer 的變數環境。
	 *
	 * @test
	 * @group edge
	 */
	public function test_template_defaults_to_main_wrapper_without_flag(): void {
		$product = $this->createSimpleProduct( [ 'regular_price' => '1000' ] );
		$shop_id = $this->create_shop( [ $this->item_for( $product->get_id() ) ] );
		$shop    = $this->repo->find( $shop_id );
		$this->assertInstanceOf( ProfitShop::class, $shop, '測試前置：賣場應可讀回' );

		$html = $this->render_template_without_flag( $shop );

		$this->assertStringContainsString(
			'<main class="ps-profit-shop-front"',
			$html,
			'旗標未定義時（ProfitShopRenderer 路徑）外層必須維持 <main>'
		);
		$this->assertStringContainsString( '</main>', $html, '關閉標籤也必須是 </main>' );
		$this->assertStringNotContainsString( '<div class="ps-profit-shop-front"', $html );
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
	 * @param OverrideItem[] $items  商品覆寫項目
	 * @param string         $status 賣場狀態
	 *
	 * @return int 賣場 ID
	 */
	private function create_shop( array $items, string $status = 'publish' ): int {
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
			settings: []
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
	 * 直接 require template 但不設 $is_shortcode_context，模擬 ProfitShopRenderer 的變數環境
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 *
	 * @return string template 輸出
	 */
	private function render_template_without_flag( ProfitShop $shop ): string {
		$shop_for_template = $shop;

		// 以 class 檔案位置反推 template 路徑，避免寫死相對層數（測試檔搬家就會壞）
		$class_dir = \dirname( (string) ( new \ReflectionClass( ProfitShopShortcode::class ) )->getFileName() );

		\ob_start();
		require $class_dir . '/templates/profit-shop-front.php';

		return (string) \ob_get_clean();
	}
}
