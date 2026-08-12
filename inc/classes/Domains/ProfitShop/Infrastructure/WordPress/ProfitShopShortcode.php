<?php
/**
 * Profit Shop Shortcode
 *
 * 補上 ShopMode::SHORTCODE 的實際實作（specs/tech-debt.md TD-13 的前半）。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 賣場 shortcode：把分潤賣場商品列表嵌入任意頁面內容
 *
 * 用法：[power_shop_profit_shop id="2225"]（id = powershop CPT 的 post ID）
 *
 * 為何用 id 而非 slug：與後台列表頁的資料一致（列表頁一定有 id，slug 可能為空，
 * 見 specs/tech-debt.md TD-14），且 slug 可被站長改動、id 不會。
 *
 * 設計原則：
 * - 與 ProfitShopRenderer 共用同一份 template（templates/profit-shop-front.php），
 *   且**渲染完全相同的 DOM**：外層一律 `<div>`（宿主頁面已有自己的 `<main>`，而 HTML spec
 *   規定一份文件內只能有一個非 hidden 的 main；獨立頁面走 theme 的 header.php 時同樣可能
 *   已經有 `<main>`，所以兩條路徑統一用 `<div>` 才是唯一安全的選擇）。
 *   Phase 14 起 `$is_shortcode_context` 旗標已移除——它是兩條路徑 DOM 分歧的唯一來源
 * - 賣場設定（`$front_settings`）與前台互動層（`ProfitShopFrontView::enqueue()`）
 *   兩條路徑都必須提供，缺一會讓 shortcode 賣場的販售狀態與商品 Modal 失效
 * - status 檢查與 ProfitShopRenderer::maybe_render 同一條紀律：
 *   publish 對外可見；非 publish 僅「對該 post 有 edit 能力」的使用者可預覽
 * - shortcode 一律 return 字串、永不 echo，且任何失敗都不得拋例外把宿主頁面弄壞
 * - 不注入 SEO meta（<title> / canonical），宿主頁面自己有；注入會造成雙 title
 *
 * ⚠️ 本 class 只實作「shortcode 拿得到、貼得上、渲染得出來」這一半。
 * TD-13 的另一半（ProfitShopRenderer 對 mode=shortcode 的賣場回 404，讓公開網址
 * 真的關掉）刻意不在此處理：既有已上線的 shortcode 模式賣場前台網址會突然變 404，
 * blast radius 過大。現況維持「shortcode 可用，且 /profit-shop/{slug}/ 依然開得起來」。
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md / specs/tech-debt.md TD-13
 */
final class ProfitShopShortcode {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Shortcode tag（已定案契約，前端「複製 shortcode」按鈕依賴此字串）
	 */
	public const TAG = 'power_shop_profit_shop';

	/**
	 * Repository（建構子 DI）
	 *
	 * @var ProfitShopRepositoryInterface
	 */
	private ProfitShopRepositoryInterface $shops;

	/**
	 * Constructor
	 *
	 * 不加 is_admin() guard：Gutenberg / Elementor 的後台預覽會透過 ajax 或
	 * 後台 render 走 do_shortcode，加 guard 會讓編輯器預覽變空白。
	 *
	 * @param ProfitShopRepositoryInterface $shops 賣場 Repository（DIP 注入）
	 */
	public function __construct( ProfitShopRepositoryInterface $shops ) {
		$this->shops = $shops;
		\add_shortcode( self::TAG, [ $this, 'render' ] );
	}

	/**
	 * Shortcode callback
	 *
	 * 一律回傳字串。驗證失敗時：
	 * - 有 edit_posts 能力（站長在看）→ 回一小段提示訊息，方便 debug
	 * - 一般訪客 → 空字串（不洩漏賣場是否存在）
	 *
	 * @param array<string, mixed>|string $atts shortcode 屬性（WP 在無屬性時傳空字串而非陣列）
	 *
	 * @return string 賣場 HTML；驗證失敗回空字串或站長專屬提示
	 */
	public function render( $atts ): string {
		$parsed = \shortcode_atts(
			[ 'id' => '' ],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);

		$shop_id = $this->parse_shop_id( $parsed['id'] );
		if ( $shop_id <= 0 ) {
			return $this->admin_hint(
				\sprintf(
					/* translators: %s: shortcode 用法範例 */
					\__( '分潤賣場 shortcode 缺少有效的 id 參數，正確用法：%s', 'power_shop' ),
					'[' . self::TAG . ' id="123"]'
				)
			);
		}

		$shop = $this->try_find_shop( $shop_id );
		if ( ! $shop instanceof ProfitShop ) {
			// 「找不到 / 已刪除」與「未發佈且無預覽權限」刻意共用同一句訊息：
			// 對只有 edit_posts（例如 Author）的使用者不區分兩者，避免洩漏草稿賣場的存在性。
			// 真正的站長（對該 post 有 edit_post 能力）看到的是渲染出來的草稿預覽，不受影響。
			return $this->admin_hint(
				\sprintf(
					/* translators: %d: 賣場 ID */
					\__( '找不到可顯示的分潤賣場（ID：%d），請確認賣場是否存在且已發佈。', 'power_shop' ),
					$shop_id
				)
			);
		}

		return $this->render_template( $shop );
	}

	/**
	 * 解析並嚴格驗證 id 屬性
	 *
	 * 與 AddToCartHook::extract_shop_id_from_request 同一條紀律：ctype_digit 純數字檢查，
	 * 拒 '01' / '+1' / '1.0' / 負數 / 空白 —— 避免「看起來像數字」的字串被 (int) 悄悄轉成合法 ID。
	 *
	 * @param mixed $raw shortcode 傳入的 id 屬性原始值
	 *
	 * @return int 合法的正整數 ID；不合法回 0
	 */
	private function parse_shop_id( $raw ): int {
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return 0;
		}

		$str = trim( (string) $raw );
		if ( '' === $str || ! ctype_digit( $str ) ) {
			return 0;
		}

		// ctype_digit 已保證純數字，但 '007' 這種前導零仍應拒絕（避免同一賣場有多種寫法）。
		if ( '0' === $str[0] ) {
			return 0;
		}

		$id = (int) $str;
		return $id > 0 ? $id : 0;
	}

	/**
	 * 取得賣場並套用 status 可見性規則
	 *
	 * @param int $shop_id 賣場 ID
	 *
	 * @return ProfitShop|null 可顯示時回 ProfitShop；不存在 / trashed / 無預覽權限回 null
	 */
	private function try_find_shop( int $shop_id ): ?ProfitShop {
		try {
			// Repository 對 trashed / 非 powershop CPT 一律回 null。
			$shop = $this->shops->find( $shop_id );
		} catch ( \Throwable $e ) {
			$this->log_failure( \sprintf( 'find shop %d failed', $shop_id ), $e );
			return null;
		}

		if ( ! $shop instanceof ProfitShop ) {
			return null;
		}

		// publish 對外可見；非 publish 僅「對該 post 有 edit 能力」者可預覽。
		// 用 edit_post（特定 post id，含 ownership 檢查）而非 edit_posts，
		// 與 ProfitShopRenderer::maybe_render 保持同一條 least-privilege 紀律。
		if ( 'publish' !== $shop->status() && ! \current_user_can( 'edit_post', $shop->id ) ) {
			return null;
		}

		return $shop;
	}

	/**
	 * 以 output buffer 渲染共用 template 並回傳字串
	 *
	 * Shortcode 必須 return 而非 echo，否則內容會被印到頁面最前面（在 the_content 之外）。
	 *
	 * @param ProfitShop $shop 已驗證的賣場聚合根
	 *
	 * @return string 賣場 HTML；渲染過程拋例外時回空字串（不讓宿主頁面壞掉）
	 */
	private function render_template( ProfitShop $shop ): string {
		/*
		* template 靠這兩個變數運作，**兩者都必須與 ProfitShopRenderer::render_shop 一致**。
		*
		* $front_settings 早期只有 renderer 會傳，shortcode 路徑因此拿到空陣列，
		* `shopStatus` 一律 fallback 成 'published'：已結束販售（或尚未開賣）的賣場
		* 用 shortcode 嵌入時，卡片與 Modal 照樣可以加購。這是實質的商業邏輯漏洞，
		* 不只是「外觀不一致」。
		*/
		$shop_for_template = $shop;
		$front_settings    = ProfitShopFrontData::settings( $shop );

		/*
		* 前台互動層。shortcode 在 `the_content` 內渲染，此時 `wp_head` 已跑完但
		* `wp_footer` 尚未執行，因此 in-footer 的 script 仍然印得出來。
		*
		* 早期 shortcode 路徑完全沒有 enqueue，賣場的商品 Modal 根本開不起來——
		* 使用者只會看到卡片，點下去是直接送出加購（等同無 JS 的降級路徑）。
		*/
		ProfitShopFrontView::enqueue();

		\ob_start();
		try {
			require __DIR__ . '/templates/profit-shop-front.php';
		} catch ( \Throwable $e ) {
			\ob_end_clean();
			$this->log_failure( \sprintf( 'render shop %d failed', $shop->id ), $e );
			return '';
		}

		return (string) \ob_get_clean();
	}

	/**
	 * 產生「僅站長可見」的除錯提示
	 *
	 * 一般訪客一律拿到空字串——既不洩漏賣場存在性，也不會在正式頁面上出現錯誤訊息。
	 *
	 * @param string $message 提示內容（呼叫端已用 __() 翻譯，此處負責 escape）
	 *
	 * @return string 提示 HTML；無 edit_posts 能力時回空字串
	 */
	private function admin_hint( string $message ): string {
		if ( ! \current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return \sprintf(
			'<div class="ps-profit-shop-shortcode-notice" style="background:#fff2f0;border:1px solid #ffccc7;border-radius:6px;padding:12px 16px;color:#a8071a;font-size:0.9em;">%s</div>',
			\esc_html( $message )
		);
	}

	/**
	 * 寫入錯誤 log（過濾 \r \n \t 防 log injection）
	 *
	 * @param string     $context 情境描述
	 * @param \Throwable $e       例外
	 *
	 * @return void
	 */
	private function log_failure( string $context, \Throwable $e ): void {
		$safe_message = (string) preg_replace( "/[\r\n\t]/", ' ', $e->getMessage() );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log(
			\sprintf(
				'[ProfitShop][Shortcode] %s: %s',
				$context,
				$safe_message
			)
		);
	}
}
