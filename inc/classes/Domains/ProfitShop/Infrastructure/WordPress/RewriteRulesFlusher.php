<?php
/**
 * Rewrite Rules Flusher 實作
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\RewriteRulesFlusherInterface;

/**
 * 包裝 RewriteRules::flush_rules_if_needed() 靜態方法的 Flusher 實作
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 *
 * Application 層注入此 class（透過 RewriteRulesFlusherInterface），
 * 實際 flush 邏輯仍走既有的 RewriteRules 靜態 API，無需重複維護。
 */
final class RewriteRulesFlusher implements RewriteRulesFlusherInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 偵測 slug 是否異動，必要時 flush rewrite rules
	 *
	 * @return void
	 */
	public function flush_rules_if_needed(): void {
		RewriteRules::flush_rules_if_needed();
	}
}
