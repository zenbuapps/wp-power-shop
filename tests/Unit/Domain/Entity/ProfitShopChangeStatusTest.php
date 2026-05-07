<?php
/**
 * ProfitShop 狀態流轉測試（Phase 3-A m-9）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6 / §8.5
 *
 * Phase 3-A 變更要求：
 *   1. $status 屬性由 public 改為 protected
 *   2. 新增 change_status(string $status) mutator
 *   3. 新增 status() getter
 *   4. invalid status 仍應拋 \DomainException
 *
 * 預期紅燈：
 *   - Method change_status does not exist
 *   - Method status does not exist
 *   - Property $status is not protected
 */

declare( strict_types=1 );

namespace Tests\Unit\Domain\Entity;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use PHPUnit\Framework\TestCase;

/**
 * ProfitShop change_status / status() / protected $status 測試
 */
final class ProfitShopChangeStatusTest extends TestCase {

	/**
	 * 由 publish 切換為 draft 應成功
	 *
	 * @group happy
	 */
	public function test_change_status_publish_to_draft_succeeds(): void {
		$shop = $this->make_shop( 'publish' );

		$shop->change_status( 'draft' );

		$this->assertSame( 'draft', $shop->status() );
	}

	/**
	 * 由 draft 切換為 publish 應成功
	 *
	 * @group happy
	 */
	public function test_change_status_draft_to_publish_succeeds(): void {
		$shop = $this->make_shop( 'draft' );

		$shop->change_status( 'publish' );

		$this->assertSame( 'publish', $shop->status() );
	}

	/**
	 * 切換為非合法 status 應拋出 \DomainException
	 *
	 * @group error
	 */
	public function test_change_status_throws_on_invalid_status(): void {
		$shop = $this->make_shop( 'publish' );

		$this->expectException( \DomainException::class );
		$shop->change_status( 'archived' );
	}

	/**
	 * $status 屬性必須為 protected（不可被外部直接寫入）
	 *
	 * 使用 ReflectionProperty 確認 visibility，逼 master 把 public 改為 protected。
	 *
	 * @group security
	 */
	public function test_status_property_is_protected(): void {
		$reflection = new \ReflectionProperty( ProfitShop::class, 'status' );

		$this->assertTrue(
			$reflection->isProtected(),
			'ProfitShop::$status 必須為 protected，不可暴露為 public 讓外部繞過 mutator 寫入'
		);
	}

	/**
	 * status() getter 應回傳當前的 status 字串
	 *
	 * @group happy
	 */
	public function test_status_getter_returns_current_value(): void {
		$shop = $this->make_shop( 'draft' );

		$this->assertSame( 'draft', $shop->status() );
	}

	/**
	 * 建立一個 ProfitShop（給多個測試共用）
	 *
	 * @param string $status 賣場狀態
	 *
	 * @return ProfitShop
	 */
	private function make_shop( string $status ): ProfitShop {
		return new ProfitShop(
			id: 1,
			title: '夏季活動賣場',
			slug: 'summer-sale',
			status: $status,
			mode: ShopMode::PAGE,
			partner_term_id: 5,
			rate: new ProfitRate( 10 ),
			items: [],
			settings: [],
		);
	}
}
