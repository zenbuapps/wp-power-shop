<?php
/**
 * 應分潤金額計算服務
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;

/**
 * 計算 line item 應分潤金額。
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §1.5
 *
 * 公式：actual_price × qty × rate / 100
 *
 * 規範：
 * - rounding 透過注入的 RoundingStrategy 執行（保持 Domain 純度，不直接呼叫 wc_format_decimal）
 * - rate=0 直接回 '0.00'，不呼叫 rounding
 * - qty=0 回 '0.00'
 * - qty<0 拋出 InvalidArgumentException
 */
final class ProfitCalculator {

	/**
	 * 建構子
	 *
	 * @param RoundingStrategy $rounding 數值四捨五入策略。
	 */
	public function __construct(
		private readonly RoundingStrategy $rounding
	) {
	}

	/**
	 * 計算應分潤金額
	 *
	 * @param string     $actual_price 實際結帳價（十進位字串）。
	 * @param int        $qty          數量。
	 * @param ProfitRate $rate         分潤比例（0-100）。
	 *
	 * @return string 十進位字串格式的應分潤金額。
	 *
	 * @throws \InvalidArgumentException 當 qty 為負數時拋出。
	 */
	public function calculate(
		string $actual_price,
		int $qty,
		ProfitRate $rate
	): string {
		if ( $qty < 0 ) {
			throw new \InvalidArgumentException( "qty 不可為負數，收到 {$qty}" );
		}

		if ( $qty === 0 || $rate->value === 0 ) {
			return '0.00';
		}

		// 使用 bcmath 保持精度：subtotal = actual × qty。
		$subtotal = bcmul( $actual_price, (string) $qty, 4 );
		// raw = subtotal × rate / 100。
		$raw = bcdiv( bcmul( $subtotal, (string) $rate->value, 4 ), '100', 4 );

		return $this->rounding->round( $raw, 4 );
	}
}
