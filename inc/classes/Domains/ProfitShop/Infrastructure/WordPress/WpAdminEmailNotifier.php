<?php
/**
 * WP Admin Email 通知適配器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

use J7\PowerShop\Domains\ProfitShop\Application\Service\EmailNotifierInterface;

/**
 * 使用 wp_mail 寄送通知到 admin_email 的 production 適配器
 *
 * 注意：呼叫端（LoginRateLimiter）必須 warn-and-swallow——
 * 即使本適配器拋例外，登入流程不可被阻擋。
 */
final class WpAdminEmailNotifier implements EmailNotifierInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 寄送通知
	 *
	 * @param string $to      收件人；若為空則使用 admin_email
	 * @param string $subject 主旨
	 * @param string $body    內文
	 *
	 * @return void
	 */
	public function notify( string $to, string $subject, string $body ): void {
		$recipient = '' === $to ? (string) \get_option( 'admin_email' ) : $to;
		if ( '' === $recipient ) {
			return;
		}

		\wp_mail( $recipient, $subject, $body );
	}
}
