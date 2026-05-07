/**
 * Partner Portal axios instance
 *
 * 設計重點：
 * - baseURL 由 readPartnerEnv() **lazy** 初始化（5-A.2 修正）
 *   * 原本 module top-level `const env = readPartnerEnv()` 會在 import 階段 throw，
 *     讓 ErrorBoundary 來不及掛載 → 直接白屏
 *   * 改為首次屬性存取時才求值，throw 會發生在 React render / event handler 期間，
 *     可被 ErrorBoundary 接住顯示「服務維護中」
 * - withCredentials: true → 自動帶 HttpOnly cookie（後端認證來源）
 * - 不設定 X-WP-Nonce header：partner 不是 WP user，後端 partner_token_permission 不檢查 nonce
 * - 401 interceptor：未在 /login 頁時，清除 sessionStorage metadata 並導向登入
 *   （注意：interceptor 在 React tree 之外，無法用 navigate hook，
 *    使用 window.location.hash 是合理選擇）
 *
 * 4-B3 重構（reviewer m-2）：baseURL 內建，呼叫端 API 函式不再需要傳 apiUrl 參數。
 */

import axios, { type AxiosInstance } from 'axios'

import { session } from '../auth/session'
import { readPartnerEnv } from '../hooks/usePartnerEnv'

/** lazy 初始化的 axios singleton（首次 method 存取時建立，5-A.2） */
let _instance: AxiosInstance | null = null

const buildInstance = (): AxiosInstance => {
	const env = readPartnerEnv()
	const inst = axios.create({
		baseURL: env.API_URL,
		timeout: 30000,
		withCredentials: true,
		headers: {
			'Content-Type': 'application/json',
		},
	})
	registerInterceptors(inst)
	return inst
}

const getInstance = (): AxiosInstance => {
	if (!_instance) {
		_instance = buildInstance()
	}
	return _instance
}

/**
 * Partner Portal 共用 axios 實例（lazy proxy）
 *
 * 透過 Proxy 將屬性存取轉發到真正的 axios instance；首次存取時才呼叫 readPartnerEnv，
 * 確保 env 注入失敗的 error 能在 React render 階段被 ErrorBoundary 接住。
 */
export const apiClient = new Proxy({} as AxiosInstance, {
	get: (_target, prop, receiver) => Reflect.get(getInstance(), prop, receiver),
})

const registerInterceptors = (inst: AxiosInstance): void => {
	inst.interceptors.response.use(
		(response) => response,
		(error: unknown) => {
			const err = error as {
				response?: { status?: number }
				config?: { headers?: Record<string, unknown> }
			}
			const status = err?.response?.status
			if (status === 401) {
				// 5-A.2: logout 路徑帶 X-Skip-Auth-Redirect 標記，不導頁
				// （避免「登出 → 401 → 自動 redirect 回 /login」的體感誤判）
				const skipRedirect =
					err?.config?.headers?.['X-Skip-Auth-Redirect'] === '1'
				if (skipRedirect) {
					return Promise.reject(error)
				}

				const isLoginPage = window.location.hash.startsWith('#/login')
				if (!isLoginPage) {
					session.clear()
					window.location.hash = '#/login'
				}
			}
			return Promise.reject(error)
		}
	)
}
