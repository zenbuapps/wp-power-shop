<?php
/**
 * 價格覆寫 ValueObject
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPriceOverride;

/**
 * 分潤賣場商品價格覆寫
 *
 * 三個欄位皆為 nullable string：
 * - regular_price：原價
 * - sale_price：特價
 * - signup_fee：訂閱型商品的註冊費
 *
 * 規範：
 * - 任一欄位為 null 表示「不覆寫該欄位」
 * - 非 null 值必須為合法十進位字串、>= 0
 * - 若 sale_price 與 regular_price 皆非 null，sale_price 必須 <= regular_price
 *
 * 為避免浮點精度問題，比較使用 bccomp 的字串十進位比較。
 */
final class PriceOverride {

	/**
	 * 原價（nullable，null 表示不覆寫）
	 *
	 * @var string|null
	 */
	public readonly ?string $regular_price;

	/**
	 * 特價（nullable，null 表示不覆寫）
	 *
	 * @var string|null
	 */
	public readonly ?string $sale_price;

	/**
	 * 註冊費（nullable，null 表示不覆寫；僅訂閱型商品適用）
	 *
	 * @var string|null
	 */
	public readonly ?string $signup_fee;

	/**
	 * 建構子
	 *
	 * @param string|null $regular_price 原價字串。
	 * @param string|null $sale_price    特價字串。
	 * @param string|null $signup_fee    註冊費字串。
	 *
	 * @throws InvalidPriceOverride 當任一欄位非 null 且不合法（非數字、負數、sale > regular）時拋出。
	 */
	public function __construct( ?string $regular_price, ?string $sale_price, ?string $signup_fee ) {
		self::assert_valid_decimal( $regular_price, 'regular_price' );
		self::assert_valid_decimal( $sale_price, 'sale_price' );
		self::assert_valid_decimal( $signup_fee, 'signup_fee' );

		if ( $regular_price !== null && $sale_price !== null ) {
			// 使用 bccomp 進行字串十進位比較，避免浮點精度損失。
			if ( bccomp( $sale_price, $regular_price, 4 ) > 0 ) {
				throw new InvalidPriceOverride(
					"特價（{$sale_price}）不得高於原價（{$regular_price}）"
				);
			}
		}

		$this->regular_price = $regular_price;
		$this->sale_price    = $sale_price;
		$this->signup_fee    = $signup_fee;
	}

	/**
	 * 比較兩個 PriceOverride 是否相等
	 *
	 * @param self $other 另一個 PriceOverride
	 *
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->regular_price === $other->regular_price
		&& $this->sale_price === $other->sale_price
		&& $this->signup_fee === $other->signup_fee;
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{regular_price: string|null, sale_price: string|null, signup_fee: string|null}
	 */
	public function to_array(): array {
		return [
			'regular_price' => $this->regular_price,
			'sale_price'    => $this->sale_price,
			'signup_fee'    => $this->signup_fee,
		];
	}

	/**
	 * 驗證單一價格欄位
	 *
	 * @param string|null $value      欲驗證的字串值（null 直接通過）。
	 * @param string      $field_name 欄位名稱（用於錯誤訊息）。
	 *
	 * @throws InvalidPriceOverride 當 $value 非 null 但不合法時拋出。
	 *
	 * @return void
	 */
	private static function assert_valid_decimal( ?string $value, string $field_name ): void {
		if ( $value === null ) {
			return;
		}

		if ( ! is_numeric( $value ) ) {
			throw new InvalidPriceOverride(
				"欄位 {$field_name} 必須為合法十進位字串，收到「{$value}」"
			);
		}

		// 使用字串比較 0 避免將 '0.00' 等視為非 0；bccomp 會回傳 -1/0/1。
		if ( bccomp( $value, '0', 4 ) < 0 ) {
			throw new InvalidPriceOverride(
				"欄位 {$field_name} 不得為負數，收到「{$value}」"
			);
		}
	}
}
