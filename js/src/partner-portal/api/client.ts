/**
 * Partner Portal axios instance
 *
 * 設計重點：
 * - withCredentials: true → 自動帶 HttpOnly cookie（後端認證來源）
 * - 不設定 X-WP-Nonce header：partner 不是 WP user，後端 partner_token_permission 不檢查 nonce
 * - 401 interceptor：未在 /login 頁時，清除 sessionStorage metadata 並導向登入
 *   （注意：interceptor 在 React tree 之外，無法用 navigate hook，
 *    使用 window.location.hash 是合理選擇）
 */

import axios from 'axios'

import { session } from '../auth/session'

/** Partner Portal 共用 axios 實例 */
export const apiClient = axios.create({
	timeout: 30000,
	withCredentials: true,
	headers: {
		'Content-Type': 'application/json',
	},
})

apiClient.interceptors.response.use(
	(response) => response,
	(error: unknown) => {
		const status = (error as { response?: { status?: number } })?.response
			?.status
		if (status === 401) {
			const isLoginPage = window.location.hash.startsWith('#/login')
			if (!isLoginPage) {
				session.clear()
				window.location.hash = '#/login'
			}
		}
		return Promise.reject(error)
	}
)
