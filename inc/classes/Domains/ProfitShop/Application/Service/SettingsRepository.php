<?php
/**
 * 全域設定 Repository Service（Phase 3-A 骨架）
 *
 * @phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;

/**
 * Profit Shop 全域設定的讀寫
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.5
 *
 * 預定責任（Phase 3-B 實作）：
 * - 以 wp_options 儲存設定
 * - 提供 SettingsDto 抽象避免散在代碼中的 get_option() / update_option()
 *
 * Phase 3-A 僅交付骨架；method body 拋 BadMethodCallException。
 */
final class SettingsRepository {

	/**
	 * 取得當前設定
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return SettingsDto
	 */
	public function get(): SettingsDto {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-B' );
	}

	/**
	 * 儲存設定
	 *
	 * @param SettingsDto $dto 待儲存的設定 DTO
	 *
	 * @throws \BadMethodCallException Phase 3-A 尚未實作
	 *
	 * @return void
	 */
	public function save( SettingsDto $dto ): void {
		throw new \BadMethodCallException( __METHOD__ . ' — TODO Phase 3-B' );
	}
}
