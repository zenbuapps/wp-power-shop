<?php
/**
 * 分潤賣場 Domain 載入器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\Rest\V2Api;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;

/**
 * 分潤賣場 Domain Loader
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §1.4、§1.5
 *
 * Phase 2：註冊 CPT / Taxonomy / Rewrite Rules。
 * Phase 3-B：加上 V2Api（profit-shops / profit-partners / profit-migration / profit-settings）。
 */
final class Loader {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		CptRegistrar::instance();
		TaxonomyRegistrar::instance();
		RewriteRules::instance();
		V2Api::instance();
	}
}
