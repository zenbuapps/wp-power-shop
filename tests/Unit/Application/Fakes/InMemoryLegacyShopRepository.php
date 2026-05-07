<?php
/**
 * InMemory LegacyShopRepository 測試替身
 *
 * 對應 master 將要新增的：
 *   inc/classes/Domains/ProfitShop/Application/Service/LegacyShopRepository.php
 *   （或位於 Infrastructure 層的 LegacyPowerShopReader）
 *
 * 介面預期合約：
 * - all(): array<int, array<string, mixed>>  列出所有舊版「一頁商店」
 * - find(int $id): ?array<string, mixed>     依 ID 取得單筆
 *
 * Phase 3-B Green 階段 master 必須建立對應的 interface 與真實實作。
 *
 * 此 fake 故意不 implements 任何介面，避免被 production code 偵測；
 * UseCase 應透過建構式注入抽象，測試傳入此 fake 即可。
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Fakes;

use J7\PowerShop\Domains\ProfitShop\Application\Service\LegacyShopRepositoryInterface;

/**
 * 純記憶體 LegacyShopRepository
 */
final class InMemoryLegacyShopRepository implements LegacyShopRepositoryInterface {

	/**
	 * legacy id => 原始陣列
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $shops = [];

	/**
	 * 列出所有舊版賣場
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return array_values( $this->shops );
	}

	/**
	 * 依 ID 取得舊版賣場
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		return $this->shops[ $id ] ?? null;
	}

	/**
	 * 預植入舊版賣場
	 *
	 * @param array<string, mixed> $data 原始資料（至少需有 id）
	 */
	public function seed( array $data ): void {
		$id                = (int) ( $data['id'] ?? 0 );
		$this->shops[ $id ] = $data;
	}
}
