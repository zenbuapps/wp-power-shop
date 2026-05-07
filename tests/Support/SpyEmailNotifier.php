<?php
/**
 * Email Notifier Spy（測試替身）
 *
 * 用於驗證 LoginRateLimiter 在第 5 次失敗時會發 admin email，
 * 同時可以模擬「email 發送失敗」（warn-and-swallow）情境。
 */

declare(strict_types=1);

namespace Tests\Support;

/**
 * 紀錄寄送呼叫的 email spy
 *
 * 預期 production 介面：notify( string $to, string $subject, string $body ): void
 *
 * 行為：
 *   - send_should_throw = false（預設）：紀錄一筆訊息，不拋例外
 *   - send_should_throw = true：紀錄一筆訊息（送達失敗前已嘗試），並拋 \RuntimeException，
 *     用以測試 LoginRateLimiter 是否 warn-and-swallow（不應 propagate 錯誤）
 */
final class SpyEmailNotifier {

	/**
	 * 已寄送（或嘗試寄送）的 message log
	 *
	 * @var array<int, array{to: string, subject: string, body: string}>
	 */
	private array $sent = [];

	/**
	 * 是否在 send 時拋例外（模擬寄送失敗）
	 *
	 * @var bool
	 */
	private bool $send_should_throw = false;

	/**
	 * 寄送（spy 行為）
	 *
	 * @param string $to      收件人
	 * @param string $subject 主旨
	 * @param string $body    內容
	 *
	 * @throws \RuntimeException 當 send_should_throw=true 時
	 */
	public function notify( string $to, string $subject, string $body ): void {
		$this->sent[] = [
			'to'      => $to,
			'subject' => $subject,
			'body'    => $body,
		];

		if ( $this->send_should_throw ) {
			throw new \RuntimeException( 'simulated email send failure' );
		}
	}

	/**
	 * 設定下一次 notify 是否拋例外
	 *
	 * @param bool $should_throw 是否拋
	 */
	public function set_send_should_throw( bool $should_throw ): void {
		$this->send_should_throw = $should_throw;
	}

	/**
	 * 取得所有寄送紀錄
	 *
	 * @return array<int, array{to: string, subject: string, body: string}>
	 */
	public function sent(): array {
		return $this->sent;
	}

	/**
	 * 寄送次數
	 */
	public function count(): int {
		return count( $this->sent );
	}

	/**
	 * 重置（測試之間清除）
	 */
	public function reset(): void {
		$this->sent              = [];
		$this->send_should_throw = false;
	}
}
