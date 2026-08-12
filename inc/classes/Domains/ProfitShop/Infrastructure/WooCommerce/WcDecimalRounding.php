<?php
/**
 * RoundingStrategy 的 bcmath 實作（四捨五入 half-up）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Service\RoundingStrategy;

/**
 * 以 bcmath 實作的十進位四捨五入策略
 *
 * `Domain\Service\RoundingStrategy` 的第一個具體實作——在此之前全專案只有 interface
 * 與 `ProfitCalculatorTest` 的 Mockery mock，production 從來沒有任何一處注入過實作
 * （`grep -rn "RoundingStrategy" inc/` 只命中 interface 自己與 ProfitCalculator 的建構子）。
 *
 * ═══ 🔴 為什麼不用 wc_format_decimal() / round() ═══
 *
 * 兩者都會把值轉成 **float**：
 *   - `wc_format_decimal( $v, 2 )` 走 `number_format( floatval( $v ), 2 )`
 *   - PHP `round()` 本身就吃 float
 *
 * 而本策略唯一的消費者是 `ProfitCalculator`——它整條計算刻意用 bcmath 字串
 * （`bcmul` / `bcdiv`）保持精度，最後一步若轉 float，前面所有努力都白費：
 * 金額會出現 `69.99930000000001` 這種尾數，寫進 `_profit_amount` 之後
 * 夥伴報表的 `bcadd` 聚合會把誤差一路帶到撥款金額，而且**沒有任何錯誤訊息**。
 *
 * ═══ half-up 的實作 ═══
 *
 * bcmath 的所有運算都是**無條件捨去**（truncate），沒有內建的四捨五入。
 * 標準做法是「先加 0.5 個目標單位，再截斷」：
 *
 *   round( '0.045', 2 ) = trunc( '0.045' + '0.005', 2 ) = trunc( '0.050', 2 ) = '0.05'
 *   round( '0.044', 2 ) = trunc( '0.044' + '0.005', 2 ) = trunc( '0.049', 2 ) = '0.04'
 *
 * 加法必須在「不損失原值精度」的 scale 下進行（`$cmp_scale`），否則會先被截斷一次，
 * 造成 `'0.0449999'` 這種值被誤判為進位（雙重截斷偏差）。
 *
 * ⚠️ 負值走減法分支（`-0.045` → `-0.05`，遠離零），並把 `-0.00` 正規化為 `0.00`——
 *    分潤金額目前不會是負數，但 interface 沒有這個限制，留著負號零會讓字串比對出錯。
 *
 * ⚠️ 非數字輸入 **fail-fast 拋例外**，不靜默回 '0'：這個值最終會變成付給夥伴的金額，
 *    把壞資料悄悄變成 0 等於少付錢而且查不出來。呼叫端
 *    （`OrderLineItemSettlementHook`）會把例外收斂成「不寫任何分潤 meta」+ error_log，
 *    符合「寧可沒有紀錄，也不要寫入錯誤金額」的紀律。
 *
 * 命名沿用 `RoundingStrategy` 介面 docblock 自己舉的例子（`WcDecimalRounding`），
 * 放在 Infrastructure/WooCommerce/ 是因為呼叫端要求的小數位數來自 WooCommerce 的
 * 貨幣設定（`wc_get_price_decimals()`）。
 */
final class WcDecimalRounding implements RoundingStrategy {

	/**
	 * 對十進位字串做 half-up 四捨五入
	 *
	 * @param string $value    十進位字串
	 * @param int    $decimals 保留小數位（負數視為 0）
	 *
	 * @throws \InvalidArgumentException 當 value 非數字字串時拋出（fail-fast，見 class docblock）
	 *
	 * @return string 四捨五入後的十進位字串，固定帶 $decimals 位小數
	 */
	public function round( string $value, int $decimals ): string {
		if ( ! is_numeric( $value ) ) {
			throw new \InvalidArgumentException( '無法對非數字字串做四捨五入：' . $value );
		}

		$scale = max( 0, $decimals );

		// 半個目標單位：scale=2 → '0.005'、scale=0 → '0.5'
		$half = '0.' . str_repeat( '0', $scale ) . '5';

		// 加法必須在「同時容得下 $value 與 $half 全部小數位」的 scale 下做，
		// 否則會在相加前先截斷一次 $value，造成雙重截斷偏差。
		$cmp_scale = max( $scale + 1, self::decimals_of( $value ) );

		$shifted = bccomp( $value, '0', $cmp_scale ) < 0
		? bcsub( $value, $half, $cmp_scale )
		: bcadd( $value, $half, $cmp_scale );

		$rounded = bcadd( $shifted, '0', $scale );

		// 正規化 '-0.00' → '0.00'
		if ( 0 === bccomp( $rounded, '0', $scale ) ) {
			return bcadd( '0', '0', $scale );
		}

		return $rounded;
	}

	/**
	 * 取得十進位字串的小數位數
	 *
	 * @param string $value 十進位字串（呼叫端已保證 is_numeric）
	 *
	 * @return int 小數位數；無小數點時為 0
	 */
	private static function decimals_of( string $value ): int {
		$dot = strpos( $value, '.' );

		if ( false === $dot ) {
			return 0;
		}

		return strlen( substr( $value, $dot + 1 ) );
	}
}
