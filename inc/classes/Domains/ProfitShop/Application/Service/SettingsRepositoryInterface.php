<?php
/**
 * 全域設定 Repository 介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;

/**
 * Profit Shop 全域設定的讀寫抽象
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 *
 * Application 層 UseCase（GetSettings / UpdateSettings / ResetSettings）
 * 只透過此介面操作設定，方便注入 in-memory 替身做單元測試。
 */
interface SettingsRepositoryInterface {

	/**
	 * 取得當前設定
	 *
	 * @return SettingsDto 找不到 / 未設定時回傳預設 DTO
	 */
	public function get(): SettingsDto;

	/**
	 * 儲存設定
	 *
	 * @param SettingsDto $dto 待儲存的設定 DTO
	 *
	 * @return void
	 */
	public function save( SettingsDto $dto ): void;

	/**
	 * 還原為預設值
	 *
	 * @return void
	 */
	public function reset(): void;
}
