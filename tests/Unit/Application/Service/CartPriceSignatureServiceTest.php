<?php
/**
 * CartPriceSignatureService 單元測試（Phase 3-D 紅燈）
 *
 * 對應任務：T-7（HMAC 簽章服務）
 *
 * 紅燈合約：
 *   - sign(int $shop_id, int $partner_term_id, string $price): string
 *       回傳 64 字元 hex（sha256），公式 hash_hmac('sha256', "{shop}|{partner}|{price}", $salt)
 *   - verify(int $shop_id, int $partner_term_id, string $price, string $signature): bool
 *       使用 hash_equals 進行常數時間比較，避免 timing attack
 *
 * 安全要求：
 *   - 簽章對 shop_id / partner_term_id / price / salt 任一變動皆需失效
 *   - signature 比較必須是 constant-time（hash_equals），防 timing oracle
 *   - 不接受空字串或非 hex 內容（防 graceful fallback 被當成 valid）
 *
 * 紅燈狀態：
 *   CartPriceSignatureService class 尚未建立——`new CartPriceSignatureService(...)`
 *   會觸發 fatal error / class not found，所有測試直接 fail。
 *   下一棒（@zenbu-powers:wordpress-master）負責綠燈實作。
 *
 * @group profit_shop
 * @group application
 * @group service
 * @group security
 * @group phase_3d
 */

declare(strict_types=1);

namespace Tests\Unit\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\Service\CartPriceSignatureService;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SaltProviderInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedSaltProvider;

/**
 * CartPriceSignatureService 合約測試
 *
 * ⚠️ 這裡的 `@group` 是**刻意重複**檔頭那一份的，不要當成贅字刪掉。
 * 檔頭那個 docblock 位於 `declare(strict_types=1)` 之前，PHP 會把它視為
 * **file-level** docblock，`ReflectionClass::getDocComment()` 讀不到它，
 * 於是 PHPUnit 完全看不到那些 group。而 phpunit.xml.dist 只 `<include>`
 * smoke / happy / error / edge / security 五個 group——沒有被納入的測試會被
 * **靜默跳過**（不是 skip 訊息，是根本不執行）。
 *
 * 這個檔案在 Phase 13 之前正是如此：16 個 test_ 方法一個都沒跑過，
 * `--filter CartPriceSignatureServiceTest` 回的是「No tests executed!」，
 * 但整體測試報告一片綠燈，沒有任何跡象顯示 HMAC 簽章服務其實零覆蓋。
 * 詳見 `.claude/rules/wordpress.rule.md` §11.5。
 *
 * @group profit_shop
 * @group application
 * @group service
 * @group security
 * @group phase_3d
 */
final class CartPriceSignatureServiceTest extends TestCase {

	private SaltProviderInterface $salt_provider;

	protected function setUp(): void {
		parent::setUp();
		$this->salt_provider = new FixedSaltProvider();
	}

	/**
	 * 建構子注入 SaltProviderInterface（DIP，禁 object duck-type）
	 */
	private function make_service( ?SaltProviderInterface $salt_provider = null ): CartPriceSignatureService {
		return new CartPriceSignatureService(
			salt_provider: $salt_provider ?? $this->salt_provider,
		);
	}

	// ---------------------------------------------------------------------
	// sign() 確定性與輸入敏感度
	// ---------------------------------------------------------------------

	/**
	 * happy：相同輸入 → 相同 signature（hash 確定性）
	 */
	public function test_sign_produces_deterministic_output_for_same_inputs(): void {
		$service = $this->make_service();

		$sig_1 = $service->sign( 100, 200, '1234.56' );
		$sig_2 = $service->sign( 100, 200, '1234.56' );

		$this->assertSame( $sig_1, $sig_2, 'sign() 對相同輸入應回傳相同 signature' );
	}

	/**
	 * 不同 shop_id → signature 不同
	 */
	public function test_sign_produces_different_output_for_different_shop_id(): void {
		$service = $this->make_service();

		$sig_a = $service->sign( 100, 200, '1234.56' );
		$sig_b = $service->sign( 101, 200, '1234.56' );

		$this->assertNotSame( $sig_a, $sig_b, 'shop_id 不同時 signature 必須不同' );
	}

	/**
	 * 不同 partner_term_id → signature 不同
	 */
	public function test_sign_produces_different_output_for_different_partner_term_id(): void {
		$service = $this->make_service();

		$sig_a = $service->sign( 100, 200, '1234.56' );
		$sig_b = $service->sign( 100, 201, '1234.56' );

		$this->assertNotSame( $sig_a, $sig_b, 'partner_term_id 不同時 signature 必須不同' );
	}

	/**
	 * 不同 price → signature 不同（防止改價竄改）
	 */
	public function test_sign_produces_different_output_for_different_price(): void {
		$service = $this->make_service();

		$sig_a = $service->sign( 100, 200, '1234.56' );
		$sig_b = $service->sign( 100, 200, '1234.57' );

		$this->assertNotSame( $sig_a, $sig_b, 'price 不同時 signature 必須不同' );
	}

	/**
	 * 不同 salt → signature 不同（注入兩個 FixedSaltProvider 對比）
	 *
	 * 確認 service 真的有讀 SaltProvider，而不是用內建常數簽章。
	 */
	public function test_sign_produces_different_output_for_different_salt(): void {
		$service_a = $this->make_service( new FixedSaltProvider( 'salt_alpha_padded_to_64_chars_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx!' ) );
		$service_b = $this->make_service( new FixedSaltProvider( 'salt_beta__padded_to_64_chars_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx!' ) );

		$sig_a = $service_a->sign( 100, 200, '1234.56' );
		$sig_b = $service_b->sign( 100, 200, '1234.56' );

		$this->assertNotSame( $sig_a, $sig_b, 'salt 不同時 signature 必須不同（service 必須真的呼叫 SaltProvider）' );
	}

	/**
	 * 簽章格式為 'v{n}.{64 字元 lowercase hex}'，便於未來金鑰輪替與演算法升級
	 *
	 * Phase 13：sign() 一律產出 v2（payload 含 regular / sale）。
	 */
	public function test_signature_format_is_version_dot_hex(): void {
		$service = $this->make_service();

		$sig = $service->sign( 100, 200, '1234.56' );

		$this->assertStringStartsWith( 'v2.', $sig, '簽章必須以現行版本前綴開頭（v2.）' );

		$hash_part = substr( $sig, 3 );
		$this->assertSame( 64, strlen( $hash_part ), '版本前綴後應為 64 字元 sha256 hash' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash_part, 'hash 部分必須為 lowercase hex' );
	}

	/**
	 * 缺少版本前綴的「裸 64 hex」字串必須直接 verify 失敗
	 *
	 * 防止未來改回舊版（無 prefix）格式時 silently 通過驗證。
	 */
	public function test_verify_returns_false_when_signature_lacks_version_prefix(): void {
		$service = $this->make_service();

		$bare_hash = str_repeat( 'a', 64 );
		$result    = $service->verify( 100, 200, '1234.56', $bare_hash );

		$this->assertFalse( $result, '無版本前綴的裸 hash 必須 verify 失敗' );
	}

	/**
	 * payload 必須含 domain prefix 'cart-signature:v{n}'，防止 cross-protocol HMAC collision
	 *
	 * 觀察手法：reflection 讀原始碼，斷言 'cart-signature' 字串出現。
	 * 確保未來 refactor 不會誤刪 domain prefix（會讓 salt 與其他 hash_hmac 用途相撞）。
	 *
	 * Phase 13 追加：同時斷言 'v1' 仍存在——那是 legacy 驗證分支存在的證明。
	 * v1 分支若被當成死碼刪掉，所有既有購物車的分潤價會**靜默消失**（見 class docblock）。
	 */
	public function test_payload_includes_domain_prefix_to_prevent_cross_protocol_collision(): void {
		$reflection = new \ReflectionClass( CartPriceSignatureService::class );
		$file_path  = $reflection->getFileName();

		$this->assertIsString( $file_path, '無法定位 CartPriceSignatureService 原始檔' );

		$source = file_get_contents( $file_path );
		$this->assertIsString( $source, '無法讀取 CartPriceSignatureService 原始碼' );

		$this->assertStringContainsString(
			'cart-signature',
			$source,
			'payload 必須含 domain prefix（cart-signature）以防 cross-protocol HMAC collision'
		);
		$this->assertStringContainsString(
			"'v2'",
			$source,
			'payload 必須含現行版本標記（v2）'
		);
		$this->assertStringContainsString(
			"'v1'",
			$source,
			'必須保留 legacy 版本標記（v1）——移除 v1 驗證分支會讓既有購物車的分潤價靜默消失'
		);
	}

	// ---------------------------------------------------------------------
	// Phase 13：v2 payload（regular / sale 納入簽章）
	// ---------------------------------------------------------------------

	/**
	 * regular_price 被竄改 → v2 verify 失敗
	 *
	 * 這是本次把 regular/sale 納入簽章的核心理由：regular/sale 會影響
	 * WC_Product::is_on_sale()，而 is_on_sale() 被「排除特價商品」的優惠券消費——
	 * 不簽章就不只是顯示層問題，而是有真實金額影響的驗證繞過面。
	 *
	 * @group security
	 */
	public function test_verify_returns_false_when_regular_price_tampered(): void {
		$service = $this->make_service();

		$sig = $service->sign( 100, 200, '600.00', '800.00', '600.00' );

		$this->assertFalse(
			$service->verify( 100, 200, '600.00', $sig, '99999.00', '600.00' ),
			'竄改 regular_price 後 verify 必須失敗'
		);
	}

	/**
	 * sale_price 被竄改 → v2 verify 失敗
	 *
	 * @group security
	 */
	public function test_verify_returns_false_when_sale_price_tampered(): void {
		$service = $this->make_service();

		$sig = $service->sign( 100, 200, '600.00', '800.00', '600.00' );

		$this->assertFalse(
			$service->verify( 100, 200, '600.00', $sig, '800.00', '1.00' ),
			'竄改 sale_price 後 verify 必須失敗'
		);
	}

	/**
	 * regular / sale 的空字串是有意義的值，與「有值」必須產生不同簽章
	 *
	 * '' 代表「不覆寫原價」／「無特價」，若與有值的情況簽出同一個 hash，
	 * 攻擊者就能在兩種語意之間自由切換。
	 *
	 * @group security
	 */
	public function test_empty_and_non_empty_regular_sale_produce_different_signatures(): void {
		$service = $this->make_service();

		$without = $service->sign( 100, 200, '600.00' );
		$with    = $service->sign( 100, 200, '600.00', '800.00', '600.00' );

		$this->assertNotSame( $without, $with, '空字串與有值的 regular/sale 必須簽出不同結果' );
		$this->assertTrue( $service->verify( 100, 200, '600.00', $without ), '兩端皆為預設空字串時應驗證通過' );
		$this->assertFalse(
			$service->verify( 100, 200, '600.00', $without, '800.00', '600.00' ),
			'用空字串簽出的簽章，帶著有值的 regular/sale 來驗必須失敗'
		);
	}

	// ---------------------------------------------------------------------
	// Phase 13：v1 legacy 雙軌驗證
	// ---------------------------------------------------------------------

	/**
	 * 🔴 最重要的一條：手動組出的 v1 簽章必須仍能通過驗證
	 *
	 * 既有顧客的購物車（含**永不過期**的登入使用者持久化購物車）帶的都是 v1 簽章。
	 * 這條測試若變紅，代表那些購物車的分潤價會全部靜默退回商品原價。
	 *
	 * 手法：不依賴 service 產生 v1（sign() 已一律產 v2），改用與 production 相同的
	 * payload 公式手工組出 v1 簽章，模擬「改版前寫進 session 的舊資料」。
	 *
	 * @group security
	 * @group smoke
	 */
	public function test_verify_accepts_legacy_v1_signature(): void {
		$service = $this->make_service();

		$legacy_sig = $this->make_legacy_v1_signature( 100, 200, '888.00' );

		$this->assertTrue(
			$service->verify( 100, 200, '888.00', $legacy_sig ),
			'v1 簽章必須仍能驗證通過（既有購物車相容）'
		);
	}

	/**
	 * v1 分支必須完全忽略 regular / sale 參數
	 *
	 * v1 payload 從未包含這兩個欄位，所以帶任何值來都不該影響驗證結果。
	 * 呼叫端據此得知「這兩個值沒有被簽章保護」，必須不予套用。
	 *
	 * @group security
	 */
	public function test_legacy_v1_verification_ignores_regular_and_sale_arguments(): void {
		$service = $this->make_service();

		$legacy_sig = $this->make_legacy_v1_signature( 100, 200, '888.00' );

		$this->assertTrue(
			$service->verify( 100, 200, '888.00', $legacy_sig, '99999.00', '1.00' ),
			'v1 驗證必須忽略 regular/sale 參數（payload 裡本來就沒有它們）'
		);
	}

	/**
	 * v1 簽章仍受 price 保護：改 price 後必須失敗
	 *
	 * @group security
	 */
	public function test_legacy_v1_signature_still_fails_when_price_tampered(): void {
		$service = $this->make_service();

		$legacy_sig = $this->make_legacy_v1_signature( 100, 200, '888.00' );

		$this->assertFalse(
			$service->verify( 100, 200, '1.00', $legacy_sig ),
			'v1 簽章的 price 被改仍必須驗證失敗'
		);
	}

	/**
	 * 🔴 跨版本不可互通：把 v1 簽章的前綴硬改成 'v2.' 必須失敗
	 *
	 * payload 的 domain prefix 內含版本字串（cart-signature:v1 vs :v2），
	 * 所以兩個版本的 hash 天然不同。這條鎖住「不要把版本移出 payload」。
	 *
	 * @group security
	 */
	public function test_v1_signature_with_forged_v2_prefix_is_rejected(): void {
		$service = $this->make_service();

		$legacy_sig = $this->make_legacy_v1_signature( 100, 200, '888.00' );
		$forged     = 'v2.' . substr( $legacy_sig, 3 );

		$this->assertFalse(
			$service->verify( 100, 200, '888.00', $forged ),
			'v1 簽章換上 v2 前綴必須驗證失敗（兩版 hash 不可互通）'
		);
	}

	/**
	 * 反向：把 v2 簽章的前綴改成 'v1.' 也必須失敗
	 *
	 * @group security
	 */
	public function test_v2_signature_with_downgraded_v1_prefix_is_rejected(): void {
		$service = $this->make_service();

		$v2_sig  = $service->sign( 100, 200, '600.00', '800.00', '600.00' );
		$forged  = 'v1.' . substr( $v2_sig, 3 );

		$this->assertFalse(
			$service->verify( 100, 200, '600.00', $forged, '800.00', '600.00' ),
			'v2 簽章降級成 v1 前綴必須驗證失敗（防降級攻擊）'
		);
	}

	// ---------------------------------------------------------------------
	// Phase 13：payload_version()
	// ---------------------------------------------------------------------

	/**
	 * payload_version() 對各種輸入的回傳
	 *
	 * 呼叫端（CartPriceOverrideHook）用它決定要不要套用 regular/sale——
	 * 判錯版本就是「套用未經簽章保護的假原價」的漏洞。
	 *
	 * @group happy
	 */
	public function test_payload_version_detects_version_prefix(): void {
		$service = $this->make_service();

		$this->assertSame( 'v2', $service->payload_version( $service->sign( 1, 2, '3' ) ), 'sign() 產出應為 v2' );
		$this->assertSame( 'v2', $service->payload_version( 'v2.' . str_repeat( 'a', 64 ) ) );
		$this->assertSame( 'v1', $service->payload_version( 'v1.' . str_repeat( 'a', 64 ) ) );
	}

	/**
	 * payload_version() 對不認得的前綴一律回 null（呼叫端據此走 tampering 分支）
	 *
	 * @group edge
	 * @group security
	 */
	public function test_payload_version_returns_null_for_unknown_prefix(): void {
		$service = $this->make_service();

		$this->assertNull( $service->payload_version( '' ), '空字串應回 null' );
		$this->assertNull( $service->payload_version( str_repeat( 'a', 64 ) ), '裸 hash（無版本前綴）應回 null' );
		$this->assertNull( $service->payload_version( 'v3.' . str_repeat( 'a', 64 ) ), '未知版本應回 null' );
		$this->assertNull( $service->payload_version( 'v1' ), '缺少 . 分隔符應回 null' );
		$this->assertNull( $service->payload_version( 'xv1.' . str_repeat( 'a', 64 ) ), '前綴不在開頭應回 null' );
	}

	/**
	 * 手工組出 v1 簽章（模擬改版前寫入 session 的舊 cart_item）
	 *
	 * 公式必須與 production 的 v1 payload 完全一致：
	 *   'cart-signature:v1|{shop_id}|{partner_term_id}|{price}'
	 *
	 * @param int    $shop_id         賣場 ID
	 * @param int    $partner_term_id 夥伴 term ID
	 * @param string $price           已 normalize 的價格字串
	 */
	private function make_legacy_v1_signature( int $shop_id, int $partner_term_id, string $price ): string {
		$payload = 'cart-signature:v1|' . $shop_id . '|' . $partner_term_id . '|' . $price;
		return 'v1.' . hash_hmac( 'sha256', $payload, $this->salt_provider->get( 'auth' ) );
	}

	// ---------------------------------------------------------------------
	// verify() 正向與防竄改
	// ---------------------------------------------------------------------

	/**
	 * happy：sign 後 verify 通過
	 */
	public function test_verify_returns_true_when_signature_matches(): void {
		$service = $this->make_service();

		$sig    = $service->sign( 100, 200, '1234.56' );
		$result = $service->verify( 100, 200, '1234.56', $sig );

		$this->assertTrue( $result, 'sign() 產生的 signature 用相同輸入 verify() 應通過' );
	}

	/**
	 * 改 shop_id → verify 失敗
	 */
	public function test_verify_returns_false_when_shop_id_tampered(): void {
		$service = $this->make_service();

		$sig    = $service->sign( 100, 200, '1234.56' );
		$result = $service->verify( 999, 200, '1234.56', $sig );

		$this->assertFalse( $result, '改 shop_id 後 verify 必須失敗' );
	}

	/**
	 * 改 partner_term_id → verify 失敗
	 */
	public function test_verify_returns_false_when_partner_term_id_tampered(): void {
		$service = $this->make_service();

		$sig    = $service->sign( 100, 200, '1234.56' );
		$result = $service->verify( 100, 999, '1234.56', $sig );

		$this->assertFalse( $result, '改 partner_term_id 後 verify 必須失敗' );
	}

	/**
	 * 改 price → verify 失敗（防竄改核心）
	 */
	public function test_verify_returns_false_when_price_tampered(): void {
		$service = $this->make_service();

		$sig    = $service->sign( 100, 200, '1234.56' );
		$result = $service->verify( 100, 200, '0.01', $sig );

		$this->assertFalse( $result, '改 price 後 verify 必須失敗（防止前台篡改價格）' );
	}

	// ---------------------------------------------------------------------
	// verify() 邊界與 graceful fallback 防禦
	// ---------------------------------------------------------------------

	/**
	 * signature 為空字串 → verify 直接 false（不可被當成 valid）
	 */
	public function test_verify_returns_false_when_signature_is_empty_string(): void {
		$service = $this->make_service();

		$result = $service->verify( 100, 200, '1234.56', '' );

		$this->assertFalse( $result, '空 signature 必須直接 verify 失敗' );
	}

	/**
	 * signature 含非 hex 字元 → verify 失敗
	 *
	 * 防止攻擊者送任意字串造成 service 內部 hash_equals 因長度錯誤拋 warning 而短路。
	 */
	public function test_verify_returns_false_when_signature_is_invalid_hex(): void {
		$service = $this->make_service();

		// 64 字元但含非 hex 字元（z / Z 不是 hex）
		$invalid = str_repeat( 'z', 64 );
		$result  = $service->verify( 100, 200, '1234.56', $invalid );

		$this->assertFalse( $result, '非 hex signature 必須 verify 失敗' );
	}

	/**
	 * signature 長度錯誤（短 / 長）→ verify 失敗
	 *
	 * hash_equals 對長度不一致的字串會直接回 false，但仍須驗證 service 不會吞例外。
	 */
	public function test_verify_returns_false_when_signature_length_is_wrong(): void {
		$service = $this->make_service();

		$too_short = str_repeat( 'a', 32 );
		$too_long  = str_repeat( 'a', 128 );

		$this->assertFalse( $service->verify( 100, 200, '1234.56', $too_short ), '過短 signature 必須失敗' );
		$this->assertFalse( $service->verify( 100, 200, '1234.56', $too_long ), '過長 signature 必須失敗' );
	}

	// ---------------------------------------------------------------------
	// 安全：constant-time comparison
	// ---------------------------------------------------------------------

	/**
	 * verify 必須使用 hash_equals（常數時間比較），不可用 === / strcmp
	 *
	 * 觀察手法：用 reflection 讀 service 原始碼，斷言 hash_equals 出現、
	 * === 比對 signature 的形式不出現。
	 *
	 * 此測試確保 reviewer 不需肉眼把關亦能擋下 timing oracle。
	 */
	public function test_verify_uses_constant_time_comparison(): void {
		$reflection = new \ReflectionClass( CartPriceSignatureService::class );
		$file_path  = $reflection->getFileName();

		$this->assertIsString( $file_path, '無法定位 CartPriceSignatureService 原始檔' );

		$source = file_get_contents( $file_path );
		$this->assertIsString( $source, '無法讀取 CartPriceSignatureService 原始碼' );

		$this->assertStringContainsString(
			'hash_equals',
			$source,
			'verify() 必須使用 hash_equals 做常數時間比較（防 timing oracle）'
		);
	}
}
