<?php
/**
 * PartnerTermRepository 整合測試
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4、§6.3、§6.4
 * 對應實作：inc/classes/Domains/ProfitShop/Infrastructure/Persistence/PartnerTermRepository.php
 *
 * 重點驗證：
 * - 密碼必須以 wp_hash_password 雜湊儲存（絕不可明文）
 * - is_in_use 對應的 _profit_partner_term_id meta 是否被正確識別
 * - find_by_slug / find_by_id 的 round-trip
 *
 * @group profit_shop
 * @group infrastructure
 * @group persistence
 * @group repository
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;
use Tests\Integration\TestCase;

/**
 * 透過真實 WP DB 驗證 Partner term + termmeta 的存取行為
 */
final class PartnerTermRepositoryTest extends TestCase {

	private PartnerTermRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = PartnerTermRepository::instance();
	}

	// ========== Happy ==========

	/**
	 * save() 必須建立 term 並將密碼以 wp_hash_password 雜湊存入 termmeta
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_save_creates_term_with_hashed_password(): void {
		$snapshot = new PartnerSnapshot(
			term_id: 0,
			name: 'Jerry',
			slug: new PartnerSlug( 'jerry' ),
			contact_email: 'jerry@example.com'
		);

		$term_id = $this->repo->save( $snapshot, 'plain-Pa55word!' );

		$this->assertGreaterThan( 0, $term_id );

		$term = \get_term( $term_id, TaxonomyRegistrar::TAXONOMY );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 'Jerry', $term->name );
		$this->assertSame( 'jerry', $term->slug );

		$stored_hash = (string) \get_term_meta( $term_id, '_partner_password', true );
		$this->assertNotSame(
			'plain-Pa55word!',
			$stored_hash,
			'安全性違規：密碼必須雜湊，絕不可以明文儲存'
		);
		$this->assertNotSame( '', $stored_hash, '_partner_password termmeta 應有寫入內容' );

		$this->assertSame(
			'jerry@example.com',
			(string) \get_term_meta( $term_id, '_partner_contact_email', true )
		);

		$changed_at = (int) \get_term_meta( $term_id, '_partner_password_changed_at', true );
		$this->assertGreaterThan( 0, $changed_at, '_partner_password_changed_at 應為有效 unix timestamp' );
	}

	/**
	 * verify_password 對正確密碼應回傳 true
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_verify_password_succeeds_with_correct_password(): void {
		$term_id = $this->create_partner( 'mary', 'Mary', 'correct-password-123' );

		$this->assertTrue(
			$this->repo->verify_password( $term_id, 'correct-password-123' ),
			'正確密碼應通過驗證'
		);
	}

	/**
	 * find_by_slug 應回傳完整 PartnerSnapshot
	 *
	 * @test
	 * @group happy
	 */
	public function test_find_by_slug_returns_partner(): void {
		$term_id = $this->create_partner( 'alice', 'Alice Wong', 'whatever' );

		$found = $this->repo->find_by_slug( 'alice' );

		$this->assertInstanceOf( PartnerSnapshot::class, $found );
		$this->assertSame( $term_id, $found->term_id );
		$this->assertSame( 'Alice Wong', $found->name );
		$this->assertSame( 'alice', $found->slug->value() );
	}

	/**
	 * 當有 powershop CPT 透過 _profit_partner_term_id 引用某 partner 時，is_in_use 應回 true
	 *
	 * @test
	 * @group happy
	 */
	public function test_is_in_use_returns_true_when_attached_to_shop(): void {
		$term_id = $this->create_partner( 'bob', 'Bob', 'whatever' );

		$post_id = $this->factory()->post->create(
			[
				'post_type'   => CptRegistrar::POST_TYPE,
				'post_title'  => 'Bob 賣場',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $post_id, '_profit_partner_term_id', $term_id );

		$this->assertTrue(
			$this->repo->is_in_use( $term_id ),
			'partner 已掛在賣場上時 is_in_use 應回 true'
		);
	}

	// ========== Error ==========

	/**
	 * verify_password 對錯誤密碼應回傳 false
	 *
	 * @test
	 * @group error
	 * @group security
	 */
	public function test_verify_password_fails_with_wrong_password(): void {
		$term_id = $this->create_partner( 'oscar', 'Oscar', 'real-password' );

		$this->assertFalse(
			$this->repo->verify_password( $term_id, 'wrong-password' ),
			'錯誤密碼必須回 false'
		);
	}

	/**
	 * partner 沒有掛在任何賣場時，is_in_use 應回 false
	 *
	 * @test
	 * @group error
	 */
	public function test_is_in_use_returns_false_when_not_attached(): void {
		$term_id = $this->create_partner( 'lonely', 'Lonely', 'whatever' );

		$this->assertFalse(
			$this->repo->is_in_use( $term_id ),
			'未掛在任何賣場上時 is_in_use 應回 false'
		);
	}

	/**
	 * find_by_slug 對不存在的 slug 應回 null（不可 throw）
	 *
	 * Repository 合約規定 find_by_slug 找不到時要回傳 null，由 caller 決定是否升格為例外。
	 *
	 * @test
	 * @group edge
	 * @group error
	 */
	public function test_find_by_slug_returns_null_for_unknown(): void {
		$this->assertNull(
			$this->repo->find_by_slug( 'non-existent-slug-xyz' ),
			'未存在的 partner slug find_by_slug() 應回 null'
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立一個 partner（透過 Repository save，確保 termmeta 寫入路徑與 production 一致）
	 *
	 * @param string $slug     partner slug
	 * @param string $name     partner 顯示名稱
	 * @param string $password 明文密碼
	 *
	 * @return int term id
	 */
	private function create_partner( string $slug, string $name, string $password ): int {
		$snapshot = new PartnerSnapshot(
			term_id: 0,
			name: $name,
			slug: new PartnerSlug( $slug ),
			contact_email: null
		);
		return $this->repo->save( $snapshot, $password );
	}
}
