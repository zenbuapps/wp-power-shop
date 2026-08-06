<?php
/**
 * 金額入庫格式（收斂到站台貨幣小數位）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Service\RoundingStrategy;

/**
 * 把計算結果收斂成「可以直接寫進 DB 的金額字串」
 *
 * ═══ 為什麼需要這一層 ═══
 *
 * `ProfitCalculator::calculate()` 內部**硬編** `round( $raw, 4 )`（那個 4 寫死在 Domain 層，
 * 且已有 Mockery 測試鎖住行為，不該為了這件事去改它）。但金額最終要面對兩個消費端：
 *
 *   - 單筆顯示：訂單頁 / 結算頁逐列顯示 `_profit_amount`
 *   - 加總：`WpSettlementSummaryProvider::format_money()` 走 `bcadd( $v, '0', 2 )`，
 *     那是**無條件捨去**
 *
 * 兩者精度不一致時，多存的那兩位會在加總時被靜默截掉，跨大量訂單累積成方向固定偏低的
 * 系統性少算（夥伴少拿）；而且「逐列相加 ≠ 總計」是站長對帳時**最先炸出來**的東西。
 *
 * ⇒ 入庫前一律再 half-up 收斂到 `wc_get_price_decimals()`，讓「單筆」與「加總」同精度。
 *
 * ⚠️ 這裡刻意**不**呼叫 `wc_format_decimal()` / PHP `round()`：兩者都會轉 float，
 *    把 `ProfitCalculator` 一路用 bcmath 保住的精度在最後一步丟掉
 *    （會出現 `69.99930000000001` 這種尾數）。四捨五入一律委派注入的 `RoundingStrategy`
 *    （production 為 `WcDecimalRounding`，純 bcmath half-up）。
 *
 * 放在 Infrastructure/WooCommerce/ 而非更上層，是因為「站台貨幣小數位」本身就是
 * WooCommerce 的設定（`wc_get_price_decimals()`）。
 */
final class MoneyFormatter {

	/**
	 * WooCommerce 未載入時的貨幣小數位預設值
	 */
	private const FALLBACK_DECIMALS = 2;

	/**
	 * 建構子
	 *
	 * @param RoundingStrategy $rounding 四捨五入策略（DIP，禁鴨子型別）
	 */
	public function __construct(
		private readonly RoundingStrategy $rounding
	) {}

	/**
	 * 收斂為入庫用的金額字串
	 *
	 * @param string $amount 十進位字串（可帶任意小數位）
	 *
	 * @throws \InvalidArgumentException 當 amount 非數字時由 RoundingStrategy 拋出（fail-fast）
	 *
	 * @return string 固定帶 `wc_get_price_decimals()` 位小數的十進位字串
	 */
	public function to_store( string $amount ): string {
		return $this->rounding->round( $amount, self::store_decimals() );
	}

	/**
	 * 站台貨幣小數位
	 *
	 * @return int
	 */
	private static function store_decimals(): int {
		if ( ! \function_exists( 'wc_get_price_decimals' ) ) {
			return self::FALLBACK_DECIMALS;
		}

		return max( 0, (int) \wc_get_price_decimals() );
	}
}
