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
 *   - SettingsDto
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
use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;
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
		];

		$dto = PartnerInput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
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
		];

		$dto = PartnerOutput::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
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
	// SettingsDto
	// ────────────────────────────────────────────────

	/**
	 * SettingsDto round-trip
	 *
	 * @group happy
	 */
	public function test_settings_dto_round_trip(): void {
		$arr = [
			'rewrite_slug'  => 'shop',
			'report_slug'   => 'report',
			'default_rate'  => 10,
			'page_template' => 'default',
		];

		$dto = SettingsDto::from_array( $arr );

		$this->assertSame( $arr, $dto->to_array() );
	}
}
