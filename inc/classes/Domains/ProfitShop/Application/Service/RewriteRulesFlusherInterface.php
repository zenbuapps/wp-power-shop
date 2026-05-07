<?php
/**
 * Rewrite Rules Flusher 介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Rewrite Rules Flush 抽象
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 *
 * 包裝既有的 RewriteRules::flush_rules_if_needed() 靜態方法，
 * 讓 UseCase 可以注入抽象，方便單元測試以 spy 驗證呼叫次數。
 */
interface RewriteRulesFlusherInterface {

	/**
	 * 偵測 slug 是否異動，必要時 flush rewrite rules
	 *
	 * @return void
	 */
	public function flush_rules_if_needed(): void;
}
