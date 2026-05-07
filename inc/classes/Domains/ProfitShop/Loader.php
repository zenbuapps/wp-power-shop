<?php
/**
 * 分潤賣場 Domain 載入器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest\V2Api;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\CartPriceOverrideHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\PartnerPortalRenderer;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpSaltProvider;

/**
 * 分潤賣場 Domain Loader
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §1.4、§1.5
 *
 * Phase 2：註冊 CPT / Taxonomy / Rewrite Rules。
 * Phase 3-B：加上 V2Api（profit-shops / profit-partners / profit-migration / profit-settings）。
 * Phase 3-D：加上 CartPriceOverrideHook（前台 cart 價格防竄改）。
 * Phase 4-B1：加上 PartnerPortalRenderer（前台 /profit-report/{slug}/ HTML 骨架輸出）。
 */
final class Loader {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		CptRegistrar::instance();
		TaxonomyRegistrar::instance();
		RewriteRules::instance();
		PartnerPortalRenderer::instance();
		V2Api::instance();

		// Phase 3-D：前台 cart 價格 override hook（最高風險：影響真實訂單金額）
		CartPriceOverrideHook::instance(
			CptProfitShopRepository::instance(),
			new CartPriceSignatureService( new WpSaltProvider() )
		);
	}
}
