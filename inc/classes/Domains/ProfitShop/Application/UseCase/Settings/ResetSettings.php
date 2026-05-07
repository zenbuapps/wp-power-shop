<?php
/**
 * 還原全域設定 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings;

use J7\PowerShop\Domains\ProfitShop\Application\Service\RewriteRulesFlusherInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettingsRepositoryInterface;

/**
 * 還原全域設定為預設值 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 *
 * 業務規則：reset 後呼叫 flusher（slug 通常會變回預設）。
 */
final class ResetSettings {

	/**
	 * 建構子
	 *
	 * @param SettingsRepositoryInterface  $settingsRepo Settings Repository
	 * @param RewriteRulesFlusherInterface $flusher      Rewrite rules flusher
	 */
	public function __construct(
		private readonly SettingsRepositoryInterface $settingsRepo,
		private readonly RewriteRulesFlusherInterface $flusher
	) {}

	/**
	 * 執行還原
	 *
	 * @return void
	 */
	public function execute(): void {
		$this->settingsRepo->reset();
		$this->flusher->flush_rules_if_needed();
	}
}
