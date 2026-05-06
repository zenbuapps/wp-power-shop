<?php
/**
 * 分潤賣場聚合根
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Entity;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductAlreadyInShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotInShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;

/**
 * 分潤賣場聚合根
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.2、§2.3、§8.5
 *
 * 商品集合內部以 product_id 為 key，確保唯一性。
 * 對外 items() 回傳 list-shape（array_values）。
 */
final class ProfitShop {

	/**
	 * 合法的 status 列表
	 *
	 * @var string[]
	 */
	private const VALID_STATUSES = [ 'publish', 'draft' ];

	/**
	 * 商品集合（內部以 product_id => OverrideItem 對照表，用於 O(1) lookup 與唯一性檢查）
	 *
	 * @var array<int, OverrideItem>
	 */
	public array $items;

	/**
	 * 建構子
	 *
	 * @param int                  $id              賣場 ID
	 * @param string               $title           賣場標題
	 * @param string               $slug            賣場 slug
	 * @param string               $status          賣場狀態（publish/draft）
	 * @param ShopMode             $mode            賣場模式（page/shortcode）
	 * @param int                  $partner_term_id 對應的合作夥伴 term_id
	 * @param ProfitRate           $rate            分潤比例
	 * @param OverrideItem[]       $items           初始商品集合
	 * @param array<string, mixed> $settings        其它設定
	 *
	 * @throws \DomainException     當 status 不在合法列表時拋出
	 * @throws ProductAlreadyInShop 當初始 items 中含有重複 product_id 時拋出
	 */
	public function __construct(
		public readonly int $id,
		public string $title,
		public string $slug,
		public string $status,
		public ShopMode $mode,
		public int $partner_term_id,
		public ProfitRate $rate,
		array $items = [],
		public array $settings = []
	) {
		self::assert_valid_status( $status );

		$this->items = [];
		foreach ( $items as $item ) {
			$this->add_item( $item );
		}
	}

	/**
	 * 驗證 status 是否為合法值
	 *
	 * @param string $status 候選 status 字串
	 *
	 * @throws \DomainException 當 status 不在 VALID_STATUSES 列表中時拋出
	 *
	 * @return void
	 */
	private static function assert_valid_status( string $status ): void {
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			throw new \DomainException( "不合法的 ProfitShop status：{$status}" );
		}
	}

	/**
	 * 將商品加入賣場；若 product_id 已存在則拋出例外
	 *
	 * @param OverrideItem $item 商品覆寫項目
	 *
	 * @throws ProductAlreadyInShop 當該 product_id 已存在於此賣場時拋出
	 *
	 * @return void
	 */
	public function add_item( OverrideItem $item ): void {
		if ( isset( $this->items[ $item->product_id ] ) ) {
			throw new ProductAlreadyInShop( "商品 ID {$item->product_id} 已存在於此賣場" );
		}
		$this->items[ $item->product_id ] = $item;
	}

	/**
	 * 從賣場移除指定商品
	 *
	 * @param int $product_id 商品 ID
	 *
	 * @throws ProductNotInShop 當該 product_id 不在賣場時拋出
	 *
	 * @return void
	 */
	public function remove_item( int $product_id ): void {
		$this->assert_has_item( $product_id );
		unset( $this->items[ $product_id ] );
	}

	/**
	 * 更新指定商品的價格覆寫
	 *
	 * @param int           $product_id 商品 ID
	 * @param PriceOverride $override   新的價格覆寫
	 *
	 * @throws ProductNotInShop 當該 product_id 不在賣場時拋出
	 *
	 * @return void
	 */
	public function update_item_override( int $product_id, PriceOverride $override ): void {
		$this->assert_has_item( $product_id );
		$this->items[ $product_id ]->override = $override;
	}

	/**
	 * 更新指定商品的灌水銷量
	 *
	 * @param int           $product_id 商品 ID
	 * @param InflatedCount $count      新的灌水銷量
	 *
	 * @throws ProductNotInShop 當該 product_id 不在賣場時拋出
	 *
	 * @return void
	 */
	public function update_inflated( int $product_id, InflatedCount $count ): void {
		$this->assert_has_item( $product_id );
		$this->items[ $product_id ]->inflated_count = $count;
	}

	/**
	 * 變更合作夥伴 term_id
	 *
	 * @param int $term_id 新的合作夥伴 term_id
	 *
	 * @return void
	 */
	public function change_partner( int $term_id ): void {
		$this->partner_term_id = $term_id;
	}

	/**
	 * 變更分潤比例
	 *
	 * @param ProfitRate $rate 新的分潤比例
	 *
	 * @return void
	 */
	public function change_rate( ProfitRate $rate ): void {
		$this->rate = $rate;
	}

	/**
	 * 取得商品集合（list-shape，連續數字索引）
	 *
	 * @return OverrideItem[]
	 */
	public function items(): array {
		return array_values( $this->items );
	}

	/**
	 * 序列化為陣列
	 *
	 * @return array{
	 *   id: int,
	 *   title: string,
	 *   slug: string,
	 *   status: string,
	 *   mode: string,
	 *   partner_term_id: int,
	 *   rate: int,
	 *   items: array<int, array<string, mixed>>,
	 *   settings: array<string, mixed>
	 * }
	 */
	public function to_array(): array {
		return [
			'id'              => $this->id,
			'title'           => $this->title,
			'slug'            => $this->slug,
			'status'          => $this->status,
			'mode'            => $this->mode->value,
			'partner_term_id' => $this->partner_term_id,
			'rate'            => $this->rate->value(),
			'items'           => array_map( static fn( OverrideItem $item ): array => $item->to_array(), $this->items() ),
			'settings'        => $this->settings,
		];
	}

	/**
	 * 確認指定 product_id 存在於賣場；不存在則拋例外
	 *
	 * @param int $product_id 商品 ID
	 *
	 * @throws ProductNotInShop 當該 product_id 不在賣場時拋出
	 *
	 * @return void
	 */
	private function assert_has_item( int $product_id ): void {
		if ( ! isset( $this->items[ $product_id ] ) ) {
			throw new ProductNotInShop( "商品 ID {$product_id} 不在此賣場內" );
		}
	}
}
