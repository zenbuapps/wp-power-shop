/**
 * Partner Portal 例外訊息對映器
 *
 * 在 admin 端的 `mapProfitShopException` 之上補入 partner 專屬的友善文案。
 * partner 場景下：
 * - `unauthorized` → 「登入已過期，請重新登入」（admin 端為一般訊息）
 * - `too_many_attempts` → partner 登入鎖定（admin 端通常用 rate_limited）
 * - `forbidden` → 改寫為更明確的「此帳號無權查看當前頁面」
 *
 * 其餘 code 全部 fallback 到 admin mapper（複用 13 種 Domain Exception 對映）。
 */

import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const PARTNER_EXTRA: Record<string, string> = {
	unauthorized: '登入已過期，請重新登入',
	too_many_attempts: '失敗次數過多，請稍後再試',
	forbidden: '此帳號無權查看當前頁面',
}

/**
 * 將 axios error 對映成 partner 場景下的友善訊息字串
 *
 * @param  error 任意 axios / fetch 失敗物件
 * @return {string} 中文使用者訊息（用於 notification.error）
 */
export const mapPartnerException = (error: unknown): string => {
	const code = (error as { response?: { data?: { code?: unknown } } })?.response
		?.data?.code
	if (typeof code === 'string' && PARTNER_EXTRA[code]) {
		return PARTNER_EXTRA[code]
	}
	return mapProfitShopException(error).toastMessage
}
