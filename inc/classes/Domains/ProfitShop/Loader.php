<?php
/**
 * 分潤賣場 Domain 載入器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PageRateLimitService;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\OverridePriceResolver;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest\V2Api;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\AddToCartHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\CartItemMetaDisplay;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\CartPriceOverrideHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\SingleProductPriceHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\PartnerPortalRenderer;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopRenderer;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\ProfitShopShortcode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\SystemClock;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpClientIpProvider;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpSaltProvider;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\WpTransientStore;

/**
 * 分潤賣場 Domain Loader
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §1.4、§1.5
 *
 * Phase 2：註冊 CPT / Taxonomy / Rewrite Rules。
 * Phase 3-B：加上 V2Api（profit-shops / profit-partners / profit-migration）。
 * Phase 3-D：加上 CartPriceOverrideHook（前台 cart 價格防竄改）。
 * Phase 4-B1：加上 PartnerPortalRenderer（前台 /profit-report/{slug}/ HTML 骨架輸出）。
 * Phase 4-C1：加上 ProfitShopRenderer（前台 /profit-shop/{slug}/ 賣場頁面，走 theme 整合）。
 * Phase 4-C2：加上 AddToCartHook（注入 profit_shop_id）+ CartItemMetaDisplay（cart UI 分潤標記）。
 * Phase 5-A.1：加上 PageRateLimitService + WpClientIpProvider（前台頁面 IP-based DoS 緩解）。
 * Phase 9：加上 SingleProductPriceHook（WC 單品頁套用賣場覆寫價，含變體）。
 * TD-13（前半）：加上 ProfitShopShortcode（[power_shop_profit_shop id="..."]，把賣場嵌入任意頁面）。
 * Phase 11：加上 FrontCartApi（前台購物車自訂 REST），移除 FrontAjax（admin-ajax）。
 * Phase 12：移除 FrontCartApi——賣場前台不再自行管理購物車，加購改走 WooCommerce
 *           原生表單（`WC_Form_Handler::add_to_cart_action()`），購物車與結帳一律由
 *           WooCommerce 自己的頁面負責。下方 AddToCartHook(5) →
 *           CartPriceOverrideHook(10/999) 的分潤定價鏈路完全不受影響：原生表單是
 *           `method="post"`，`profit_shop_id` 會正常進 `$_POST`，三道閘門照常驗證。
 */
final class Loader {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		CptRegistrar::instance();
		TaxonomyRegistrar::instance();
		RewriteRules::instance();

		// Phase 5-A.1：前台頁面 rate-limit 共用基礎設施（PartnerPortalRenderer / ProfitShopRenderer 共用）
		$page_rate_limit = new PageRateLimitService(
			WpTransientStore::instance(),
			SystemClock::instance(),
			new WpSaltProvider()
		);
		$ip_provider     = WpClientIpProvider::instance();

		// Phase 4-B1：Partner portal renderer（Phase 5-A.1：注入 rate-limit + ip provider）
		PartnerPortalRenderer::instance(
			$page_rate_limit,
			$ip_provider
		);

		// Phase 4-C1：賣場前台 renderer（DI 注入 Repository；Phase 5-A.1：注入 rate-limit + ip provider）
		ProfitShopRenderer::instance(
			CptProfitShopRepository::instance(),
			$page_rate_limit,
			$ip_provider
		);

		// TD-13（前半）：shortcode 呈現模式的實際實作。與 ProfitShopRenderer 共用同一份 template，
		// 但外層標籤改用 <div>（宿主頁面已有自己的 <main>），且不注入 SEO meta。
		ProfitShopShortcode::instance(
			CptProfitShopRepository::instance()
		);

		V2Api::instance();

		// Phase 4-C2：add-to-cart 注入 profit_shop_id（priority 5，必須早於下方 CartPriceOverrideHook 的 priority 10）
		AddToCartHook::instance(
			CptProfitShopRepository::instance()
		);

		// Phase 13：cart 與單品頁共用同一份取價語意（{price, regular, sale} 三元組）。
		// 兩邊各自複製一份 fallback chain 曾導致「購物車劃線原價顯示商品原始原價」的分歧。
		$price_resolver = new OverridePriceResolver();

		// Phase 3-D：前台 cart 價格 override hook（最高風險：影響真實訂單金額）
		CartPriceOverrideHook::instance(
			CptProfitShopRepository::instance(),
			new CartPriceSignatureService( new WpSaltProvider() ),
			$price_resolver
		);

		// Phase 4-C2：cart UI「來自賣場 XXX」分潤標記（mini-cart / cart page / checkout 一致顯示）
		CartItemMetaDisplay::instance(
			CptProfitShopRepository::instance()
		);

		// Phase 9：WC 單品頁套用賣場覆寫價（含變體），消除「單品頁一個價、購物車另一個價」的落差。
		// 只在 is_product() 且網址帶 profit_shop_id 時才註冊 filter，不影響 cart / checkout。
		SingleProductPriceHook::instance(
			CptProfitShopRepository::instance(),
			$price_resolver
		);
	}
}
