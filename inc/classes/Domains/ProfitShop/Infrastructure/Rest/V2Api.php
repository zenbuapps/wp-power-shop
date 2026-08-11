<?php
/**
 * Profit Shop REST API V2
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ChangePasswordInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerLoginInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\CsvExporter;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ItemValidator;
use J7\PowerShop\Domains\ProfitShop\Application\Service\LoginRateLimiter;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerAuthService;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PartnerTokenStore;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditRecorder;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetector;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\ImportLegacyShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\ListImportableLegacyShops;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\ChangePasswordUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\GetCurrentPartnerUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\LoginPartnerUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\LogoutPartnerUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\RegeneratePartnerPasswordUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Auth\SetPartnerPasswordUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\CreatePartner;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\DeletePartner;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\GetPartner;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\ListPartners;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\GetPartnerKpiUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\GetPartnerTrendUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\Report\ListPartnerSettlementsUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\UpdatePartner;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\ExportSettlementsUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\GetSettlementSummaryUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\ListSettlementOrderIdsUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\ListSettlementsUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\MarkSettlementsCancelledUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\MarkSettlementsPaidUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settlement\ReversePaidSettlementsUseCase;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\CreateShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\DeleteShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\DuplicateShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\GetShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\ListShops;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\PublishShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\UnpublishShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\UpdateShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\ValidateSlugUseCase;
use J7\PowerShop\Domains\ProfitShop\Domain\Criteria\FilterCriteria;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\LegacyOnePageShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerSettledLogRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpAdminSettlementQuery;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpProductVariationQuery;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpSettlementStatusMutator;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpSettlementSummaryProvider;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\SystemClock;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpAdminEmailNotifier;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpProductLookup;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpSaltProvider;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpSlugConflictLookup;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpTransientStore;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\WP;

/**
 * Profit Shop V2 REST API
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.1、§4.2、§4.7、§4.8
 *
 * 路由命名規則（ApiBase 自動解析）：
 *   - {method}_{endpoint_segments_underscored}_callback
 *   - `/` → `_`
 *   - `(?P<id>\d+)` → `with_id`
 *
 * Permission 策略（spec §4 對齊）：
 *  - profit-shops/* GET/POST/PUT/DELETE / publish / unpublish / duplicate：
 *    permission_callback = null（= ApiBase 預設 manage_options OR manage_woocommerce）
 *  - profit-partners GET / GET-by-id：null（同上，讀取類允許 shop manager）
 *  - profit-partners POST / PUT / DELETE：admin_only_permission（manage_options，
 *    因 partner 涉及密碼系統與身分發放，僅 admin 可操作）
 *  - profit-migration/*：admin_only_permission（manage_options）
 *
 * Exception 處理：每個 callback 自 try/catch \Throwable 後走 ExceptionMapper（避開
 * ApiBase::try 把 \Exception 整體變 500 + 洩漏 request payload 的問題）。
 *
 * 安全：
 *  - 所有寫入 endpoint 走 read_json_body() 經 WP::sanitize_text_field_deep
 *  - Partner endpoint 走 read_json_body_for_partner()，把 PARTNER_RAW_FIELDS 中的
 *    欄位（如 password）排除於 sanitize_text_field 之外，避免密碼被 strip tags / collapse 空白變形
 */
final class V2Api extends ApiBase {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Partner endpoint 中需保持原值、不經過 sanitize_text_field 的欄位
	 *
	 * Note：sanitize_text_field 會 strip tags 並 collapse 空白，會破壞密碼字面值。
	 * 對這些欄位仍以 string 型別保留並在 UseCase 層做雜湊處理。
	 *
	 * @var string[]
	 */
	private const PARTNER_RAW_FIELDS = [ 'password' ];

	/**
	 * Partner token TTL（秒）
	 *
	 * 此常數同時用於：
	 *   - PartnerTokenStore::issue 的 transient 過期時間
	 *   - login response 的 Set-Cookie Max-Age
	 *
	 * 兩處必須一致，避免 cookie 還在但 transient 已過期（或反之）造成 401 認知不一致。
	 *
	 * @var int
	 */
	private const PARTNER_TOKEN_TTL_SECONDS = 3600;

	/**
	 * Partner token cookie 路徑
	 *
	 * 收斂 cookie 作用域到本外掛 REST namespace，避免被同網域其他 endpoint 讀到。
	 * 涵蓋 /wp-json/power-shop/partner-auth/* 與 /wp-json/power-shop/partner-reports/*。
	 *
	 * 前提：partner SPA 走的是 /wp-json/power-shop/ base URL（與 Bootstrap::API_URL + KEBAB 對齊）。
	 *
	 * @var string
	 */
	private const PARTNER_COOKIE_PATH = '/wp-json/power-shop/';

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'power-shop';

	/**
	 * APIs
	 *
	 * @var array{endpoint: string, method: string, permission_callback: ?callable}[]
	 */
	protected $apis;

	/** Constructor */
	public function __construct() {
		$admin_only      = [ self::class, 'admin_only_permission' ];
		$public          = '__return_true';
		$partner_token   = [ $this, 'partner_token_permission' ];

		$this->apis = [
			// §4.1 profit-shops
			[
				'endpoint' => 'profit-shops',
				'method' => 'get',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-shops',
				'method' => 'post',
				'permission_callback' => null,
			],
			// §6.11 validate-slug（必須在 /(?P<id>\d+) 之前，避免被 numeric route 攔截）
			[
				'endpoint' => 'profit-shops/validate-slug',
				'method' => 'get',
				'permission_callback' => null,
			],

			/*
			 * 商品變體查詢（賣場編輯頁的變體覆寫表格）
			 *
			 * 🔴 刻意**不走** `wc/v3/products/{id}/variations`：那支 API 的
			 * `per_page` 硬上限是 100，超過的變體站長在賣場編輯頁點不到，
			 * 等於那些 SKU 永遠只能賣原價（原 TD-16）。本端點無上限、不分頁。
			 */
			[
				'endpoint' => 'product-variations/(?P<id>\d+)',
				'method' => 'get',
				'permission_callback' => null,
			],

			/*
			 * 佈景主題的頁面 / 文章範本清單（賣場編輯頁的「頁面範本」下拉）
			 */
			[
				'endpoint' => 'theme-templates',
				'method' => 'get',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-shops/(?P<id>\d+)',
				'method' => 'get',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-shops/(?P<id>\d+)',
				'method' => 'put',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-shops/(?P<id>\d+)',
				'method' => 'delete',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-shops/(?P<id>\d+)/publish',
				'method' => 'post',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-shops/(?P<id>\d+)/unpublish',
				'method' => 'post',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-shops/(?P<id>\d+)/duplicate',
				'method' => 'post',
				'permission_callback' => null,
			],
			// §4.2 profit-partners
			[
				'endpoint' => 'profit-partners',
				'method' => 'get',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-partners',
				'method' => 'post',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-partners/(?P<id>\d+)',
				'method' => 'get',
				'permission_callback' => null,
			],
			[
				'endpoint' => 'profit-partners/(?P<id>\d+)',
				'method' => 'put',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-partners/(?P<id>\d+)',
				'method' => 'delete',
				'permission_callback' => $admin_only,
			],
			// §4.3 profit-settlements（後台結算頁）
			//
			// 🔴 七個端點**一律** admin_only（manage_options），連唯讀的列表 / 摘要也是。
			// 規格 query-settlements.feature 與 export-settlements-csv.feature 都明文斷言
			// 「僅具商店管理權限」→ 403。理由寫在 feature 檔頭：結算頁緊鄰「標記已撥款」
			// 這個不可逆的金流動作，讓 shop_manager 看得到卻按不了按鈕只會讓人繞路
			// （截圖、口頭轉述金額），整頁收在 manage_options 才是一致的邊界。
			// ⚠️ 這比 spec §6.1 的 manage_woocommerce 嚴格；兩份文件不一致時以 feature 為準
			// （後者較新，且是可執行的驗收條件）。
			// 三個 mutation 另外要求 X-WP-Nonce：capability 擋「你是誰」、nonce 擋
			// 「這個請求是不是你自己發的」，兩者防的不是同一件事。
			[
				'endpoint' => 'profit-settlements',
				'method' => 'get',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-settlements/summary',
				'method' => 'get',
				'permission_callback' => $admin_only,
			],
			// D-6：訂單列表「分潤賣場」過濾用（WC REST orders 無法依 order item meta 篩選）
			[
				'endpoint' => 'profit-settlements/order-ids',
				'method' => 'get',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-settlements/export',
				'method' => 'post',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-settlements/mark-paid',
				'method' => 'post',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-settlements/mark-cancelled',
				'method' => 'post',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-settlements/reverse-paid',
				'method' => 'post',
				'permission_callback' => $admin_only,
			],
			// §4.7 profit-migration
			[
				'endpoint' => 'profit-migration/legacy-shops',
				'method' => 'get',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-migration/import',
				'method' => 'post',
				'permission_callback' => $admin_only,
			],
			// §4.2 regenerate-password（admin only；補 Phase 3-B observation 1）
			[
				'endpoint' => 'profit-partners/(?P<id>\d+)/regenerate-password',
				'method' => 'post',
				'permission_callback' => $admin_only,
			],
			// §4.2 set-password（admin only；站長指定密碼，與 regenerate 並存）
			[
				'endpoint' => 'profit-partners/(?P<id>\d+)/set-password',
				'method' => 'post',
				'permission_callback' => $admin_only,
			],
			// §4.4 PartnerAuth（公開：登入 / 登出 / me）
			[
				'endpoint' => 'partner-auth/login',
				'method' => 'post',
				'permission_callback' => $public,
			],
			[
				'endpoint' => 'partner-auth/logout',
				'method' => 'post',
				'permission_callback' => $public,
			],
			[
				'endpoint' => 'partner-auth/me',
				'method' => 'get',
				'permission_callback' => $partner_token,
			],
			// §6.3 partner 自助修密碼（Phase 6-A1）
			[
				'endpoint' => 'partner-auth/change-password',
				'method' => 'post',
				'permission_callback' => $partner_token,
			],
			// §4.5 PartnerReports（partner token 認證）
			[
				'endpoint' => 'partner-reports/kpi',
				'method' => 'get',
				'permission_callback' => $partner_token,
			],
			[
				'endpoint' => 'partner-reports/trend',
				'method' => 'get',
				'permission_callback' => $partner_token,
			],
			[
				'endpoint' => 'partner-reports/settlements',
				'method' => 'get',
				'permission_callback' => $partner_token,
			],
		];

		parent::__construct();
	}

	/**
	 * Admin-only permission callback（manage_options）
	 *
	 * @return bool
	 */
	public static function admin_only_permission(): bool {
		return \current_user_can( 'manage_options' );
	}

	/**
	 * Partner token permission callback
	 *
	 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.5、§6.3
	 *
	 * 支援三種 token 來源（任一即可，優先級鏈見 extract_partner_token）：
	 *   1. Header `X-Partner-Token: {token}`               - SPA 直接帶（最常見）
	 *   2. Header `Authorization: Bearer {token}`           - REST API 通用慣例
	 *   3. Cookie `profit_partner_token`                    - 瀏覽器自動帶（fallback）
	 *
	 * 通過驗證後，將 partner_term_id 透過 set_param() 寫入 request；
	 * WP_REST_Request::set_param() 會 overwrite 所有已存在 buckets 中的同名 key——
	 * 即使 attacker 在 query string 帶 `?_partner_term_id=999`，set_param 也會覆寫為認證取得的真值。
	 * （命名 `_` 前綴僅為 convention 標示「內部、非外部輸入」，與安全性無關。）
	 *
	 * 失敗回 401 + code=unauthorized（與 InvalidCredentials 對映一致）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return bool|\WP_Error true 通過；WP_Error 401 失敗
	 */
	public function partner_token_permission( \WP_REST_Request $request ) {
		$token = $this->extract_partner_token( $request );
		if ( '' === $token ) {
			return new \WP_Error(
				'unauthorized',
				'INVALID_CREDENTIALS',
				[ 'status' => 401 ]
			);
		}

		$tokens          = self::make_partner_token_store();
		$partner_term_id = $tokens->verify( $token );
		if ( null === $partner_term_id ) {
			return new \WP_Error(
				'unauthorized',
				'INVALID_CREDENTIALS',
				[ 'status' => 401 ]
			);
		}

		// 將 partner_term_id 塞進 request 內部欄位（前綴 _，不被外部覆蓋）
		$request->set_param( '_partner_term_id', $partner_term_id );

		return true;
	}

	/**
	 * 從 request 中取出 partner token（三條讀取路徑，優先級鏈）
	 *
	 * 優先級（高 → 低）：
	 *   1. Header 'X-Partner-Token'                        - SPA 直接帶 token（最常見、最直接）
	 *   2. Header 'Authorization: Bearer {token}'           - REST API 通用慣例
	 *   3. Cookie 'profit_partner_token'                    - 瀏覽器自動帶（fallback）
	 *
	 * 三選一即可。多帶以第一個有效者為準。client 可任選一條，
	 * 但官方 SPA 推薦走 X-Partner-Token（與 X-WP-Nonce 對稱、不依賴 cookie 設定）.
	 *
	 * 不刪除任何路徑（向後相容）.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return string 找不到回空字串
	 */
	private function extract_partner_token( \WP_REST_Request $request ): string {
		$from_header = (string) $request->get_header( 'x_partner_token' );
		if ( '' !== $from_header ) {
			return $from_header;
		}

		// Authorization: Bearer {token}
		$auth = (string) $request->get_header( 'authorization' );
		if ( '' !== $auth && 0 === stripos( $auth, 'bearer ' ) ) {
			return trim( substr( $auth, 7 ) );
		}

		// Cookie：wp_unslash 處理 magic_quotes 殘留 + sanitize_text_field 防注入
		if ( isset( $_COOKIE['profit_partner_token'] ) ) {
			return \sanitize_text_field( \wp_unslash( $_COOKIE['profit_partner_token'] ) );
		}

		return '';
	}

	// ========== §4.1 profit-shops callbacks ==========

	/**
	 * GET /profit-shops
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_shops_callback( $request ): \WP_REST_Response {
		try {
			$params          = WP::sanitize_text_field_deep( $request->get_query_params(), false );
			$partner_term_id = isset( $params['partner_term_id'] ) ? (int) $params['partner_term_id'] : null;

			$useCase = new ListShops( shopRepo: CptProfitShopRepository::instance() );
			$outputs = $useCase->execute( partner_term_id: $partner_term_id );

			$data = array_map( static fn( $o ): array => $o->to_array(), $outputs );
			return self::ok( $data );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-shops
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_shops_callback( $request ): \WP_REST_Response {
		try {
			$body  = self::read_json_body( $request );
			$input = ProfitShopInput::from_array( $body );

			$useCase = new CreateShop(
				shopRepo: CptProfitShopRepository::instance(),
				partnerRepo: PartnerTermRepository::instance(),
				itemValidator: new ItemValidator( WpProductLookup::instance() ),
				slugDetector: new SlugConflictDetector( WpSlugConflictLookup::instance() )
			);

			$output = $useCase->execute( $input );
			return self::created( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /theme-templates
	 *
	 * 回傳佈景主題登記過的頁面 / 文章範本，供賣場編輯頁選擇。
	 *
	 * 清單是**白名單的唯一來源**：`ProfitShopRenderer::resolve_theme_template()`
	 * 只接受出現在這份清單裡的檔名，站長（或竄改過的請求）因此指不到任意 PHP 檔。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_theme_templates_callback( $request ): \WP_REST_Response {
		unset( $request );

		try {
			$theme = \wp_get_theme();

			// 頁面與文章範本合併：站長要的是「用哪一個版型包住賣場」，
			// 那個版型是登記給 page 還是 post 對他來說沒有差別。
			$templates = $theme->get_page_templates( null, 'page' ) + $theme->get_page_templates( null, 'post' );

			$items = [];
			foreach ( $templates as $file => $label ) {
				$items[] = [
					'value' => (string) $file,
					'label' => (string) $label,
				];
			}

			return self::ok(
				[
					'items' => $items,
					'total' => count( $items ),
				]
			);
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /product-variations/{id}
	 *
	 * 回傳指定商品的**全部**變體（無 100 筆上限）。
	 *
	 * 回應形狀刻意與其他 admin endpoint 一致（`{code, message, data}`），
	 * `data` 為 `{items, total}`。前端因此仍走 `useCustom` +
	 * `dataProviderName: 'power-shop'`，與 profit-* 三兄弟同一條路線
	 * （見 CLAUDE.md Pitfall #10）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_product_variations_with_id_callback( $request ): \WP_REST_Response {
		try {
			$product_id = (int) $request->get_param( 'id' );
			$items      = WpProductVariationQuery::instance()->find_by_product( $product_id );

			return self::ok(
				[
					'items' => $items,
					'total' => count( $items ),
				]
			);
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /profit-shops/validate-slug
	 *
	 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
	 *
	 * Query string：?slug=xxx
	 *
	 * 此 endpoint 必須在 /profit-shops/(?P<id>\d+) 之前註冊，
	 * 否則 'validate-slug' 會被 numeric route 攔截（雖然 \d+ 不會 match 字串，
	 * 但 WP REST router 比對順序仍以登錄順序為準，提前註冊較保險）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_shops_validate_slug_callback( $request ): \WP_REST_Response {
		try {
			$params = WP::sanitize_text_field_deep( $request->get_query_params(), false );
			$slug   = isset( $params['slug'] ) ? (string) $params['slug'] : '';

			$useCase = new ValidateSlugUseCase(
				slugDetector: new SlugConflictDetector( WpSlugConflictLookup::instance() )
			);
			$output = $useCase->execute( $slug );

			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /profit-shops/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_shops_with_id_callback( $request ): \WP_REST_Response {
		try {
			$id = (int) $request->get_param( 'id' );
			$useCase = new GetShop( shopRepo: CptProfitShopRepository::instance() );
			$output  = $useCase->execute( $id );
			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * PUT /profit-shops/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function put_profit_shops_with_id_callback( $request ): \WP_REST_Response {
		try {
			$id    = (int) $request->get_param( 'id' );
			$body  = self::read_json_body( $request );
			$input = ProfitShopInput::from_array( $body );

			$useCase = new UpdateShop(
				shopRepo: CptProfitShopRepository::instance(),
				partnerRepo: PartnerTermRepository::instance(),
				itemValidator: new ItemValidator( WpProductLookup::instance() ),
				slugDetector: new SlugConflictDetector( WpSlugConflictLookup::instance() )
			);
			$output = $useCase->execute( $id, $input );
			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * DELETE /profit-shops/{id}
	 *
	 * 走 wp_trash_post 軟刪除，符合 spec §4.1。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function delete_profit_shops_with_id_callback( $request ): \WP_REST_Response {
		try {
			$id = (int) $request->get_param( 'id' );
			$useCase = new DeleteShop( shopRepo: CptProfitShopRepository::instance() );
			$useCase->execute( $id );
			return self::ok(
				 [
					 'id' => $id,
					 'deleted' => true,
				 ]
				);
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-shops/{id}/publish
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_shops_with_id_publish_callback( $request ): \WP_REST_Response {
		try {
			$id = (int) $request->get_param( 'id' );
			$useCase = new PublishShop( shopRepo: CptProfitShopRepository::instance() );
			$output  = $useCase->execute( $id );
			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-shops/{id}/unpublish
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_shops_with_id_unpublish_callback( $request ): \WP_REST_Response {
		try {
			$id = (int) $request->get_param( 'id' );
			$useCase = new UnpublishShop( shopRepo: CptProfitShopRepository::instance() );
			$output  = $useCase->execute( $id );
			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-shops/{id}/duplicate
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_shops_with_id_duplicate_callback( $request ): \WP_REST_Response {
		try {
			$id = (int) $request->get_param( 'id' );
			$useCase = new DuplicateShop( shopRepo: CptProfitShopRepository::instance() );
			$output  = $useCase->execute( $id );
			return self::created( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	// ========== §4.2 profit-partners callbacks ==========

	/**
	 * GET /profit-partners
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_partners_callback( $request ): \WP_REST_Response {
		try {
			$useCase = new ListPartners( partnerRepo: PartnerTermRepository::instance() );
			$outputs = $useCase->execute();
			$data    = array_map( static fn( $o ): array => $o->to_array(), $outputs );
			return self::ok( $data );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-partners
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_partners_callback( $request ): \WP_REST_Response {
		try {
			$body  = self::read_json_body_for_partner( $request );
			$input = PartnerInput::from_array( $body );

			$useCase = new CreatePartner( partnerRepo: PartnerTermRepository::instance() );
			$output  = $useCase->execute( $input );
			return self::created( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /profit-partners/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_partners_with_id_callback( $request ): \WP_REST_Response {
		try {
			$id = (int) $request->get_param( 'id' );
			$useCase = new GetPartner( partnerRepo: PartnerTermRepository::instance() );
			$output  = $useCase->execute( $id );
			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * PUT /profit-partners/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function put_profit_partners_with_id_callback( $request ): \WP_REST_Response {
		try {
			$id    = (int) $request->get_param( 'id' );
			$body  = self::read_json_body_for_partner( $request );
			$input = PartnerInput::from_array( $body );

			$useCase = new UpdatePartner( partnerRepo: PartnerTermRepository::instance() );
			$output  = $useCase->execute( $id, $input );
			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * DELETE /profit-partners/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function delete_profit_partners_with_id_callback( $request ): \WP_REST_Response {
		try {
			$id = (int) $request->get_param( 'id' );
			$useCase = new DeletePartner( partnerRepo: PartnerTermRepository::instance() );
			$useCase->execute( $id );
			return self::ok(
				 [
					 'id' => $id,
					 'deleted' => true,
				 ]
				);
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	// ========== §4.7 profit-migration callbacks ==========

	/**
	 * GET /profit-migration/legacy-shops
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_migration_legacy_shops_callback( $request ): \WP_REST_Response {
		try {
			$useCase = new ListImportableLegacyShops( legacyRepo: LegacyOnePageShopRepository::instance() );
			$data    = $useCase->execute();
			return self::ok( $data );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-migration/import
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_migration_import_callback( $request ): \WP_REST_Response {
		try {
			$body            = self::read_json_body( $request );
			$legacy_id       = isset( $body['legacy_id'] ) ? (int) $body['legacy_id'] : 0;
			$partner_term_id = isset( $body['partner_term_id'] ) ? (int) $body['partner_term_id'] : 0;

			$useCase = new ImportLegacyShop(
				legacyRepo: LegacyOnePageShopRepository::instance(),
				shopRepo: CptProfitShopRepository::instance(),
				partnerRepo: PartnerTermRepository::instance()
			);
			$output = $useCase->execute( $legacy_id, $partner_term_id );
			return self::created( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	// ========== Response helpers ==========

	/**
	 * 200 OK response
	 *
	 * @param mixed $data 回應資料
	 *
	 * @return \WP_REST_Response
	 */
	private static function ok( mixed $data ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code' => 'success',
				'data' => $data,
			],
			200
		);
	}

	/**
	 * 201 Created response
	 *
	 * @param mixed $data 回應資料
	 *
	 * @return \WP_REST_Response
	 */
	private static function created( mixed $data ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code' => 'success',
				'data' => $data,
			],
			201
		);
	}

	/**
	 * 從 request 讀取 JSON body 並 sanitize
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_json_body( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( ! is_array( $json ) ) {
			$json = $request->get_body_params();
		}
		if ( ! is_array( $json ) ) {
			$json = $request->get_params();
		}

		// 全面 sanitize（POST/PUT 內含的字串字段都會經過 sanitize_text_field）。
		$sanitized = WP::sanitize_text_field_deep( $json, false );
		return is_array( $sanitized ) ? $sanitized : [];
	}

	// ========== §4.2 regenerate-password callback ==========

	/**
	 * POST /profit-partners/{id}/regenerate-password
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_partners_with_id_regenerate_password_callback( $request ): \WP_REST_Response {
		try {
			// admin nonce 檢查（reviewer follow-up T-4.1）：除既有 manage_options capability，
			// 額外要求 X-WP-Nonce 防 CSRF——防範 admin 開著瀏覽器被誘騙呼叫此端點，
			// 在 admin 不知情下重置 partner 密碼.
			// （Refine SPA 已自動帶 X-WP-Nonce header，此 nonce 與既有設計對齊）
			$nonce = (string) $request->get_header( 'x_wp_nonce' );
			if ( ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_REST_Response(
					[
						'code'    => 'rest_forbidden',
						'message' => '缺少有效的 admin nonce',
					],
					403
				);
			}

			$id = (int) $request->get_param( 'id' );

			$useCase  = new RegeneratePartnerPasswordUseCase( partnerRepo: PartnerTermRepository::instance() );
			$plain_pw = $useCase->execute( partner_term_id: $id );

			// Partner 系列端點回應直接是 payload（與 admin endpoint 的 {code, data} 結構不同），
			// 對齊 spec §4.4 / §4.5 partner SPA 的呼叫慣例 + 測試合約。
			$response = new \WP_REST_Response(
				[
					'partner_id' => $id,
					'password'   => $plain_pw,
				],
				200
			);

			// 強制不快取（reviewer follow-up T-4.2）：
			// 防範密碼明文被 proxy / browser 快取造成洩漏.
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
			$response->header( 'Pragma', 'no-cache' );

			return $response;
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-partners/{id}/set-password
	 *
	 * 站長指定一組密碼給 partner（與 regenerate-password 並存，用途不同：
	 * regenerate 是「夥伴忘記密碼、一鍵重設強密碼」，本端點是「站長要主動
	 * 交一組自己知道的密碼給夥伴」）。
	 *
	 * ⚠️ body 的 `password` **不過** `WP::sanitize_text_field_deep()`——
	 * 那會吃掉密碼裡的合法字元（`<` / `>` / 連續空白等），造成「設定的密碼與
	 * 實際存進去的不同」，而且完全無聲。同 partner-auth 的 login / change-password。
	 *
	 * response **不回傳密碼明文**：站長自己輸入的，本來就知道；回傳只會多一個
	 * 沒必要的洩漏面。這是與 regenerate-password 的關鍵差異（後者系統產生，
	 * 不回傳就沒人知道）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_partners_with_id_set_password_callback( $request ): \WP_REST_Response {
		try {
			// admin nonce 檢查：與 regenerate-password 同一條紀律——除 manage_options
			// capability 外額外要求 X-WP-Nonce，防 admin 被誘騙在不知情下改掉 partner 密碼。
			$nonce = (string) $request->get_header( 'x_wp_nonce' );
			if ( ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_REST_Response(
					[
						'code'    => 'rest_forbidden',
						'message' => '缺少有效的 admin nonce',
					],
					403
				);
			}

			$id = (int) $request->get_param( 'id' );

			// ⚠️ 必須用 read_json_body_for_partner()，**不是** read_json_body()——
			// 後者會對所有欄位跑 WP::sanitize_text_field_deep()，把密碼裡的 `<` / `>` /
			// 連續空白靜默改寫，症狀是「站長設的密碼夥伴登不進去」，而且兩邊看到的
			// 字串完全一樣、毫無錯誤訊息。`PARTNER_RAW_FIELDS` 就是為此存在的。
			$body     = self::read_json_body_for_partner( $request );
			$password = isset( $body['password'] ) ? (string) $body['password'] : '';

			$useCase    = new SetPartnerPasswordUseCase( partnerRepo: PartnerTermRepository::instance() );
			$changed_at = $useCase->execute( partner_term_id: $id, plain_password: $password );

			// 與 regenerate-password 一致的裸 payload 形狀（partner 系列慣例）。
			$response = new \WP_REST_Response(
				[
					'partner_id'          => $id,
					'password_changed_at' => $changed_at,
				],
				200
			);

			// 強制不快取：request body 帶過明文密碼，避免中間層留下任何殘影。
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
			$response->header( 'Pragma', 'no-cache' );

			return $response;
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	// ========== §4.4 partner-auth callbacks ==========

	/**
	 * POST /partner-auth/login
	 *
	 * Set-Cookie 透過 `WP_REST_Response::header()` 直接寫 raw header（避免使用 setcookie，
	 * REST API 已 buffer output）。Cookie 屬性：HttpOnly + Secure + SameSite=Lax +
	 * Path=PARTNER_COOKIE_PATH（/wp-json/power-shop/）+ Max-Age=PARTNER_TOKEN_TTL_SECONDS（3600）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_partner_auth_login_callback( $request ): \WP_REST_Response {
		try {
			$body  = self::read_json_body_for_partner( $request );
			$input = PartnerLoginInput::from_array( $body );

			// 由 server 觀測 IP 注入 limiter（per-IP rate-limit / reviewer M-2）；
			// IP 不放進 client payload DTO 以維持語意純度。filter_var 防呆，無效視同 null。
			$ip_raw = isset( $_SERVER['REMOTE_ADDR'] )
			? \sanitize_text_field( \wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';
			/** @var string|false $validated */
			$validated = '' === $ip_raw ? false : filter_var( $ip_raw, FILTER_VALIDATE_IP );
			$ip        = false === $validated ? null : (string) $validated;

			/**
			 * Filter the resolved client IP for partner login rate-limit.
			 *
			 * 預設取 $_SERVER['REMOTE_ADDR']（不解析 X-Forwarded-For 避免 header 偽造繞鎖）.
			 * 部署於 reverse proxy / Cloudflare 後方時，可透過此 filter 注入經 trust proxy
			 * 白名單驗證後的真實 client IP（reviewer M-1）.
			 *
			 * @param string|null      $ip      已 filter_var FILTER_VALIDATE_IP 驗證過的 IP，無效為 null.
			 * @param \WP_REST_Request $request 當前 REST request.
			 */
			$ip = \apply_filters( 'power_shop_partner_login_client_ip', $ip, $request );

			$useCase = new LoginPartnerUseCase(
				auth: self::make_partner_auth_service(),
				tokens: self::make_partner_token_store(),
			);
			$output  = $useCase->execute( $input, $ip );

			$response = new \WP_REST_Response( $output->to_array(), 200 );

			$cookie = sprintf(
				'profit_partner_token=%s; HttpOnly; Secure; SameSite=Lax; Path=%s; Max-Age=%d',
				rawurlencode( $output->token ),
				self::PARTNER_COOKIE_PATH,
				self::PARTNER_TOKEN_TTL_SECONDS
			);
			$response->header( 'Set-Cookie', $cookie );

			return $response;
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /partner-auth/logout
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_partner_auth_logout_callback( $request ): \WP_REST_Response {
		try {
			$token = $this->extract_partner_token( $request );

			$useCase = new LogoutPartnerUseCase(
				tokens: self::make_partner_token_store(),
			);
			$useCase->execute( $token );

			$response = new \WP_REST_Response( [ 'logged_out' => true ], 200 );

			// 清空 cookie：Max-Age=0；Path 必須與 set 時一致才能清除
			$response->header(
				'Set-Cookie',
				sprintf(
					'profit_partner_token=; HttpOnly; Secure; SameSite=Lax; Path=%s; Max-Age=0',
					self::PARTNER_COOKIE_PATH
				)
			);

			return $response;
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /partner-auth/me
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_partner_auth_me_callback( $request ): \WP_REST_Response {
		try {
			$token = $this->extract_partner_token( $request );

			$useCase = new GetCurrentPartnerUseCase(
				tokens: self::make_partner_token_store(),
				partners: PartnerTermRepository::instance(),
			);
			$snapshot = $useCase->execute( $token );

			return new \WP_REST_Response(
				[
					'partner_id'    => $snapshot->term_id,
					'partner_name'  => $snapshot->name,
					'partner_slug'  => $snapshot->slug->value(),
					'contact_email' => $snapshot->contact_email,
				],
				200
			);
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /partner-auth/change-password
	 *
	 * Partner 自助修密碼（Phase 6-A1）。對應規格：specs/2026-05-06-profit-shop-design.md §6.3。
	 *
	 * 認證：partner_token_permission（cookie / X-Partner-Token / Bearer 三選一），
	 * permission_callback 已將 partner_term_id 從 token 解出並寫入 `_partner_term_id`，
	 * **永不從 query string / body 讀 partner_term_id**（防 cross-partner 越權）。
	 *
	 * Body：{ current_password: string, new_password: string }（**不過 sanitize_text_field**，
	 * 避免 strip tags / collapse 空白破壞密碼字面值）。
	 *
	 * 200 回應：
	 *   - body：{ success: true, password_changed_at: int }（裸 payload，與其他 partner endpoint 一致）
	 *   - Set-Cookie：profit_partner_token 清空 + Max-Age=0（強制重新登入；
	 *     password_changed_at 寫入 termmeta 後，舊 token 在 PartnerTokenStore::verify
	 *     會被識別為已撤銷，這裡的 cookie 清空是 UX 層的同步釋放）
	 *   - Cache-Control：no-store, no-cache, must-revalidate（防 proxy / browser 快取響應內容）
	 *
	 * 失敗對映：
	 *   - 401 unauthorized：未帶 cookie / token（permission_callback 拒絕）
	 *   - 400 validation_failed：缺欄位（fallback InvalidArgumentException → DomainException）
	 *   - 401 unauthorized：current_password 錯（InvalidCredentials → 既有 mapping）
	 *   - 422 weak_password：new_password 弱（WeakPassword → ExceptionMapper 攜帶 reasons）
	 *   - 429 rate_limited：失敗 5 次後第 6 次（TooManyAttempts → 含 Retry-After）
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_partner_auth_change_password_callback( $request ): \WP_REST_Response {
		try {
			// partner_term_id 鎖死於 token（permission_callback 寫入 `_partner_term_id`）
			$partner_term_id = (int) $request->get_param( '_partner_term_id' );

			// reviewer L-4 phase 6-A1：defense-in-depth fail-fast.
			// permission_callback 應已透過 set_param 寫入合法 partner_term_id；
			// 若未寫入而落到此處（=0 / 負數），代表 permission 流程缺陷或被繞過，
			// 直接拋以避免污染 'pwchange:0' rate-limit 維度.
			// 走 ExceptionMapper fallback → 400 validation_failed（與既有 missing field 一致）.
			if ( $partner_term_id <= 0 ) {
				throw new \InvalidArgumentException( '無效的 partner_term_id（permission_callback 應已 set_param）' );
			}

			// JSON body：**不**過 sanitize_text_field（密碼欄位的 strip tags / collapse 空白會破壞字面值）
			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = $request->get_body_params();
			}
			if ( ! is_array( $params ) ) {
				$params = [];
			}

			$current_password = isset( $params['current_password'] ) && is_string( $params['current_password'] )
			? $params['current_password']
			: '';
			$new_password     = isset( $params['new_password'] ) && is_string( $params['new_password'] )
			? $params['new_password']
			: '';

			if ( '' === $current_password || '' === $new_password ) {
				// 透過 InvalidArgumentException 走 ExceptionMapper fallback → 400 validation_failed
				throw new \InvalidArgumentException( '缺少必要欄位：current_password / new_password' );
			}

			// IP 取值流程與 partner login 一致（DRY）：filter_var 防呆 + filter hook 支援 trust proxy
			$ip_raw = isset( $_SERVER['REMOTE_ADDR'] )
			? \sanitize_text_field( \wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';
			/** @var string|false $validated */
			$validated = '' === $ip_raw ? false : filter_var( $ip_raw, FILTER_VALIDATE_IP );
			$ip        = false === $validated ? null : (string) $validated;

			/**
			 * Filter the resolved client IP for partner change-password rate-limit.
			 *
			 * 與 partner login 共用同一個 filter hook（reviewer M-1）.
			 *
			 * @param string|null      $ip      已 filter_var FILTER_VALIDATE_IP 驗證過的 IP，無效為 null.
			 * @param \WP_REST_Request $request 當前 REST request.
			 */
			$ip = \apply_filters( 'power_shop_partner_login_client_ip', $ip, $request );

			$input   = new ChangePasswordInput(
				partner_term_id: $partner_term_id,
				current_password: $current_password,
				new_password: $new_password,
			);
			$useCase = new ChangePasswordUseCase(
				partnerRepo: PartnerTermRepository::instance(),
				limiter: self::make_partner_login_rate_limiter(),
				clock: SystemClock::instance(),
			);
			$output  = $useCase->execute( $input, $ip );

			$response = new \WP_REST_Response( $output->to_array(), 200 );

			// 清空 cookie：強制 partner 重新登入（path 與 set 時一致才能清除）
			$response->header(
				'Set-Cookie',
				sprintf(
					'profit_partner_token=; HttpOnly; Secure; SameSite=Lax; Path=%s; Max-Age=0',
					self::PARTNER_COOKIE_PATH
				)
			);

			// 強制不快取：防 proxy / browser 快取響應（success-only path 加，與 regenerate-password 一致）
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
			$response->header( 'Pragma', 'no-cache' );

			return $response;
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	// ========== §4.5 partner-reports callbacks ==========

	/**
	 * GET /partner-reports/kpi
	 *
	 * Partner_term_id 來源：permission_callback 已從 token 解出並寫入 `_partner_term_id`，
	 * 永不從 query string 取（防範 cross-partner 越權）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_partner_reports_kpi_callback( $request ): \WP_REST_Response {
		try {
			$partner_term_id = (int) $request->get_param( '_partner_term_id' );
			$criteria        = self::build_filter_criteria_from_request( $request );

			$useCase = new GetPartnerKpiUseCase( summary: WpSettlementSummaryProvider::instance() );
			$kpi     = $useCase->execute( $partner_term_id, $criteria );

			return new \WP_REST_Response( $kpi->to_array(), 200 );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /partner-reports/trend
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_partner_reports_trend_callback( $request ): \WP_REST_Response {
		try {
			$partner_term_id = (int) $request->get_param( '_partner_term_id' );
			$criteria        = self::build_filter_criteria_from_request( $request );

			$params   = WP::sanitize_text_field_deep( $request->get_query_params(), false );
			$interval = isset( $params['interval'] ) ? (string) $params['interval'] : 'day';

			$useCase = new GetPartnerTrendUseCase( summary: WpSettlementSummaryProvider::instance() );
			$trend   = $useCase->execute( $partner_term_id, $criteria, $interval );

			return new \WP_REST_Response( $trend->to_array(), 200 );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /partner-reports/settlements
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_partner_reports_settlements_callback( $request ): \WP_REST_Response {
		try {
			$partner_term_id = (int) $request->get_param( '_partner_term_id' );
			$criteria        = self::build_filter_criteria_from_request( $request );

			$useCase = new ListPartnerSettlementsUseCase( summary: WpSettlementSummaryProvider::instance() );
			$output  = $useCase->execute( $partner_term_id, $criteria );

			return new \WP_REST_Response( $output->to_array(), 200 );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	// ========== §4.3 profit-settlements callbacks ==========

	/**
	 * GET /profit-settlements
	 *
	 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3
	 *
	 * Query string：partner_id / shop_id / status（皆可複數或逗號分隔）、
	 * date_start / date_end（unix timestamp）、page / per_page。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_settlements_callback( $request ): \WP_REST_Response {
		try {
			$filter = self::read_settlement_filter( $request );

			$useCase = new ListSettlementsUseCase( query: WpAdminSettlementQuery::instance() );
			$output  = $useCase->execute( $filter );

			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /profit-settlements/order-ids
	 *
	 * 決策 D-6：WooCommerce REST 的 `orders` 端點無法依 order item meta 篩選，
	 * 前端得先向本端點取回 order_id 陣列，再當成 `wc-rest` 的 `include` filter 送出。
	 *
	 * ⚠️ 回應的 `truncated` 為 true 時前端**必須**提示站長縮小範圍——
	 * 靜默截斷會讓他直接得出「這個賣場只有這幾張訂單」的錯誤結論。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_settlements_order_ids_callback( $request ): \WP_REST_Response {
		try {
			$filter = self::read_settlement_filter( $request );

			$params = WP::sanitize_text_field_deep( $request->get_query_params(), false );
			$limit  = ( is_array( $params ) && isset( $params['limit'] ) ) ? (int) $params['limit'] : null;

			$useCase = new ListSettlementOrderIdsUseCase( query: WpAdminSettlementQuery::instance() );
			$output  = $useCase->execute( $filter, $limit );

			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * GET /profit-settlements/summary
	 *
	 * 摘要卡與列表吃同一組過濾條件（見 GetSettlementSummaryUseCase docblock）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_settlements_summary_callback( $request ): \WP_REST_Response {
		try {
			$filter = self::read_settlement_filter( $request );

			$useCase = new GetSettlementSummaryUseCase( query: WpAdminSettlementQuery::instance() );
			$output  = $useCase->execute( $filter );

			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-settlements/mark-paid
	 *
	 * Body：`{ "order_item_ids": [123, 456] }`
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_settlements_mark_paid_callback( $request ): \WP_REST_Response {
		$nonce_error = self::admin_nonce_error( $request );
		if ( null !== $nonce_error ) {
			return $nonce_error;
		}

		try {
			$useCase = new MarkSettlementsPaidUseCase(
				settlements: OrderItemSettlementRepository::instance(),
				mutator: WpSettlementStatusMutator::instance(),
				auditor: self::make_settlement_audit_recorder(),
				clock: SystemClock::instance(),
			);

			$output = $useCase->execute(
				self::read_order_item_ids( $request ),
				\get_current_user_id()
			);

			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-settlements/mark-cancelled
	 *
	 * Body：`{ "order_item_ids": [123, 456] }`
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_settlements_mark_cancelled_callback( $request ): \WP_REST_Response {
		$nonce_error = self::admin_nonce_error( $request );
		if ( null !== $nonce_error ) {
			return $nonce_error;
		}

		try {
			$useCase = new MarkSettlementsCancelledUseCase(
				settlements: OrderItemSettlementRepository::instance(),
				mutator: WpSettlementStatusMutator::instance(),
				auditor: self::make_settlement_audit_recorder(),
			);

			$output = $useCase->execute(
				self::read_order_item_ids( $request ),
				\get_current_user_id()
			);

			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-settlements/reverse-paid
	 *
	 * 人工沖回（paid → refunded）。Body：`{ "order_item_ids": [123, 456] }`
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_settlements_reverse_paid_callback( $request ): \WP_REST_Response {
		$nonce_error = self::admin_nonce_error( $request );
		if ( null !== $nonce_error ) {
			return $nonce_error;
		}

		try {
			$useCase = new ReversePaidSettlementsUseCase(
				settlements: OrderItemSettlementRepository::instance(),
				mutator: WpSettlementStatusMutator::instance(),
				auditor: self::make_settlement_audit_recorder(),
			);

			$output = $useCase->execute(
				self::read_order_item_ids( $request ),
				\get_current_user_id()
			);

			return self::ok( $output->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-settlements/export
	 *
	 * 匯出**整個篩選結果**（不只當前頁）為 CSV，回應仍是 `{code, data}` 包裝，
	 * `data.content` 是 CSV 全文（含 UTF-8 BOM），由前端 Blob 下載。
	 * 理由見 SettlementExportOutput docblock。
	 *
	 * 過濾條件走 body（條件較長，且 partner / shop 多選會讓 query string 過長）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_settlements_export_callback( $request ): \WP_REST_Response {
		try {
			$body   = self::read_json_body( $request );
			$filter = SettlementFilterInput::from_array( $body );

			$useCase = new ExportSettlementsUseCase(
				query: WpAdminSettlementQuery::instance(),
				csv: new CsvExporter(),
				clock: SystemClock::instance(),
			);
			$output = $useCase->execute( $filter );

			$response = self::ok( $output->to_array() );
			// 匯出內容含全站分潤金額，不該被任何中介快取留存。
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
			$response->header( 'Pragma', 'no-cache' );

			return $response;
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	// ========== §4.3 profit-settlements helpers ==========

	/**
	 * 從 request 讀出結算過濾條件（GET 走 query string、POST 走 body）
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return SettlementFilterInput
	 */
	private static function read_settlement_filter( \WP_REST_Request $request ): SettlementFilterInput {
		$params = WP::sanitize_text_field_deep( $request->get_query_params(), false );
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		return SettlementFilterInput::from_array( $params );
	}

	/**
	 * 從 request body 讀出 order_item_ids
	 *
	 * 型別正規化（去除非正整數、去重、上限）交給 `SettlementBatchGuard`——
	 * 那是 Application 層的守門，這裡只負責把值取出來。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return array<int, mixed>
	 */
	private static function read_order_item_ids( \WP_REST_Request $request ): array {
		$body = self::read_json_body( $request );
		$raw  = $body['order_item_ids'] ?? [];

		if ( ! is_array( $raw ) ) {
			// 容忍 "1,2,3" 這種送法（有些 HTTP client 不好送陣列）。
			$raw = is_string( $raw ) ? explode( ',', $raw ) : [ $raw ];
		}

		return array_values( $raw );
	}

	/**
	 * Admin nonce 檢查（CSRF 防禦），不通過時回傳 403 response，通過回 null
	 *
	 * 與 regenerate-password / set-password 同一條紀律：capability 擋的是「你是誰」，
	 * nonce 擋的是「這個請求是不是你自己發的」。撥款端點若少了 nonce，
	 * 站長開著後台被誘導點一個外部頁面就可能在不知情下把整批款項標記成已撥。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return \WP_REST_Response|null
	 */
	private static function admin_nonce_error( \WP_REST_Request $request ): ?\WP_REST_Response {
		$nonce = (string) $request->get_header( 'x_wp_nonce' );
		if ( \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return null;
		}

		return new \WP_REST_Response(
			[
				'code'    => 'rest_forbidden',
				'message' => '缺少有效的 admin nonce',
			],
			403
		);
	}

	/**
	 * 建立 SettlementAuditRecorder（production 連 partner termmeta + 系統時鐘）
	 *
	 * @return SettlementAuditRecorder
	 */
	private static function make_settlement_audit_recorder(): SettlementAuditRecorder {
		return new SettlementAuditRecorder(
			log: PartnerSettledLogRepository::instance(),
			clock: SystemClock::instance(),
		);
	}

	// ========== Service factories（poor-man's DI；單元測試直接 new，不走這） ==========

	/**
	 * 建立 PartnerTokenStore（production 連 wp transient + partner repo + wp_salt）
	 *
	 * Phase 3-D Batch 2 升級：
	 *   - 注入 PartnerTermRepository → verify() 比對 password_changed_at（撤銷邏輯）
	 *   - 注入 WpSaltProvider → hash_token 用 hash_hmac + wp_salt（reviewer L-1）
	 *
	 * @return PartnerTokenStore
	 */
	private static function make_partner_token_store(): PartnerTokenStore {
		return new PartnerTokenStore(
			transients: WpTransientStore::instance(),
			clock: SystemClock::instance(),
			partners: PartnerTermRepository::instance(),
			salt_provider: new WpSaltProvider(),
			ttl: self::PARTNER_TOKEN_TTL_SECONDS,
			key_prefix: 'ps_partner_token_',
		);
	}

	/**
	 * 建立 PartnerAuthService（production 連 wp transient + admin email）
	 *
	 * @return PartnerAuthService
	 */
	private static function make_partner_auth_service(): PartnerAuthService {
		return new PartnerAuthService(
			partnerRepo: PartnerTermRepository::instance(),
			limiter: self::make_partner_login_rate_limiter(),
		);
	}

	/**
	 * 建立 LoginRateLimiter（partner login 與 partner change-password 共用）
	 *
	 * 與 partner login 同樣 max_attempts=5 + window_seconds=900；slug 維度由 caller 決定
	 * （login 用 partner slug；change-password 用 pseudo-slug "pwchange:{id}"）.
	 *
	 * @return LoginRateLimiter
	 */
	private static function make_partner_login_rate_limiter(): LoginRateLimiter {
		return new LoginRateLimiter(
			transients: WpTransientStore::instance(),
			clock: SystemClock::instance(),
			email: WpAdminEmailNotifier::instance(),
			salt_provider: new WpSaltProvider(),
			max_attempts: 5,
			window_seconds: 900,
		);
	}

	/**
	 * 從 query string 建構 FilterCriteria（不接受 partner_term_id；安全鎖死）
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return FilterCriteria
	 */
	private static function build_filter_criteria_from_request( \WP_REST_Request $request ): FilterCriteria {
		$params = WP::sanitize_text_field_deep( $request->get_query_params(), false );
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$date_start = isset( $params['date_start'] ) ? (int) $params['date_start'] : null;
		$date_end   = isset( $params['date_end'] ) ? (int) $params['date_end'] : null;

		$shop_ids = [];
		if ( isset( $params['shop_ids'] ) ) {
			$raw = is_array( $params['shop_ids'] ) ? $params['shop_ids'] : explode( ',', (string) $params['shop_ids'] );
			foreach ( $raw as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$shop_ids[] = $id;
				}
			}
		}

		$statuses = [];
		if ( isset( $params['statuses'] ) ) {
			$raw = is_array( $params['statuses'] ) ? $params['statuses'] : explode( ',', (string) $params['statuses'] );
			foreach ( $raw as $s ) {
				$s = (string) $s;
				if ( '' !== $s ) {
					$statuses[] = $s;
				}
			}
		}

		// page 上限 cap（reviewer LOW-T5-2）：避免極大 page 造成 OFFSET 巨大 query 拖庫.
		$page     = isset( $params['page'] ) ? max( 1, min( 10000, (int) $params['page'] ) ) : 1;
		$per_page = isset( $params['per_page'] ) ? max( 1, min( 100, (int) $params['per_page'] ) ) : 20;

		return new FilterCriteria(
			date_start: $date_start,
			date_end: $date_end,
			shop_ids: $shop_ids,
			statuses: $statuses,
			page: $page,
			per_page: $per_page,
		);
	}

	/**
	 * Partner endpoint 專用 body reader：保留 PARTNER_RAW_FIELDS 中的欄位原值不經 sanitize_text_field
	 *
	 * Note：sanitize_text_field 會 strip tags 並 collapse 空白，會破壞密碼字面值。
	 * 流程：
	 *   1. top-level guard（若 $json 非陣列直接視為 []）
	 *   2. 暫存 raw 欄位（避開 unset on non-array 的警告）
	 *   3. sanitize 其餘欄位
	 *   4. raw 欄位回填
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_json_body_for_partner( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( ! is_array( $json ) ) {
			$json = $request->get_body_params();
		}
		if ( ! is_array( $json ) ) {
			$json = $request->get_params();
		}
		if ( ! is_array( $json ) ) {
			$json = [];
		}

		// 暫存 raw fields（避免 sanitize 變形）
		$raw_values = [];
		foreach ( self::PARTNER_RAW_FIELDS as $field ) {
			if ( isset( $json[ $field ] ) && is_string( $json[ $field ] ) ) {
				$raw_values[ $field ] = $json[ $field ];
				unset( $json[ $field ] );
			}
		}

		$sanitized = WP::sanitize_text_field_deep( $json, false );
		if ( ! is_array( $sanitized ) ) {
			$sanitized = [];
		}

		// 放回 raw values
		foreach ( $raw_values as $field => $value ) {
			$sanitized[ $field ] = $value;
		}

		return $sanitized;
	}
}
