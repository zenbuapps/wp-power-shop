<?php
/**
 * ShopMode ValueObject（PHP 8.1 backed enum）單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.2、§8.5
 * 規範：backed enum string；兩個 case：PAGE='page'、SHORTCODE='shortcode'
 *
 * 預期紅燈：Enum J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * ShopMode enum 測試
 */
final class ShopModeTest extends TestCase {

	/**
	 * PAGE case 的 value 為 'page'
	 *
	 * @group happy
	 */
	public function test_page_case_value_is_page(): void {
		$this->assertSame( 'page', ShopMode::PAGE->value );
	}

	/**
	 * SHORTCODE case 的 value 為 'shortcode'
	 *
	 * @group happy
	 */
	public function test_shortcode_case_value_is_shortcode(): void {
		$this->assertSame( 'shortcode', ShopMode::SHORTCODE->value );
	}

	/**
	 * from('page') 回傳 PAGE case
	 *
	 * @group happy
	 */
	public function test_from_valid_string_page_returns_page_case(): void {
		$mode = ShopMode::from( 'page' );
		$this->assertSame( ShopMode::PAGE, $mode );
	}

	/**
	 * from('shortcode') 回傳 SHORTCODE case
	 *
	 * @group happy
	 */
	public function test_from_valid_string_shortcode_returns_shortcode_case(): void {
		$mode = ShopMode::from( 'shortcode' );
		$this->assertSame( ShopMode::SHORTCODE, $mode );
	}

	/**
	 * from(非法字串) 應拋出 ValueError（PHP enum 內建行為）
	 *
	 * @group error
	 */
	public function test_from_invalid_string_throws(): void {
		$this->expectException( ValueError::class );
		ShopMode::from( 'unknown_mode' );
	}

	/**
	 * tryFrom(非法字串) 回傳 null（PHP enum 內建行為）
	 *
	 * @group edge
	 */
	public function test_try_from_invalid_returns_null(): void {
		$this->assertNull( ShopMode::tryFrom( 'invalid' ) );
	}

	/**
	 * tryFrom(合法字串) 回傳對應 case
	 *
	 * @group happy
	 */
	public function test_try_from_valid_returns_case(): void {
		$this->assertSame( ShopMode::PAGE, ShopMode::tryFrom( 'page' ) );
		$this->assertSame( ShopMode::SHORTCODE, ShopMode::tryFrom( 'shortcode' ) );
	}

	/**
	 * cases() 應只回傳兩個 case
	 *
	 * @group happy
	 */
	public function test_cases_returns_two_cases(): void {
		$cases = ShopMode::cases();
		$this->assertCount( 2, $cases );
		$this->assertContains( ShopMode::PAGE, $cases );
		$this->assertContains( ShopMode::SHORTCODE, $cases );
	}
}
