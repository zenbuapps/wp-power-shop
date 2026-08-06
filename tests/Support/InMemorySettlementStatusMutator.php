<?php
/**
 * 結算狀態原子轉換測試替身（記憶體實作）
 */

declare( strict_types=1 );

namespace Tests\Support;

use J7\PowerShop\Domains\ProfitShop\Application\Service\SettlementStatusMutatorInterface;

/**
 * 記憶體版 compare-and-set
 *
 * 🔴 本 fake **必須真的實作 compare-and-set 語意**（比對 `$from` 才寫入），
 * 不能無條件回 true。若它總是成功，「第二位管理員沒搶到」這條路徑在單元測試裡
 * 就永遠走不到——而那正是防止重複撥款的關鍵分支。
 *
 * 額外能力（測試專用）：
 *   - `seed_status()`：預埋初始狀態
 *   - `$cas_calls`：記錄每次 CAS 的 (id, from, to, won)，供順序 / 次數斷言
 *   - `$settled`：記錄 `mark_settled()` 的寫入，驗證「只有搶到的那一方才寫」
 *   - `simulate_race_for()`：模擬「讀出來是 A、但 CAS 當下已被別人改掉」的競態
 */
final class InMemorySettlementStatusMutator implements SettlementStatusMutatorInterface {

	/**
	 * order_item_id => 當前狀態（未設定者視為狀態 meta 不存在）
	 *
	 * @var array<int, string>
	 */
	private array $statuses = [];

	/**
	 * 每次 compare_and_set() 的紀錄
	 *
	 * @var array<int, array{order_item_id: int, from: string, to: string, won: bool}>
	 */
	public array $cas_calls = [];

	/**
	 * mark_settled() 的寫入紀錄：order_item_id => [settled_at, settled_by]
	 *
	 * @var array<int, array{settled_at: int, settled_by: int}>
	 */
	public array $settled = [];

	/**
	 * 下一次對這些 ID 的 CAS 會被判定為「沒搶到」，並把狀態改成指定值
	 *
	 * @var array<int, string>
	 */
	private array $race_losers = [];

	/**
	 * 預埋初始狀態
	 *
	 * @param int    $order_item_id 訂單品項 ID
	 * @param string $status        初始狀態
	 *
	 * @return void
	 */
	public function seed_status( int $order_item_id, string $status ): void {
		$this->statuses[ $order_item_id ] = $status;
	}

	/**
	 * 模擬競態：這個 ID 的下一次 CAS 會失敗，且當下狀態變成 $winner_status
	 *
	 * 對應真實情境——另一位管理員在「讀出狀態」與「寫入」之間搶先完成了轉換。
	 *
	 * @param int    $order_item_id  訂單品項 ID
	 * @param string $winner_status  對手寫入的狀態
	 *
	 * @return void
	 */
	public function simulate_race_for( int $order_item_id, string $winner_status ): void {
		$this->race_losers[ $order_item_id ] = $winner_status;
	}

	/**
	 * 原子地把狀態從 $from 換成 $to
	 *
	 * @param int    $order_item_id 訂單品項 ID
	 * @param string $from          期望的當前狀態
	 * @param string $to            目標狀態
	 *
	 * @return bool
	 */
	public function compare_and_set( int $order_item_id, string $from, string $to ): bool {
		// 模擬競態：對手在此刻搶先寫入
		if ( isset( $this->race_losers[ $order_item_id ] ) ) {
			$this->statuses[ $order_item_id ] = $this->race_losers[ $order_item_id ];
			unset( $this->race_losers[ $order_item_id ] );
		}

		$current = $this->statuses[ $order_item_id ] ?? null;
		$won     = ( null !== $current && $current === $from && $from !== $to );

		if ( $won ) {
			$this->statuses[ $order_item_id ] = $to;
		}

		$this->cas_calls[] = [
			'order_item_id' => $order_item_id,
			'from'          => $from,
			'to'            => $to,
			'won'           => $won,
		];

		return $won;
	}

	/**
	 * 寫入撥款稽核欄位
	 *
	 * @param int $order_item_id 訂單品項 ID
	 * @param int $settled_at    撥款時間
	 * @param int $settled_by    操作者 user ID
	 *
	 * @return void
	 */
	public function mark_settled( int $order_item_id, int $settled_at, int $settled_by ): void {
		$this->settled[ $order_item_id ] = [
			'settled_at' => $settled_at,
			'settled_by' => $settled_by,
		];
	}

	/**
	 * 讀取當前狀態
	 *
	 * @param int $order_item_id 訂單品項 ID
	 *
	 * @return string|null
	 */
	public function current_status( int $order_item_id ): ?string {
		return $this->statuses[ $order_item_id ] ?? null;
	}
}
