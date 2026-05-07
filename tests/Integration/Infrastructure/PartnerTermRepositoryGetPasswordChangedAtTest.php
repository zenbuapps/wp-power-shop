<?php
/**
 * PartnerTermRepository::get_password_changed_at() 整合測試（Phase 3-D Task T-1 紅燈）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4 + Phase 3-D 預告 #1
 * 對應實作（將要新增）：inc/classes/Domains/ProfitShop/Infrastructure/Persistence/PartnerTermRepository.php
 *
 * 驗證：以真實 WP termmeta + profit_partner taxonomy 確認 _partner_password_changed_at
 * 寫入後可被 get_password_changed_at() 正確讀回。
 *
 * 紅燈預期：
 *   - PartnerTermRepository::get_password_changed_at() 尚未實作
 *   - 呼叫時 PHP 會拋 Error: Call to undefined method
 *   - 整檔 4 個測試全部紅燈
 *
 * @group profit_shop
 * @group infrastructure
 * @group persistence
 * @group repository
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;
use Tests\Integration\TestCase;

/**
 * 透過真實 WP DB 驗證 _partner_password_changed_at termmeta 的讀取行為
 */
final class PartnerTermRepositoryGetPasswordChangedAtTest extends TestCase {

	private PartnerTermRepository $repo;

	/**
	 * 本測試建立的 partner term_id 清單（tearDown 用以清除）
	 *
	 * @var int[]
	 */
	private array $created_term_ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->repo             = PartnerTermRepository::instance();
		$this->created_term_ids = [];
	}

	public function tear_down(): void {
		// 清除本測試建立的 partner term，避免污染後續測試
		foreach ( $this->created_term_ids as $term_id ) {
			\wp_delete_term( $term_id, TaxonomyRegistrar::TAXONOMY );
		}
		$this->created_term_ids = [];
		parent::tear_down();
	}

	// ========== Happy / Edge ==========

	/**
	 * 沒有寫過密碼的 partner（透過 wp_insert_term 直接建立、未走 save 帶 plain_password）
	 * 應回 null，而非 0。
	 *
	 * 紅燈訊息預期：Call to undefined method PartnerTermRepository::get_password_changed_at()
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_get_password_changed_at_returns_null_for_new_partner_without_password_change_history(): void {
		// 直接用 wp_insert_term 建 term，不走 save() → 不會寫入 _partner_password_changed_at
		$result = \wp_insert_term( 'NoPwdHistory', TaxonomyRegistrar::TAXONOMY, [ 'slug' => 'no-pwd-history' ] );
		$this->assertIsArray( $result );
		$term_id                  = (int) $result['term_id'];
		$this->created_term_ids[] = $term_id;

		// pre-condition: meta 確實不存在
		$raw = \get_term_meta( $term_id, '_partner_password_changed_at', true );
		$this->assertSame( '', (string) $raw, 'pre-condition：未經 save 的 partner 不應有 _partner_password_changed_at meta' );

		$this->assertNull(
			$this->repo->get_password_changed_at( $term_id ),
			'未經密碼變更歷史的 partner 應回 null（不可回 0，否則 token 撤銷邏輯會失效）'
		);
	}

	/**
	 * save() 帶 plain_password 後立刻查 → 應拿到 >= 測試開始時間 的 unix timestamp
	 *
	 * 紅燈訊息預期：Call to undefined method PartnerTermRepository::get_password_changed_at()
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_get_password_changed_at_returns_timestamp_after_save_with_plain_password(): void {
		$before  = time();
		$term_id = $this->create_partner_with_password( 'pwd-set', 'PwdSet', 'plain-Pa55word!' );
		$after   = time();

		$changed_at = $this->repo->get_password_changed_at( $term_id );

		$this->assertNotNull( $changed_at, 'save() 帶 plain_password 後應寫入 _partner_password_changed_at' );
		$this->assertGreaterThanOrEqual( $before, $changed_at, '_partner_password_changed_at 應 >= 測試開始時間' );
		$this->assertLessThanOrEqual( $after, $changed_at, '_partner_password_changed_at 應 <= 測試結束時間' );
	}

	/**
	 * 同一個 partner 連讀 3 次 get_password_changed_at 應穩定回同值
	 *
	 * 紅燈訊息預期：Call to undefined method PartnerTermRepository::get_password_changed_at()
	 *
	 * @test
	 * @group happy
	 */
	public function test_get_password_changed_at_persists_through_multiple_reads(): void {
		$term_id = $this->create_partner_with_password( 'stable-read', 'StableRead', 'first-pw' );

		$first  = $this->repo->get_password_changed_at( $term_id );
		$second = $this->repo->get_password_changed_at( $term_id );
		$third  = $this->repo->get_password_changed_at( $term_id );

		$this->assertNotNull( $first, '第一次讀取應有值' );
		$this->assertSame( $first, $second, '第二次讀取應與第一次相同' );
		$this->assertSame( $first, $third, '第三次讀取應與第一次相同' );
	}

	/**
	 * 密碼被變更後 get_password_changed_at 應回較大的 timestamp
	 *
	 * 此為 Phase 3-D Task T-2 token 撤銷邏輯的核心依賴：
	 * 一旦密碼改變，password_changed_at 變大，舊 token（issued_at < password_changed_at）即可被識別為失效。
	 *
	 * 紅燈訊息預期：Call to undefined method PartnerTermRepository::get_password_changed_at()
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_get_password_changed_at_updates_after_subsequent_save_with_new_password(): void {
		$term_id = $this->create_partner_with_password( 'rotate-pw', 'RotatePw', 'first-pw' );
		$first   = $this->repo->get_password_changed_at( $term_id );
		$this->assertNotNull( $first, '第一次密碼設定後應有 timestamp' );

		// 直接把 termmeta 倒退 2 秒，確保第二次 save 後一定變大（避免 time() 同秒情況）
		\update_term_meta( $term_id, '_partner_password_changed_at', $first - 2 );

		// 重新 save 帶新密碼
		$snapshot = new PartnerSnapshot(
			term_id: $term_id,
			name: 'RotatePw',
			slug: new PartnerSlug( 'rotate-pw' ),
			contact_email: null,
		);
		$this->repo->save( $snapshot, 'second-pw-' . uniqid() );

		$second = $this->repo->get_password_changed_at( $term_id );
		$this->assertNotNull( $second, '第二次密碼設定後仍應有 timestamp' );
		$this->assertGreaterThan(
			$first - 2,
			$second,
			'重新 save 新密碼後 _partner_password_changed_at 應變大（token 撤銷邏輯之核心依賴）'
		);
	}

	// ========== Helpers ==========

	/**
	 * 透過 Repository save 建立 partner（保留 term_id 以供 tearDown 清除）
	 *
	 * @param string $slug     partner slug
	 * @param string $name     partner 顯示名稱
	 * @param string $password 明文密碼
	 *
	 * @return int term id
	 */
	private function create_partner_with_password( string $slug, string $name, string $password ): int {
		$snapshot = new PartnerSnapshot(
			term_id: 0,
			name: $name,
			slug: new PartnerSlug( $slug ),
			contact_email: null,
		);
		$term_id  = $this->repo->save( $snapshot, $password );
		$this->created_term_ids[] = $term_id;
		return $term_id;
	}
}
