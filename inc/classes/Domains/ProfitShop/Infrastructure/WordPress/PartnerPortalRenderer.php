<?php
/**
 * Partner Portal Renderer
 *
 * Phase 4-B1：分潤夥伴自助查詢入口前台 HTML 骨架輸出。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Plugin;
use J7\Powerhouse\Utils\Base as PowerhouseUtils;
use Kucrut\Vite;

/**
 * Partner self-service portal HTML renderer
 *
 * 攔截 /profit-report/{slug}/ URL，輸出獨立的 partner SPA HTML 骨架。
 *
 * 設計原則：
 * - template_redirect priority 9（早於 theme template_include 的 default 11）
 * - 完全脫離 theme：不呼叫 get_header() / get_footer()，避免 theme CSS 污染
 * - 只在 query var profit_partner_report 非空時介入
 * - partner term 不存在 → 404
 * - partner term 存在 → 輸出 mount 點 + enqueue partner bundle → exit
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.8 / Phase 4-B1
 */
final class PartnerPortalRenderer {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Mount 點 element id
	 */
	public const MOUNT_ID = 'profit_partner_portal';

	/**
	 * Vite entry path（相對於 plugin root）
	 */
	private const VITE_ENTRY = 'js/src/partner-portal/main.tsx';

	/**
	 * Script handle
	 */
	private const SCRIPT_HANDLE = 'power-shop-partner-portal';

	/**
	 * Constructor
	 *
	 * 掛在 template_redirect priority 9，早於 theme template_include。
	 */
	public function __construct() {
		\add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ], 9 );
	}

	/**
	 * 檢查 query var 並決定是否輸出 partner portal
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		$slug = (string) \get_query_var( RewriteRules::QUERY_VAR, '' );
		if ( '' === $slug ) {
			return; // 不是 partner portal 路徑，fall through to theme.
		}

		// Sanitize slug（雙保險，雖然 WP 已過 query var filter）。
		$slug = \sanitize_title( $slug );
		if ( '' === $slug ) {
			self::render_404();
			exit;
		}

		// 驗證 partner term 存在。
		$term = \get_term_by( 'slug', $slug, 'profit_partner' );
		if ( ! $term || \is_wp_error( $term ) ) {
			self::render_404();
			exit;
		}

		self::render_portal( $slug );
		exit;
	}

	/**
	 * 輸出維護中畫面（503）
	 *
	 * 用於 Vite manifest 缺對應 entry 的情境（例：build artifact 未同步上傳）。
	 * 不走 get_header/get_footer，避免 theme 干擾。
	 *
	 * @return void
	 */
	private static function render_maintenance(): void {
		\status_header( 503 );
		\nocache_headers();

		$title   = \__( '服務暫時維護中', 'power_shop' );
		$message = \__( '請稍後再試，如有疑問請聯絡管理員。', 'power_shop' );
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
	 * 輸出 404 HTML
	 *
	 * 不走 get_header/get_footer，避免 theme 干擾。
	 *
	 * @return void
	 */
	private static function render_404(): void {
		\status_header( 404 );
		\nocache_headers();

		$title   = \__( '找不到分潤頁面', 'power_shop' );
		$message = \__( '請確認連結是否正確，或聯絡賣場管理員。', 'power_shop' );
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
	 * 輸出 partner portal HTML 骨架
	 *
	 * - 不呼叫 get_header() / get_footer() / wp_head() / wp_footer()
	 *   避免 theme CSS、admin bar、wp_rest nonce 污染（partner 不是 WP user）
	 * - 透過 wp_print_styles() / wp_print_scripts() 輸出 enqueue 的資源
	 *
	 * @param string $slug 已驗證 + sanitize 過的 partner slug
	 *
	 * @return void
	 */
	private static function render_portal( string $slug ): void {
		// Enqueue partner bundle（由 Vite 處理，manifest 由 4-B1.2 react-master 確保包含此 entry）。
		Vite\enqueue_asset(
			Plugin::$dir . '/js/dist',
			self::VITE_ENTRY,
			[
				'handle'    => self::SCRIPT_HANDLE,
				'in-footer' => true,
			]
		);

		// 防呆（reviewer L-3）：若 Vite manifest 缺對應 entry，wp_scripts 不會註冊 handle，
		// 此時若繼續輸出空殼 HTML 會讓 partner 看到一片空白。改顯示「服務維護中」。
		$scripts = \wp_scripts();
		if ( ! isset( $scripts->registered[ self::SCRIPT_HANDLE ] ) ) {
			self::render_maintenance();
			return;
		}

		// 注入 env（不需 nonce，partner 不用 wp_rest nonce）。
		$env = [
			'SITE_URL' => \untrailingslashit( \site_url() ),
			'API_URL'  => \untrailingslashit( \esc_url_raw( \rest_url( 'power-shop' ) ) ),
			'KEBAB'    => Plugin::$kebab,
			'SLUG'     => $slug, // 給前端 partner-portal 知道當前 partner slug
		];

		$encrypt_env = PowerhouseUtils::simple_encrypt( $env );

		\wp_localize_script(
			self::SCRIPT_HANDLE,
			Plugin::$snake . '_partner_data',
			[ 'env' => $encrypt_env ]
		);

		\nocache_headers();

		$site_name = (string) \get_bloginfo( 'name' );
		?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo \esc_html__( '分潤夥伴', 'power_shop' ); ?> | <?php echo \esc_html( $site_name ); ?></title>
		<?php \wp_print_styles(); ?>
</head>
<body>
<div id="<?php echo \esc_attr( self::MOUNT_ID ); ?>" data-slug="<?php echo \esc_attr( $slug ); ?>"></div>
		<?php \wp_print_scripts(); ?>
</body>
</html>
		<?php
	}
}
