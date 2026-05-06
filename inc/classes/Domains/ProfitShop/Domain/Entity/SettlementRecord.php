<?php
/**
 * 分潤結算紀錄 Entity
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Entity;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidStatusTransition;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;

/**
 * 分潤結算紀錄（line item 級）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.5、§5.3、§5.5、§8.5
 *
 * 凍結交易當下的價格與比例。
 *
 * 狀態機：
 *   - pending → paid（mark_paid）
 *   - pending → cancelled（mark_cancelled）
 *   - pending → refunded（mark_refunded）
 *   - paid    → refunded（mark_refunded，set is_refund_after_paid = true）
 *   - 其他轉換皆 throw InvalidStatusTransition
 */
final class SettlementRecord {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_PAID      = 'paid';
	public const STATUS_REFUNDED  = 'refunded';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * 建構子
	 *
	 * @param int        $order_item_id        訂單品項 ID（line item）
	 * @param int        $shop_id              所屬分潤賣場 ID
	 * @param int        $partner_term_id      合作夥伴 term_id（凍結值）
	 * @param ProfitRate $rate                 分潤比例（凍結值）
	 * @param string     $actual_price         實際成交價（凍結值，十進位字串）
	 * @param string     $profit_amount        分潤金額（凍結值，十進位字串）
	 * @param string     $status               目前狀態（pending/paid/refunded/cancelled）
	 * @param int|null   $settled_at           結算時間（unix timestamp）
	 * @param int|null   $settled_by           結算操作者 user_id
	 * @param bool       $is_refund_after_paid 是否為已付款後退款
	 */
	public function __construct(
		public readonly int $order_item_id,
		public readonly int $shop_id,
		public readonly int $partner_term_id,
		public readonly ProfitRate $rate,
		public readonly string $actual_price,
		public readonly string $profit_amount,
		public string $status = self::STATUS_PENDING,
		public ?int $settled_at = null,
		public ?int $settled_by = null,
		public bool $is_refund_after_paid = false
	) {
	}

	/**
	 * 標記為已付款（pending → paid）
	 *
	 * @param int $by 操作者 user_id
	 * @param int $at 結算時間（unix timestamp）
	 *
	 * @throws InvalidStatusTransition 當目前狀態非 pending 時拋出
	 *
	 * @return void
	 */
	public function mark_paid( int $by, int $at ): void {
		if ( self::STATUS_PENDING !== $this->status ) {
			throw new InvalidStatusTransition( "無法從 {$this->status} 轉換到 paid" );
		}
		$this->status     = self::STATUS_PAID;
		$this->settled_at = $at;
		$this->settled_by = $by;
	}

	/**
	 * 標記為已取消（pending → cancelled）
	 *
	 * @throws InvalidStatusTransition 當目前狀態非 pending 時拋出
	 *
	 * @return void
	 */
	public function mark_cancelled(): void {
		if ( self::STATUS_PENDING !== $this->status ) {
			throw new InvalidStatusTransition( "無法從 {$this->status} 轉換到 cancelled" );
		}
		$this->status = self::STATUS_CANCELLED;
	}

	/**
	 * 標記為已退款
	 *
	 * - pending → refunded：is_refund_after_paid 維持 false
	 * - paid    → refunded：is_refund_after_paid 設為 true
	 * - 其他狀態：拋出 InvalidStatusTransition
	 *
	 * @throws InvalidStatusTransition 當目前狀態非 pending 也非 paid 時拋出
	 *
	 * @return void
	 */
	public function mark_refunded(): void {
		if ( self::STATUS_PAID === $this->status ) {
			$this->is_refund_after_paid = true;
			$this->status               = self::STATUS_REFUNDED;
			return;
		}

		if ( self::STATUS_PENDING !== $this->status ) {
			throw new InvalidStatusTransition( "無法從 {$this->status} 轉換到 refunded" );
		}

		$this->status = self::STATUS_REFUNDED;
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   order_item_id: int,
	 *   shop_id: int,
	 *   partner_term_id: int,
	 *   rate: int,
	 *   actual_price: string,
	 *   profit_amount: string,
	 *   status: string,
	 *   settled_at: int|null,
	 *   settled_by: int|null,
	 *   is_refund_after_paid: bool
	 * }
	 */
	public function to_array(): array {
		return [
			'order_item_id'        => $this->order_item_id,
			'shop_id'              => $this->shop_id,
			'partner_term_id'      => $this->partner_term_id,
			'rate'                 => $this->rate->value(),
			'actual_price'         => $this->actual_price,
			'profit_amount'        => $this->profit_amount,
			'status'               => $this->status,
			'settled_at'           => $this->settled_at,
			'settled_by'           => $this->settled_by,
			'is_refund_after_paid' => $this->is_refund_after_paid,
		];
	}
}
