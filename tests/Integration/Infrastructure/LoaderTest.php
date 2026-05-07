<?php
/**
 * 分潤賣場 Domain Loader 整合測試
 *
 * 對應實作：inc/classes/Domains/ProfitShop/Loader.php
 *
 * @group profit_shop
 * @group infrastructure
 * @group loader
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure;

use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;
use J7\PowerShop\Domains\ProfitShop\Loader;
use Tests\Integration\TestCase;

/**
 * 驗證 ProfitShop Loader 真的有把三個 Registrar 串接起來，且 hook 都註冊到 init action
 */
final class LoaderTest extends TestCase {

	// ========== Smoke ==========

	/**
	 * Loader 必須掛上 CPT / Taxonomy / RewriteRules 三個 init hook
	 *
	 * @test
	 * @group smoke
	 */
	public function test_loader_registers_all_three_init_hooks(): void {
		// Singleton 應已在 bootstrap 階段被實例化
		$this->assertInstanceOf( Loader::class, Loader::instance() );

		$this->assertSame(
			11,
			\has_action( 'init', [ CptRegistrar::class, 'register' ] ),
			'CptRegistrar::register 應掛在 init priority 11'
		);
		$this->assertSame(
			11,
			\has_action( 'init', [ TaxonomyRegistrar::class, 'register' ] ),
			'TaxonomyRegistrar::register 應掛在 init priority 11'
		);
		$this->assertSame(
			12,
			\has_action( 'init', [ RewriteRules::class, 'register' ] ),
			'RewriteRules::register 應掛在 init priority 12（晚於 CPT/Taxonomy）'
		);
	}
}
