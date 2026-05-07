<?php
/**
 * profit_partner Taxonomy 註冊器整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/WordPress/TaxonomyRegistrar.php
 *
 * @group profit_shop
 * @group infrastructure
 * @group taxonomy
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;
use Tests\Integration\TestCase;

/**
 * 驗證 profit_partner taxonomy 是否被正確註冊並掛接到 powershop CPT
 */
final class TaxonomyRegistrarTest extends TestCase {

	// ========== Smoke ==========

	/**
	 * profit_partner taxonomy 必須被註冊
	 *
	 * @test
	 * @group smoke
	 * @group happy
	 */
	public function test_taxonomy_profit_partner_is_registered(): void {
		$this->assertTrue(
			\taxonomy_exists( TaxonomyRegistrar::TAXONOMY ),
			'profit_partner taxonomy 未註冊到 WordPress'
		);
	}

	// ========== Happy ==========

	/**
	 * profit_partner taxonomy 必須掛接到 powershop CPT
	 *
	 * @test
	 * @group happy
	 */
	public function test_taxonomy_attached_to_powershop_cpt(): void {
		$taxonomy = \get_taxonomy( TaxonomyRegistrar::TAXONOMY );

		$this->assertNotFalse( $taxonomy, 'get_taxonomy 應回傳 profit_partner 物件' );
		$this->assertContains(
			CptRegistrar::POST_TYPE,
			$taxonomy->object_type,
			'profit_partner 應掛接到 powershop CPT（spec §2.4）'
		);
	}

	/**
	 * profit_partner taxonomy 必須開啟 REST 支援，React SPA 才能存取
	 *
	 * @test
	 * @group happy
	 */
	public function test_taxonomy_show_in_rest(): void {
		$taxonomy = \get_taxonomy( TaxonomyRegistrar::TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		$this->assertTrue(
			$taxonomy->show_in_rest,
			'profit_partner show_in_rest 應為 true（React SPA 需求）'
		);
	}
}
