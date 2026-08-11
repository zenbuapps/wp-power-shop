<?php
/**
 * Profit Shop 前台 Renderer
 *
 * Phase 4-C1：分潤賣場前台 /profit-shop/{slug}/ 頁面輸出。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\ClientIpProviderInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PageRateLimitService;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;

/**
 * 賣場前台 HTML renderer
 *
 * 攔截 /profit-shop/{slug}/ URL，走 theme 整合（get_header / get_footer），
 * 輸出商品列表與加入購物車按鈕。
 *
 * 設計原則：
 * - template_redirect priority 9（早於 theme template_include 的 default 11）
 * - 與 PartnerPortalRenderer 不同：要走 theme 整合，由 get_header/get_footer 包覆
 * - 只在 query var profit_shop_slug 非空時介入
 * - rate-limit（Phase 5-A.1）在 Repository 查詢之前，避免攻擊者用 DB 查詢消耗資源
 * - 賣場不存在 / status 不為 publish → 404（admin 可預覽 draft）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md / Phase 4-C1 / Phase 5-A.1
 */
final class ProfitShopRenderer {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Page key（rate-limit namespace 區隔）
	 */
	private const PAGE_KEY = 'profit_shop';

	/**
	 * Repository（建構子 DI）
	 *
	 * @var ProfitShopRepositoryInterface
	 */
	private ProfitShopRepositoryInterface $shops;

	/**
	 * IP rate-limit Service（Phase 5-A.1）
	 *
	 * @var PageRateLimitService
	 */
	private PageRateLimitService $rate_limit;

	/**
	 * Client IP Provider（Phase 5-A.1）
	 *
	 * @var ClientIpProviderInterface
	 */
	private ClientIpProviderInterface $ip_provider;

	/**
	 * Constructor
	 *
	 * 掛在 template_redirect priority 9，早於 theme template_include。
	 *
	 * Phase 5-A.1：增注 PageRateLimitService / ClientIpProviderInterface（前台 DoS 緩解）。
	 *
	 * @param ProfitShopRepositoryInterface $shops       賣場 Repository（DIP 注入）
	 * @param PageRateLimitService          $rate_limit  IP rate-limit service
	 * @param ClientIpProviderInterface     $ip_provider Client IP 來源
	 */
	public function __construct(
		ProfitShopRepositoryInterface $shops,
		PageRateLimitService $rate_limit,
		ClientIpProviderInterface $ip_provider
	) {
		$this->shops       = $shops;
		$this->rate_limit  = $rate_limit;
		$this->ip_provider = $ip_provider;
		\add_action( 'template_redirect', [ $this, 'maybe_render' ], 9 );
	}

	/**
	 * 檢查 query var 並決定是否輸出賣場頁面
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$raw = (string) \get_query_var( RewriteRules::SHOP_QUERY_VAR, '' );
		if ( '' === $raw ) {
			return; // 不是賣場路徑，fall through to theme.
		}

		// Sanitize slug（雙保險，雖然 WP 已過 query var filter）。
		$slug = \sanitize_title( $raw );
		if ( '' === $slug ) {
			$this->render_404();
			exit;
		}

		// Phase 5-A.1：rate-limit 檢查（在 Repository 查詢之前，省 DB query）
		try {
			$this->rate_limit->check_or_throw( $this->ip_provider->get_ip(), self::PAGE_KEY );
		} catch ( TooManyAttempts $e ) {
			$this->render_429( $e );
			exit;
		}

		$shop = $this->shops->find_by_slug( $slug );
		if ( ! $shop instanceof ProfitShop ) {
			$this->render_404();
			exit;
		}

		// publish 對外可見；draft 僅可由「對特定 post 有 edit 能力」的使用者預覽。
		// edit_post (specific id) 走 WP capability mapping，含 ownership 檢查；
		// 比 edit_posts (any author 都過) 更精準，符合 least-privilege 原則。
		if ( 'publish' !== $shop->status() && ! \current_user_can( 'edit_post', $shop->id ) ) {
			$this->render_404();
			exit;
		}

		$this->render_shop( $shop );
		exit;
	}

	/**
	 * 輸出 429 Too Many Requests（Phase 5-A.1 自決 Q2=A）
	 *
	 * 純文字回應 + Retry-After header，不走 theme，避免被攻擊者用 rate-limit 觸發 theme render 消耗資源。
	 *
	 * @param TooManyAttempts $e Domain Exception，含 retry_after 秒數
	 *
	 * @return void
	 */
	private function render_429( TooManyAttempts $e ): void {
		\status_header( 429 );
		\header( 'Retry-After: ' . $e->getRetryAfter() );
		\nocache_headers();
		\header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'Too Many Requests';
	}

	/**
	 * 輸出 404
	 *
	 * 不走 theme，避免 partial-render 造成 SEO 雜訊。
	 *
	 * @return void
	 */
	private function render_404(): void {
		\status_header( 404 );
		\nocache_headers();

		$title   = \__( '找不到賣場', 'power_shop' );
		$message = \__( '請確認連結是否正確。', 'power_shop' );
		?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo \esc_html( $title ); ?></title>
<style>body{font-family:sans-serif;text-align:center;padding:80px 20px;}h1{font-size:2em;color:#999;}p{color:#666;}</style>
</head>
<body>
<h1><?php echo \esc_html( $title ); ?></h1>
<p><?php echo \esc_html( $message ); ?></p>
</body>
</html>
		<?php
	}

	/**
	 * 輸出賣場頁面（走 theme 整合）
	 *
	 * 流程：
	 * 1. 在 wp_head 注入 SEO meta（title / canonical）並移除 default rel_canonical
	 * 2. get_header() → template body → get_footer()
	 * 3. exit（避免再走 theme 的 single 流程）
	 *
	 * @param ProfitShop $shop 已驗證的賣場聚合根
	 *
	 * @return void
	 */
	private function render_shop( ProfitShop $shop ): void {
		// 注入 SEO meta（必須在 get_header 前 add_action）。
		\add_action(
			'wp_head',
			function () use ( $shop ): void {
				$this->print_seo_meta( $shop );
			},
			1
		);

		// 我們會自己印 canonical，避免 theme/WP 印另一條造成兩條 canonical。
		\remove_action( 'wp_head', 'rel_canonical' );

		// theme 若 add_theme_support('title-tag') 會在 wp_head priority 1 印 <title>，
		// 我們也在 priority 1 印自己的 <title>，移除以避免雙 title。
		\remove_action( 'wp_head', '_wp_render_title_tag', 1 );

		// Phase 5-B / reviewer LOW-3：publish 不送 nocache，讓 CDN / cache plugin
		// 能快取賣場頁，提升大流量場景效能。draft 預覽 / 非 publish → 仍送 nocache。
		//
		// Trade-off：CDN 快取後若 admin 即時下架 shop（status: publish → draft），
		// TTL 過期前訪客仍會看到舊頁。建議搭配 cache plugin 提供的 purge hook，
		// 在 publish/unpublish use case 完成後手動 invalidate（待 Phase 5-D 接 hook，可 defer）。
		//
		// 對登入用戶（admin bar 等個人化）：一般 cache plugin 預設對 logged-in 自動 bypass cache，
		// 故無需額外條件化；PartnerPortalRenderer 因 partner cookie 變動需 nocache，行為不同 → 不動。
		if ( 'publish' !== $shop->status() ) {
			\nocache_headers();
		}

		// 前台顯示設定（主色 / 販售狀態 / 庫存與購買人數開關）。
		// ⚠ 這個變數**必須**傳給 template：缺了它 `shopStatus` 會 fallback 成
		// 'published'，已結束販售的賣場照樣能加購。ProfitShopShortcode 也必須傳。
		$front_settings = ProfitShopFrontData::settings( $shop );

		// 前台互動層（手寫 vanilla JS，不走 Vite）。必須在 get_header() 之前 enqueue，
		// wp_footer 才印得到 script。樣式由 powerhouse 的 front.min.css 提供，本外掛無 CSS。
		ProfitShopFrontView::enqueue();

		/*
		* 🔴 把全域 $post / 主查詢設定成這篇賣場，**必須在任何 `the_content` 之前**。
		*
		* 這是 TD-28（Elementor 版型不生效）的根因：頁面建構器掛在 `the_content` 上，
		* 但它們一律用 `get_the_ID()` / `get_queried_object_id()` 去查「這篇是不是我排版的」——
		* 例如 Elementor 的 `Frontend::apply_builder_in_content()` 會呼叫
		* `Plugin::$instance->documents->get_doc_for_frontend( get_the_ID() )`。
		*
		* 而本 renderer 是自訂流程、不走 theme 的 single 樣板，主查詢從來沒有解析到這篇 post
		* ⇒ `get_the_ID()` 回 false ⇒ 建構器直接放棄，`the_content` 只剩原始標記
		* ⇒ 站長看到的是一堆沒被渲染的 shortcode / 註解，而**沒有任何錯誤訊息**。
		*
		* 補上這段之後，`the_content` 在「正確的 post 上下文」內執行，
		* 建構器與其他吃 `the_content` 的外掛都能正常運作。
		*/
		$this->setup_shop_post_context( $shop );

		$theme_template = $this->resolve_theme_template( $shop );

		if ( '' !== $theme_template ) {
			$this->render_via_theme_template( $shop, $front_settings, $theme_template );
			return;
		}

		/*
		 * 🔴 WooCommerce 通知必須在 `get_header()` **之前**就收下來。
		 *
		 * 這是 TD-49（原生表單加購成功後沒有提示）的根因。實測（turbo.local，
		 * Blocksy + Elementor + WP Rocket）在 renderer 兩個位置各夾一個探針：
		 *
		 *     get_header() 之前 → wc_notice_count() = 1   ✔ 通知確實產生了
		 *     template 內      → wc_notice_count() = 0   ✘ 已經被吃掉
		 *
		 * `wc_print_notices()` 印完會**清空** session 內的通知，而佈景主題 / 頁面
		 * 建構器的頁首（購物車面板、fragments 之類）在 `get_header()` 期間就呼叫過
		 * 它一次；那次的輸出又落在一個不會顯示的地方 ⇒ 通知就這樣消失了，
		 * 頁面上完全找不到，而且**沒有任何錯誤訊息**。
		 *
		 * 先搶先贏是唯一穩健的解：這裡先把它渲染成字串收起來，後面誰再呼叫
		 * `wc_print_notices()` 都只會拿到空的，不會有重複顯示的問題。
		 *
		 * ⚠️ shortcode 路徑不適用（宿主頁面的 `get_header()` 早就跑完了），
		 *    因此 template 對「沒收到這個變數」的情況仍保留原本的 `wc_print_notices()`。
		 */
		$ps_notices_html = $this->capture_wc_notices();

		\get_header();

		// 把 $shop 傳給 template；變數名故意取 $shop_for_template 以避免污染外層 scope。
		$shop_for_template = $shop;
		require $this->resolve_template_path();

		\get_footer();
	}

	/**
	 * 把目前的 WooCommerce 通知渲染成 HTML 字串並清空 session
	 *
	 * 回傳空字串代表「沒有通知」或「WooCommerce 尚未就緒」，
	 * template 兩種情況都是不印容器。
	 *
	 * @return string
	 */
	private function capture_wc_notices(): string {
		if ( ! \function_exists( 'wc_print_notices' ) || ! \function_exists( 'WC' ) ) {
			return '';
		}

		if ( null === \WC()->session || 0 === \wc_notice_count() ) {
			return '';
		}

		\ob_start();
		\wc_print_notices();

		return (string) \ob_get_clean();
	}

	/**
	 * 讓全域 post / 主查詢指向這篇賣場
	 *
	 * 只動「判斷這篇是誰」所需的最小欄位，不去偽造整個 loop 狀態——
	 * 動太多會讓佈景主題誤以為在跑一般文章頁而印出上一篇 / 下一篇、留言區等等。
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 *
	 * @return void
	 */
	private function setup_shop_post_context( ProfitShop $shop ): void {
		$shop_post = \get_post( $shop->id );

		if ( ! $shop_post instanceof \WP_Post ) {
			return;
		}

		global $post, $wp_query;

		$post = $shop_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		\setup_postdata( $shop_post );

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->queried_object    = $shop_post;
			$wp_query->queried_object_id = (int) $shop_post->ID;
		}
	}

	/**
	 * 解析賣場 template 的實體路徑（可被 hook 覆寫）
	 *
	 * 讓佈景主題或其他外掛整個換掉賣場的 PHP template，而不必改本外掛的程式碼。
	 *
	 * ⚠️ 覆寫的檔案會拿到 `$shop_for_template` 與 `$front_settings` 兩個變數，
	 *    並且**必須**自行遵守販售期間的判斷（非販售期間不可印出可送出的加購表單）——
	 *    `AddToCartHook` 的閘門雖然也擋販售期間，但把表單印出來仍會讓顧客按了才被拒。
	 *
	 * 回傳的路徑不存在時**退回內建 template**，不讓一個手誤的路徑把整個賣場頁打成白畫面。
	 *
	 * @return string 實體檔案路徑
	 */
	private function resolve_template_path(): string {
		$default = __DIR__ . '/templates/profit-shop-front.php';

		/**
		 * 覆寫賣場前台的 PHP template
		 *
		 * @param string $template 預設 template 的絕對路徑
		 */
		$template = (string) \apply_filters( 'power_shop_profit_shop_template', $default );

		return ( '' !== $template && \file_exists( $template ) ) ? $template : $default;
	}

	/**
	 * 解析站長選擇的佈景主題範本（頁面 / 文章範本）
	 *
	 * 站長在賣場編輯頁選了範本時回傳其實體路徑，未選（或檔案不存在）一律回空字串
	 * ⇒ 走既有的 `get_header()` + 內建 template 流程，**預設行為完全不變**。
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 *
	 * @return string 範本實體路徑；未使用時為空字串
	 */
	private function resolve_theme_template( ProfitShop $shop ): string {
		$settings = $shop->settings;
		$slug     = isset( $settings['pageTemplate'] ) ? (string) $settings['pageTemplate'] : '';

		if ( '' === $slug || 'default' === $slug ) {
			return '';
		}

		// 只接受佈景主題**真的登記過**的範本檔名，避免站長（或竄改的請求）
		// 指向任意路徑而讀到不該讀的 PHP 檔。
		$allowed = \wp_get_theme()->get_page_templates( null, 'page' )
		+ \wp_get_theme()->get_page_templates( null, 'post' );

		if ( ! isset( $allowed[ $slug ] ) ) {
			return '';
		}

		$path = \locate_template( $slug );

		return \is_string( $path ) && '' !== $path ? $path : '';
	}

	/**
	 * 以佈景主題範本渲染賣場
	 *
	 * 範本自己會呼叫 `get_header()` / `the_content()` / `get_footer()`，
	 * 因此本方法**只負責兩件事**：把賣場內容接到 `the_content` 後面、載入範本。
	 *
	 * 🔴 賣場內容以 `the_content` **附加**而非取代：站長用頁面建構器排的版型
	 *    要留在原地，商品 grid 接在它後面。取代掉等於把站長的排版丟掉，
	 *    而那正是選擇範本的人最在意的東西。
	 *
	 * @param ProfitShop           $shop           賣場聚合根
	 * @param array<string, mixed> $front_settings 前台顯示設定
	 * @param string               $template       範本實體路徑
	 *
	 * @return void
	 */
	private function render_via_theme_template( ProfitShop $shop, array $front_settings, string $template ): void {
		$shop_id = (int) $shop->id;

		$append = function ( $content ) use ( $shop, $front_settings, $shop_id ) {
			// 只接在**這篇賣場自己**的內容後面：範本可能還會對側邊欄、
			// 相關文章等其他 post 跑 the_content，接錯地方會讓商品 grid 到處冒出來。
			if ( (int) \get_the_ID() !== $shop_id ) {
				return $content;
			}

			// 同一篇在某些範本會被跑兩次（例如摘要 + 內文），第二次不再附加。
			static $done = false;
			if ( $done ) {
				return $content;
			}
			$done = true;

			$shop_for_template = $shop;
			// 範本已經印過 post_content，template 內那一段必須關掉，否則整段重複。
			$ps_skip_post_content = true;

			\ob_start();
			require $this->resolve_template_path();

			return $content . (string) \ob_get_clean();
		};

		\add_filter( 'the_content', $append, 20 );

		require $template;

		\remove_filter( 'the_content', $append, 20 );
	}

	/**
	 * 印出 SEO meta（title / canonical）
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 *
	 * @return void
	 */
	private function print_seo_meta( ProfitShop $shop ): void {
		$partner_name = '';
		$partner_term = \get_term( $shop->partner_term_id, 'profit_partner' );
		if ( $partner_term instanceof \WP_Term ) {
			$partner_name = $partner_term->name;
		}

		$site_name = (string) \get_bloginfo( 'name' );
		$title     = '' !== $partner_name
		? \sprintf( '%1$s｜%2$s 的分潤賣場 - %3$s', $shop->title, $partner_name, $site_name )
		: \sprintf( '%1$s - %2$s', $shop->title, $site_name );

		$canonical = \home_url( '/' . RewriteRules::SHOP_REWRITE_PREFIX . '/' . $shop->slug . '/' );

		echo '<title>' . \esc_html( $title ) . "</title>\n";
		echo '<link rel="canonical" href="' . \esc_url( $canonical ) . "\" />\n";
	}
}
