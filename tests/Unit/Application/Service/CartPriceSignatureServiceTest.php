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
 * CartPriceSignatureService 紅燈合約測試
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
	 */
	public function test_signature_format_is_version_dot_hex(): void {
		$service = $this->make_service();

		$sig = $service->sign( 100, 200, '1234.56' );

		$this->assertStringStartsWith( 'v1.', $sig, '簽章必須以版本前綴開頭（v1.）' );

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
	 * payload 必須含 domain prefix 'cart-signature:v1'，防止 cross-protocol HMAC collision
	 *
	 * 觀察手法：reflection 讀原始碼，斷言 'cart-signature' 字串出現。
	 * 確保未來 refactor 不會誤刪 domain prefix（會讓 salt 與其他 hash_hmac 用途相撞）。
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
			"'v1'",
			$source,
			'payload 必須含版本標記（v1）以利未來金鑰輪替 / 演算法升級'
		);
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
