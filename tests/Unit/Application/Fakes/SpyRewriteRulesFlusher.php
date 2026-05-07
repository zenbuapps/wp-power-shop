<?php
/**
 * RewriteRules flusher Spy
 *
 * 對應 master 將要新增的：
 *   inc/classes/Domains/ProfitShop/Application/Service/RewriteRulesFlusherInterface.php
 *
 * UseCase 不該直接 call 靜態的 RewriteRules::flush_rules_if_needed()，
 * 應透過抽象注入。此 spy 只記錄被呼叫次數，方便測試驗證。
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Fakes;

use J7\PowerShop\Domains\ProfitShop\Application\Service\RewriteRulesFlusherInterface;

/**
 * RewriteRules flush 行為 spy（測試用）
 */
final class SpyRewriteRulesFlusher implements RewriteRulesFlusherInterface {

	/**
	 * flush_rules_if_needed 被呼叫的次數
	 *
	 * @var int
	 */
	private int $calls = 0;

	/**
	 * 模擬 RewriteRules::flush_rules_if_needed()
	 */
	public function flush_rules_if_needed(): void {
		++$this->calls;
	}

	/**
	 * 取得呼叫次數
	 */
	public function call_count(): int {
		return $this->calls;
	}
}
