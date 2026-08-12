<?php
/**
 * 已知落差的現況鎖（Known-Gap Characterization Tests）
 *
 * 對應規格：specs/features/profit-shop/*.feature 內標為「已知落差」的 Rule
 *
 * ── 為什麼「已知落差」也要有測試 ──────────────────────────────
 *
 * 這些 Rule 描述的是**目前刻意不做的事**（後端不驗證、前端才擋、公開網址仍開著）。
 * 過去它們一律標成 NOT-COVERED——但那等於「沒有人守著這個決定」：
 *
 *   - 有人日後補上驗證 → 規格與實作分家，而**沒有任何徵兆**
 *   - 有人以為「後端一定有擋」→ 在前端放寬限制，直接開一個洞
 *
 * 本檔用 characterization test 把現況鎖住：**測試綠 = 落差仍存在且被知悉**。
 * 一旦行為改變，測試轉紅，那正是提醒「該回去更新規格了」的訊號，
 * 而不是「壞了」——每條測試的 docblock 都寫明了轉紅時該做什麼。
 *
 * ⚠️ 這些**不是**「期望行為正確」的測試，是「期望現況不變」的測試。
 * 兩者的修法完全不同，不要混淆。
 *
 * @group profit_shop
 * @group rest
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\Rest;

use Tests\Integration\TestCase;

/**
 * 已知落差的現況鎖
 */
final class ProfitShopKnownGapsTest extends TestCase {

	private int $partner_term_id = 0;

	/**
	 * 遞增序號，確保每次產生的 slug 唯一
	 *
	 * ⚠️ 刻意不用 `wp_generate_password()`——它會產生**大寫字母**，
	 * 而 `PartnerSlug` 只接受 `^[a-z0-9_-]+$`，隨機大寫時夥伴會建立失敗，
	 * 症狀是「有時綠有時紅」的 flaky（第一版就這樣，4 條有 3 條紅）。
	 *
	 * @var int
	 */
	private static int $seq = 0;

	public function set_up(): void {
		parent::set_up();
		++self::$seq;
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->partner_term_id = $this->create_partner();
	}

	// ========== save-profit-shop：已知落差（驗證）==========

	/**
	 * 🔒 現況鎖：永久連結在後端**沒有**必填與格式驗證
	 *
	 * 規格 Rule「已知落差（驗證）- 永久連結在後端沒有必填與格式驗證」。
	 * 前端 SlugInput 會即時擋，但繞過前端直接打 API 一樣建得起來。
	 *
	 * ⚠️ 這條轉紅代表**後端補上驗證了**——那是好事，但請同步：
	 *   1. 把 save-profit-shop.feature 的該 Rule 從「已知落差」改寫成正向規格
	 *   2. 把本測試改成驗證新的驗證行為
	 * 不要為了讓它綠而把驗證拿掉。
	 *
	 * @test
	 * @group edge
	 */
	public function test_known_gap_backend_does_not_validate_slug_format(): void {
		foreach ( [ '', 'UPPER CASE', '中文 slug', '!!!@@@', str_repeat( 'a', 300 ) ] as $bad_slug ) {
			$res = $this->create_shop_via_rest( [ 'slug' => $bad_slug ] );
			$this->assertLessThan(
				300,
				$res->get_status(),
				sprintf( '現況：後端不驗證 slug 格式，"%s" 應建立成功', $bad_slug )
			);
		}
	}

	/**
	 * 🔒 現況鎖：賣場商品數量上限**只在前端把關**
	 *
	 * 規格 Rule「已知落差（數量）- 賣場商品數量上限只在前端把關」與
	 * select-shop-products 的「單次批次加入不得超過 100 筆 / 總數超過 500 筆應警示」。
	 *
	 * 前端擋 100（硬）/ 500（軟），後端 `ItemValidator` 完全沒有數量檢查
	 * （profit-shop.rule.md §12.9 記載的刻意取捨）。
	 *
	 * ⚠️ 轉紅代表後端加了數量上限——請同步更新那三條 Rule 的敘述。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_known_gap_backend_accepts_more_items_than_frontend_limit(): void {
		// 前端硬上限是「單次批次加入 100 筆」，這裡送 105 筆**不同**商品越過它。
		// ⚠️ 必須用不同 product_id：後端會以 409 ProductAlreadyInShop 擋下重複商品
		//    （那是另一條獨立的行為，見下一個測試），用同一個商品重複 120 次
		//    測到的會是重複檢查而不是數量上限。
		$items = [];
		for ( $i = 0; $i < 105; $i++ ) {
			$items[] = [
				'product_id'     => $this->createSimpleProduct(
					[
						'name'          => 'gap-product-' . $i,
						'regular_price' => '100',
					]
				)->get_id(),
				'inflated_count' => 0,
				'override'       => [
					'regular_price' => null,
					'sale_price'    => null,
					'signup_fee'    => null,
				],
				'variations'     => [],
			];
		}

		$res = $this->create_shop_via_rest( [ 'items' => $items ] );

		$this->assertLessThan(
			300,
			$res->get_status(),
			'現況：後端 ItemValidator 沒有任何數量檢查，送超過前端 100 筆上限一樣建得起來'
		);
	}

	/**
	 * 🔒 現況鎖：後端**會**以 409 擋下重複的 product_id
	 *
	 * 這條不是「已知落差」，是寫上一條時意外測出來的真實行為——前端
	 * `handleBatchAdd` 有雙層 dedupe，而後端也獨立擋著（`ProductAlreadyInShop`）。
	 * 兩道防線都在，值得鎖住：日後若有人為了「效能」拿掉後端這道，
	 * 繞過前端直接打 API 就能讓同一個商品在賣場裡出現兩次。
	 *
	 * @test
	 * @group error
	 */
	public function test_backend_rejects_duplicate_product_in_same_shop(): void {
		$product_id = $this->createSimpleProduct(
			[
				'name'          => 'dup-product',
				'regular_price' => '100',
			]
		)->get_id();

		$item  = [
			'product_id'     => $product_id,
			'inflated_count' => 0,
			'override'       => [
				'regular_price' => null,
				'sale_price'    => null,
				'signup_fee'    => null,
			],
			'variations'     => [],
		];
		$res   = $this->create_shop_via_rest( [ 'items' => [ $item, $item ] ] );

		$this->assertSame(
			409,
			$res->get_status(),
			'後端必須擋下同一賣場內重複的 product_id（前端 dedupe 之外的第二道防線）'
		);
	}

	// ========== schedule-shop-availability：已知落差（驗證 / 存取）==========

	/**
	 * 🔒 現況鎖：排程欄位**沒有**後端 Domain 驗證
	 *
	 * 規格 Rule「已知落差（驗證）- 排程欄位沒有後端驗證」（TD-47）。
	 * `settings` 沒有對應的 ValueObject，結束時間早於開始時間一樣存得進去
	 * ——後果有界（賣場永不開賣），因此刻意不擋。
	 *
	 * ⚠️ 轉紅代表加了驗證，請同步更新 Rule 敘述與 TD-47。
	 *
	 * @test
	 * @group edge
	 */
	public function test_known_gap_backend_accepts_end_time_before_start_time(): void {
		$res = $this->create_shop_via_rest(
			[
				'settings' => [
					'startTime' => 1900000000000,
					'endTime'   => 1000000000000, // 早於 startTime
				],
			]
		);

		$this->assertLessThan(
			300,
			$res->get_status(),
			'現況：後端不驗證 startTime / endTime 的先後，顛倒的區間一樣存得進去'
		);
	}

	// ========== manage-partner-credentials：已知落差（建立）==========

	/**
	 * 🔒 現況鎖：建立夥伴時的密碼**沒有**後端強度驗證
	 *
	 * 規格 Rule「已知落差（建立）- 建立夥伴時的密碼沒有後端強度驗證」（TD-33）。
	 *
	 * 🔴 這是一個真實的不一致：站長可在建立頁設出 `12345678`，但那位夥伴之後
	 * 想**自己改密碼**時會被 `PartnerPassword` VO 以「需包含英文字母」擋下——
	 * 他現在用的密碼反而是系統不允許他設定的。
	 *
	 * ⚠️ 轉紅代表 CreatePartner 接上 VO 了（那正是 TD-33 的修法），
	 * 請同步：把該 Rule 從「已知落差」改寫、更新 TD-33、並檢查既有測試 fixture
	 * 是否用了弱密碼。
	 *
	 * @test
	 * @group edge
	 * @group security
	 */
	public function test_known_gap_create_partner_accepts_weak_password(): void {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) \wp_json_encode(
				[
					'name'     => 'Weak Password Partner',
					'slug'     => 'weak-pw-partner-' . (string) self::$seq,
					'password' => '12345678', // 純數字：ChangePasswordUseCase 會擋，建立時不會
				]
			)
		);

		$res = \rest_do_request( $request );

		$this->assertLessThan(
			300,
			$res->get_status(),
			'現況：建立夥伴不過 PartnerPassword VO，純數字密碼一樣建得起來（TD-33）'
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立測試用夥伴
	 *
	 * @return int term id
	 */
	private function create_partner(): int {
		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-partners' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) \wp_json_encode(
				[
					'name'     => 'Known Gap Partner',
					'slug'     => 'known-gap-partner-' . (string) self::$seq,
					'password' => 'gappass123',
				]
			)
		);
		$res  = \rest_do_request( $request );
		$body = $res->get_data();
		$id   = (int) ( $body['data']['id'] ?? 0 );
		$this->assertGreaterThan( 0, $id, '測試前置：夥伴應建立成功' );

		return $id;
	}

	/**
	 * 透過 REST 建立賣場（可覆寫任意欄位）
	 *
	 * @param array<string, mixed> $overrides 覆寫欄位
	 *
	 * @return \WP_REST_Response
	 */
	private function create_shop_via_rest( array $overrides = [] ): \WP_REST_Response {
		$payload = array_merge(
			[
				'title'           => 'Known Gap Shop',
				'slug'            => 'known-gap-shop-' . (string) self::$seq,
				'status'          => 'draft',
				'mode'            => 'page',
				'partner_term_id' => $this->partner_term_id,
				'rate'            => 10,
				'items'           => [],
				'settings'        => [],
			],
			$overrides
		);

		$request = new \WP_REST_Request( 'POST', '/power-shop/profit-shops' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( $payload ) );

		return \rest_do_request( $request );
	}
}
