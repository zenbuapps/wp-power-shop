<?php
/**
 * 取得全域設定 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettingsRepositoryInterface;

/**
 * 取得全域設定 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 */
final class GetSettings {

	/**
	 * 建構子
	 *
	 * @param SettingsRepositoryInterface $settingsRepo Settings Repository
	 */
	public function __construct(
		private readonly SettingsRepositoryInterface $settingsRepo
	) {}

	/**
	 * 執行讀取
	 *
	 * @return SettingsDto
	 */
	public function execute(): SettingsDto {
		return $this->settingsRepo->get();
	}
}
