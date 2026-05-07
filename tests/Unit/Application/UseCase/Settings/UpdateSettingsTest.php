<?php
/**
 * UpdateSettings UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8（PUT profit-settings）、§6.5、§6.11
 *
 * 業務規則：
 *   - PUT 後必須呼叫 RewriteRules::flush_rules_if_needed()，否則 slug 變更不會生效
 *   - 寫入前必須對 rewrite_slug / report_slug 跑 SlugConflictDetector 防止綁架既有路由
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group settings
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Settings;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings\UpdateSettings;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemorySettingsRepository;
use Tests\Unit\Application\Fakes\SpyRewriteRulesFlusher;

/**
 * UpdateSettings UseCase 測試
 */
final class UpdateSettingsTest extends TestCase {

	/**
	 * happy：寫入後 settings 已變更
	 *
	 * @group happy
	 */
	public function test_persists_settings(): void {
		$repo     = new InMemorySettingsRepository();
		$flusher  = new SpyRewriteRulesFlusher();
		$detector = $this->makeNoopDetector();

		$useCase = new UpdateSettings(
			settingsRepo: $repo,
			flusher: $flusher,
			slugDetector: $detector
		);

		$dto = new SettingsDto(
			rewrite_slug: 'profit-shops',
			report_slug: 'profit-report',
			default_rate: 20,
			page_template: 'banner-top'
		);

		$useCase->execute( $dto );

		$current = $repo->get();
		$this->assertSame( 'profit-shops', $current->rewrite_slug );
		$this->assertSame( 'profit-report', $current->report_slug );
		$this->assertSame( 20, $current->default_rate );
		$this->assertSame( 'banner-top', $current->page_template );
	}

	/**
	 * happy：UpdateSettings 後一定會呼叫 RewriteRules::flush_rules_if_needed
	 *
	 * @group happy
	 */
	public function test_calls_flush_rules_if_needed(): void {
		$repo     = new InMemorySettingsRepository();
		$flusher  = new SpyRewriteRulesFlusher();
		$detector = $this->makeNoopDetector();

		$useCase = new UpdateSettings(
			settingsRepo: $repo,
			flusher: $flusher,
			slugDetector: $detector
		);

		$dto = new SettingsDto(
			rewrite_slug: 'kol-shop',
			report_slug: 'profit-report',
			default_rate: 5,
			page_template: 'default'
		);

		$useCase->execute( $dto );

		$this->assertSame(
			1,
			$flusher->call_count(),
			'spec §4.8：UpdateSettings 後應呼叫 flush_rules_if_needed 一次'
		);
	}

	/**
	 * edge：default_rate 邊界值 0 與 100 可寫入
	 *
	 * @group edge
	 */
	public function test_accepts_default_rate_at_boundaries(): void {
		$repo     = new InMemorySettingsRepository();
		$flusher  = new SpyRewriteRulesFlusher();
		$detector = $this->makeNoopDetector();

		$useCase = new UpdateSettings(
			settingsRepo: $repo,
			flusher: $flusher,
			slugDetector: $detector
		);

		$useCase->execute(
			new SettingsDto( 'shop', 'report', 0, 'default' )
		);
		$this->assertSame( 0, $repo->get()->default_rate );

		$useCase->execute(
			new SettingsDto( 'shop', 'report', 100, 'default' )
		);
		$this->assertSame( 100, $repo->get()->default_rate );
	}

	/**
	 * H1：rewrite_slug 偵測到衝突 → 拋出 SlugConflictException、不寫入、不 flush
	 *
	 * @group error
	 * @group security
	 */
	public function test_throws_slug_conflict_when_rewrite_slug_is_wp_admin(): void {
		$repo     = new InMemorySettingsRepository();
		$flusher  = new SpyRewriteRulesFlusher();
		$detector = $this->makeAlwaysConflictDetector();

		$useCase = new UpdateSettings(
			settingsRepo: $repo,
			flusher: $flusher,
			slugDetector: $detector
		);

		$dto = new SettingsDto(
			rewrite_slug: 'wp-admin',
			report_slug: 'safe-report',
			default_rate: 10,
			page_template: 'default'
		);

		$thrown = false;
		try {
			$useCase->execute( $dto );
		} catch ( SlugConflictException $e ) {
			$thrown = true;
			$this->assertNotEmpty( $e->getConflicts(), 'SlugConflictException 必須攜帶 conflicts payload' );
		}

		$this->assertTrue( $thrown, '應拋出 SlugConflictException' );
		$this->assertSame( 0, $flusher->call_count(), 'flush 不該被呼叫（衝突早於 save）' );
	}

	/**
	 * 建立 always-noop 的 detector（永遠回傳空陣列 = 無衝突）
	 */
	private function makeNoopDetector(): SlugConflictDetectorInterface {
		return new class() implements SlugConflictDetectorInterface {
			public function detect( string $slug, string $context ): array {
				return [];
			}
		};
	}

	/**
	 * 建立 always-conflict 的 detector（永遠回傳一筆衝突）
	 */
	private function makeAlwaysConflictDetector(): SlugConflictDetectorInterface {
		return new class() implements SlugConflictDetectorInterface {
			public function detect( string $slug, string $context ): array {
				return [
					new SlugConflict(
						conflict_kind: 'wp_reserved',
						conflicting_slug: $slug,
						conflicting_id: null,
						conflicting_label: 'fake conflict'
					),
				];
			}
		};
	}
}
