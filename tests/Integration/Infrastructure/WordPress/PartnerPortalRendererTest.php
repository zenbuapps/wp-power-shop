<?php
/**
 * 夥伴報表頁（`/profit-report/{slug}/`）的掛載與 fall-through 契約
 *
 * 對應規格：specs/features/profit-shop/login-partner.feature
 *          specs/ui/partner-report.md
 *
 * 對應實作：inc/classes/.../WordPress/PartnerPortalRenderer.php
 *
 * ═══ ⚠️ 可測範圍的誠實界線（先讀這段再改本檔）═══
 *
 * `maybe_render()` 在**每一條真的要輸出畫面的路徑**結尾都呼叫 `exit`
 * （404 / 429 / 維護中 / 正常渲染）。PHP 的 `exit` 無法被 PHPUnit 攔截，
 * 因此那四條路徑**在同一個 PHP 行程內無法斷言**——這是語言層限制，不是漏寫。
 *
 * 本檔因此只覆蓋**不 exit 的那一條**，而它剛好也是風險最高的一條：
 *
 *   if ( '' === $slug ) { return; }   // 不是報表頁 ⇒ 交還給佈景主題
 *
 * 這一行若壞掉（例如有人把守門改成 `if ( ! isset( $slug ) )`，而 `get_query_var()`
 * 查無時回的是空字串**而不是** null），`maybe_render()` 會在**全站每一個頁面**
 * 往下跑到 `get_term_by()`，查不到 term ⇒ `render_404()` + `exit`
 * ⇒ **整個網站每一頁都變成「找不到分潤頁面」**。
 * 這是本檔唯一能測、但破壞力最大的一條。
 *
 * 其餘四條路徑的驗證分工：
 *   - URL → query var 的對應：`RewriteRulesTest`（已覆蓋）
 *   - 登入 / 報表資料 / 跨夥伴隔離：`PartnerReportEndToEndTest`（已覆蓋）
 *   - 404 / 429 / 維護中三種畫面：**仍未自動化**，只有真實站台看得到
 *
 * 🔴 不要為了測那四條路徑而在 production 加「跳過 exit」的開關——
 *    那等於在正式環境開一個可以讓渲染流程不終止的旁路，
 *    而這支 renderer 的 `exit` 正是「輸出完就停手、不讓後續 hook 覆寫」的保證。
 *
 * @group profit_shop
 * @group infrastructure
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\PartnerPortalRenderer;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
use Tests\Integration\TestCase;

/**
 * 夥伴報表頁掛載測試
 */
final class PartnerPortalRendererTest extends TestCase {

	/**
	 * tearDown：清掉可能殘留的 query var
	 */
	public function tear_down(): void {
		\set_query_var( RewriteRules::QUERY_VAR, '' );

		parent::tear_down();
	}

	/**
	 * 🔴 renderer 必須真的被掛上 `template_redirect`
	 *
	 * 沒掛上的話 `/profit-report/{slug}/` 會直接落到佈景主題 ⇒ 夥伴看到站台的 404 頁，
	 * 而**所有 REST 測試照樣全綠**（那些測的是 API，不是頁面）。
	 * 這條是「頁面存不存在」唯一的自動化保證。
	 *
	 * @test
	 * @group smoke
	 */
	public function test_renderer_is_hooked_on_template_redirect(): void {
		$priority = \has_action( 'template_redirect', [ PartnerPortalRenderer::instance(), 'maybe_render' ] );

		$this->assertNotFalse( $priority, '報表頁 renderer 未掛上 template_redirect' );
		$this->assertSame(
			9,
			$priority,
			'priority 必須早於佈景主題的 template_include；改動前先確認不會與其他 renderer 互搶'
		);
	}

	/**
	 * 🔴 不是報表頁時必須安靜地交還控制權（不輸出、不 exit）
	 *
	 * 本檔唯一測得到、也是破壞力最大的一條。
	 *
	 * 兩個斷言缺一不可：
	 *   - 沒有輸出 ⇒ 沒有污染別人的頁面
	 *   - 這行測試本身跑得完 ⇒ 沒有 `exit`（若守門壞掉走到 render_404()，
	 *     PHPUnit 行程會被 `exit` 直接終止，整輪測試中斷，紅燈形狀非常明確）
	 *
	 * @test
	 * @group smoke
	 */
	public function test_non_report_request_falls_through_without_output(): void {
		\set_query_var( RewriteRules::QUERY_VAR, '' );

		ob_start();
		PartnerPortalRenderer::instance()->maybe_render();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output, '非報表頁不得輸出任何內容' );
		$this->assertTrue( true, '能執行到這一行 = 沒有 exit，控制權已交還佈景主題' );
	}

	/**
	 * 🔴 掛載點 id 是前後端的硬契約，改名會讓報表頁變成一片空白
	 *
	 * `js/src/partner-portal/main.tsx` 用 `document.getElementById()` 找這個 id 掛 React root。
	 * PHP 這邊改名之後，頁面 HTML 照印、bundle 照載、**沒有任何錯誤訊息**，
	 * 只是 React 永遠掛不上去——夥伴看到的是一片空白。
	 *
	 * @test
	 * @group smoke
	 */
	public function test_mount_id_contract_is_stable(): void {
		$this->assertSame(
			'profit_partner_portal',
			PartnerPortalRenderer::MOUNT_ID,
			'改這個常數必須同步改 js/src/partner-portal/main.tsx 的 getElementById'
		);
	}

	/**
	 * query var 常數必須與 rewrite rule 用的是同一個
	 *
	 * 兩邊對不上時 `get_query_var()` 永遠回空字串 ⇒ 報表頁**永遠 fall through**
	 * ⇒ 夥伴一律看到佈景主題的 404，而上面那條 fall-through 測試照樣綠。
	 *
	 * @test
	 * @group smoke
	 */
	public function test_query_var_is_the_one_registered_by_rewrite_rules(): void {
		$this->assertContains(
			RewriteRules::QUERY_VAR,
			(array) \apply_filters( 'query_vars', [] ),
			'報表頁的 query var 未被註冊 ⇒ get_query_var() 永遠取不到值'
		);
	}
}
