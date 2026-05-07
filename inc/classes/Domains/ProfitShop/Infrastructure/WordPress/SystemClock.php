<?php
/**
 * 系統時鐘適配器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\ClockInterface;

/**
 * 以 PHP time() 為時間來源的 production 適配器
 */
final class SystemClock implements ClockInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 當前 Unix timestamp（秒）
	 *
	 * @return int
	 */
	public function now(): int {
		return time();
	}
}
