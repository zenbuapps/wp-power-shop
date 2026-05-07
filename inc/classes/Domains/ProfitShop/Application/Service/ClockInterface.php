<?php
/**
 * Clock 抽象介面（測試用）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * 時鐘抽象（Port）
 *
 * Application Service 依賴此介面取得「當前時間」（Unix timestamp 秒）。
 * Production 由 SystemClock 包 time()；Test 由 FixedClock 提供可控時間。
 */
interface ClockInterface {

	/**
	 * 當前 Unix timestamp（秒）
	 *
	 * @return int
	 */
	public function now(): int;
}
