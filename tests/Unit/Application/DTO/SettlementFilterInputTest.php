<?php
/**
 * SettlementFilterInput 單元測試（輸入正規化與白名單）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.3、§6.8
 *
 * @group profit_shop
 * @group application
 * @group settlement
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\DTO;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use PHPUnit\Framework\TestCase;

/**
 * SettlementFilterInput 契約測試
 */
final class SettlementFilterInputTest extends TestCase {

	/**
	 * happy：完整輸入正確對映
	 *
	 * @group happy
	 */
	public function test_from_array_maps_all_fields(): void {
		$filter = SettlementFilterInput::from_array(
			[
				'partner_term_ids' => [ 5, 7 ],
				'shop_ids'         => [ 100 ],
				'statuses'         => [ 'pending', 'paid' ],
				'date_start'       => 1700000000,
				'date_end'         => 1700003600,
				'page'             => 3,
				'per_page'         => 50,
			]
		);

		$this->assertSame( [ 5, 7 ], $filter->partner_term_ids );
		$this->assertSame( [ 100 ], $filter->shop_ids );
		$this->assertSame( [ 'pending', 'paid' ], $filter->statuses );
		$this->assertSame( 1700000000, $filter->date_start );
		$this->assertSame( 1700003600, $filter->date_end );
		$this->assertSame( 3, $filter->page );
		$this->assertSame( 50, $filter->per_page );
	}

	/**
	 * happy：spec §4.3 的單數鍵名（partner_id / shop_id / status）同樣吃得下
	 *
	 * @group happy
	 */
	public function test_from_array_accepts_singular_keys(): void {
		$filter = SettlementFilterInput::from_array(
			[
				'partner_id' => 5,
				'shop_id'    => 100,
				'status'     => 'paid',
			]
		);

		$this->assertSame( [ 5 ], $filter->partner_term_ids );
		$this->assertSame( [ 100 ], $filter->shop_ids );
		$this->assertSame( [ 'paid' ], $filter->statuses );
	}

	/**
	 * happy：逗號分隔字串（query string 常見寫法）會被拆開
	 *
	 * @group happy
	 */
	public function test_from_array_splits_comma_separated_strings(): void {
		$filter = SettlementFilterInput::from_array(
			[
				'shop_ids' => '10,20,30',
				'statuses' => 'pending,paid',
			]
		);

		$this->assertSame( [ 10, 20, 30 ], $filter->shop_ids );
		$this->assertSame( [ 'pending', 'paid' ], $filter->statuses );
	}

	/**
	 * 🔴 security：不在白名單的 status 一律拋例外，**不可**靜默丟棄
	 *
	 * 靜默丟棄會讓過濾條件被清空後退化成「不過濾」，站長看到全部品項卻以為
	 * 自己正在看某個子集——在決定「要轉多少錢給誰」的頁面上極度危險。
	 *
	 * @group security
	 */
	public function test_unknown_status_throws_instead_of_being_dropped(): void {
		$this->expectException( \DomainException::class );

		SettlementFilterInput::from_array( [ 'statuses' => [ 'paid', 'shipped' ] ] );
	}

	/**
	 * security：SQL 注入樣式的 status 同樣被拒（不會退化成不過濾）
	 *
	 * @group security
	 */
	public function test_sql_injection_shaped_status_throws(): void {
		$this->expectException( \DomainException::class );

		SettlementFilterInput::from_array( [ 'status' => "paid' OR 1=1 --" ] );
	}

	/**
	 * happy：全部合法的 status 正常通過
	 *
	 * @group happy
	 */
	public function test_all_valid_statuses_are_accepted(): void {
		$filter = SettlementFilterInput::from_array(
			[
				'statuses' => [ 'pending', 'paid', 'refunded', 'cancelled' ],
			]
		);

		$this->assertSame( [ 'pending', 'paid', 'refunded', 'cancelled' ], $filter->statuses );
	}

	/**
	 * security：ID 欄位一律轉正整數，注入字串不會原樣留存
	 *
	 * @group security
	 */
	public function test_ids_are_coerced_to_positive_integers(): void {
		$filter = SettlementFilterInput::from_array(
			[
				'shop_ids'         => [ '5', 0, -3, 'abc', '7 OR 1=1' ],
				'partner_term_ids' => [ '  9  ' ],
			]
		);

		$this->assertSame( [ 5, 7 ], $filter->shop_ids );
		$this->assertSame( [ 9 ], $filter->partner_term_ids );
		foreach ( $filter->shop_ids as $id ) {
			$this->assertIsInt( $id );
		}
	}

	/**
	 * edge：重複 ID 去重（前端重複勾選不該讓 IN 子句膨脹）
	 *
	 * @group edge
	 */
	public function test_duplicate_ids_are_deduplicated(): void {
		$filter = SettlementFilterInput::from_array(
			[
				'shop_ids' => [ 5, 5, '5', 6 ],
				'statuses' => [ 'paid', 'paid' ],
			]
		);

		$this->assertSame( [ 5, 6 ], $filter->shop_ids );
		$this->assertSame( [ 'paid' ], $filter->statuses );
	}

	/**
	 * edge：page / per_page 被 clamp 在合理範圍（DoS 防護）
	 *
	 * @group edge
	 */
	public function test_pagination_is_clamped(): void {
		$huge = SettlementFilterInput::from_array(
			[
				'page'     => 999999999,
				'per_page' => 100000,
			]
		);
		$this->assertSame( SettlementFilterInput::MAX_PAGE, $huge->page );
		$this->assertSame( SettlementFilterInput::MAX_PER_PAGE, $huge->per_page );

		$tiny = SettlementFilterInput::from_array(
			[
				'page'     => -5,
				'per_page' => 0,
			]
		);
		$this->assertSame( 1, $tiny->page );
		$this->assertSame( 1, $tiny->per_page );
	}

	/**
	 * edge：非數字 / 非正數的時間戳視為未指定，不會退化成 1970
	 *
	 * @group edge
	 */
	public function test_invalid_timestamps_become_null(): void {
		$filter = SettlementFilterInput::from_array(
			[
				'date_start' => 'not-a-timestamp',
				'date_end'   => 0,
			]
		);

		$this->assertNull( $filter->date_start );
		$this->assertNull( $filter->date_end );
	}

	/**
	 * smoke：空輸入回安全預設值
	 *
	 * @group smoke
	 */
	public function test_empty_input_returns_safe_defaults(): void {
		$filter = SettlementFilterInput::from_array( [] );

		$this->assertSame( [], $filter->partner_term_ids );
		$this->assertSame( [], $filter->shop_ids );
		$this->assertSame( [], $filter->statuses );
		$this->assertNull( $filter->date_start );
		$this->assertNull( $filter->date_end );
		$this->assertSame( 1, $filter->page );
		$this->assertSame( 20, $filter->per_page );
	}

	/**
	 * happy：from_array / to_array round-trip 對稱
	 *
	 * @group happy
	 */
	public function test_round_trip_is_symmetric(): void {
		$original = SettlementFilterInput::from_array(
			[
				'partner_term_ids' => [ 5 ],
				'shop_ids'         => [ 100, 200 ],
				'statuses'         => [ 'pending' ],
				'date_start'       => 1700000000,
				'date_end'         => 1700003600,
				'page'             => 2,
				'per_page'         => 30,
			]
		);

		$restored = SettlementFilterInput::from_array( $original->to_array() );

		$this->assertEquals( $original->to_array(), $restored->to_array() );
	}

	/**
	 * happy：with_pagination 只換分頁、其餘條件原封不動
	 *
	 * @group happy
	 */
	public function test_with_pagination_keeps_other_conditions(): void {
		$original = SettlementFilterInput::from_array(
			[
				'partner_term_ids' => [ 5 ],
				'statuses'         => [ 'paid' ],
				'date_start'       => 1700000000,
				'page'             => 1,
				'per_page'         => 20,
			]
		);

		$paged = $original->with_pagination( 4, 100 );

		$this->assertSame( 4, $paged->page );
		$this->assertSame( 100, $paged->per_page );
		$this->assertSame( [ 5 ], $paged->partner_term_ids );
		$this->assertSame( [ 'paid' ], $paged->statuses );
		$this->assertSame( 1700000000, $paged->date_start );
		$this->assertSame( 1, $original->page, '原物件必須維持不可變' );
	}

	/**
	 * security：with_pagination 不得成為繞過 per_page 上限的後門
	 *
	 * @group security
	 */
	public function test_with_pagination_still_clamps(): void {
		$filter = SettlementFilterInput::from_array( [] )->with_pagination( 999999999, 99999 );

		$this->assertSame( SettlementFilterInput::MAX_PAGE, $filter->page );
		$this->assertSame( SettlementFilterInput::MAX_PER_PAGE, $filter->per_page );
	}
}
