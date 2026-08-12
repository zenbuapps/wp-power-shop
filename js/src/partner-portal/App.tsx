/**
 * Partner Portal App
 *
 * 路由：
 * - /login            → Login 頁
 * - /                 → Dashboard（受 AuthGate 保護）
 * - /change-password  → 修改密碼（受 AuthGate 保護，Phase 6-A2）
 * - *                 → 重定向到 /
 *
 * AuthGate 邏輯：
 * - status === 'loading' → 顯示載入畫面
 * - status === 'guest'   → 重定向到 /login
 * - status === 'authenticated' → 渲染受保護內容
 */

import { memo, type PropsWithChildren } from 'react'
import { Navigate, Route, Routes } from 'react-router'

import { useAuth } from './auth/AuthContext'
import { LoadingScreen } from './components/LoadingScreen'
import { ChangePassword } from './pages/ChangePassword'
import { Dashboard } from './pages/Dashboard'
import { Login } from './pages/Login'

/** 路由守門元件 */
const AuthGate = memo(({ children }: PropsWithChildren) => {
	const { status } = useAuth()
	if (status === 'loading') return <LoadingScreen />
	if (status === 'guest') return <Navigate to="/login" replace />
	return <>{children}</>
})
AuthGate.displayName = 'AuthGate'

/** Partner Portal 路由根節點 */
const AppComponent = () => (
	<Routes>
		<Route path="/login" element={<Login />} />
		<Route
			path="/"
			element={
				<AuthGate>
					<Dashboard />
				</AuthGate>
			}
		/>
		<Route
			path="/change-password"
			element={
				<AuthGate>
					<ChangePassword />
				</AuthGate>
			}
		/>
		<Route path="*" element={<Navigate to="/" replace />} />
	</Routes>
)

export default memo(AppComponent)
