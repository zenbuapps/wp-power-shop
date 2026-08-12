<?php
/**
 * ListImportableLegacyShops UseCase 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7（GET profit-migration/legacy-shops）
 *
 * @group profit_shop
 * @group application
 * @group usecase
 * @group migration
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\UseCase\Migration;

use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\ListImportableLegacyShops;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\Fakes\InMemoryLegacyShopRepository;

/**
 * ListImportableLegacyShops UseCase 測試
 */
final class ListImportableLegacyShopsTest extends TestCase {

	private InMemoryLegacyShopRepository $legacyRepo;

	protected function setUp(): void {
		parent::setUp();
		$this->legacyRepo = new InMemoryLegacyShopRepository();
	}

	/**
	 * happy：legacy 有資料 → 全部回傳，每筆含 id / title / can_import 等基本欄位
	 *
	 * @group happy
	 */
	public function test_lists_all_legacy_shops(): void {
		$this->legacyRepo->seed(
			[
				'id'    => 11,
				'title' => '舊版 A',
				'meta'  => [ 'banner_url' => 'https://example.com/a.jpg' ],
			]
		);
		$this->legacyRepo->seed(
			[
				'id'    => 12,
				'title' => '舊版 B',
				'meta'  => [],
			]
		);

		$useCase = new ListImportableLegacyShops( legacyRepo: $this->legacyRepo );

		$output = $useCase->execute();

		$this->assertCount( 2, $output );
	}

	/**
	 * edge：legacy 為空 → 回空陣列
	 *
	 * @group edge
	 */
	public function test_returns_empty_when_no_legacy_shops(): void {
		$useCase = new ListImportableLegacyShops( legacyRepo: $this->legacyRepo );

		$this->assertSame( [], $useCase->execute() );
	}

	/**
	 * happy：含 banner / btn_color 等可匯入設定的 legacy 仍應列出（spec §4.7 不過濾）
	 *
	 * @group happy
	 */
	public function test_lists_legacy_with_settings_included(): void {
		$this->legacyRepo->seed(
			[
				'id'    => 21,
				'title' => '帶 banner 的舊版',
				'meta'  => [
					'banner_url' => 'https://example.com/banner.jpg',
					'btn_color'  => '#1677ff',
				],
			]
		);

		$useCase = new ListImportableLegacyShops( legacyRepo: $this->legacyRepo );

		$output = $useCase->execute();

		$this->assertCount( 1, $output );
	}
}
