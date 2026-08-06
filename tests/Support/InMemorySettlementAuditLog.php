<?php
/**
 * 結算稽核紀錄測試替身（記憶體實作）
 */

declare( strict_types=1 );

namespace Tests\Support;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementAuditLogInterface;

/**
 * 記憶體版稽核紀錄
 *
 * `fail_for()` 用來模擬「狀態已寫入、稽核紀錄寫入失敗」這個部分成功情境——
 * 那正是 `SettlementBatchOutput::$audit_log_failed_partner_ids` 存在的理由。
 */
final class InMemorySettlementAuditLog implements SettlementAuditLogInterface {

	/**
	 * partner_term_id => entries
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $entries = [];

	/**
	 * 這些 partner_term_id 的 append() 會拋例外
	 *
	 * @var int[]
	 */
	private array $failing_partner_ids = [];

	/**
	 * 讓指定 partner 的 append() 拋例外
	 *
	 * @param int $partner_term_id Partner term ID
	 *
	 * @return void
	 */
	public function fail_for( int $partner_term_id ): void {
		$this->failing_partner_ids[] = $partner_term_id;
	}

	/**
	 * 追加一筆稽核紀錄
	 *
	 * @param int                  $partner_term_id Partner term ID
	 * @param array<string, mixed> $entry           稽核項目
	 *
	 * @throws \RuntimeException 當該 partner 被設定為失敗時拋出
	 *
	 * @return void
	 */
	public function append( int $partner_term_id, array $entry ): void {
		if ( in_array( $partner_term_id, $this->failing_partner_ids, true ) ) {
			throw new \RuntimeException( '模擬稽核紀錄寫入失敗' );
		}

		if ( ! isset( $this->entries[ $partner_term_id ] ) ) {
			$this->entries[ $partner_term_id ] = [];
		}
		$this->entries[ $partner_term_id ][] = $entry;
	}

	/**
	 * 讀取指定 partner 的全部稽核紀錄
	 *
	 * @param int $partner_term_id Partner term ID
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read( int $partner_term_id ): array {
		return $this->entries[ $partner_term_id ] ?? [];
	}
}
