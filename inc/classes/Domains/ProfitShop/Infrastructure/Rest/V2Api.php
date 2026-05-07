<?php
/**
 * Profit Shop REST API V2
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ItemValidator;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettingsRepository;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetector;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\ImportLegacyShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\ListImportableLegacyShops;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\CreatePartner;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\DeletePartner;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\GetPartner;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\ListPartners;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Partner\UpdatePartner;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings\GetSettings;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings\ResetSettings;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings\UpdateSettings;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\CreateShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\DeleteShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\DuplicateShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\GetShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\ListShops;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\PublishShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\UnpublishShop;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\UpdateShop;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\LegacyOnePageShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRulesFlusher;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpProductLookup;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpSlugConflictLookup;
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
 *  - profit-settings/*：admin_only_permission（manage_options）
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
		$admin_only = [ self::class, 'admin_only_permission' ];

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
			// §4.8 profit-settings
			[
				'endpoint' => 'profit-settings',
				'method' => 'get',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-settings',
				'method' => 'put',
				'permission_callback' => $admin_only,
			],
			[
				'endpoint' => 'profit-settings/reset',
				'method' => 'post',
				'permission_callback' => $admin_only,
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

	// ========== §4.8 profit-settings callbacks ==========

	/**
	 * GET /profit-settings
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_profit_settings_callback( $request ): \WP_REST_Response {
		try {
			$useCase = new GetSettings( settingsRepo: SettingsRepository::instance() );
			$dto     = $useCase->execute();
			return self::ok( $dto->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * PUT /profit-settings
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function put_profit_settings_callback( $request ): \WP_REST_Response {
		try {
			$body = self::read_json_body( $request );
			$dto  = SettingsDto::from_array( $body );

			$useCase = new UpdateSettings(
				settingsRepo: SettingsRepository::instance(),
				flusher: RewriteRulesFlusher::instance(),
				slugDetector: new SlugConflictDetector( WpSlugConflictLookup::instance() )
			);
			$persisted = $useCase->execute( $dto );
			return self::ok( $persisted->to_array() );
		} catch ( \Throwable $e ) {
			return ExceptionMapper::map( $e );
		}
	}

	/**
	 * POST /profit-settings/reset
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_profit_settings_reset_callback( $request ): \WP_REST_Response {
		try {
			$useCase = new ResetSettings(
				settingsRepo: SettingsRepository::instance(),
				flusher: RewriteRulesFlusher::instance()
			);
			$useCase->execute();
			return self::ok( SettingsRepository::instance()->get()->to_array() );
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
