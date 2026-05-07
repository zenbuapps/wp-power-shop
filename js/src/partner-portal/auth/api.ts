/**
 * Partner Auth REST API wrappers
 *
 * 對應後端 V2Api 的 partner-auth endpoint（spec §4.4 / wordpress.rule.md Phase 3-C 表）：
 * - POST /partner-auth/login    （public，回傳 token + Set-Cookie）
 * - POST /partner-auth/logout   （public，idempotent）
 * - GET  /partner-auth/me       （partner_token，由 cookie 驗證）
 *
 * 注意：partner endpoint 不裹 `{code, data}`，body 直接是 payload。
 *
 * 4-B3 重構（reviewer m-2）：apiClient 已內建 baseURL，不再傳 apiUrl 參數。
 */

import { apiClient } from '../api/client'

/** /partner-auth/login 輸入 */
export type TLoginInput = {
	slug: string
	password: string
}

/**
 * /partner-auth/login 後端回傳 payload
 *
 * 注意：token 欄位前端**永不**儲存（cookie 已自動帶上）。
 */
export type TLoginOutput = {
	token: string
	expires_at: number
	partner_id: number
	partner_name: string
}

/** /partner-auth/me 後端回傳 payload */
export type TMeOutput = {
	partner_id: number
	partner_slug: string
	partner_name: string
	contact_email: string | null
	expires_at: number
}

/**
 * 登入 partner（POST /partner-auth/login）
 *
 * @param input { slug, password }
 */
export const login = (input: TLoginInput) =>
	apiClient.post<TLoginOutput>('/partner-auth/login', input)

/**
 * 登出 partner（POST /partner-auth/logout，idempotent）
 */
export const logout = () => apiClient.post('/partner-auth/logout', {})

/**
 * 取得當前 partner 資訊（GET /partner-auth/me）
 *
 * 依靠 HttpOnly cookie 自動帶入；無有效 cookie 時後端回 401。
 */
export const fetchMe = () => apiClient.get<TMeOutput>('/partner-auth/me')
