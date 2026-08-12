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
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
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

	// ========== 夥伴圖片（_partner_image_id）==========

	/**
	 * save() 應只持久化 image_id，image_url 於讀取時由 attachment 即時解析
	 *
	 * @test
	 * @group happy
	 */
	public function test_save_persists_image_id_and_resolves_url_on_read(): void {
		$attachment_id = $this->create_image_attachment();

		$term_id = $this->repo->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Ivy',
				slug: new PartnerSlug( 'ivy-with-image' ),
				contact_email: null,
				image_id: $attachment_id,
				// 刻意傳入假的 image_url——save() 必須忽略它，只寫 image_id。
				image_url: 'https://evil.example/should-be-ignored.jpg'
			),
			'plain-Pa55word!'
		);

		$this->assertSame(
			$attachment_id,
			(int) \get_term_meta( $term_id, '_partner_image_id', true ),
			'_partner_image_id termmeta 應寫入 attachment ID'
		);

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertSame( $attachment_id, $persisted->image_id );
		$this->assertNotNull( $persisted->image_url, 'image_url 應由 attachment 解析出來' );
		$this->assertStringNotContainsString(
			'evil.example',
			(string) $persisted->image_url,
			'save() 傳入的 image_url 不可被持久化'
		);
	}

	/**
	 * image_id 傳 null 應清空既有 termmeta（等同「移除夥伴圖片」）
	 *
	 * @test
	 * @group happy
	 */
	public function test_save_with_null_image_id_clears_existing_meta(): void {
		$attachment_id = $this->create_image_attachment();

		$term_id = $this->repo->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Ivy',
				slug: new PartnerSlug( 'ivy-clear-image' ),
				contact_email: null,
				image_id: $attachment_id
			)
		);

		// 第二次儲存不帶圖片 → 應清除。
		$this->repo->save(
			new PartnerSnapshot(
				term_id: $term_id,
				name: 'Ivy',
				slug: new PartnerSlug( 'ivy-clear-image' ),
				contact_email: null,
				image_id: null
			)
		);

		$this->assertSame(
			'',
			(string) \get_term_meta( $term_id, '_partner_image_id', true ),
			'image_id 傳 null 時 termmeta 應被刪除'
		);

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertNull( $persisted->image_id );
		$this->assertNull( $persisted->image_url );
	}

	/**
	 * attachment 已被刪除時：image_url 回 null，但 image_id termmeta 保留
	 *
	 * 讀取路徑不可有寫入副作用——保留 ID 才能在檔案被救回後自動復原顯示。
	 *
	 * @test
	 * @group edge
	 */
	public function test_deleted_attachment_yields_null_url_but_keeps_id(): void {
		$attachment_id = $this->create_image_attachment();

		$term_id = $this->repo->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Ivy',
				slug: new PartnerSlug( 'ivy-broken-image' ),
				contact_email: null,
				image_id: $attachment_id
			)
		);

		\wp_delete_attachment( $attachment_id, true );

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertSame( $attachment_id, $persisted->image_id, '孤兒 attachment ID 應保留' );
		$this->assertNull( $persisted->image_url, '無法解析的 attachment 應回 null URL' );
		$this->assertSame(
			$attachment_id,
			(int) \get_term_meta( $term_id, '_partner_image_id', true ),
			'讀取不可產生清除 termmeta 的副作用'
		);
	}

	// ========== 報表網址（report_url 唯讀衍生欄位）==========

	/**
	 * report_url 必須與實際註冊 rewrite rule 的 report 前綴同源
	 *
	 * 斷言值刻意寫成 `RewriteRules::get_report_slug()` 而非字面值 `'profit-report'`——
	 * 這樣它鎖的是「report_url 與 rewrite rule 同源」這個契約，而不是當下那個字串。
	 * 前綴日後若改動，rewrite rule 與本測試會一起跟著變；反之若有人把
	 * `resolve_report_url()` 改成 hardcode，兩者就會分家、本測試立刻紅燈。
	 *
	 * 沒有這道鎖時，分家的症狀是前台按鈕安靜地連到 404（頁面正常載入、只是找不到
	 * 報表），沒有任何錯誤訊息可循。
	 *
	 * @test
	 * @group happy
	 */
	public function test_report_url_matches_registered_report_prefix(): void {
		$term_id = $this->create_partner( 'ken-report-url', 'Ken', 'plain-Pa55word!' );

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertSame(
			\home_url( '/' . RewriteRules::get_report_slug() . '/ken-report-url/' ),
			$persisted->report_url,
			'report_url 的前綴必須與 RewriteRules 註冊 rewrite rule 時所用的來源一致'
		);
	}

	/**
	 * wp_options 內殘留的舊 `report_slug` 必須被忽略（report 前綴已改為固定常數）
	 *
	 * report_slug 曾經是 `#/profit-settings` 的設定項，後來移除（改動它會讓所有已發給
	 * partner 的報表網址一次失效）。既有站台的 wp_options 仍可能留著那個 key 且**不做
	 * migration**，所以「殘留值不得復活」必須有測試守住——否則舊站台升級後，
	 * report_url 會指向一個 rewrite rule 根本沒註冊的前綴，一樣是靜默 404。
	 *
	 * @test
	 * @group edge
	 */
	public function test_report_url_ignores_stale_report_slug_option(): void {
		$term_id = $this->create_partner( 'ken-stale-slug', 'Ken', 'plain-Pa55word!' );

		// ⚠️ 刻意寫字面值而非引用常數——`RewriteRules::OPTIONS_KEY` 已隨全域設定頁移除，
		// production 端不該再有它。測試需要這個 key 只是為了塞進殘留值、驗證它被忽略。
		$legacy_option_key = 'power_shop_profit_settings';

		$original = \get_option( $legacy_option_key, [] );
		\update_option(
			$legacy_option_key,
			[ 'report_slug' => 'stale-legacy-report' ],
			false
		);

		try {
			$persisted = $this->repo->find_by_id( $term_id );
			$this->assertNotNull( $persisted );
			$this->assertStringNotContainsString(
				'stale-legacy-report',
				(string) $persisted->report_url,
				'wp_options 殘留的舊 report_slug 不可影響 report_url'
			);
			$this->assertSame(
				\home_url( '/' . RewriteRules::DEFAULT_REPORT_SLUG . '/ken-stale-slug/' ),
				$persisted->report_url,
				'report 前綴應恆為 DEFAULT_REPORT_SLUG 常數'
			);
		} finally {
			\update_option( $legacy_option_key, $original, false );
		}
	}

	/**
	 * save() 必須忽略傳入的 report_url（與 image_url 同屬唯讀衍生欄位）
	 *
	 * @test
	 * @group happy
	 * @group security
	 */
	public function test_save_ignores_supplied_report_url(): void {
		$term_id = $this->repo->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Leo',
				slug: new PartnerSlug( 'leo-report-url' ),
				contact_email: null,
				image_id: null,
				image_url: null,
				// 刻意傳入假的 report_url——save() 必須忽略它，讀取時重新組出。
				report_url: 'https://evil.example/should-be-ignored/'
			),
			'plain-Pa55word!'
		);

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertStringNotContainsString(
			'evil.example',
			(string) $persisted->report_url,
			'save() 傳入的 report_url 不可被持久化'
		);
		$this->assertSame(
			\home_url( '/' . RewriteRules::DEFAULT_REPORT_SLUG . '/leo-report-url/' ),
			$persisted->report_url,
			'report_url 應於讀取時由 slug + report slug 重新組出'
		);
	}

	// ========== 明文密碼（站長後台查看 / 複製用） ==========

	/**
	 * save() 寫入的明文可被讀回，且與雜湊驗證的結果一致
	 *
	 * 雜湊與明文是**兩份獨立寫入**，只要有一邊漏寫或寫錯，症狀就是
	 * 「站長把後台看到的密碼給夥伴、夥伴卻登不進去」，而且兩邊都不會有錯誤訊息。
	 * 因此這條測試同時斷言兩件事：讀回的明文正確、且該明文真的能通過驗證。
	 *
	 * @group happy
	 */
	public function test_save_persists_plain_password_alongside_hash(): void {
		$term_id = $this->create_partner( 'pw-plain', 'PW Plain', 'partner-pw-1234' );

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertSame( 'partner-pw-1234', $persisted->password, '明文應可讀回供站長複製' );

		$this->assertTrue(
			$this->repo->verify_password( $term_id, 'partner-pw-1234' ),
			'讀回的明文必須真的能通過雜湊驗證，否則後台顯示的密碼是錯的'
		);
	}

	/**
	 * 含反斜線 / 引號的密碼不會在往返後變形
	 *
	 * `update_metadata()` 內部會對值跑一次 `wp_unslash()`，不先 `wp_slash()` 的話
	 * `\` / `'` / `"` 會被靜默吃掉——而雜湊是用未經處理的原始明文算的，
	 * 於是後台顯示的密碼比實際密碼少了幾個字元。這正是「看起來正常、實際登不進去」的成因。
	 *
	 * @group edge
	 */
	public function test_save_preserves_backslash_and_quotes_in_plain_password(): void {
		$tricky  = 'a\\b\'c"d1234';
		$term_id = $this->create_partner( 'pw-tricky', 'PW Tricky', $tricky );

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertSame( $tricky, $persisted->password, '反斜線 / 引號不可在 termmeta 往返中被吃掉' );

		$this->assertTrue(
			$this->repo->verify_password( $term_id, $tricky ),
			'明文與雜湊必須對同一個字串成立'
		);
	}

	/**
	 * 未帶密碼的 save() 不會清掉既有明文
	 *
	 * 站長只改名稱 / Email 時 `$plain_password` 是 null，此時**兩份**密碼資料
	 * （雜湊與明文）都必須原封不動。若明文被誤清成空，列表頁的「複製密碼」
	 * 會突然變成停用狀態，站長會以為資料壞了。
	 *
	 * @group edge
	 */
	public function test_save_without_password_keeps_existing_plain_password(): void {
		$term_id = $this->create_partner( 'pw-keep', 'PW Keep', 'original-pw-99' );

		$this->repo->save(
			new PartnerSnapshot(
				term_id: $term_id,
				name: 'PW Keep Renamed',
				slug: new PartnerSlug( 'pw-keep' ),
				contact_email: null
			)
		);

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertSame( 'PW Keep Renamed', $persisted->name, '名稱應已更新' );
		$this->assertSame( 'original-pw-99', $persisted->password, '未帶密碼時明文不可被清掉' );
		$this->assertTrue( $this->repo->verify_password( $term_id, 'original-pw-99' ) );
	}

	/**
	 * Snapshot 傳入的 password 一律被忽略（唯讀衍生欄位）
	 *
	 * 與 `image_url` / `report_url` 同一條紀律：密碼變更**只**認 `save()` 的第二參數。
	 * 若 Snapshot 的 password 也能寫入，就會多出一條繞過 `password_changed_at` bump
	 * （= 舊 token 撤銷）的隱形寫入路徑。
	 *
	 * @group security
	 */
	public function test_save_ignores_password_field_on_snapshot(): void {
		$term_id = $this->create_partner( 'pw-ignore', 'PW Ignore', 'legit-pw-123' );

		$this->repo->save(
			new PartnerSnapshot(
				term_id: $term_id,
				name: 'PW Ignore',
				slug: new PartnerSlug( 'pw-ignore' ),
				contact_email: null,
				password: 'injected-pw-999'
			)
		);

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertSame( 'legit-pw-123', $persisted->password, 'Snapshot 的 password 不可被持久化' );
		$this->assertFalse(
			$this->repo->verify_password( $term_id, 'injected-pw-999' ),
			'透過 Snapshot 注入的密碼不可生效'
		);
	}

	/**
	 * 只有雜湊、沒有明文的舊資料，password 讀為 null
	 *
	 * 模擬「明文功能上線前建立」的夥伴：能正常登入（雜湊在），但明文無法還原。
	 * 這是合法狀態，必須是 null 而非空字串——UI 靠這個分流顯示「無法顯示，請重設密碼」。
	 *
	 * @group edge
	 */
	public function test_legacy_partner_without_plain_meta_reads_password_as_null(): void {
		$term_id = $this->create_partner( 'pw-legacy', 'PW Legacy', 'legacy-pw-77' );

		// 模擬舊資料：刪掉明文 meta，只留雜湊
		\delete_term_meta( $term_id, '_partner_password_plain' );

		$persisted = $this->repo->find_by_id( $term_id );
		$this->assertNotNull( $persisted );
		$this->assertNull( $persisted->password, '無明文 meta 時應回 null（不是空字串）' );
		$this->assertTrue(
			$this->repo->verify_password( $term_id, 'legacy-pw-77' ),
			'明文不存在不影響登入——雜湊仍是驗證的唯一依據'
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立一張測試用圖片 attachment（不需真實檔案，只要 URL 可解析）
	 *
	 * @param string $filename 檔名
	 *
	 * @return int attachment ID
	 */
	private function create_image_attachment( string $filename = 'partner-avatar.jpg' ): int {
		$attachment_id = \wp_insert_attachment(
			[
				'post_title'     => $filename,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			]
		);

		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		\update_post_meta( $attachment_id, '_wp_attached_file', '2026/07/' . $filename );

		return (int) $attachment_id;
	}

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
