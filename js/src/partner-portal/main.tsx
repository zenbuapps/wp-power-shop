/**
 * Partner self-service portal entry point
 *
 * 對應 Phase 4-B mount 點：<div id="profit_partner_portal">
 * URL: /profit-report/{slug}/
 *
 * Phase 4-B1.3 完成的責任鏈：
 *  Mount(profit_partner_portal)
 *    └─ QueryClientProvider (TanStack Query v4)
 *        └─ ConfigProvider   (antd v5 theme token)
 *            └─ HashRouter   (與 admin SPA 一致)
 *                └─ AuthProvider (cookie + sessionStorage metadata)
 *                    └─ <App />
 *
 * 注意：Partner 不是 WP user，因此整個 portal 不依賴 wp_rest nonce / antd-toolkit 的 EnvProvider。
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ConfigProvider } from 'antd'
import { createRoot } from 'react-dom/client'
import { HashRouter } from 'react-router'

import App from './App'
import { AuthProvider } from './auth/AuthContext'
import { ErrorBoundary } from './components/ErrorBoundary'

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			retry: 0,
			refetchOnWindowFocus: false,
		},
	},
})

const init = (): void => {
	const mountNode = document.getElementById('profit_partner_portal')
	if (!mountNode) return

	// 5-A.2：ErrorBoundary 必須包在最外層
	// 1) AuthProvider 內部會呼叫 readPartnerEnv() — env 注入失敗會 throw，
	//    需要 ErrorBoundary 接住顯示「服務維護中」畫面，避免白屏
	// 2) ErrorBoundary 也兼任所有子樹未捕獲 React 錯誤的最後防線
	createRoot(mountNode).render(
		<ErrorBoundary>
			<QueryClientProvider client={queryClient}>
				<ConfigProvider theme={{ token: { colorPrimary: '#1677ff' } }}>
					<HashRouter>
						<AuthProvider>
							<App />
						</AuthProvider>
					</HashRouter>
				</ConfigProvider>
			</QueryClientProvider>
		</ErrorBoundary>
	)
}

document.addEventListener('DOMContentLoaded', init)
