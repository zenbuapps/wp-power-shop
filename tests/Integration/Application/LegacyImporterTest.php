<?php
/**
 * LegacyImporter IT
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * @group profit_shop
 * @group rest
 * @group application
 * @group migration
 */

declare( strict_types=1 );

namespace Tests\Integration\Application;

use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\LegacyOnePageShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\PartnerTermRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar;
use Tests\Integration\TestCase;

/**
 * LegacyImporter IT
 */
final class LegacyImporterTest extends TestCase {

	private int $partner_term_id;

	public function set_up(): void {
		parent::set_up();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$this->partner_term_id = PartnerTermRepository::instance()->save(
			new PartnerSnapshot(
				term_id: 0,
				name: 'Importer',
				slug: new PartnerSlug( 'importer-kol' ),
				contact_email: null
			),
			'pw'
		);
	}

	/**
	 * GET /profit-migration/legacy-shops → 200 + 列表
	 *
	 * @test
	 * @group happy
	 */
	public function test_list_legacy_shops_returns_200(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-shop/profit-migration/legacy-shops' );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * POST /profit-migration/import 缺 partner_term_id → 422 legacy_unimportable
	 *
	 * @test
	 * @group error
	 */
	public function test_import_without_partner_term_id_returns_422(): void {
		// 先建一個舊版 power-shop CPT 賣場（legacy）
		$legacy_id = self::factory()->post->create(
			[
				'post_type'   => 'power-shop',
				'post_status' => 'publish',
				'post_title'  => '舊版賣場',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-migration/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'legacy_id'       => $legacy_id,
					'partner_term_id' => 0,
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'legacy_unimportable', $body['code'] ?? null );
	}

	/**
	 * POST /profit-migration/import 帶合法 partner_term_id → 201 + 新賣場 ID
	 *
	 * @test
	 * @group happy
	 */
	public function test_import_with_partner_creates_new_profit_shop(): void {
		$legacy_id = self::factory()->post->create(
			[
				'post_type'   => 'power-shop',
				'post_status' => 'publish',
				'post_title'  => '舊版賣場待匯入',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-migration/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'legacy_id'       => $legacy_id,
					'partner_term_id' => $this->partner_term_id,
				]
			)
		);

		$response = \rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		$this->assertGreaterThan( 0, $body['data']['id'] ?? 0 );
		$this->assertSame( 'page', $body['data']['mode'] ?? null, 'spec §4.7：匯入後 mode=page' );
	}

	// ====================================================================
	//  Rule: 權限（寫入）- 匯入需要 manage_options
	// ====================================================================

	/**
	 * 🔴 只有 manage_woocommerce 的角色不得匯入
	 *
	 * 本檔在此之前**完全沒有**驗過匯入端點的權限：唯一掛在這條 Rule 上的
	 * `test_list_legacy_shops_returns_200` 是以 administrator 呼叫 **GET 列表**
	 * 並斷言 200——既不是 import 端點、也沒有驗證任何拒絕行為。
	 * 也就是說把 `profit-migration/import` 的 permission 從 `admin_only`
	 * 降成 `default`（`manage_woocommerce`），整個測試套件一條都不會紅。
	 *
	 * 🔴 **只斷言狀態碼不夠**：那擋不住「回了 403、但賣場其實已經建好了」這種
	 *    permission_callback 被繞過 / 檢查排在寫入之後的壞法。因此同時斷言
	 *    powershop 的數量前後不變——匯入是不可逆操作，「有沒有真的寫進去」
	 *    才是這條 Rule 真正要保護的東西。
	 *
	 * 前置斷言（`manage_woocommerce` 為真、`manage_options` 為假）同樣不可少：
	 * 少了它，哪天 shop_manager 這個角色的能力集合變了，本測試會變成
	 * 「拿一個什麼權限都沒有的使用者去測」的假綠燈。
	 *
	 * @test
	 * @group security
	 */
	public function test_import_requires_manage_options(): void {
		$legacy_id = $this->create_legacy_shop( [ 'post_title' => '不該被 shop_manager 匯入' ] );

		$shop_manager_id = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		\wp_set_current_user( $shop_manager_id );

		$this->assertTrue(
			\current_user_can( 'manage_woocommerce' ),
			'測試前置失效：shop_manager 應具備 manage_woocommerce，否則本測試沒測到「僅具 manage_woocommerce」這個情境'
		);
		$this->assertFalse(
			\current_user_can( 'manage_options' ),
			'測試前置失效：shop_manager 不應具備 manage_options'
		);

		$before = $this->count_profit_shops();

		$response = $this->import_via_api( $legacy_id, $this->partner_term_id );

		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'shop_manager（無 manage_options）不應被允許匯入舊版賣場'
		);
		$this->assertSame(
			$before,
			$this->count_profit_shops(),
			'請求被拒絕了，卻仍然建立了賣場 ⇒ 權限檢查沒有擋在寫入之前'
		);
	}

	/**
	 * 🔴 未登入的請求同樣不得匯入
	 *
	 * 與上一條是同一條 Rule 的另一半：上一條擋的是「權限不足的登入者」，
	 * 這條擋的是「完全沒有身分的請求」。兩者走的是 permission_callback 內
	 * 不同的判斷路徑，只留一條會讓另一條變成零覆蓋。
	 *
	 * @test
	 * @group security
	 */
	public function test_import_rejects_unauthenticated_request(): void {
		$legacy_id = $this->create_legacy_shop( [ 'post_title' => '訪客不該匯入' ] );

		\wp_set_current_user( 0 );

		$before = $this->count_profit_shops();

		$response = $this->import_via_api( $legacy_id, $this->partner_term_id );

		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'未登入的請求不應被允許匯入舊版賣場'
		);
		$this->assertSame(
			$before,
			$this->count_profit_shops(),
			'未登入請求被拒絕了，卻仍然建立了賣場'
		);
	}

	// ====================================================================
	//  Rule: 後置（保留）- 匯入不刪除也不修改舊版資料
	// ====================================================================

	/**
	 * 🔴 匯入之後舊版賣場必須原封不動
	 *
	 * 本條在此之前是零覆蓋。原本掛在這條 Rule 上的兩條 `ListImportableLegacyShops`
	 * 單元測試測的是**列表** UseCase，全程沒有執行過任何一次匯入；
	 * `ImportLegacyShopTest` 內那句 `assertSame( 1, $this->shopRepo->count() )`
	 * 的註解雖然寫著「不刪除舊版，只新增」，但它數的是**新的 ProfitShop repo**、
	 * 不是 legacy repo ⇒ 在 UseCase 內加一行刪除 legacy 的程式碼，一條測試都不會紅。
	 *
	 * 🔴 **只斷言「還在」不夠**：那擋不住「沒刪掉、但把標題／slug／meta 改寫了」
	 *    這種壞法（例如「匯入後把舊版標記成已匯入」這類看似無害的順手改動）。
	 *    因此逐欄比對**整份快照**（`find()` 的 id / title / slug / meta 全部）
	 *    以及 post 本體的 status / title / name / content。
	 *
	 * 🔴 **`clean_post_cache()` 不可省略**：`get_post()` 會回物件快取裡的那一個，
	 *    若匯入是以直接 SQL 刪除 / 改寫（不清快取），讀到的會是舊的快取值 ⇒
	 *    測試全綠但資料其實已經被動過。清掉快取才是真的從 DB 重讀。
	 *
	 * @test
	 * @group happy
	 */
	public function test_import_does_not_delete_or_modify_the_legacy_shop(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$legacy_id = $this->create_legacy_shop(
			[
				'post_title'   => '舊版賣場原封不動',
				'post_name'    => 'legacy-untouched',
				'post_content' => 'PS_LEGACY_BODY',
			]
		);
		\update_post_meta( $legacy_id, 'btn_color', '#123456' );
		\update_post_meta( $legacy_id, 'show_stock', 'yes' );

		$legacy_repo     = LegacyOnePageShopRepository::instance();
		$before_snapshot = $legacy_repo->find( $legacy_id );
		$before_post     = $this->snapshot_post_fields( $legacy_id );

		$this->assertIsArray( $before_snapshot, '測試前置：舊版賣場應讀得到' );

		$response = $this->import_via_api( $legacy_id, $this->partner_term_id );
		$this->assertSame( 201, $response->get_status(), '測試前置：匯入本身應成功，否則下面的斷言等於什麼都沒驗' );

		\clean_post_cache( $legacy_id );

		$after_snapshot = $legacy_repo->find( $legacy_id );

		$this->assertIsArray(
			$after_snapshot,
			'匯入後舊版賣場不見了 ⇒ 匯入刪除了來源資料（新舊系統應可並存）'
		);
		$this->assertSame(
			$before_snapshot,
			$after_snapshot,
			'舊版賣場的 title / slug / meta 被改寫了 ⇒ 匯入不得修改來源資料'
		);
		$this->assertSame(
			$before_post,
			$this->snapshot_post_fields( $legacy_id ),
			'舊版賣場 post 本體（狀態／標題／永久連結／內容）被改寫了'
		);
	}

	// ====================================================================
	//  Helper
	// ====================================================================

	/**
	 * 建立一筆舊版一頁商店（`power-shop` CPT）
	 *
	 * @param array<string, mixed> $args 覆蓋預設欄位
	 *
	 * @return int post ID
	 */
	private function create_legacy_shop( array $args = [] ): int {
		return (int) self::factory()->post->create(
			\array_merge(
				[
					'post_type'   => LegacyOnePageShopRepository::LEGACY_POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => '舊版賣場',
				],
				$args
			)
		);
	}

	/**
	 * 呼叫 `POST /profit-migration/import`
	 *
	 * @param int $legacy_id       舊版資料 ID
	 * @param int $partner_term_id 分潤夥伴 term ID
	 *
	 * @return \WP_REST_Response
	 */
	private function import_via_api( int $legacy_id, int $partner_term_id ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-migration/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			\wp_json_encode(
				[
					'legacy_id'       => $legacy_id,
					'partner_term_id' => $partner_term_id,
				]
			)
		);

		return \rest_do_request( $request );
	}

	/**
	 * 數目前有幾個新版分潤賣場（`powershop` CPT）
	 *
	 * `post_status => any` 是必要的：匯入建出來的是 **draft**，
	 * 用預設的 publish-only 查詢會數不到它，變成永遠相等的假綠燈。
	 *
	 * @return int
	 */
	private function count_profit_shops(): int {
		return \count(
			(array) \get_posts(
				[
					'post_type'      => CptRegistrar::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				]
			)
		);
	}

	/**
	 * 把 post 本體的關鍵欄位抓成純量陣列
	 *
	 * 刻意回傳陣列而不是 `WP_Post` 物件：`get_post()` 回的是物件快取裡的那一個實例，
	 * 拿它當「之前的樣子」保存下來，之後若有人就地改寫同一個實例，前後會變成同一份東西。
	 *
	 * @param int $post_id post ID
	 *
	 * @return array<string, string>
	 */
	private function snapshot_post_fields( int $post_id ): array {
		$post = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return [];
		}

		return [
			'post_type'    => (string) $post->post_type,
			'post_status'  => (string) $post->post_status,
			'post_title'   => (string) $post->post_title,
			'post_name'    => (string) $post->post_name,
			'post_content' => (string) $post->post_content,
		];
	}
}
