<?php
/**
 * 後台結算查詢 Provider 測試替身（記憶體實作）
 */

declare( strict_types=1 );

namespace Tests\Support;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementFilterInput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementQueryProviderInterface;

/**
 * 記憶體版後台結算查詢 Provider
 *
 * 以 `seed_rows()` 預埋一整批列，並在本 fake 內部自行做分頁切片——
 * 讓「匯出必須翻完所有頁」這件事能被真正驗證（若 fake 每次都回全部，
 * `ExportSettlementsUseCase` 的翻頁迴圈壞掉也測不出來）。
 *
 * `$filters_seen` 記錄每次呼叫收到的 filter，供「摘要與列表吃同一組條件」、
 * 「匯出忽略傳入 page」等契約斷言。
 */
final class InMemorySettlementQueryProvider implements SettlementQueryProviderInterface {

	/**
	 * 預埋的全部資料列
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * 預埋的摘要（未設定時由 rows 推不出來，直接回全 0）
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $summary = null;

	/**
	 * 每次 find_settlements() 收到的 filter
	 *
	 * @var array<int, SettlementFilterInput>
	 */
	public array $filters_seen = [];

	/**
	 * 每次 summarize_settlements() 收到的 filter
	 *
	 * @var array<int, SettlementFilterInput>
	 */
	public array $summary_filters_seen = [];

	/**
	 * 預埋資料列
	 *
	 * @param array<int, array<string, mixed>> $rows 資料列
	 *
	 * @return void
	 */
	public function seed_rows( array $rows ): void {
		$this->rows = array_values( $rows );
	}

	/**
	 * 預埋摘要
	 *
	 * @param array<string, mixed> $summary 摘要
	 *
	 * @return void
	 */
	public function seed_summary( array $summary ): void {
		$this->summary = $summary;
	}

	/**
	 * 依過濾條件取得結算列表（本 fake 只做分頁切片，不做欄位過濾）
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function find_settlements( SettlementFilterInput $filter ): array {
		$this->filters_seen[] = $filter;

		$offset = ( max( 1, $filter->page ) - 1 ) * max( 1, $filter->per_page );

		return array_slice( $this->rows, $offset, max( 1, $filter->per_page ) );
	}

	/**
	 * 依過濾條件取得總筆數
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return int
	 */
	public function count_settlements( SettlementFilterInput $filter ): int {
		return count( $this->rows );
	}

	/**
	 * 依過濾條件取得摘要
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return array<string, mixed>
	 */
	public function summarize_settlements( SettlementFilterInput $filter ): array {
		$this->summary_filters_seen[] = $filter;

		return $this->summary ?? [];
	}

	/**
	 * 依過濾條件取得 order_id 清單（本 fake 從 rows 推導）
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 * @param int                   $limit  上限
	 *
	 * @return int[]
	 */
	public function find_order_ids( SettlementFilterInput $filter, int $limit ): array {
		return array_slice( $this->distinct_order_ids(), 0, max( 0, $limit ) );
	}

	/**
	 * 依過濾條件取得訂單總數（去重）
	 *
	 * @param SettlementFilterInput $filter 過濾條件
	 *
	 * @return int
	 */
	public function count_order_ids( SettlementFilterInput $filter ): int {
		return count( $this->distinct_order_ids() );
	}

	/**
	 * 從預埋的 rows 取出去重後的 order_id
	 *
	 * @return int[]
	 */
	private function distinct_order_ids(): array {
		$ids = [];
		foreach ( $this->rows as $row ) {
			$id = (int) ( $row['order_id'] ?? 0 );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
