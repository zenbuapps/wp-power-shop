<?php
/**
 * InMemory SettingsRepository（測試替身）
 *
 * 為避免依賴 SettingsRepository 真實 class（final，不可子類化），
 * 此 fake 是獨立 class，提供與 SettingsRepository 相同 method 簽名。
 *
 * Phase 3-B Green 階段，master 必須：
 *   - 將 SettingsRepository 拆出 SettingsRepositoryInterface（或拿掉 final）
 *   - 讓 UseCase 接收抽象，方便測試替換
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Fakes;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettingsRepositoryInterface;

/**
 * 純記憶體 SettingsRepository
 *
 * @internal Phase 3-B 測試用替身
 */
final class InMemorySettingsRepository implements SettingsRepositoryInterface {

	/**
	 * @var SettingsDto|null
	 */
	private ?SettingsDto $current = null;

	public function __construct() {
		$this->current = SettingsDto::from_array( [] );
	}

	public function get(): SettingsDto {
		return $this->current ?? SettingsDto::from_array( [] );
	}

	public function save( SettingsDto $dto ): void {
		$this->current = $dto;
	}

	public function reset(): void {
		$this->current = SettingsDto::from_array( [] );
	}

	/**
	 * 直接覆寫設定（測試 setup 用）
	 */
	public function seed( SettingsDto $dto ): void {
		$this->current = $dto;
	}
}
