<?php
/**
 * ProfitShop 聚合根 Entity 單元測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.2、§2.3、§8.5
 * 規範：分潤賣場聚合根，包含基本欄位 + items（OverrideItem 集合）
 *      內部維護 product_id 唯一性；商品操作須驗證存在性。
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Entity;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductAlreadyInShop;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProductNotInShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use PHPUnit\Framework\TestCase;

/**
 * ProfitShop 聚合根測試
 */
final class ProfitShopTest extends TestCase {

	/**
	 * 以最少必要參數建構成功
	 *
	 * @group happy
	 */
	public function test_construct_with_minimum_required_props(): void {
		$shop = $this->make_shop();

		$this->assertSame( 1, $shop->id );
		$this->assertSame( '夏季活動賣場', $shop->title );
		$this->assertSame( 'summer-sale', $shop->slug );
		$this->assertSame( 'publish', $shop->status );
		$this->assertSame( ShopMode::PAGE, $shop->mode );
		$this->assertSame( 5, $shop->partner_term_id );
		$this->assertSame( 10, $shop->rate->value() );
		$this->assertSame( [], $shop->items );
		$this->assertSame( [], $shop->settings );
	}

	/**
	 * add_item 將 OverrideItem 加入 items 集合
	 *
	 * @group happy
	 */
	public function test_add_item_appends_to_items(): void {
		$shop = $this->make_shop();
		$item = $this->make_item( 123 );

		$shop->add_item( $item );

		$this->assertCount( 1, $shop->items() );
	}

	/**
	 * 加入相同 product_id 應拋出 ProductAlreadyInShop
	 *
	 * @group error
	 */
	public function test_add_item_throws_when_product_id_already_exists(): void {
		$shop = $this->make_shop();
		$shop->add_item( $this->make_item( 123 ) );

		$this->expectException( ProductAlreadyInShop::class );
		$shop->add_item( $this->make_item( 123 ) );
	}

	/**
	 * remove_item 依 product_id 移除商品成功
	 *
	 * @group happy
	 */
	public function test_remove_item_by_product_id_succeeds(): void {
		$shop = $this->make_shop();
		$shop->add_item( $this->make_item( 123 ) );
		$shop->add_item( $this->make_item( 456 ) );

		$shop->remove_item( 123 );

		$items = $shop->items();
		$this->assertCount( 1, $items );
		$this->assertSame( 456, $items[0]->product_id );
	}

	/**
	 * remove_item 對不存在的 product_id 應拋出 ProductNotInShop
	 *
	 * @group error
	 */
	public function test_remove_item_throws_when_product_id_not_found(): void {
		$shop = $this->make_shop();

		$this->expectException( ProductNotInShop::class );
		$shop->remove_item( 999 );
	}

	/**
	 * update_item_override 將指定商品的價格覆寫替換為新值
	 *
	 * @group happy
	 */
	public function test_update_item_override_replaces_price_override(): void {
		$shop = $this->make_shop();
		$shop->add_item( $this->make_item( 123 ) );

		$new_override = new PriceOverride( '2000.00', '1599.00', null );
		$shop->update_item_override( 123, $new_override );

		$items = $shop->items();
		$this->assertTrue( $new_override->equals( $items[0]->override ) );
	}

	/**
	 * update_item_override 對不存在的 product_id 應拋出 ProductNotInShop
	 *
	 * @group error
	 */
	public function test_update_item_override_throws_when_product_not_in_shop(): void {
		$shop         = $this->make_shop();
		$new_override = new PriceOverride( '2000.00', '1599.00', null );

		$this->expectException( ProductNotInShop::class );
		$shop->update_item_override( 999, $new_override );
	}

	/**
	 * update_inflated 將指定商品的 inflated_count 替換為新值
	 *
	 * @group happy
	 */
	public function test_update_inflated_replaces_count(): void {
		$shop = $this->make_shop();
		$shop->add_item( $this->make_item( 123 ) );

		$new_count = new InflatedCount( 9999 );
		$shop->update_inflated( 123, $new_count );

		$items = $shop->items();
		$this->assertSame( 9999, $items[0]->inflated_count->value() );
	}

	/**
	 * update_inflated 對不存在的 product_id 應拋出 ProductNotInShop
	 *
	 * @group error
	 */
	public function test_update_inflated_throws_when_product_not_in_shop(): void {
		$shop      = $this->make_shop();
		$new_count = new InflatedCount( 9999 );

		$this->expectException( ProductNotInShop::class );
		$shop->update_inflated( 999, $new_count );
	}

	/**
	 * change_partner 更新 partner_term_id
	 *
	 * @group happy
	 */
	public function test_change_partner_updates_term_id(): void {
		$shop = $this->make_shop();

		$shop->change_partner( 99 );

		$this->assertSame( 99, $shop->partner_term_id );
	}

	/**
	 * change_rate 更新分潤比例 ProfitRate
	 *
	 * @group happy
	 */
	public function test_change_rate_updates_profit_rate(): void {
		$shop = $this->make_shop();

		$shop->change_rate( new ProfitRate( 25 ) );

		$this->assertSame( 25, $shop->rate->value() );
	}

	/**
	 * items() 回傳的元素皆為 OverrideItem instance
	 *
	 * @group happy
	 */
	public function test_items_returns_overrideitem_collection(): void {
		$shop = $this->make_shop();
		$shop->add_item( $this->make_item( 123 ) );
		$shop->add_item( $this->make_item( 456 ) );

		$items = $shop->items();

		$this->assertCount( 2, $items );
		foreach ( $items as $item ) {
			$this->assertInstanceOf( OverrideItem::class, $item );
		}
	}

	/**
	 * 多次 add_item 後 items() 計數正確
	 *
	 * @group edge
	 */
	public function test_items_count_after_multiple_adds(): void {
		$shop = $this->make_shop();

		$shop->add_item( $this->make_item( 100 ) );
		$shop->add_item( $this->make_item( 200 ) );
		$shop->add_item( $this->make_item( 300 ) );
		$shop->add_item( $this->make_item( 400 ) );

		$this->assertCount( 4, $shop->items() );
	}

	/**
	 * to_array 應包含 items 為 list（陣列）形式
	 *
	 * @group happy
	 */
	public function test_to_array_includes_items_as_list(): void {
		$shop = $this->make_shop();
		$shop->add_item( $this->make_item( 123 ) );
		$shop->add_item( $this->make_item( 456 ) );

		$arr = $shop->to_array();

		$this->assertArrayHasKey( 'items', $arr );
		$this->assertIsArray( $arr['items'] );
		$this->assertCount( 2, $arr['items'] );
		// items 應為連續 numeric index（list 形式）
		$this->assertSame( [ 0, 1 ], array_keys( $arr['items'] ) );
		$this->assertSame( 123, $arr['items'][0]['product_id'] );
		$this->assertSame( 456, $arr['items'][1]['product_id'] );
	}

	/**
	 * 建立一個基本 ProfitShop（給多個測試共用）
	 *
	 * @return ProfitShop
	 */
	private function make_shop(): ProfitShop {
		return new ProfitShop(
			id: 1,
			title: '夏季活動賣場',
			slug: 'summer-sale',
			status: 'publish',
			mode: ShopMode::PAGE,
			partner_term_id: 5,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: [],
		);
	}

	/**
	 * 建立一個 OverrideItem（給多個測試共用）
	 *
	 * @param int $product_id 商品 id
	 *
	 * @return OverrideItem
	 */
	private function make_item( int $product_id ): OverrideItem {
		return new OverrideItem(
			$product_id,
			new PriceOverride( '1000.00', '799.00', null ),
			new InflatedCount( 100 ),
		);
	}
}
