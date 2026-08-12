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
	 * `setup_shop_post_context()` 要清成 false 的主查詢條件旗標
	 *
	 * 賣場頁在 WordPress 眼中是一篇單獨的 powershop CPT，以下旗標**一個都不該成立**。
	 * 實測（local-turbo.powerhouse.tw，2026-08-11）修正前只有 `is_home` 為 true，
	 * 其餘皆已是 false；仍逐一列出而非只清 `is_home`，是因為「這一版的 rewrite 規則
	 * 剛好讓 WP 解析成首頁查詢」只是當下的事實，改了 rewrite 就可能換成別的旗標為真。
	 *
	 * ⚠️ `is_page` 也在清單內：powershop 是 `hierarchical => false` 的 CPT，
	 *    WordPress 對它解析出來的是 `is_single`，不是 `is_page`（理由見
	 *    `setup_shop_post_context()` 的 docblock）。
	 *
	 * @var string[]
	 */
	private const QUERY_FLAGS_TO_CLEAR = [
		'is_home',
		'is_posts_page',
		'is_page',
		'is_archive',
		'is_post_type_archive',
		'is_category',
		'is_tag',
		'is_tax',
		'is_author',
		'is_date',
		'is_year',
		'is_month',
		'is_day',
		'is_time',
		'is_search',
		'is_feed',
		'is_comment_feed',
		'is_trackback',
		'is_embed',
		'is_paged',
		'is_attachment',
		'is_404',
	];

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
		* 而本 renderer 是自訂流程、不走 theme 的 single 樣板，主查詢從來沒有解析到這篇 post。
		*
		* 🔴 **實測（turbo.local，2026-08-11，探針夾在 template 內量測）**：
		*
		*     停用本方法 → get_the_ID()=738  get_queried_object_id()=0
		*     啟用本方法 → get_the_ID()=1932 get_queried_object_id()=1932  （1932 = 賣場 post）
		*
		* 注意停用時**不是回 0，是回 738**——某篇完全不相干的文章（全域 $post 的殘留值）。
		* 也就是說建構器不只是「放棄渲染」，它會拿**別人的版型資料**去查，
		* 而這一切沒有任何錯誤訊息。
		*
		* 補上這段之後，`the_content` 在「正確的 post 上下文」內執行，
		* 建構器與其他吃 `the_content` 的外掛都能正常運作。
		*
		* ⚠️ 上面驗的是**前提條件**（建構器查得到正確的 post id）。
		*    「以建構器排版的賣場實際渲染出來長什麼樣」尚未端到端驗證——
		*    測試站台的賣場都不是用建構器排的。
		*/
		$this->setup_shop_post_context( $shop );

		/*
		* 🔴 WooCommerce 通知必須在**任何** `get_header()` 之前就收下來。
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
		* 🔴 **這一行必須排在 `$theme_template` 分支之前**：佈景主題範本自己會呼叫
		*    `get_header()`，捕捉點若留在分支之後，選了範本的賣場就完全走不到這裡，
		*    通知會落回 template 那條 `wc_print_notices()` 退路——而那條退路執行時
		*    範本的 `get_header()` 早就跑完了，正好是上面實測「1 → 0」的那個時機。
		*    症狀與 TD-49 修好之前一模一樣：加購成功卻看不到任何提示。
		*
		* ⚠️ shortcode 路徑不適用（宿主頁面的 `get_header()` 早就跑完了），
		*    因此 template 對「沒收到這個變數」的情況仍保留原本的 `wc_print_notices()`。
		*/
		$ps_notices_html = $this->capture_wc_notices();

		$theme_template = $this->resolve_theme_template( $shop );

		if ( '' !== $theme_template ) {
			$this->render_via_theme_template( $shop, $front_settings, $theme_template, $ps_notices_html );
			return;
		}

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
	 * 讓全域 post **與主查詢**都指向這篇賣場（完整的 single-post 狀態）
	 *
	 * 分三塊：① 這篇是誰 ② 條件旗標 ③ 主查詢裡有什麼。三塊缺一不可，理由如下。
	 *
	 * ## 為什麼「這篇是誰」不夠——③ 是佈景主題範本能不能用的前提
	 *
	 * 佈景主題範本的契約就是「跑主查詢的 loop」（`while ( have_posts() ) : the_post();`）。
	 * 而 `/profit-shop/{slug}/` 的主查詢**從來沒有解析到這篇賣場**——`RewriteRules`
	 * 只塞了一個 `profit_shop_slug` query var，WP 照樣跑「最新文章」那組查詢。
	 *
	 * 🔴 **實測（local-turbo.powerhouse.tw，2026-08-11，探針夾在本方法前後）**：
	 *
	 *     修正前 is_home=true  is_singular=false  post_count=2  posts=738,1  current_post=-1
	 *     修正後 is_home=false is_singular=true   post_count=1  posts=1932   current_post=-1
	 *
	 * 修正前那組數字的後果是：`the_post()` 把全域 `$post` 依序覆寫成 738、1，
	 * `render_via_theme_template()` 的 `get_the_ID() !== $shop_id` 閘門兩次都成立
	 * ⇒ 商品 grid **一次都沒附加**，賣場網址改印那兩篇不相干文章的內容，
	 * 而且 **HTTP 200、零錯誤訊息**。同一組範本實測 `data-ps-product-id` 由 7 變 0。
	 *
	 * ## 為什麼 ② 也是必要的，不只是「chrome 好不好看」
	 *
	 * `WP_Query::generate_postdata()` 決定 `$more` 的判斷式是
	 * `$post->ID === get_queried_object_id() && ( $this->is_page() || $this->is_single() )`
	 * （`wp-includes/class-wp-query.php`，WordPress 7.0.3）。旗標不設，`$more` 為 0，
	 * 站長內容裡的 `<!--more-->` 之後整段會被截掉、只印摘要。
	 * 另外 `is_home` 修正前是 **true**（實測），亦即佈景主題一直把賣場頁當成部落格首頁；
	 * `WP_Query::is_front_page()` 在 `show_on_front = posts` 的站台還會因此回 true。
	 *
	 * ## 用 `is_single` 而不是 `is_page`
	 *
	 * powershop 是 `hierarchical => false` 的自訂 CPT（`CptRegistrar`），WordPress 自己
	 * 對這種 post type 解析出來的就是 `is_single = true` / `is_page = false` / `is_singular = true`。
	 * 偽造 `is_page = true` 可以少掉一些佈景主題的單篇 chrome，但那會造出一個
	 * **WordPress 本身永遠不會產生**的狀態（非 hierarchical post type 卻 `is_page`），
	 * 屬於本專案已經吃過虧的那類「回傳一個下游從未預期的狀態」。
	 *
	 * ## ⚠️ 已量測到的副作用：範本呼叫 `the_post_navigation()` 會印出上下篇連結
	 *
	 * 實測（2026-08-11，探針範本刻意呼叫 `the_post_navigation()`）：印出了
	 * **`下一篇：shortcode`**——另一個 powershop 賣場。成因是 `get_adjacent_post()`
	 * 讀全域 `$post` 並在**同 post type** 內找相鄰文章，而 ③ 之後 `$post` 才終於
	 * 正確指向這篇賣場。（修正前它會拿 738 / 1 那兩篇去找相鄰的一般文章，
	 * 同樣會印東西，只是印的是別人的鄰居——兩種都不對，差別只在錯得像不像。）
	 * 這與旗標無關：`get_adjacent_post()` 完全不看 `is_single`。
	 *
	 * 留言區則**確定不會出現**，而且有 CPT 層面的保證、不靠佈景主題自律：
	 * powershop 的 `supports` 沒有 `comments`，post 1932 的 `comment_status` 實測為
	 * `closed` ⇒ 探針量到 `comments_open() === false` / `get_comments_number() === 0`。
	 *
	 * ⚠️ **以上是本站台（Blocksy）的實測值，不是通則**：換一套會對單篇自動印作者 /
	 * 日期 / 相關文章的佈景主題，那些區塊就會冒出來。這是「選用佈景主題範本」
	 * 這個功能本身的取捨——站長要的正是「用佈景主題的版型包住賣場」，
	 * 版型附帶什麼由那個版型決定，本 renderer 不去猜哪些該砍掉。
	 *
	 * ## 🔴 本方法對「沒選範本」的預設路徑同樣生效——已實測其差異
	 *
	 * `render_shop()` 在分支**之前**就呼叫本方法，兩條路徑因此共用同一份查詢狀態。
	 * 刻意不為了「預設路徑輸出零變動」而把 ③ 收窄到只在範本路徑跑：那會讓兩條路徑
	 * 各有一份 setup，正是本專案反覆吃虧的「兩份定義遲早分歧」；而且預設路徑同樣會
	 * 跑 `the_content`（TD-28 的頁面建構器），一樣需要正確的單篇上下文。
	 *
	 * 預設路徑實測差異（同一個賣場，修正前後各抓一次、正規化掉 nonce 與 ?ver）：
	 * 三個關鍵標記 `ps-profit-shop-front` / `data-ps-product-id` / `data-ps-modal-for`
	 * **完全相同（1 / 7 / 6）**，差異只有三處，且都是 WordPress 依 `is_singular()`
	 * 如實描述這個頁面：
	 *
	 *   1. `<body class>`：`blog` → `wp-singular powershop-template-default single
	 *      single-powershop postid-1932`（修正前把賣場頁標成部落格首頁）
	 *   2. 多出 oEmbed discovery 兩條 `<link rel="alternate">`（`wp_oembed_add_discovery_links()` 只在 singular 印）
	 *   3. 多出 `<link rel='shortlink' href='...?p=1932'>`（`wp_shortlink_wp_head()` 同理）
	 *
	 * 2 與 3 指向 `/powershop/{post-name}/` 那條「沒有任何程式碼連過去」的 CPT 網址，
	 * 但都不是 canonical（本 renderer 仍自印 `/profit-shop/{slug}/` 的 canonical），
	 * 不影響索引結果。Blocksy 的版型是靠 `data-prefix` / `data-link` 屬性決定的，
	 * 實測那兩個屬性在修正前後**一字未變** ⇒ 版面沒有變化。
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

		if ( $wp_query instanceof \WP_Query ) {
			// ① 這篇是誰。
			$wp_query->queried_object          = $shop_post;
			$wp_query->queried_object_id       = (int) $shop_post->ID;
			$wp_query->query_vars['p']         = (int) $shop_post->ID;
			$wp_query->query_vars['post_type'] = $shop_post->post_type;

			// ② 條件旗標。先清掉全部非單篇旗標（`is_page` 也在那份清單內），
			// 再打開 `is_single` / `is_singular` 兩個——順序不可對調，
			// 先設再被迴圈清掉就白做了。
			foreach ( self::QUERY_FLAGS_TO_CLEAR as $flag ) {
				$wp_query->$flag = false;
			}

			$wp_query->is_single   = true;
			$wp_query->is_singular = true;

			// ③ 主查詢裡有什麼（佈景主題範本的 loop 讀的就是這一組）。
			// current_post = -1 代表「loop 尚未開始」，have_posts() 第一次才會回 true。
			$wp_query->posts         = [ $shop_post ];
			$wp_query->post          = $shop_post;
			$wp_query->post_count    = 1;
			$wp_query->found_posts   = 1;
			$wp_query->max_num_pages = 1;
			$wp_query->current_post  = -1;
			$wp_query->in_the_loop   = false;
		}

		// 🔴 `setup_postdata()` 必須排在 ①②③ **之後**：它內部的 `generate_postdata()`
		// 會讀 `get_queried_object_id()` 與 `is_single()` 決定 `$more`（見上方說明），
		// 先呼叫的話那些值都還是舊查詢的。
		$post = $shop_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		\setup_postdata( $shop_post );
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
	 * 站長在賣場編輯頁選了範本時回傳其實體路徑，未選（或不在白名單 / 檔案不存在）
	 * 一律回空字串 ⇒ 走既有的 `get_header()` + 內建 template 流程，**預設行為完全不變**。
	 *
	 * 白名單與路徑解析都委派 `ThemeTemplateWhitelist`，與 `GET /theme-templates`
	 * 端點列給站長看的清單同源——`settings.pageTemplate` 是站長可寫入的自由欄位，
	 * 那個 fail-closed 的守門在 `ThemeTemplateWhitelist::resolve()` 內。
	 *
	 * @param ProfitShop $shop 賣場聚合根
	 *
	 * @return string 範本實體路徑；未使用時為空字串
	 */
	private function resolve_theme_template( ProfitShop $shop ): string {
		$settings = $shop->settings;
		$slug     = isset( $settings['pageTemplate'] ) ? (string) $settings['pageTemplate'] : '';

		return ThemeTemplateWhitelist::resolve( $slug );
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
	 * 🔴 本方法能運作的前提是 `setup_shop_post_context()` 已經把**主查詢**也換成
	 *    這篇賣場——範本的 `while ( have_posts() ) : the_post();` 讀的是主查詢，
	 *    只設全域 `$post` 是不夠的（詳見該方法 docblock 的實測數字）。
	 *
	 * @param ProfitShop           $shop            賣場聚合根
	 * @param array<string, mixed> $front_settings  前台顯示設定
	 * @param string               $template        範本實體路徑
	 * @param string               $ps_notices_html 已在 `get_header()` 之前捕捉好的 WooCommerce 通知
	 *
	 * @return void
	 */
	private function render_via_theme_template( ProfitShop $shop, array $front_settings, string $template, string $ps_notices_html ): void {
		$shop_id = (int) $shop->id;

		$append = function ( $content ) use ( $shop, $front_settings, $shop_id, $ps_notices_html ) {
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

			// `$shop_for_template` / `$front_settings` / `$ps_skip_post_content` /
			// `$ps_notices_html` 四個變數都是靠 `require` 繼承目前 scope 傳進 template 的
			// （`$front_settings` 與 `$ps_notices_html` 由 `use` 帶進閉包）。
			// ⚠️ 少傳 `$ps_notices_html` 時 template 會落回自己呼叫 `wc_print_notices()`
			// 的退路，而那時範本的 `get_header()` 早已把通知吃光 ⇒ TD-49 回歸。
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
