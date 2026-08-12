<?php
/**
 * SlugConflictDetector 測試替身
 *
 * 為避免依賴 SlugConflictDetector 真實 class（final，不可子類化），
 * 此 fake 是獨立 class，提供與 SlugConflictDetector 相同 method 簽名。
 *
 * Phase 3-B Green 階段，master 必須將 SlugConflictDetector 拆 interface（或拿掉 final）
 * 讓 UseCase 接收抽象，方便測試替換。
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Fakes;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;

/**
 * 可控偵測結果的 SlugConflictDetector 替身
 *
 * @internal Phase 3-B 測試用替身
 */
final class FakeSlugConflictDetector implements SlugConflictDetectorInterface {

	/**
	 * @param SlugConflict[] $conflicts 預設回傳的衝突清單
	 */
	public function __construct( private readonly array $conflicts = [] ) {}

	public function detect( string $slug, string $context ): array {
		return $this->conflicts;
	}
}
