<?php
/**
 * ResetSettings UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 *
 * 業務規則：
 *   ResetSettings 將設定還原為預設值，並 flush rewrite rules（slug 通常會變）。
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group settings
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Settings;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings\ResetSettings;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemorySettingsRepository;
use Tests\Unit\Application\Fakes\SpyRewriteRulesFlusher;

/**
 * ResetSettings UseCase 測試
 */
final class ResetSettingsTest extends TestCase {

	/**
	 * happy：還原為預設值
	 *
	 * @group happy
	 */
	public function test_resets_to_defaults(): void {
		$repo = new InMemorySettingsRepository();
		$repo->seed(
			new SettingsDto(
				rewrite_slug: 'custom-shop',
				report_slug: 'custom-report',
				default_rate: 50,
				page_template: 'banner-bottom'
			)
		);
		$flusher = new SpyRewriteRulesFlusher();

		$useCase = new ResetSettings( settingsRepo: $repo, flusher: $flusher );

		$useCase->execute();

		$current = $repo->get();
		$this->assertSame( 'shop', $current->rewrite_slug );
		$this->assertSame( 'report', $current->report_slug );
		$this->assertSame( 10, $current->default_rate, 'spec 預設 default_rate 為 10' );
		$this->assertSame( 'default', $current->page_template );
	}

	/**
	 * happy：ResetSettings 後也呼叫 flush_rules_if_needed
	 *
	 * @group happy
	 */
	public function test_calls_flush_rules_if_needed(): void {
		$repo    = new InMemorySettingsRepository();
		$flusher = new SpyRewriteRulesFlusher();

		$useCase = new ResetSettings( settingsRepo: $repo, flusher: $flusher );

		$useCase->execute();

		$this->assertSame( 1, $flusher->call_count() );
	}

	/**
	 * edge：對「已是預設」的設定再 reset → 仍然 idempotent
	 *
	 * @group edge
	 */
	public function test_reset_is_idempotent_when_already_default(): void {
		$repo    = new InMemorySettingsRepository();
		$flusher = new SpyRewriteRulesFlusher();

		$useCase = new ResetSettings( settingsRepo: $repo, flusher: $flusher );

		$useCase->execute();
		$useCase->execute();

		$this->assertSame( 'shop', $repo->get()->rewrite_slug );
	}
}
