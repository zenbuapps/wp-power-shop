<?php
/**
 * powershop CPT 註冊器整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §1.1、§2.2、§6.7
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/WordPress/CptRegistrar.php
 *
 * @group profit_shop
 * @group infrastructure
 * @group cpt
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use Tests\Integration\TestCase;

/**
 * 驗證 powershop CPT 是否被正確註冊到 WordPress 全域註冊表
 */
final class CptRegistrarTest extends TestCase {

	/**
	 * 舊版全域設定的 wp_options key
	 *
	 * ⚠️ 刻意寫成字面值而非引用常數——`CptRegistrar::OPTIONS_KEY` 已隨全域設定頁移除，
	 * production 端**不該**再有這個常數（有的話代表 slug 又變回可設定值了）。
	 * 測試需要它只是為了驗證「殘留的舊 option 完全不影響取值」。
	 */
	private const LEGACY_SETTINGS_OPTION = 'power_shop_profit_settings';

	/**
	 * 測試後清除殘留 settings option，避免污染其他 test class
	 */
	public function tear_down(): void {
		\delete_option( self::LEGACY_SETTINGS_OPTION );
		parent::tear_down();
	}

	// ========== Smoke ==========

	/**
	 * powershop CPT 必須被註冊
	 *
	 * @test
	 * @group smoke
	 * @group happy
	 */
	public function test_cpt_powershop_is_registered(): void {
		$this->assertTrue(
			\post_type_exists( CptRegistrar::POST_TYPE ),
			'powershop CPT 未註冊到 WordPress'
		);
	}

	// ========== Happy ==========

	/**
	 * powershop CPT 必須開啟 REST 支援（show_in_rest = true），React SPA 才能透過 REST 操作
	 *
	 * @test
	 * @group happy
	 */
	public function test_cpt_supports_block_editor_via_rest(): void {
		$post_type = \get_post_type_object( CptRegistrar::POST_TYPE );

		$this->assertNotNull( $post_type, 'get_post_type_object 應回傳 powershop 物件' );
		$this->assertTrue(
			$post_type->show_in_rest,
			'powershop CPT show_in_rest 應為 true（spec §1.1 React SPA 需求）'
		);
		$this->assertSame(
			CptRegistrar::POST_TYPE,
			$post_type->rest_base,
			'powershop CPT rest_base 應等於 post_type 字串'
		);
	}

	/**
	 * 沒有殘留 option 時，rewrite slug 為固定常數 'powershop'
	 *
	 * @test
	 * @group happy
	 */
	public function test_cpt_rewrite_slug_uses_default_when_option_absent(): void {
		\delete_option( self::LEGACY_SETTINGS_OPTION );

		$this->assertSame(
			CptRegistrar::DEFAULT_REWRITE_SLUG,
			CptRegistrar::get_rewrite_slug(),
			'rewrite slug 應為固定常數'
		);
	}

	/**
	 * 回歸鎖：rewrite slug 是固定常數，wp_options 殘留設定一律無效
	 *
	 * ⚠️ 本測試原本斷言的是相反行為（`test_cpt_rewrite_slug_respects_option`：
	 * 設定 `rewrite_slug = fanrun` 後 CPT 的 rewrite slug 應同步變更）。
	 * 全域設定頁移除後契約反轉——`rewrite_slug` 產生的 `/{slug}/{post-name}/`
	 * 是條沒有任何程式碼連過去、打開只有佈景主題 single + `post_content` 的半殘網址
	 * （商品 grid 只在 `ProfitShopRenderer` 走 `profit_shop_slug` query var 時才渲染），
	 * 讓站長設定它沒有任何正面價值。
	 *
	 * 這條同時是 `RewriteRules::REWRITE_VERSION` 版本比對式 flush 的前提：
	 * 那個機制假設 rewrite 規則全是常數，若 slug 又變回可設定值，會出現
	 * 「同版本號、不同規則」的站台。
	 *
	 * @test
	 * @group edge
	 */
	public function test_cpt_rewrite_slug_ignores_stale_option(): void {
		\update_option( self::LEGACY_SETTINGS_OPTION, [ 'rewrite_slug' => 'fanrun' ] );

		$this->assertSame(
			CptRegistrar::DEFAULT_REWRITE_SLUG,
			CptRegistrar::get_rewrite_slug(),
			'殘留的 rewrite_slug 設定必須被忽略'
		);

		CptRegistrar::register();

		$post_type = \get_post_type_object( CptRegistrar::POST_TYPE );
		$this->assertNotNull( $post_type );
		$this->assertSame(
			CptRegistrar::DEFAULT_REWRITE_SLUG,
			$post_type->rewrite['slug'] ?? '',
			'CPT 註冊時用的也必須是常數，不是殘留設定值'
		);
	}
}
