/**
 * 解析 axios error 的 Retry-After header
 *
 * 用於 partner 登入 rate-limit（HTTP 429）時的倒數提示。
 * 後端 LoginRateLimiter 會在達到失敗上限時回傳 Retry-After header（秒）。
 */

import type { AxiosError } from 'axios'

/**
 * 從 AxiosError 取出 Retry-After header 並解析成秒數
 *
 * @param  error 任意 axios 錯誤物件
 * @return {number | null} 秒數（正整數）；無 header 或解析失敗時回 null
 */
export const parseRetryAfter = (error: unknown): number | null => {
	const headers = (error as AxiosError | undefined)?.response?.headers as
		| Record<string, unknown>
		| undefined
	const raw = headers?.['retry-after']
	if (typeof raw !== 'string' && typeof raw !== 'number') return null
	const seconds = Number(raw)
	return Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds) : null
}
