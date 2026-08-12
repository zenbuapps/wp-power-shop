<?php
/**
 * ItemValidator 測試替身
 *
 * 為避免依賴 ItemValidator 真實 class（final，不可子類化），
 * 此 fake 是獨立 class，提供與 ItemValidator 相同 method 簽名。
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Fakes;

use J7\PowerShop\Domains\ProfitShop\Application\Service\ItemValidatorInterface;

/**
 * 可控行為的 ItemValidator 替身
 *
 * 透過 mode 控制：
 * - 'accept'：永遠通過
 * - 'reject_product'：拋 ProductNotFound
 * - 'reject_partner'：拋 PartnerNotFound
 * - 'reject_variation'：拋 InvalidVariation
 *
 * @internal Phase 3-B 測試用替身
 */
final class FakeItemValidator implements ItemValidatorInterface {

	public function __construct( private readonly string $mode = 'accept' ) {}

	public function validate( array $items ): void {
		switch ( $this->mode ) {
			case 'accept':
				return;
			case 'reject_product':
				throw new \J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotFound(
					'測試替身：商品不存在'
				);
			case 'reject_partner':
				throw new \J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound(
					'測試替身：partner 不存在'
				);
			case 'reject_variation':
				throw new \J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidVariation(
					'測試替身：variation 不歸屬該商品'
				);
			default:
				throw new \LogicException( "未知的 FakeItemValidator mode：{$this->mode}" );
		}
	}
}
