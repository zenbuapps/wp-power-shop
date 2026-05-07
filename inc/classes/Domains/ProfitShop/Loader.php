<?php
/**
 * 分潤賣場 Domain 載入器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest\V2Api;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\AddToCartHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\CartItemMetaDisplay;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\CartPriceOverrideHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\PartnerPortalRenderer;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopRenderer;
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
 * Phase 4-C1：加上 ProfitShopRenderer（前台 /profit-shop/{slug}/ 賣場頁面，走 theme 整合）。
 * Phase 4-C2：加上 AddToCartHook（注入 profit_shop_id）+ CartItemMetaDisplay（cart UI 分潤標記）。
 */
final class Loader {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		CptRegistrar::instance();
		TaxonomyRegistrar::instance();
		RewriteRules::instance();
		PartnerPortalRenderer::instance();

		// Phase 4-C1：賣場前台 renderer（DI 注入 Repository）
		ProfitShopRenderer::instance(
			CptProfitShopRepository::instance()
		);

		V2Api::instance();

		// Phase 4-C2：add-to-cart 注入 profit_shop_id（priority 5，必須早於下方 CartPriceOverrideHook 的 priority 10）
		AddToCartHook::instance(
			CptProfitShopRepository::instance()
		);

		// Phase 3-D：前台 cart 價格 override hook（最高風險：影響真實訂單金額）
		CartPriceOverrideHook::instance(
			CptProfitShopRepository::instance(),
			new CartPriceSignatureService( new WpSaltProvider() )
		);

		// Phase 4-C2：cart UI「來自賣場 XXX」分潤標記（mini-cart / cart page / checkout 一致顯示）
		CartItemMetaDisplay::instance(
			CptProfitShopRepository::instance()
		);
	}
}
