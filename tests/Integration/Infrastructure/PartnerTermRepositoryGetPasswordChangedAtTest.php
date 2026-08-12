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

	/**
	 * 🔴 核心不變量：連續密碼寫入必須嚴格遞增，**同一秒內也不例外**
	 *
	 * ⚠️ 上一條測試（`..._updates_after_subsequent_save_with_new_password`）刻意
	 * 「把 termmeta 倒退 2 秒，避免 time() 同秒情況」——它**知道**同秒會有問題，
	 * 卻選擇迴避而不是驗證。那正是這個缺陷能長期潛伏的原因之一：
	 * 唯一一條測「值會變大」的測試，主動避開了唯一會出錯的情境。
	 *
	 * 本測試不迴避：連續呼叫 `save()`（在同一個測試方法內、幾乎必然落在同一秒），
	 * 直接斷言值仍嚴格遞增。
	 *
	 * 這條不變量是 `PartnerTokenStore::verify()` 撤銷判斷的**唯一依據**——
	 * 值一旦沒變，「簽發後改密」與「簽發前改密」就再也分不出來，
	 * 舊 token 會在改密後繼續有效（實測症狀：改密相關測試出現「預期 401 實得 200」）。
	 * 若有人把 `next_password_changed_at()` 簡化回 `time()`，本測試會立刻紅。
	 *
	 * @test
	 * @group security
	 * @group edge
	 */
	public function test_password_changed_at_strictly_increases_even_within_same_second(): void {
		$term_id  = $this->create_partner_with_password( 'monotonic-pw', 'MonotonicPw', 'first-pw1' );
		$snapshot = new PartnerSnapshot(
			term_id: $term_id,
			name: 'MonotonicPw',
			slug: new PartnerSlug( 'monotonic-pw' ),
			contact_email: null,
		);

		$observed = [ $this->repo->get_password_changed_at( $term_id ) ];

		// 連續 3 次改密，全程不 sleep（同秒是常態，不是例外）
		for ( $i = 2; $i <= 4; $i++ ) {
			$this->repo->save( $snapshot, "pw-number-{$i}" );
			$observed[] = $this->repo->get_password_changed_at( $term_id );
		}

		foreach ( $observed as $index => $value ) {
			$this->assertNotNull( $value, "第 {$index} 次寫入後不應為 null" );
		}

		for ( $i = 1; $i < count( $observed ); $i++ ) {
			$this->assertGreaterThan(
				$observed[ $i - 1 ],
				$observed[ $i ],
				sprintf(
					'第 %d 次密碼寫入的 password_changed_at（%d）必須嚴格大於前一次（%d）；'
					. '同秒寫入時 next_password_changed_at() 應退而取 prev + 1',
					$i + 1,
					(int) $observed[ $i ],
					(int) $observed[ $i - 1 ]
				)
			);
		}
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
