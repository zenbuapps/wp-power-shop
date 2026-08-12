/**
 * Partner Portal 例外訊息對映器
 *
 * 在 admin 端的 `mapProfitShopException` 之上補入 partner 專屬的友善文案。
 * partner 場景下：
 * - `unauthorized` → 「登入已過期，請重新登入」（admin 端為一般訊息）
 * - `too_many_attempts` → partner 登入鎖定（admin 端通常用 rate_limited）
 * - `forbidden` → 改寫為更明確的「此帳號無權查看當前頁面」
 *
 * 6-A2 擴充：context-aware 修改密碼場景（context = 'change-password'）
 * - `invalid_credentials` → 「目前密碼不正確」
 * - `weak_password` → 翻譯 reasons[]（too_short / missing_letter / missing_digit）
 * - `rate_limited` / `too_many_attempts` → 拼接 Retry-After 倒數秒數
 *
 * 其餘 code 全部 fallback 到 admin mapper（複用 13 種 Domain Exception 對映）。
 */

import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

/** 對映 context — 用於決定錯誤訊息的場景 wording */
export type TPartnerExceptionContext = 'default' | 'change-password'

const PARTNER_EXTRA: Record<string, string> = {
	unauthorized: '登入已過期，請重新登入',
	too_many_attempts: '失敗次數過多，請稍後再試',
	forbidden: '此帳號無權查看當前頁面',
}

/**
 * weak_password reason 白名單（LOW-4 security）
 *
 * 與後端 PHP `WeakPasswordException::REASONS` 對齊（spec §6.A1）：
 * - too_short / missing_letter / missing_digit
 *
 * 為什麼用白名單：避免後端意外回傳未預期的 reason 字串透傳到前端訊息
 * （antd notification 已自動 escape，白名單作為深度防禦 + UX 保護）
 */
const KNOWN_WEAK_PASSWORD_REASONS = [
	'too_short',
	'missing_letter',
	'missing_digit',
] as const

type TWeakPasswordReason = (typeof KNOWN_WEAK_PASSWORD_REASONS)[number]

const WEAK_PASSWORD_REASON_LABEL: Record<TWeakPasswordReason, string> = {
	too_short: '密碼至少需 8 字元',
	missing_letter: '密碼需含至少 1 個英文字母',
	missing_digit: '密碼需含至少 1 個數字',
}

const isKnownWeakPasswordReason = (r: string): r is TWeakPasswordReason =>
	(KNOWN_WEAK_PASSWORD_REASONS as readonly string[]).includes(r)

/** 從 axios error response.data 取出 code */
const extractCode = (error: unknown): string | null => {
	const code = (error as { response?: { data?: { code?: unknown } } })?.response
		?.data?.code
	return typeof code === 'string' ? code : null
}

/** 從 axios error response.data 取出 weak_password reasons[] */
const extractWeakPasswordReasons = (error: unknown): string[] => {
	const reasons = (
		error as {
			response?: { data?: { data?: { reasons?: unknown } } }
		}
	)?.response?.data?.data?.reasons
	if (!Array.isArray(reasons)) return []
	return reasons.filter((r): r is string => typeof r === 'string')
}

/** 從 axios error 取 Retry-After header（秒） */
const extractRetryAfter = (error: unknown): number | null => {
	const headers = (
		error as { response?: { headers?: Record<string, unknown> } }
	)?.response?.headers
	const raw = headers?.['retry-after']
	if (typeof raw !== 'string' && typeof raw !== 'number') return null
	const n = Number(raw)
	return Number.isFinite(n) && n > 0 ? Math.ceil(n) : null
}

/**
 * 將 axios error 對映成 partner 場景下的友善訊息字串
 *
 * @param  error   任意 axios / fetch 失敗物件
 * @param  context 場景 context（預設 'default'；修改密碼頁傳 'change-password'
 *                 以取得對應文案）
 * @return {string} 中文使用者訊息（用於 notification.error）
 */
export const mapPartnerException = (
	error: unknown,
	context: TPartnerExceptionContext = 'default'
): string => {
	const code = extractCode(error)

	// 6-A2: change-password context 專屬訊息
	if (context === 'change-password' && code) {
		if (code === 'invalid_credentials') {
			return '目前密碼不正確'
		}
		if (code === 'weak_password') {
			const reasons = extractWeakPasswordReasons(error)

			// LOW-4 (security): 白名單過濾，未知 reason 一律丟棄
			const reasonText = reasons
				.filter(isKnownWeakPasswordReason)
				.map((r) => WEAK_PASSWORD_REASON_LABEL[r])
				.join('、')
			return reasonText
				? `新密碼不符合複雜度需求：${reasonText}`
				: '新密碼不符合複雜度需求'
		}
		if (code === 'rate_limited' || code === 'too_many_attempts') {
			const retryAfter = extractRetryAfter(error)
			return retryAfter
				? `嘗試次數過多，請於 ${retryAfter} 秒後再試`
				: '嘗試次數過多，請稍後再試'
		}
	}

	if (code && PARTNER_EXTRA[code]) {
		return PARTNER_EXTRA[code]
	}
	return mapProfitShopException(error).toastMessage
}
