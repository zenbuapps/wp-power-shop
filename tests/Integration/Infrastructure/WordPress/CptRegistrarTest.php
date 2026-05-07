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
	 * 測試後清除自訂 settings option，避免污染其他 test class
	 */
	public function tear_down(): void {
		\delete_option( CptRegistrar::OPTIONS_KEY );
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
	 * 未設定 settings option 時，rewrite slug 應為預設值 'powershop'
	 *
	 * @test
	 * @group happy
	 */
	public function test_cpt_rewrite_slug_uses_default_when_option_absent(): void {
		\delete_option( CptRegistrar::OPTIONS_KEY );

		$slug = CptRegistrar::get_rewrite_slug();

		$this->assertSame(
			CptRegistrar::DEFAULT_REWRITE_SLUG,
			$slug,
			'未設定 settings 時應回傳預設 rewrite slug'
		);
	}

	/**
	 * 設定 settings.rewrite_slug 後，重新呼叫 register() rewrite slug 應反映設定值
	 *
	 * @test
	 * @group happy
	 */
	public function test_cpt_rewrite_slug_respects_option(): void {
		\update_option(
			CptRegistrar::OPTIONS_KEY,
			[ 'rewrite_slug' => 'fanrun' ]
		);

		$this->assertSame( 'fanrun', CptRegistrar::get_rewrite_slug() );

		// 重新觸發 register（init priority 11 已執行過，此處驗證結果一致）
		CptRegistrar::register();

		$post_type = \get_post_type_object( CptRegistrar::POST_TYPE );
		$this->assertNotNull( $post_type );
		$this->assertSame(
			'fanrun',
			$post_type->rewrite['slug'] ?? '',
			'設定 rewrite_slug = fanrun 後，CPT 的 rewrite slug 應同步變更'
		);
	}
}
