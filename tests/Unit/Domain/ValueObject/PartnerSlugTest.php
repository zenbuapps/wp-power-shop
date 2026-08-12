<?php
/**
 * PartnerSlug ValueObject 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4、§6.11、§7.3、§8.5
 * 規範：長度 1–60；regex /^[a-z0-9_-]+$/；保留字黑名單；違反拋 InvalidPartnerSlug（extends \DomainException）
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use PHPUnit\Framework\TestCase;

/**
 * PartnerSlug ValueObject 測試
 */
final class PartnerSlugTest extends TestCase {

	/**
	 * 純小寫字母 slug 為合法
	 *
	 * @group happy
	 */
	public function test_construct_with_simple_lowercase_slug_succeeds(): void {
		$slug = new PartnerSlug( 'jerry' );
		$this->assertSame( 'jerry', $slug->value() );
	}

	/**
	 * 含底線與連字號的 slug 為合法
	 *
	 * @group happy
	 */
	public function test_construct_with_underscore_and_dash_succeeds(): void {
		$slug = new PartnerSlug( 'jerry_liu-2026' );
		$this->assertSame( 'jerry_liu-2026', $slug->value() );
	}

	/**
	 * 空字串應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_empty(): void {
		$this->expectException( InvalidPartnerSlug::class );
		new PartnerSlug( '' );
	}

	/**
	 * 長度超過 60 字元應拋出例外
	 *
	 * @group error
	 */
	public function test_construct_throws_when_too_long_61_chars(): void {
		$this->expectException( InvalidPartnerSlug::class );
		new PartnerSlug( str_repeat( 'a', 61 ) );
	}

	/**
	 * 含大寫字母應拋出例外（防大小寫衝突的同時也防 URL 碰撞）
	 *
	 * @group security
	 */
	public function test_construct_throws_when_contains_uppercase(): void {
		$this->expectException( InvalidPartnerSlug::class );
		new PartnerSlug( 'Jerry' );
	}

	/**
	 * 含中文字元應拋出例外
	 *
	 * @group security
	 */
	public function test_construct_throws_when_contains_chinese(): void {
		$this->expectException( InvalidPartnerSlug::class );
		new PartnerSlug( '老師' );
	}

	/**
	 * 含 emoji 應拋出例外
	 *
	 * @group security
	 */
	public function test_construct_throws_when_contains_emoji(): void {
		$this->expectException( InvalidPartnerSlug::class );
		new PartnerSlug( 'jerry🎉' );
	}

	/**
	 * 保留字 admin 應被拒絕
	 *
	 * @group security
	 */
	public function test_construct_throws_when_reserved_admin(): void {
		$this->expectException( InvalidPartnerSlug::class );
		new PartnerSlug( 'admin' );
	}

	/**
	 * 保留字 root 應被拒絕
	 *
	 * @group security
	 */
	public function test_construct_throws_when_reserved_root(): void {
		$this->expectException( InvalidPartnerSlug::class );
		new PartnerSlug( 'root' );
	}

	/**
	 * 保留字 profit-report 應被拒絕（防衝突報表 URL）
	 *
	 * @group security
	 */
	public function test_construct_throws_when_reserved_profit_report(): void {
		$this->expectException( InvalidPartnerSlug::class );
		new PartnerSlug( 'profit-report' );
	}

	/**
	 * value() 回傳 string 型別
	 *
	 * @group happy
	 */
	public function test_value_returns_string(): void {
		$slug = new PartnerSlug( 'luke' );
		$this->assertIsString( $slug->value() );
		$this->assertSame( 'luke', $slug->value() );
	}
}
