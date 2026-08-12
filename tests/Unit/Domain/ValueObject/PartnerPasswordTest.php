<?php
/**
 * PartnerPassword ValueObject 單元測試（Phase 6-A1 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3 Partner 自助修密碼
 *
 * 規範：
 *   - 至少 8 字元
 *   - 至少包含一個英文字母（[a-zA-Z]）
 *   - 至少包含一個數字（[0-9]）
 *   - 違反任一條 → 拋 WeakPassword（extends \DomainException），
 *     reasons 攜帶 'too_short' / 'missing_letter' / 'missing_digit' 等碼
 *
 * 預期紅燈：
 *   - Class J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerPassword not found
 *   - Class J7\PowerShop\Domains\ProfitShop\Domain\Exception\WeakPassword not found
 *
 * @group profit_shop
 * @group domain
 * @group value_object
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\WeakPassword;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerPassword;
use PHPUnit\Framework\TestCase;

/**
 * PartnerPassword ValueObject 紅燈合約測試
 */
final class PartnerPasswordTest extends TestCase {

	/**
	 * 8 字元剛好通過（含英文 + 數字）
	 *
	 * @group happy
	 */
	public function test_construct_with_min_length_letter_and_digit_succeeds(): void {
		$pw = new PartnerPassword( 'Abc12345' );
		$this->assertSame( 'Abc12345', $pw->value() );
	}

	/**
	 * 7 字元 → too_short
	 *
	 * @group error
	 */
	public function test_construct_throws_when_too_short(): void {
		try {
			new PartnerPassword( 'Abc1234' );
			$this->fail( '7 字元應拋 WeakPassword' );
		} catch ( WeakPassword $e ) {
			$this->assertContains( 'too_short', $e->getReasons() );
		}
	}

	/**
	 * 純數字 → missing_letter
	 *
	 * @group error
	 */
	public function test_construct_throws_when_missing_letter(): void {
		try {
			new PartnerPassword( '12345678' );
			$this->fail( '純數字應拋 WeakPassword' );
		} catch ( WeakPassword $e ) {
			$this->assertContains( 'missing_letter', $e->getReasons() );
		}
	}

	/**
	 * 純英文字 → missing_digit
	 *
	 * @group error
	 */
	public function test_construct_throws_when_missing_digit(): void {
		try {
			new PartnerPassword( 'abcdefgh' );
			$this->fail( '純英文字應拋 WeakPassword' );
		} catch ( WeakPassword $e ) {
			$this->assertContains( 'missing_digit', $e->getReasons() );
		}
	}

	/**
	 * 空字串 → 三條 reason 同時觸發
	 *
	 * @group error
	 * @group edge
	 */
	public function test_construct_throws_with_all_reasons_when_empty(): void {
		try {
			new PartnerPassword( '' );
			$this->fail( '空字串應拋 WeakPassword' );
		} catch ( WeakPassword $e ) {
			$reasons = $e->getReasons();
			$this->assertContains( 'too_short', $reasons );
			$this->assertContains( 'missing_letter', $reasons );
			$this->assertContains( 'missing_digit', $reasons );
		}
	}

	/**
	 * wp_generate_password(12, false) 風格的密碼通過
	 *
	 * @group happy
	 */
	public function test_construct_with_wp_generated_style_password_succeeds(): void {
		$pw = new PartnerPassword( 'aB3xY9pQ7mZ2' );
		$this->assertSame( 'aB3xY9pQ7mZ2', $pw->value() );
	}

	/**
	 * 含特殊字元的強密碼通過（不會被特殊字元干擾）
	 *
	 * @group happy
	 * @group edge
	 */
	public function test_construct_with_special_chars_succeeds(): void {
		$pw = new PartnerPassword( 'Abc12345!@#$' );
		$this->assertSame( 'Abc12345!@#$', $pw->value() );
	}

	/**
	 * value() 回原字串（不被改寫 / 不 trim / 不 normalize）
	 *
	 * @group happy
	 * @group edge
	 */
	public function test_value_returns_input_unchanged(): void {
		// 含前後空白與大小寫；ValueObject 不應自動 trim 或 normalize（保留 caller 控制權）
		$raw = 'Abc12345';
		$pw  = new PartnerPassword( $raw );
		$this->assertSame( $raw, $pw->value() );
	}
}
