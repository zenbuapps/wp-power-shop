/**
 * Partner Auth Context
 *
 * 集中管理 partner 認證狀態：
 * - 啟動時打 /partner-auth/me 嘗試以 cookie 自動登入
 * - 提供 login() / logout() 給頁面元件
 * - 跨 partner 偵測：若 me.partner_slug !== URL slug，強制登出
 *   （防止 partner A 開啟 partner B 的 URL 卻仍持有舊 cookie）
 *
 * 安全紀律：
 * - login response 內的 token 欄位**永不**寫入任何 storage
 * - sessionStorage 只放展示用 metadata（見 session.ts 註解）
 */

import { useQuery, useQueryClient } from '@tanstack/react-query'
import { notification } from 'antd'
import {
	createContext,
	memo,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	type PropsWithChildren,
} from 'react'

import { usePartnerEnv } from '../hooks/usePartnerEnv'

import {
	fetchMe,
	login as apiLogin,
	logout as apiLogout,
	type TMeOutput,
} from './api'
import { session } from './session'

/** Auth 載入狀態 */
export type TAuthStatus = 'loading' | 'authenticated' | 'guest'

/**
 * Context 對外型別
 *
 * status:  目前認證狀態
 * partner: 已驗證的 partner 資料（status === 'authenticated' 時必有值）
 * login:   執行登入
 * logout:  執行登出（永遠執行 local cleanup，即便後端 logout 失敗）
 */
export type TAuthContextValue = {
	status: TAuthStatus
	partner: TMeOutput | null
	login: (slug: string, password: string) => Promise<void>
	logout: () => Promise<void>
}

const AuthContext = createContext<TAuthContextValue | null>(null)

/**
 * AuthProvider 元件
 *
 * 必須在 QueryClientProvider 之內使用。
 */
const AuthProviderComponent = ({ children }: PropsWithChildren) => {
	const { SLUG } = usePartnerEnv()
	const queryClient = useQueryClient()

	// 啟動時用 useQuery 自動打 /me，依靠 cookie 已被瀏覽器自動帶。
	// 401 由 axios interceptor 處理（redirect to /login），這裡 retry: 0 避免重試。
	const meQuery = useQuery<TMeOutput, unknown>({
		queryKey: ['partner-me'],
		queryFn: async () => {
			const res = await fetchMe()
			return res.data
		},
		retry: 0,
		staleTime: 10 * 60 * 1000,

		// 401 時 axios interceptor 會清掉 hash + session，這裡會 isError，AuthGate 導去 login
		refetchOnWindowFocus: false,
	})

	// 跨 partner mismatch 偵測：me.partner_slug 必須等於 URL 的 SLUG
	// !SLUG 時（env 解密失敗或測試環境）視為 matched，向後相容
	const isPartnerMatched =
		!SLUG || (meQuery.data?.partner_slug ?? null) === SLUG

	let status: TAuthStatus
	if (meQuery.isLoading) {
		status = 'loading'
	} else if (meQuery.isSuccess) {
		// mismatch 時設 'loading' 而非 'authenticated'，等下方 useEffect 觸發 logout
		// 期間 AuthGate 顯示 LoadingScreen 而非 redirect 到 /login，避免閃 partner A 的資料
		status = isPartnerMatched ? 'authenticated' : 'loading'
	} else {
		status = 'guest'
	}

	// 雙保險：partner 對外暴露時做 mismatch 遮蔽，即使某種 race 下 status 短暫 'authenticated'，
	// partner 物件也會是 null → Dashboard 不會閃 partner A 的姓名
	const partner: TMeOutput | null =
		meQuery.data && isPartnerMatched ? meQuery.data : null

	// logout 用 ref 包住，讓 useEffect 不必把 logout 列入依賴（避免重複執行）
	const logoutRef = useRef<() => Promise<void>>(async () => {})

	const handleLogout = useCallback(async (): Promise<void> => {
		try {
			await apiLogout()
		} catch {
			// logout 永遠 local cleanup（即便網路失敗）
		}
		session.clear()
		queryClient.clear()

		// 用 hash 而非 navigate：可從 React tree 外（interceptor）觸發
		if (!window.location.hash.startsWith('#/login')) {
			window.location.hash = '#/login'
		}
	}, [queryClient])

	logoutRef.current = handleLogout

	// 跨 partner 偵測：me.partner_slug 必須等於 URL 的 SLUG
	useEffect(() => {
		if (meQuery.data && SLUG && meQuery.data.partner_slug !== SLUG) {
			notification.warning({
				message: '此帳號不屬於當前頁面',
				description: '請以對應的分潤夥伴帳號重新登入。',
			})
			void logoutRef.current()
		}
	}, [meQuery.data, SLUG])

	const handleLogin = useCallback(
		async (slug: string, password: string): Promise<void> => {
			const res = await apiLogin({ slug, password })

			// **永不**儲存 token；只放展示用 metadata
			session.save({
				partner_id: res.data.partner_id,
				partner_name: res.data.partner_name,
				partner_slug: slug,
				expires_at: res.data.expires_at,
			})

			// 觸發 /me 重新 fetch，讓 status 更新為 authenticated
			await queryClient.invalidateQueries({ queryKey: ['partner-me'] })
		},
		[queryClient]
	)

	const value = useMemo<TAuthContextValue>(
		() => ({
			status,
			partner,
			login: handleLogin,
			logout: handleLogout,
		}),
		[status, partner, handleLogin, handleLogout]
	)

	return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export const AuthProvider = memo(AuthProviderComponent)

/**
 * 取得 Auth Context 值的 hook
 *
 * 必須在 <AuthProvider> 之內呼叫。
 */
export const useAuth = (): TAuthContextValue => {
	const ctx = useContext(AuthContext)
	if (!ctx) {
		throw new Error('useAuth 必須在 <AuthProvider> 之內使用')
	}
	return ctx
}
