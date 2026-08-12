<?php
/**
 * 分潤賣場 Domain 載入器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Application\Service\PageRateLimitService;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditRecorder;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\OverridePriceResolver;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\ProfitCalculator;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\OrderItemSettlementRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerSettledLogRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\WpSettlementStatusMutator;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest\V2Api;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\AddToCartHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\CartPriceOverrideHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\MoneyFormatter;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\OrderLineItemSettlementHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\RefundAfterPaidNotice;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\RenewalProfitHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\SettlementReversalHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\SingleProductPriceHook;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\WcDecimalRounding;
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
 * Phase 4-C2：加上 AddToCartHook（注入 profit_shop_id）。
 * Phase 5-A.1：加上 PageRateLimitService + WpClientIpProvider（前台頁面 IP-based DoS 緩解）。
 * Phase 9：加上 SingleProductPriceHook（WC 單品頁套用賣場覆寫價，含變體）。
 * TD-13（前半）：加上 ProfitShopShortcode（[power_shop_profit_shop id="..."]，把賣場嵌入任意頁面）。
 * Phase 11：加上 FrontCartApi（前台購物車自訂 REST），移除 FrontAjax（admin-ajax）。
 * Phase 12：移除 FrontCartApi——賣場前台不再自行管理購物車，加購改走 WooCommerce
 *           原生表單（`WC_Form_Handler::add_to_cart_action()`），購物車與結帳一律由
 *           WooCommerce 自己的頁面負責。下方 AddToCartHook(5) →
 *           CartPriceOverrideHook(10/999) 的分潤定價鏈路完全不受影響：原生表單是
 *           `method="post"`，`profit_shop_id` 會正常進 `$_POST`，三道閘門照常驗證。
 * Phase 15：移除 CartItemMetaDisplay——站長要求前台不顯示「來自賣場 XXX」。那個 class
 *           是**純顯示**（唯一作用是 `woocommerce_get_item_data` filter），移除後
 *           AddToCartHook(5) → CartPriceOverrideHook(10/999) 的定價鏈路、cart_item 的
 *           `_profit_*` meta 與 HMAC 簽章、以及結算紀錄全部不受任何影響。
 *           真要復原見 git commit `f813e07`。
 * 2026-08：加上 OrderLineItemSettlementHook——結帳時把驗過章的 cart item `_profit_*` meta
 *           凍結成 order itemmeta 快照（spec §2.5 / §5.2）。這是整條分潤結算鏈路的第一環，
 *           在此之前訂單成立後完全沒有分潤紀錄，夥伴報表永遠是空的且無任何錯誤訊息。
 * 2026-08：加上 SettlementReversalHook——退款 / 取消訂單時回沖結算紀錄（spec §5.3）。
 *           pending 直接翻狀態；paid **不自動改**，只寫稽核 + 後台待辦（§7.9，錢已經出去了，
 *           要不要向夥伴追討是業務決策）；部分退款重算 `_profit_amount` 並維持 pending（D-3）。
 * 2026-08：加上 RenewalProfitHook——訂閱續訂單的分潤重置（spec §5.4）。職責是「重置」而非
 *           「複製」：WCS 會把母訂單的 `_profit_*` **整組複製**過去（含 `_settlement_status='paid'`
 *           與 `_settled_at`），不清掉會讓站長對同一筆分潤重複撥款、或以為已付而讓夥伴這期
 *           一毛都拿不到。WooCommerce Subscriptions 未安裝時整段不註冊。
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

		// 簽章服務無狀態，cart hook 與結帳 hook 共用同一個實例（sign 端 / verify 端同源）。
		$signatures = new CartPriceSignatureService( new WpSaltProvider() );

		// Phase 3-D：前台 cart 價格 override hook（最高風險：影響真實訂單金額）
		CartPriceOverrideHook::instance(
			CptProfitShopRepository::instance(),
			$signatures,
			$price_resolver
		);

		// Phase 9：WC 單品頁套用賣場覆寫價（含變體），消除「單品頁一個價、購物車另一個價」的落差。
		// 只在 is_product() 且網址帶 profit_shop_id 時才註冊 filter，不影響 cart / checkout。
		SingleProductPriceHook::instance(
			CptProfitShopRepository::instance(),
			$price_resolver
		);

		/*
		* 結帳寫入分潤結算快照（cart item meta → order itemmeta）。
		*
		* 掛 `woocommerce_checkout_create_order_line_item`(20)——與上面四個 hook 完全不同的
		* 擴充點（`woocommerce_add_cart_item_data` 5/10、`woocommerce_before_calculate_totals` 999、
		* `template_redirect` 20），零衝突。priority 20 的完整理由見該 class 的 docblock。
		*
		* 這是整條結算鏈路的第一環：少了它，訂單成立後 wc_order_itemmeta 沒有任何分潤紀錄，
		* 夥伴報表永遠是空的，而且沒有任何錯誤訊息。
		*/
		$rounding = new WcDecimalRounding();

		OrderLineItemSettlementHook::instance( CptProfitShopRepository::instance(), OrderItemSettlementRepository::instance(), $signatures, new ProfitCalculator( $rounding ), new MoneyFormatter( $rounding ) );

		/*
		* 結算鏈路的「反向」與「續期」兩環（下面兩行刻意各自獨立，彼此不相鄰、也不共用
		* 中間變數的宣告行——它們由不同工作項平行開發，合併時才不會撞在同一行）。
		*
		* 退款 / 取消回沖（設計 §5.3）：顧客退款或訂單取消時，把還沒撥出去的分潤收回來。
		* 少了它，站長會對一筆已經退還給顧客的交易照樣付出分潤，且結算頁完全看不出異常。
		*
		* 🔴 這兩個 hook **不可**加 `is_admin()` guard：退款與取消必然發生在 wp-admin，
		* 照抄 cart hook 的守門等於整個功能不會執行，而且沒有任何錯誤訊息。
		*/
		$settlement_calculator = new ProfitCalculator( $rounding );
		$settlement_money      = new MoneyFormatter( $rounding );

		SettlementReversalHook::instance( OrderItemSettlementRepository::instance(), WpSettlementStatusMutator::instance(), new SettlementAuditRecorder( PartnerSettledLogRepository::instance(), SystemClock::instance() ), $settlement_calculator, $settlement_money, RefundAfterPaidNotice::instance() );

		/*
		* 訂閱續訂帶入（設計 §5.4）。WooCommerce Subscriptions 是**選用**外掛：未安裝時
		* 整段不註冊，否則引用不存在的函式會 fatal、整個 power-shop 掛掉。
		* `RenewalProfitHook` 的建構子內另有同一道守門（結構保證），這裡再檢查一次是為了
		* 讓 Loader 讀起來就看得出這是選用相依。
		*/
		if ( RenewalProfitHook::is_supported() ) {
			RenewalProfitHook::instance( CptProfitShopRepository::instance(), OrderItemSettlementRepository::instance(), $settlement_calculator, $settlement_money );
		}
	}
}
