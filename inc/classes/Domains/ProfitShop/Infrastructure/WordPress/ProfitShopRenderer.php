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

		\get_header();

		// 把 $shop 傳給 template；變數名故意取 $shop_for_template 以避免污染外層 scope。
		$shop_for_template = $shop;
		require __DIR__ . '/templates/profit-shop-front.php';

		\get_footer();
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
