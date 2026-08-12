<?php
/**
 * Email 通知抽象介面
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Email 通知抽象（Port）
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.3、§6.4
 *
 * 用於 brute-force 防禦在第 5 次失敗時通知 admin。
 * Production 由 WpAdminEmailNotifier 包 wp_mail；Test 由 SpyEmailNotifier 紀錄呼叫。
 *
 * 為對齊測試 spy（Tests\Support\SpyEmailNotifier::notify）介面，採 notify(to, subject, body)。
 */
interface EmailNotifierInterface {

	/**
	 * 寄送通知
	 *
	 * @param string $to      收件人 email
	 * @param string $subject 主旨
	 * @param string $body    內文
	 *
	 * @return void
	 *
	 * @throws \Throwable 寄送失敗時可能拋例外（呼叫端應 warn-and-swallow）
	 */
	public function notify( string $to, string $subject, string $body ): void;
}
