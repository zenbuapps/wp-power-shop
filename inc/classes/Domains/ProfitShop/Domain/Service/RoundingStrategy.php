<?php
/**
 * 數值四捨五入策略介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Service;

/**
 * 為 Domain 層提供 rounding 抽象，避免直接相依 wc_format_decimal()。
 *
 * Infrastructure 層提供具體實作（例如 WcDecimalRounding implements RoundingStrategy），
 * 讓 ProfitCalculator 等 Domain Service 維持純粹、可單元測試。
 */
interface RoundingStrategy {

	/**
	 * 對十進位字串做指定小數位數的四捨五入
	 *
	 * @param string $value    十進位字串。
	 * @param int    $decimals 保留小數位。
	 *
	 * @return string 四捨五入後的十進位字串。
	 */
	public function round( string $value, int $decimals ): string;
}
