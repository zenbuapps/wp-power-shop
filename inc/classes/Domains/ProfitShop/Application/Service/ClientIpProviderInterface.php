<?php
/**
 * Client IP 提供者抽象（Application Port）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

/**
 * Client IP 提供者抽象（DIP）
 *
 * Production：WpClientIpProvider 從 $_SERVER 取得（含 reverse proxy 處理）。
 * Test：可注入 fake 提供固定 IP。
 *
 * 部署提醒（Phase 5-A.1 自決 Q1=B）：
 *   本 Port 的 production 實作預設讀取 X-Forwarded-For 第一段非保留 IP。
 *   站台部署在 Cloudflare / Nginx 等反向代理後方時，必須確認代理層
 *   strip 來源端的 spoof header，否則 IP 可被偽造繞過 rate-limit。
 */
interface ClientIpProviderInterface {

	/**
	 * 取得當前請求的 client IP
	 *
	 * 取不到（CLI / 測試環境 / header 全空）回 null；
	 * fail-open 行為由 caller 決定（PageRateLimitService 收到 null 直接放行）。
	 *
	 * @return string|null 已驗證合法的 IP 字串，或 null
	 */
	public function get_ip(): ?string;
}
