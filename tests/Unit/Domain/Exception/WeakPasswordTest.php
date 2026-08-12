<?php
/**
 * WeakPassword Exception 單元測試（Phase 6-A1 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§7
 *
 * 紅燈合約：
 *   final class WeakPassword extends \DomainException {
 *     public function __construct(string[] $reasons, string $message = ...);
 *     public function getReasons(): string[];
 *   }
 *
 * @group profit_shop
 * @group domain
 * @group exception
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Exception;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\WeakPassword;
use PHPUnit\Framework\TestCase;

/**
 * WeakPassword Exception 紅燈合約測試
 */
final class WeakPasswordTest extends TestCase {

	/**
	 * constructor 接受 reasons array → getReasons() 回原 array
	 *
	 * @group happy
	 */
	public function test_constructor_preserves_reasons(): void {
		$reasons = [ 'too_short', 'missing_digit' ];
		$ex      = new WeakPassword( $reasons );

		$this->assertSame( $reasons, $ex->getReasons() );
	}

	/**
	 * getMessage() 含可讀繁中訊息或某項 reason 標記
	 *
	 * 不鎖死文案（避免後續微調文案就破壞測試），但要能讓 admin / log 一眼看出是「弱密碼」
	 *
	 * @group happy
	 */
	public function test_message_indicates_weak_password(): void {
		$ex      = new WeakPassword( [ 'too_short' ] );
		$message = $ex->getMessage();

		$contains_chinese = ( false !== strpos( $message, '弱' ) ) || ( false !== strpos( $message, '密碼' ) );
		$contains_reason  = false !== strpos( $message, 'too_short' );

		$this->assertTrue(
			$contains_chinese || $contains_reason,
			'WeakPassword::getMessage() 應包含繁中關鍵字（弱 / 密碼）或 reason code（如 too_short），實際訊息：' . $message
		);
	}

	/**
	 * 鎖死 instanceof \DomainException（避免有人改 extends \Exception 後 ExceptionMapper fallback 對映漏接）
	 *
	 * @group security
	 */
	public function test_extends_domain_exception(): void {
		$ex = new WeakPassword( [ 'too_short' ] );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}
}
