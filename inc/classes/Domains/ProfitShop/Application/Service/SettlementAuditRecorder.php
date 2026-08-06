<?php
/**
 * 結算稽核紀錄寫入器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;

/**
 * 把一批已完成狀態轉換的 SettlementRecord 依 partner 分組，寫入 `_partner_settled_log`
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §5.5、§7.5
 *
 * spec §7.5 的項目形狀：
 * ```json
 * {"date": "2026-04-30", "type": "settle", "total": 15000, "item_count": 23, "by": 1}
 * ```
 *
 * 三條實作紀律：
 *
 * 1. **金額一律 bcadd 字串累加**。這裡加出來的數字就是站長要轉給夥伴的金額，
 *    float 累加在幾百筆之後就會出現分位誤差（`0.1 + 0.2 !== 0.3`），而且誤差只會在
 *    對帳時才被發現。`total` 因此輸出**字串**而非 spec 範例裡的數字型別。
 * 2. **一個 partner 一筆紀錄**。批次撥款橫跨多個 partner 時，每個 partner 的
 *    `_partner_settled_log` 只該看到「自己」的金額——把全批總額寫進每個 partner 的紀錄
 *    會讓每位夥伴的對帳頁看到別人的數字。
 * 3. **寫入失敗不得往外拋**。呼叫本類時狀態早已寫進 order itemmeta 並且不可回滾
 *    （沒有交易邊界），此時拋例外只會讓站長看到「操作失敗」卻發現狀態其實已改，
 *    然後重按一次——第二次會因為狀態已不是 pending 而被跳過（安全），但他會誤以為
 *    這次撥款沒生效。改為回傳失敗的 partner id，由 UseCase 誠實回報「部分成功」。
 */
final class SettlementAuditRecorder {

	/**
	 * 稽核類型：標記已撥款
	 *
	 * @var string
	 */
	public const TYPE_SETTLE = 'settle';

	/**
	 * 稽核類型：標記取消
	 *
	 * @var string
	 */
	public const TYPE_CANCEL = 'cancel';

	/**
	 * 稽核類型：已撥款後人工沖回（spec §7.5 明列此類型）
	 *
	 * @var string
	 */
	public const TYPE_REFUND_AFTER_PAID = 'refund_after_paid';

	/**
	 * 建構子
	 *
	 * @param SettlementAuditLogInterface $log   稽核紀錄儲存 Port
	 * @param ClockInterface              $clock 時鐘（測試可注入 FixedClock）
	 */
	public function __construct(
		private readonly SettlementAuditLogInterface $log,
		private readonly ClockInterface $clock
	) {}

	/**
	 * 依 partner 分組寫入稽核紀錄
	 *
	 * @param SettlementRecord[] $records 已完成狀態轉換的紀錄
	 * @param string             $type    稽核類型（TYPE_* 常數）
	 * @param int                $by      操作者 user ID
	 *
	 * @return int[] 寫入失敗的 partner term ID（空陣列 = 全部成功）
	 */
	public function record( array $records, string $type, int $by ): array {
		if ( empty( $records ) ) {
			return [];
		}

		$now  = $this->clock->now();
		$date = gmdate( 'Y-m-d', $now );

		/** @var array<int, array{total: string, count: int}> $by_partner */
		$by_partner = [];
		foreach ( $records as $record ) {
			$partner_id = $record->partner_term_id;
			if ( ! isset( $by_partner[ $partner_id ] ) ) {
				$by_partner[ $partner_id ] = [
					'total' => '0.00',
					'count' => 0,
				];
			}
			$by_partner[ $partner_id ]['total'] = bcadd(
				$by_partner[ $partner_id ]['total'],
				self::normalize_amount( $record->profit_amount ),
				2
			);
			++$by_partner[ $partner_id ]['count'];
		}

		$failed = [];
		foreach ( $by_partner as $partner_id => $bucket ) {
			try {
				$this->log->append(
					$partner_id,
					[
						'date'       => $date,
						'timestamp'  => $now,
						'type'       => $type,
						'total'      => $bucket['total'],
						'item_count' => $bucket['count'],
						'by'         => $by,
					]
				);
			} catch ( \Throwable $e ) {
				// 見 class docblock 紀律 3：狀態已不可回滾，這裡拋出去只會誤導站長。
				$failed[] = $partner_id;
			}
		}

		return $failed;
	}

	/**
	 * 計算一批紀錄的分潤金額合計（bcmath 字串）
	 *
	 * @param SettlementRecord[] $records 紀錄陣列
	 *
	 * @return string 兩位小數字串
	 */
	public static function sum_amount( array $records ): string {
		$total = '0.00';
		foreach ( $records as $record ) {
			$total = bcadd( $total, self::normalize_amount( $record->profit_amount ), 2 );
		}
		return $total;
	}

	/**
	 * 把可能毀損的金額字串正規化成 bcadd 吃得下的形式
	 *
	 * `_profit_amount` 是 order itemmeta，理論上一定是數字字串；但 meta 是可被任意
	 * 外掛寫入的公開儲存區，拿到非數字時 bcadd 在部分 PHP 版本會拋 ValueError，
	 * 那會讓整批撥款失敗。fail-safe 成 0 並讓該筆金額不計入，比整批爆掉好。
	 *
	 * @param string $raw 原始金額字串
	 *
	 * @return string
	 */
	private static function normalize_amount( string $raw ): string {
		$trimmed = trim( $raw );
		if ( '' === $trimmed || ! is_numeric( $trimmed ) ) {
			return '0.00';
		}
		return $trimmed;
	}
}
