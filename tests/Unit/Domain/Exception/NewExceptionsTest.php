<?php
/**
 * Phase 3-A 新增 9 個 Domain Exception 行為測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6 / §7
 *
 * 涵蓋：
 *   - PartnerNotFound
 *   - ProductNotFound
 *   - InvalidVariation
 *   - InvalidCredentials
 *   - TooManyAttempts
 *   - Forbidden
 *   - SlugConflictException
 *   - RateLimitExceeded
 *   - LegacyShopNotImportable
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Domain\Exception\<Name> not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Exception;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\Forbidden;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidCredentials;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidVariation;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\LegacyShopNotImportable;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\RateLimitExceeded;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\TooManyAttempts;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3-A 新 9 個 Exception 行為測試
 */
final class NewExceptionsTest extends TestCase {

	// ────────────────────────────────────────────────
	// PartnerNotFound
	// ────────────────────────────────────────────────

	/**
	 * PartnerNotFound 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_partner_not_found_extends_domain_exception(): void {
		$ex = new PartnerNotFound( '找不到合作夥伴' );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * PartnerNotFound 可承載自訂訊息
	 *
	 * @group happy
	 */
	public function test_partner_not_found_carries_custom_message(): void {
		$ex = new PartnerNotFound( '找不到合作夥伴：slug=alice' );

		$this->assertSame( '找不到合作夥伴：slug=alice', $ex->getMessage() );
	}

	// ────────────────────────────────────────────────
	// ProductNotFound
	// ────────────────────────────────────────────────

	/**
	 * ProductNotFound 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_product_not_found_extends_domain_exception(): void {
		$ex = new ProductNotFound( '找不到商品' );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * ProductNotFound 可承載自訂訊息
	 *
	 * @group happy
	 */
	public function test_product_not_found_carries_custom_message(): void {
		$ex = new ProductNotFound( '找不到商品 ID=123' );

		$this->assertSame( '找不到商品 ID=123', $ex->getMessage() );
	}

	// ────────────────────────────────────────────────
	// InvalidVariation
	// ────────────────────────────────────────────────

	/**
	 * InvalidVariation 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_invalid_variation_extends_domain_exception(): void {
		$ex = new InvalidVariation( '不合法的商品變體' );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * InvalidVariation 可承載自訂訊息
	 *
	 * @group happy
	 */
	public function test_invalid_variation_carries_custom_message(): void {
		$ex = new InvalidVariation( '商品變體 456 不屬於商品 123' );

		$this->assertSame( '商品變體 456 不屬於商品 123', $ex->getMessage() );
	}

	// ────────────────────────────────────────────────
	// InvalidCredentials（不洩漏使用者是否存在）
	// ────────────────────────────────────────────────

	/**
	 * InvalidCredentials 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_invalid_credentials_extends_domain_exception(): void {
		$ex = new InvalidCredentials();

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * InvalidCredentials 訊息為固定字樣，不應洩漏「使用者是否存在」相關資訊
	 *
	 * 這條測試確保 message 不會出現 user input（slug/email/password）等 PII 或
	 * 「使用者不存在」「密碼錯誤」等可區分性提示。
	 *
	 * @group security
	 */
	public function test_invalid_credentials_message_does_not_leak_user_existence(): void {
		$ex = new InvalidCredentials();

		$message = $ex->getMessage();

		// 訊息必須是固定字樣（非空字串）
		$this->assertNotSame( '', $message );

		// 不可包含「使用者不存在 / 密碼錯誤」等可區分性提示
		$this->assertStringNotContainsString( '使用者不存在', $message );
		$this->assertStringNotContainsString( '密碼錯誤', $message );
		$this->assertStringNotContainsString( 'user not found', strtolower( $message ) );
		$this->assertStringNotContainsString( 'wrong password', strtolower( $message ) );
	}

	// ────────────────────────────────────────────────
	// TooManyAttempts（含 retryAfter）
	// ────────────────────────────────────────────────

	/**
	 * TooManyAttempts 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_too_many_attempts_extends_domain_exception(): void {
		$ex = new TooManyAttempts( 60 );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * TooManyAttempts 應透過 getRetryAfter() 回傳建構子注入的秒數
	 *
	 * @group happy
	 */
	public function test_too_many_attempts_carries_retry_after(): void {
		$ex = new TooManyAttempts( 90 );

		$this->assertSame( 90, $ex->getRetryAfter() );
	}

	// ────────────────────────────────────────────────
	// Forbidden
	// ────────────────────────────────────────────────

	/**
	 * Forbidden 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_forbidden_extends_domain_exception(): void {
		$ex = new Forbidden( '無權執行此操作' );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * Forbidden 可承載自訂訊息
	 *
	 * @group happy
	 */
	public function test_forbidden_carries_custom_message(): void {
		$ex = new Forbidden( '此 partner 無權編輯該賣場' );

		$this->assertSame( '此 partner 無權編輯該賣場', $ex->getMessage() );
	}

	// ────────────────────────────────────────────────
	// SlugConflictException（承載 SlugConflict[]）
	// ────────────────────────────────────────────────

	/**
	 * SlugConflictException 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_slug_conflict_exception_extends_domain_exception(): void {
		$conflict = new SlugConflict(
			conflict_kind: 'profit_shop',
			conflicting_slug: 'summer',
			conflicting_id: 99,
			conflicting_label: '夏季賣場',
		);
		$ex       = new SlugConflictException( [ $conflict ] );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * SlugConflictException 應透過 getConflicts() 回傳建構子注入的 SlugConflict 陣列
	 *
	 * @group happy
	 */
	public function test_slug_conflict_exception_returns_conflicts(): void {
		$c1 = new SlugConflict(
			conflict_kind: 'profit_shop',
			conflicting_slug: 'summer',
			conflicting_id: 99,
			conflicting_label: '夏季賣場',
		);
		$c2 = new SlugConflict(
			conflict_kind: 'product',
			conflicting_slug: 'summer',
			conflicting_id: 200,
			conflicting_label: '夏季 T-shirt',
		);

		$ex = new SlugConflictException( [ $c1, $c2 ] );

		$conflicts = $ex->getConflicts();
		$this->assertCount( 2, $conflicts );
		$this->assertSame( $c1, $conflicts[0] );
		$this->assertSame( $c2, $conflicts[1] );
	}

	/**
	 * SlugConflictException 至少應承載 1 筆 conflict
	 *
	 * @group edge
	 */
	public function test_slug_conflict_exception_has_at_least_one_conflict(): void {
		$conflict = new SlugConflict(
			conflict_kind: 'profit_shop',
			conflicting_slug: 'summer',
			conflicting_id: 99,
			conflicting_label: '夏季賣場',
		);
		$ex       = new SlugConflictException( [ $conflict ] );

		$this->assertGreaterThanOrEqual( 1, count( $ex->getConflicts() ) );
	}

	// ────────────────────────────────────────────────
	// RateLimitExceeded（含 retryAfter）
	// ────────────────────────────────────────────────

	/**
	 * RateLimitExceeded 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_rate_limit_exceeded_extends_domain_exception(): void {
		$ex = new RateLimitExceeded( 30 );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * RateLimitExceeded 應透過 getRetryAfter() 回傳建構子注入的秒數
	 *
	 * @group happy
	 */
	public function test_rate_limit_exceeded_carries_retry_after(): void {
		$ex = new RateLimitExceeded( 120 );

		$this->assertSame( 120, $ex->getRetryAfter() );
	}

	// ────────────────────────────────────────────────
	// LegacyShopNotImportable（含 reason）
	// ────────────────────────────────────────────────

	/**
	 * LegacyShopNotImportable 應繼承 \DomainException
	 *
	 * @group happy
	 */
	public function test_legacy_shop_not_importable_extends_domain_exception(): void {
		$ex = new LegacyShopNotImportable( 'partner_term_missing' );

		$this->assertInstanceOf( \DomainException::class, $ex );
	}

	/**
	 * LegacyShopNotImportable 應透過 getReason() 回傳建構子注入的原因字串
	 *
	 * @group happy
	 */
	public function test_legacy_shop_not_importable_carries_reason(): void {
		$ex = new LegacyShopNotImportable( 'partner_term_missing' );

		$this->assertSame( 'partner_term_missing', $ex->getReason() );
	}
}
