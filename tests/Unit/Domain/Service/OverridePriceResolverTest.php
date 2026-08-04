<?php
/**
 * OverridePriceResolver 單元測試（Phase 13）
 *
 * 對應實作：inc/classes/Domains/ProfitShop/Domain/Service/OverridePriceResolver.php
 * 對應規劃：specs/plan/2026-08-03-cart-regular-price-override-plan.md 第一階段
 *
 * 這組語意在 Phase 13 之前只被 SingleProductPriceHookTest（IT）間接覆蓋，
 * 且 cart 端另有一份**行為不同**的複製品（只回傳單一價格），這正是
 * 「購物車劃線原價顯示商品原始原價」這個 bug 的成因。抽成 Domain 服務後
 * 用本檔直接鎖住三元組語意。
 *
 * ⚠️ 最關鍵的一條是「變體有任一覆寫欄位就整組採用變體層、不與商品層逐欄混用」，
 *    改成逐欄 fallback 會讓單品頁與購物車再次顯示不同的價格。
 *
 * @group profit_shop
 * @group domain
 * @group service
 */

declare(strict_types=1);

namespace Tests\Unit\Domain\Service;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Service\OverridePriceResolver;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\VariationOverride;
use PHPUnit\Framework\TestCase;

/**
 * OverridePriceResolver 三元組解析語意測試
 */
final class OverridePriceResolverTest extends TestCase {

	private OverridePriceResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new OverridePriceResolver();
	}

	// ========== 商品層 ==========

	/**
	 * 商品層同時覆寫原價與特價 → price 取特價，regular / sale 各自帶出
	 *
	 * @group happy
	 * @group smoke
	 */
	public function test_parent_override_with_regular_and_sale_returns_full_triplet(): void {
		$item = $this->make_item( new PriceOverride( '800', '600', null ) );

		$result = $this->resolver->resolve( $item, null );

		$this->assertNotNull( $result, '有覆寫時不應回 null' );
		$this->assertSame( '600', $result['price'], '有特價時 price 應取特價' );
		$this->assertSame( '800', $result['regular'] );
		$this->assertSame( '600', $result['sale'] );
	}

	/**
	 * 商品層只覆寫原價 → price 取原價，sale 為 null（＝呼叫端要清掉商品原本的特價）
	 *
	 * @group happy
	 */
	public function test_parent_override_with_regular_only_returns_null_sale(): void {
		$item = $this->make_item( new PriceOverride( '800', null, null ) );

		$result = $this->resolver->resolve( $item, null );

		$this->assertNotNull( $result );
		$this->assertSame( '800', $result['price'], '無特價時 price 應取原價' );
		$this->assertSame( '800', $result['regular'] );
		$this->assertNull( $result['sale'], '未設特價時 sale 必須是 null（呼叫端據此清掉商品原本的特價）' );
	}

	/**
	 * 商品層只覆寫特價 → regular 為 null（＝呼叫端保留商品原本的原價）
	 *
	 * @group happy
	 */
	public function test_parent_override_with_sale_only_returns_null_regular(): void {
		$item = $this->make_item( new PriceOverride( null, '600', null ) );

		$result = $this->resolver->resolve( $item, null );

		$this->assertNotNull( $result );
		$this->assertSame( '600', $result['price'] );
		$this->assertNull( $result['regular'], '未設原價覆寫時 regular 必須是 null（呼叫端據此保留商品原價）' );
		$this->assertSame( '600', $result['sale'] );
	}

	/**
	 * 完全沒有任何覆寫 → 回 null（呼叫端完全不介入，商品走原價）
	 *
	 * @group edge
	 * @group smoke
	 */
	public function test_returns_null_when_no_override_at_all(): void {
		$item = $this->make_item( new PriceOverride( null, null, null ) );

		$this->assertNull( $this->resolver->resolve( $item, null ), '無任何覆寫時必須回 null' );
		$this->assertNull( $this->resolver->resolve( $item, 999 ), '帶 variation_id 也一樣回 null' );
	}

	/**
	 * signup_fee 不影響三元組（它不是 cart / 單品頁的顯示價格）
	 *
	 * @group edge
	 */
	public function test_signup_fee_alone_does_not_trigger_override(): void {
		$item = $this->make_item( new PriceOverride( null, null, '300' ) );

		$this->assertNull(
			$this->resolver->resolve( $item, null ),
			'只設 signup_fee 不構成價格覆寫（regular / sale 皆 null）'
		);
	}

	// ========== 變體層 ==========

	/**
	 * 變體層有覆寫 → 整組採用變體層的值
	 *
	 * @group happy
	 */
	public function test_variation_override_takes_precedence_over_parent(): void {
		$item = $this->make_item(
			new PriceOverride( '800', '600', null ),
			[
				11 => new VariationOverride( new PriceOverride( '500', '450', null ), new InflatedCount( 0 ) ),
			]
		);

		$result = $this->resolver->resolve( $item, 11 );

		$this->assertNotNull( $result );
		$this->assertSame( '450', $result['price'] );
		$this->assertSame( '500', $result['regular'] );
		$this->assertSame( '450', $result['sale'] );
	}

	/**
	 * 🔴 核心語意鎖：變體只設原價、商品層有特價時，**整組**採用變體層，不逐欄混用
	 *
	 * 若改成逐欄 fallback（變體沒設特價就去拿商品層特價 300），
	 * 單品頁會顯示 300、購物車實收 500——正是本類要消滅的價格跳動。
	 *
	 * @group edge
	 * @group smoke
	 */
	public function test_variation_override_does_not_merge_field_wise_with_parent(): void {
		$item = $this->make_item(
			new PriceOverride( '800', '300', null ),
			[
				11 => new VariationOverride( new PriceOverride( '500', null, null ), new InflatedCount( 0 ) ),
			]
		);

		$result = $this->resolver->resolve( $item, 11 );

		$this->assertNotNull( $result );
		$this->assertSame( '500', $result['price'], '變體有覆寫時 price 必須是變體層原價，不得取商品層特價 300' );
		$this->assertSame( '500', $result['regular'] );
		$this->assertNull( $result['sale'], '變體層未設特價 → sale 必須是 null，不得沿用商品層的 300' );
	}

	/**
	 * 變體 override 存在但兩個價格欄位皆 null → fallback 到商品層
	 *
	 * 這個情境真實存在：變體只設了 inflated_count、沒設任何價格。
	 *
	 * @group edge
	 */
	public function test_variation_override_with_no_prices_falls_back_to_parent(): void {
		$item = $this->make_item(
			new PriceOverride( '800', '600', null ),
			[
				11 => new VariationOverride( new PriceOverride( null, null, null ), new InflatedCount( 7 ) ),
			]
		);

		$result = $this->resolver->resolve( $item, 11 );

		$this->assertNotNull( $result );
		$this->assertSame( '600', $result['price'], '變體無價格覆寫時應 fallback 到商品層' );
		$this->assertSame( '800', $result['regular'] );
		$this->assertSame( '600', $result['sale'] );
	}

	/**
	 * 查詢的 variation_id 沒有對應覆寫 → fallback 到商品層
	 *
	 * @group edge
	 */
	public function test_unknown_variation_id_falls_back_to_parent(): void {
		$item = $this->make_item(
			new PriceOverride( '800', null, null ),
			[
				11 => new VariationOverride( new PriceOverride( '500', null, null ), new InflatedCount( 0 ) ),
			]
		);

		$result = $this->resolver->resolve( $item, 22 );

		$this->assertNotNull( $result );
		$this->assertSame( '800', $result['price'], '未登記的 variation_id 應走商品層' );
		$this->assertSame( '800', $result['regular'] );
	}

	/**
	 * variation_id 傳 null 時只看商品層，即使該 item 有變體覆寫也不採用
	 *
	 * @group edge
	 */
	public function test_null_variation_id_only_reads_parent_layer(): void {
		$item = $this->make_item(
			new PriceOverride( '800', null, null ),
			[
				11 => new VariationOverride( new PriceOverride( '500', '450', null ), new InflatedCount( 0 ) ),
			]
		);

		$result = $this->resolver->resolve( $item, null );

		$this->assertNotNull( $result );
		$this->assertSame( '800', $result['price'], 'variation_id 為 null 時不得採用任何變體層的值' );
		$this->assertSame( '800', $result['regular'] );
		$this->assertNull( $result['sale'] );
	}

	/**
	 * 覆寫價為 '0'（免費贈品）必須被視為「有覆寫」，不可與「未覆寫」混淆
	 *
	 * '0' 是 falsy 值，用 empty() / ! $x 判斷會誤判成沒設定 → 商品會安靜地走原價。
	 *
	 * @group edge
	 * @group security
	 */
	public function test_zero_price_override_is_treated_as_a_real_override(): void {
		$item = $this->make_item( new PriceOverride( '800', '0', null ) );

		$result = $this->resolver->resolve( $item, null );

		$this->assertNotNull( $result, '覆寫價為 0 時仍必須回傳三元組（0 是合法的免費價）' );
		$this->assertSame( '0', $result['price'] );
		$this->assertSame( '0', $result['sale'] );
	}

	// ========== Helper ==========

	/**
	 * 建立一個 OverrideItem
	 *
	 * @param PriceOverride                 $override   商品層覆寫
	 * @param array<int, VariationOverride> $variations 變體層覆寫
	 */
	private function make_item( PriceOverride $override, array $variations = [] ): OverrideItem {
		return new OverrideItem(
			product_id: 101,
			override: $override,
			inflated_count: new InflatedCount( 0 ),
			variations: $variations
		);
	}
}
