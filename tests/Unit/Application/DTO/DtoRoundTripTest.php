<?php
/**
 * Phase 3-A 13 個 Application DTO round-trip 測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §6 / §7
 *
 * 約定：
 *   - DTO 為 final class、欄位 public readonly
 *   - 提供 static factory `from_array(array $data): self`
 *   - 提供對稱輸出 `to_array(): array`
 *   - DTO 不做業務驗證（驗證留給 UseCase / Service）
 *
 * 涵蓋 13 個 DTO：
 *   - ProfitShopInput / ProfitShopOutput
 *   - PartnerInput / PartnerOutput
 *   - SettlementListOutput / SettlementSummaryOutput
 *   - BulkResult
 *   - PartnerLoginInput / PartnerLoginOutput
 *   - KpiReport / TrendReport
 *   - SlugConflict
 *   （SettingsDto 已隨 #/profit-settings 設定頁整組移除，見檔尾註解）
 *
 * 預期紅燈：Class J7\PowerShop\Domains\ProfitShop\Application\DTO\<Name> not found
 */

declare( strict_types=1 );

namespace Tests\Unit\Application\DTO;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\BulkResult;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\KpiReport;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerLoginInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerLoginOutput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\PartnerOutput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementListOutput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettlementSummaryOutput;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\TrendReport;
use PHPUnit\Framework\TestCase;

/**
 * 13 個 Application DTO round-trip 測試
 */
final class DtoRoundTripTest extends TestCase {

	// ────────────────────────────────────────────────
	// ProfitShopInput
	// ────────────────────────────────────────────────

	/**
	 * ProfitShopInput round-trip：from_array → to_array 等價
	 *
	 * @group happy
	 */
	public function test_profit_shop_input_round_trip(): void {
		$arr = [
			'id'              => 1,
			'title'           => '夏季活動賣場',
			'slug'            => 'summer-sale',
			'status'          => 'publish',
			'mode'            => 'page',
			'partner_term_id' => 5,
			'rate'            => 10,
			'items'           => [
				[
					'product_id'     => 123,
					'regular_price'  => '1000.00',
					'sale_price'     => '799.00',
					'sale_period'    => null,
					'inflated_count' => 100,
				],
			],
			'settings'        => [ 'rewrite_slug' => 'shop' ],
		];

		$dto = ProfitShopInput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// ProfitShopOutput
	// ────────────────────────────────────────────────

	/**
	 * ProfitShopOutput round-trip
	 *
	 * @group happy
	 */
	public function test_profit_shop_output_round_trip(): void {
		$arr = [
			'id'              => 10,
			'title'           => '夏季活動賣場',
			'slug'            => 'summer-sale',
			'status'          => 'publish',
			'mode'            => 'page',
			'partner_term_id' => 5,
			'rate'            => 10,
			'items'           => [],
			'settings'        => [],
			'created_at'      => '2026-05-01 09:00:00',
			'updated_at'      => '2026-05-06 12:00:00',
		];

		$dto = ProfitShopOutput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// PartnerInput
	// ────────────────────────────────────────────────

	/**
	 * PartnerInput round-trip
	 *
	 * @group happy
	 */
	public function test_partner_input_round_trip(): void {
		$arr = [
			'id'            => 7,
			'name'          => 'Alice',
			'slug'          => 'alice',
			'contact_email' => 'alice@example.com',
			'password'      => 'plain-password',
			'image_id'      => 321,
		];

		$dto = PartnerInput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	/**
	 * PartnerInput：image_id 缺席 / 0 / 負數一律正規化為 null
	 *
	 * 對應「清除夥伴圖片時前端送 null」的契約，同時擋掉 attachment ID 0 這種不存在的值。
	 *
	 * @group edge
	 */
	public function test_partner_input_normalizes_invalid_image_id_to_null(): void {
		$base = [
			'id'            => 7,
			'name'          => 'Alice',
			'slug'          => 'alice',
			'contact_email' => null,
			'password'      => null,
		];

		$this->assertNull( PartnerInput::from_array( $base )->image_id, '缺席時應為 null' );
		$this->assertNull( PartnerInput::from_array( $base + [ 'image_id' => 0 ] )->image_id, '0 應正規化為 null' );
		$this->assertNull( PartnerInput::from_array( $base + [ 'image_id' => -5 ] )->image_id, '負數應正規化為 null' );
		$this->assertSame( 88, PartnerInput::from_array( $base + [ 'image_id' => '88' ] )->image_id, '字串數字應轉 int' );
	}

	// ────────────────────────────────────────────────
	// PartnerOutput
	// ────────────────────────────────────────────────

	/**
	 * PartnerOutput round-trip
	 *
	 * @group happy
	 */
	public function test_partner_output_round_trip(): void {
		$arr = [
			'id'            => 7,
			'name'          => 'Alice',
			'slug'          => 'alice',
			'contact_email' => 'alice@example.com',
			'created_at'    => '2026-04-01 09:00:00',
			'image_id'      => 321,
			'image_url'     => 'https://example.test/wp-content/uploads/alice-150x150.jpg',
			'report_url'    => 'https://example.test/profit-report/alice/',
		];

		$dto = PartnerOutput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	/**
	 * PartnerOutput：未設定夥伴圖片時 image_id / image_url 皆為 null
	 *
	 * 鎖死「沒有圖片」的線材形狀是 null（而非 0 / 空字串），前端才能用
	 * `image_url ?? fallback` 直接判斷要不要顯示預設頭像。
	 *
	 * @group edge
	 */
	public function test_partner_output_without_image_is_null(): void {
		$dto = PartnerOutput::from_array(
			[
				'id'         => 7,
				'name'       => 'Alice',
				'slug'       => 'alice',
				'created_at' => '2026-04-01 09:00:00',
			]
		);

		$this->assertNull( $dto->image_id );
		$this->assertNull( $dto->image_url );
		$this->assertArrayHasKey( 'image_id', $dto->to_array() );
		$this->assertArrayHasKey( 'image_url', $dto->to_array() );
	}

	/**
	 * PartnerOutput：report_url 缺席時為 null，且 key 仍存在於 to_array()
	 *
	 * `report_url` 與 `image_url` 同屬唯讀衍生欄位，由 Infrastructure 層在 hydrate 時
	 * 組出；DTO 本身不推導，缺席就是 null。key 必須恆存在，前端才能用
	 * `report_url ?? null` 直接判斷要不要停用「查看報表」按鈕，
	 * 而不必區分「欄位不存在」與「值為 null」兩種狀態。
	 *
	 * @group edge
	 */
	public function test_partner_output_without_report_url_is_null(): void {
		$dto = PartnerOutput::from_array(
			[
				'id'         => 7,
				'name'       => 'Alice',
				'slug'       => 'alice',
				'created_at' => '2026-04-01 09:00:00',
			]
		);

		$this->assertNull( $dto->report_url );
		$this->assertArrayHasKey( 'report_url', $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// SettlementListOutput
	// ────────────────────────────────────────────────

	/**
	 * SettlementListOutput round-trip
	 *
	 * @group happy
	 */
	public function test_settlement_list_output_round_trip(): void {
		$arr = [
			'items'    => [
				[
					'id'         => 1,
					'order_id'   => 1001,
					'profit'     => '150.00',
					'state'      => 'pending',
					'created_at' => '2026-05-01 09:00:00',
				],
			],
			'total'    => 1,
			'page'     => 1,
			'per_page' => 20,
		];

		$dto = SettlementListOutput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// SettlementSummaryOutput
	// ────────────────────────────────────────────────

	/**
	 * SettlementSummaryOutput round-trip
	 *
	 * @group happy
	 */
	public function test_settlement_summary_output_round_trip(): void {
		$arr = [
			'total_sales'     => '12000.00',
			'profit_pending'  => '600.00',
			'profit_paid'     => '500.00',
			'profit_refunded' => '100.00',
		];

		$dto = SettlementSummaryOutput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// BulkResult
	// ────────────────────────────────────────────────

	/**
	 * BulkResult round-trip
	 *
	 * @group happy
	 */
	public function test_bulk_result_round_trip(): void {
		$arr = [
			'success_ids' => [ 1, 2, 3 ],
			'failures'    => [
				4 => '商品不存在',
				5 => '價格格式不合法',
			],
		];

		$dto = BulkResult::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// PartnerLoginInput
	// ────────────────────────────────────────────────

	/**
	 * PartnerLoginInput round-trip
	 *
	 * @group happy
	 */
	public function test_partner_login_input_round_trip(): void {
		$arr = [
			'slug'     => 'alice',
			'password' => 'plain-password',
		];

		$dto = PartnerLoginInput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// PartnerLoginOutput
	// ────────────────────────────────────────────────

	/**
	 * PartnerLoginOutput round-trip
	 *
	 * @group happy
	 */
	public function test_partner_login_output_round_trip(): void {
		$arr = [
			'token'        => 'jwt-fake-token',
			'expires_at'   => '2026-05-07 12:00:00',
			'partner_id'   => 7,
			'partner_name' => 'Alice',
		];

		$dto = PartnerLoginOutput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// KpiReport
	// ────────────────────────────────────────────────

	/**
	 * KpiReport round-trip
	 *
	 * @group happy
	 */
	public function test_kpi_report_round_trip(): void {
		$arr = [
			'total_sales'     => '12000.00',
			'profit_pending'  => '600.00',
			'profit_paid'     => '500.00',
			'profit_refunded' => '100.00',
			'period_start'    => '2026-05-01',
			'period_end'      => '2026-05-31',
		];

		$dto = KpiReport::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// TrendReport
	// ────────────────────────────────────────────────

	/**
	 * TrendReport round-trip
	 *
	 * @group happy
	 */
	public function test_trend_report_round_trip(): void {
		$arr = [
			'series' => [
				[
					'date'   => '2026-05-01',
					'profit' => '50.00',
					'sales'  => '500.00',
				],
				[
					'date'   => '2026-05-02',
					'profit' => '80.00',
					'sales'  => '800.00',
				],
			],
		];

		$dto = TrendReport::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// SlugConflict
	// ────────────────────────────────────────────────

	/**
	 * SlugConflict round-trip
	 *
	 * @group happy
	 */
	public function test_slug_conflict_round_trip(): void {
		$arr = [
			'conflict_kind'     => 'profit_shop',
			'conflicting_slug'  => 'summer',
			'conflicting_id'    => 99,
			'conflicting_label' => '夏季賣場',
		];

		$dto = SlugConflict::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}

	// ────────────────────────────────────────────────
	// SettingsDto —— 已整組移除（連同 #/profit-settings 設定頁）
	//
	// 它剩下的兩個欄位都是「有消費點，但不該讓站長改」：
	//  - `report_slug`：一改就讓所有已發給 partner 的報表網址失效
	//  - `rewrite_slug`：控制的 /{slug}/{post-name}/ 沒有任何程式碼連過去，
	//    打開只有佈景主題 single + post_content（商品 grid 只在 ProfitShopRenderer
	//    走 profit_shop_slug query var 時才渲染）
	//
	// 兩者現為 CptRegistrar::DEFAULT_REWRITE_SLUG / RewriteRules::DEFAULT_REPORT_SLUG
	// 固定常數。wp_options 殘留的 power_shop_profit_settings 成孤兒，無人讀寫，
	// 不需要 migration。route 層的回歸鎖見 V2ApiRoutingTest::test_profit_settings_routes_are_removed。
	// ────────────────────────────────────────────────
}
