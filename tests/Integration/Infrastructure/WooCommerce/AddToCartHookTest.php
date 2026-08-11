<?php
/**
 * AddToCartHook 的輸入驗證與閘門
 *
 * 對應規格：specs/features/profit-shop/attribute-cart-item-to-shop.feature
 *   Rule: 前置（來源）- profit_shop_id 只從 $_GET 與 $_POST 讀取
 *   Rule: 前置（格式）- profit_shop_id 必須是正整數字串
 *   Rule: 前置（短路）- 上游已帶入 profit_shop_id 時不重覆注入
 *   Rule: 閘門①（存在）/ 閘門②（狀態）/ 閘門③（期間）/ 閘門④（商品）
 *
 * ── 為什麼這個檔案在 2026-08-11 之前不存在是個問題 ──────────────
 *
 * 對抗性稽核（126 agent）發現：`AddToCartHook` **全專案沒有任何測試檔**。
 * 既有的 `CartPriceOverrideHookTest` 幾乎都走 `add_to_cart_via_shop()`，
 * 那個 helper 把 profit_shop_id 直接塞進 `$cart_item_data` 第 5 參數——
 * 正好命中 `inject_profit_shop_id()` 開頭的 short-circuit，
 * **`extract_shop_id_from_request()` 一行都不會執行**。
 *
 * 後果實測（本檔撰寫前）：
 *   - `tests/` 內 grep `_COOKIE` → **0 命中**（排除 Cookie 是防 XSS 竄改分潤歸因的守門）
 *   - 把 `$_GET`/`$_POST` 改回 `$_REQUEST`，沒有任何測試會轉紅
 *   - `ctype_digit` 格式守門（含拒絕前導零）同樣零覆蓋
 *
 * 本檔一律走**攻擊者實際能操作的那條路徑**（設定 $_GET / $_POST 後呼叫 filter），
 * 讓四道閘門完整跑過一次。
 *
 * @group profit_shop
 * @group infrastructure
 * @group woocommerce
 * @group cart
 */

declare( strict_types=1 );

namespace Tests\Integration\Infrastructure\WooCommerce;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\OverrideItem;
use J7\PowerShop\Domains\ProfitShop\Domain\Entity\ProfitShop;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\InflatedCount;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PriceOverride;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ProfitRate;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WooCommerce\AddToCartHook;
use Tests\Integration\TestCase;

/**
 * AddToCartHook 輸入驗證測試
 */
final class AddToCartHookTest extends TestCase {

	private CptProfitShopRepository $repo;

	private AddToCartHook $hook;

	private int $partner_term_id = 0;

	private int $product_id = 0;

	private int $shop_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->repo = new CptProfitShopRepository();
		$this->hook = new AddToCartHook( $this->repo );

		$term = \wp_insert_term( 'ATC Partner ' . uniqid(), 'profit_partner' );
		$this->partner_term_id = is_array( $term ) ? (int) $term['term_id'] : 0;

		$this->product_id = $this->createSimpleProduct( [ 'regular_price' => '1000' ] )->get_id();
		$this->shop_id    = $this->create_shop( [ $this->product_id ] );

		$this->clear_request();
	}

	public function tear_down(): void {
		$this->clear_request();
		parent::tear_down();
	}

	// ========== 前置（來源）==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_shop_id_is_read_from_get(): void {
		$_GET['profit_shop_id'] = (string) $this->shop_id;

		$this->assertSame(
			$this->shop_id,
			$this->inject()['profit_shop_id'] ?? null,
			'GET 帶入 profit_shop_id 必須成立歸屬'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_shop_id_is_read_from_post(): void {
		$_POST['profit_shop_id'] = (string) $this->shop_id;

		$this->assertSame(
			$this->shop_id,
			$this->inject()['profit_shop_id'] ?? null,
			'POST 帶入 profit_shop_id 必須成立歸屬（賣場前台的商品表單走這條）'
		);
	}

	/**
	 * 🔴 只有 Cookie 帶值時不得成立歸屬
	 *
	 * 這是防 XSS 竄改分潤歸因的守門：攻擊者若能在受害者瀏覽器植入一個
	 * `profit_shop_id` cookie，之後那位顧客在**任何**商店頁面的加購都會被
	 * 記到攻擊者的賣場名下——顧客與站長都不會看到任何異常。
	 *
	 * 實作用 `$_GET` / `$_POST` 逐一檢查而非 `$_REQUEST`（後者含 `$_COOKIE`）。
	 * 這條測試是唯一會對「改回 $_REQUEST」轉紅的東西。
	 *
	 * @test
	 * @group security
	 */
	public function test_cookie_alone_never_attributes_to_shop(): void {
		$_COOKIE['profit_shop_id'] = (string) $this->shop_id;

		$this->assertArrayNotHasKey(
			'profit_shop_id',
			$this->inject(),
			'只有 Cookie 帶 profit_shop_id 時絕不可成立歸屬（防 XSS 竄改分潤歸因）'
		);
	}

	/**
	 * 🔴 Cookie 不得覆寫、也不得補位
	 *
	 * 縱深防禦：即使 $_GET 已有合法值，Cookie 也不該參與任何決策。
	 * 這條與上一條分開，是為了讓「$_REQUEST 的合併順序剛好讓某些情境仍然通過」
	 * 這種半吊子退化也會被抓到。
	 *
	 * @test
	 * @group security
	 */
	public function test_cookie_does_not_override_legitimate_get(): void {
		$other_shop = $this->create_shop( [ $this->product_id ] );

		$_GET['profit_shop_id']    = (string) $this->shop_id;
		$_COOKIE['profit_shop_id'] = (string) $other_shop;

		$this->assertSame(
			$this->shop_id,
			$this->inject()['profit_shop_id'] ?? null,
			'Cookie 不得覆寫 GET 帶入的合法賣場'
		);
	}

	/**
	 * GET 優先於 POST（兩者同時存在時的取值順序）
	 *
	 * @test
	 * @group edge
	 */
	public function test_get_takes_precedence_over_post(): void {
		$other_shop = $this->create_shop( [ $this->product_id ] );

		$_GET['profit_shop_id']  = (string) $this->shop_id;
		$_POST['profit_shop_id'] = (string) $other_shop;

		$this->assertSame(
			$this->shop_id,
			$this->inject()['profit_shop_id'] ?? null,
			'實作依序檢查 $_GET → $_POST，GET 命中即返回'
		);
	}

	// ========== 前置（格式）==========

	/**
	 * 🔴 格式非法的字串一律視為未提供——**即使它轉型後指向一個真實存在的賣場**
	 *
	 * ⚠️ 這個測試的第一版是**假綠燈**，值得記下來：
	 * 原本用的是 `'abc'` / `'19.32'` / `'007'` 這類憑空的字串。實測把
	 * `ctype_digit` 守門整段刪掉之後，**25 條測試依然全綠**——因為那些字串
	 * `(int)` 轉型後得到的是 0 或某個不存在的 ID，會被**閘門①（賣場存在）**
	 * 順手擋掉。測試通過的理由從頭到尾就不是「格式守門有效」。
	 *
	 * 正解是讓非法字串轉型後**剛好等於真實賣場 ID**，這樣閘門①～④全部會放行，
	 * 唯一擋得住它的就只剩格式守門本身：
	 *
	 *   '+1932'   → (int) 1932  ← 真實賣場
	 *   ' 1932'   → (int) 1932
	 *   '01932'   → (int) 1932
	 *   '1932.9'  → (int) 1932
	 *   '1932abc' → (int) 1932
	 *
	 * 為什麼這件事重要：攻擊面不是「亂打一個 ID」，而是「用格式變體繞過**未來**
	 * 可能加上的比對邏輯」。今天守門只是 `> 0`，明天若有人加上白名單比對，
	 * 一個能通過格式檢查的 `' 1932'` 就會和 `'1932'` 被當成兩個不同的字串。
	 *
	 * @test
	 * @group security
	 * @dataProvider provide_malformed_shop_ids
	 */
	public function test_malformed_shop_id_is_rejected( string $suffix_or_prefix, string $why ): void {
		// 由真實 shop_id 組出「轉型後仍等於它」的非法字串
		$raw = str_replace( '{id}', (string) $this->shop_id, $suffix_or_prefix );

		$this->assertSame(
			$this->shop_id,
			(int) $raw,
			sprintf( '測試前提："%s" 轉型後必須等於真實賣場 ID，否則測到的是閘門①而非格式守門', $raw )
		);

		$_GET['profit_shop_id'] = $raw;

		$this->assertArrayNotHasKey(
			'profit_shop_id',
			$this->inject(),
			sprintf( '"%s" 必須被格式守門拒絕：%s', $raw, $why )
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_malformed_shop_ids(): array {
		return [
			'正號前綴'     => [ '+{id}', 'ctype_digit 對 + 回 false' ],
			'小數尾'       => [ '{id}.9', '(int) 會截斷小數' ],
			'數字後接英文' => [ '{id}abc', '(int) 會忽略尾部非數字' ],
		];
	}

	/**
	 * 🔒 現況鎖：前後空白與前導零**會**被接受
	 *
	 * 寫測試時預期這兩種會被擋，實測不會，成因各不相同：
	 *   ' 1932' → `sanitize_text_field()` 先 trim 掉空白，進到 ctype_digit 時已是 '1932'
	 *   '01932' → `ctype_digit('01932')` 本來就是 **true**（每個字元都是數字）
	 *
	 * 記錄現況而非改實作，因為**沒有安全影響**：兩者 `(int)` 之後都等於同一個
	 * 真實賣場 ID，接著四道閘門（存在 / publish / 販售期間 / 商品屬於該賣場）
	 * 一模一樣地跑一次，結果與直接送 '1932' 完全相同。它不是繞過，是同義寫法。
	 *
	 * ⚠️ 這條轉紅代表有人收緊了格式守門（例如改用 `preg_match('/^[1-9][0-9]*$/')`）。
	 * 那是好事，但請一併確認：`ProfitShopShortcode` 對 id 是**額外拒絕前導零**的
	 *（見該檔紀律），兩處若要一致就該一起改，不要只改一邊。
	 *
	 * @test
	 * @group edge
	 */
	public function test_known_gap_whitespace_and_leading_zero_are_accepted(): void {
		foreach ( [ ' ' . $this->shop_id, $this->shop_id . ' ', '0' . $this->shop_id ] as $raw ) {
			$this->clear_request();
			$_GET['profit_shop_id'] = (string) $raw;

			$this->assertSame(
				$this->shop_id,
				$this->inject()['profit_shop_id'] ?? null,
				sprintf( '現況："%s" 會被接受並解析到同一個賣場', $raw )
			);
		}
	}

	/**
	 * 值不成立時一律視為未提供（這批本來就會被閘門①擋，但仍該在格式層先擋掉）
	 *
	 * @test
	 * @group edge
	 * @dataProvider provide_non_positive_shop_ids
	 */
	public function test_non_positive_shop_id_is_rejected( string $raw ): void {
		$_GET['profit_shop_id'] = $raw;

		$this->assertArrayNotHasKey( 'profit_shop_id', $this->inject(), sprintf( '"%s" 必須被拒絕', $raw ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_non_positive_shop_ids(): array {
		return [
			'空字串' => [ '' ],
			'純英文' => [ 'abc' ],
			'負數'   => [ '-1932' ],
			'零'     => [ '0' ],
		];
	}

	/**
	 * 陣列形式視同未提供（不得讓 sanitize_text_field 收到陣列而拋錯）
	 *
	 * @test
	 * @group edge
	 */
	public function test_array_shop_id_is_rejected(): void {
		$_GET['profit_shop_id'] = [ (string) $this->shop_id ];

		$this->assertArrayNotHasKey(
			'profit_shop_id',
			$this->inject(),
			'?profit_shop_id[]=1932 這種陣列形式必須被視為未提供'
		);
	}

	// ========== 前置（短路）==========

	/**
	 * 🔴 上游已帶入時，請求參數不得覆寫
	 *
	 * `?wc-ajax=add_to_cart` 之外的通道（例如其他外掛、或未來的自訂 endpoint）
	 * 可能已經在 `$cart_item_data` 帶了經過自己驗證的 profit_shop_id。
	 * 此時若還去讀請求參數，等於讓攻擊者用 query string 蓋掉上游的決定。
	 *
	 * @test
	 * @group security
	 */
	public function test_upstream_value_is_not_overwritten_by_request(): void {
		$upstream_shop = $this->create_shop( [ $this->product_id ] );

		$_GET['profit_shop_id'] = (string) $this->shop_id;

		$result = $this->inject( [ 'profit_shop_id' => $upstream_shop ] );

		$this->assertSame(
			$upstream_shop,
			$result['profit_shop_id'],
			'上游已帶入時必須尊重上游的值，不得被請求參數覆寫'
		);
	}

	/**
	 * 上游帶入時，即使請求參數完全沒有值也不受影響
	 *
	 * @test
	 * @group edge
	 */
	public function test_upstream_value_survives_when_request_is_empty(): void {
		$result = $this->inject( [ 'profit_shop_id' => $this->shop_id ] );

		$this->assertSame( $this->shop_id, $result['profit_shop_id'] );
	}

	// ========== 四道閘門 ==========

	/**
	 * 閘門①：賣場不存在
	 *
	 * @test
	 * @group security
	 */
	public function test_nonexistent_shop_is_rejected(): void {
		$_GET['profit_shop_id'] = '999999';

		$this->assertArrayNotHasKey( 'profit_shop_id', $this->inject() );
	}

	/**
	 * 閘門②：賣場非 publish
	 *
	 * @test
	 * @group security
	 */
	public function test_draft_shop_is_rejected(): void {
		$draft_id               = $this->create_shop( [ $this->product_id ], 'draft' );
		$_GET['profit_shop_id'] = (string) $draft_id;

		$this->assertArrayNotHasKey(
			'profit_shop_id',
			$this->inject(),
			'草稿賣場的預覽價不得洩漏到顧客的購物車'
		);
	}

	/**
	 * 閘門③：販售期間已結束
	 *
	 * @test
	 * @group security
	 */
	public function test_closed_shop_is_rejected(): void {
		$closed_id = $this->create_shop(
			[ $this->product_id ],
			'publish',
			[
				'startTime' => ( time() - 86400 * 3 ) * 1000,
				'endTime'   => ( time() - 86400 ) * 1000,
			]
		);
		$_GET['profit_shop_id'] = (string) $closed_id;

		$this->assertArrayNotHasKey(
			'profit_shop_id',
			$this->inject(),
			'已結束販售的賣場必須擋下手工 POST（前台 Modal 不印只是 UI 防呆）'
		);
	}

	/**
	 * 閘門③：尚未開賣
	 *
	 * @test
	 * @group security
	 */
	public function test_not_yet_open_shop_is_rejected(): void {
		$future_id = $this->create_shop(
			[ $this->product_id ],
			'publish',
			[ 'startTime' => ( time() + 86400 ) * 1000 ]
		);
		$_GET['profit_shop_id'] = (string) $future_id;

		$this->assertArrayNotHasKey( 'profit_shop_id', $this->inject() );
	}

	/**
	 * 閘門③：未設定時間 = 不限制（絕大多數賣場）
	 *
	 * @test
	 * @group happy
	 */
	public function test_shop_without_schedule_is_always_open(): void {
		$_GET['profit_shop_id'] = (string) $this->shop_id;

		$this->assertSame( $this->shop_id, $this->inject()['profit_shop_id'] ?? null );
	}

	/**
	 * 閘門④：商品不屬於該賣場
	 *
	 * @test
	 * @group security
	 */
	public function test_product_not_in_shop_is_rejected(): void {
		$outsider = $this->createSimpleProduct( [ 'regular_price' => '500' ] )->get_id();

		$_GET['profit_shop_id'] = (string) $this->shop_id;

		$this->assertArrayNotHasKey(
			'profit_shop_id',
			$this->inject( [], $outsider ),
			'拿正版賣場套未授權商品必須被擋下'
		);
	}

	/**
	 * 閘門④：變體商品以**父商品 ID** 比對
	 *
	 * `?wc-ajax=add_to_cart` 送的是變體 ID，但 WC_AJAX 會先換算成父商品才觸發
	 * 本 filter，所以這裡收到的 $product_id 是父商品。
	 *
	 * @test
	 * @group edge
	 */
	public function test_gate_compares_parent_product_id(): void {
		$_GET['profit_shop_id'] = (string) $this->shop_id;

		// 第三參數 variation_id 非 0，但比對依據仍是 product_id
		$this->assertSame(
			$this->shop_id,
			$this->inject( [], $this->product_id, 4321 )['profit_shop_id'] ?? null
		);
	}

	// ========== Helpers ==========

	/**
	 * 呼叫被測的 filter
	 *
	 * @param array<string, mixed> $cart_item_data 上游帶入的資料
	 * @param int|null             $product_id     商品 ID（預設為賣場內的商品）
	 * @param int                  $variation_id   變體 ID
	 *
	 * @return array<string, mixed>
	 */
	private function inject( array $cart_item_data = [], ?int $product_id = null, int $variation_id = 0 ): array {
		return $this->hook->inject_profit_shop_id(
			$cart_item_data,
			$product_id ?? $this->product_id,
			$variation_id
		);
	}

	/**
	 * 建立賣場
	 *
	 * @param int[]                $product_ids 商品 ID 清單
	 * @param string               $status      狀態
	 * @param array<string, mixed> $settings    前台設定
	 */
	private function create_shop( array $product_ids, string $status = 'publish', array $settings = [] ): int {
		$items = array_map(
			fn( int $pid ) => new OverrideItem(
				product_id: $pid,
				override: new PriceOverride( '800', '600', null ),
				inflated_count: new InflatedCount( 0 )
			),
			$product_ids
		);

		return $this->repo->save(
			new ProfitShop(
				id: 0,
				title: 'ATC Shop ' . uniqid(),
				slug: 'atc-shop-' . uniqid(),
				status: $status,
				mode: ShopMode::PAGE,
				partner_term_id: $this->partner_term_id,
				rate: new ProfitRate( 10 ),
				items: $items,
				settings: $settings
			)
		);
	}

	/** 清乾淨三個超全域，避免測試之間互相污染 */
	private function clear_request(): void {
		unset( $_GET['profit_shop_id'], $_POST['profit_shop_id'], $_COOKIE['profit_shop_id'] );
	}
}
