<?php
/**
 * GetSettings UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group settings
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Settings;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings\GetSettings;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemorySettingsRepository;

/**
 * GetSettings UseCase 測試
 */
final class GetSettingsTest extends TestCase {

	/**
	 * happy：repository 有設定 → 回傳當前 SettingsDto
	 *
	 * @group happy
	 */
	public function test_returns_current_settings(): void {
		$repo = new InMemorySettingsRepository();
		$repo->seed(
			new SettingsDto(
				rewrite_slug: 'shops',
				report_slug: 'kpi',
				default_rate: 12,
				page_template: 'minimal'
			)
		);

		$useCase = new GetSettings( settingsRepo: $repo );

		$dto = $useCase->execute();

		$this->assertSame( 'shops', $dto->rewrite_slug );
		$this->assertSame( 'kpi', $dto->report_slug );
		$this->assertSame( 12, $dto->default_rate );
		$this->assertSame( 'minimal', $dto->page_template );
	}

	/**
	 * happy：repository 無資料時回傳預設 → 預設值來自 SettingsDto::from_array([])
	 *
	 * @group happy
	 */
	public function test_returns_defaults_when_no_settings_persisted(): void {
		$repo = new InMemorySettingsRepository();

		$useCase = new GetSettings( settingsRepo: $repo );

		$dto = $useCase->execute();

		$this->assertSame( 'shop', $dto->rewrite_slug );
		$this->assertSame( 'report', $dto->report_slug );
		$this->assertSame( 10, $dto->default_rate, 'spec 預設 default_rate 為 10' );
		$this->assertSame( 'default', $dto->page_template );
	}
}
