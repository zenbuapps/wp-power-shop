/**
 * Partner Portal Error Boundary
 *
 * 例外允許 class component：React 目前僅 class component 能透過
 * componentDidCatch / static getDerivedStateFromError 攔截子樹錯誤。
 *
 * fallback：簡潔的錯誤畫面 + 重新整理按鈕（最不會出錯的恢復路徑）。
 */

import { Button, Result } from 'antd'
import { Component, type ErrorInfo, type ReactNode } from 'react'

import { PartnerEnvInjectionError } from '../hooks/usePartnerEnv'

/** ErrorBoundary props（受保護的子樹） */
type TErrorBoundaryProps = {
	children: ReactNode
}

/** ErrorBoundary 內部 state（hasError 表示子樹是否拋錯，error 保留訊息供 UI 判斷） */
type TErrorBoundaryState = {
	hasError: boolean
	error: Error | null
}

/** 攔截子樹 React 錯誤的 Error Boundary（class 元件，框架限制） */
export class ErrorBoundary extends Component<
	TErrorBoundaryProps,
	TErrorBoundaryState
> {
	constructor(props: TErrorBoundaryProps) {
		super(props)
		this.state = { hasError: false, error: null }
	}

	static getDerivedStateFromError(error: Error): TErrorBoundaryState {
		return { hasError: true, error }
	}

	componentDidCatch(error: Error, info: ErrorInfo): void {
		// 不送遠端服務（partner portal 沒接 Sentry），但保留 console 紀錄方便排查
		// eslint-disable-next-line no-console
		console.error('Partner portal error:', error, info)
	}

	private handleReload = (): void => {
		window.location.reload()
	}

	render(): ReactNode {
		if (this.state.hasError) {
			// 5-A.2 / 5-C.3：判別 readPartnerEnv 拋出的環境注入錯誤 → 顯示「服務維護中」
			// 其他錯誤 → 顯示通用「畫面錯誤」
			// 改用 instanceof 而非 regex 比對 message（訊息文字變動不會破測試）
			const isEnvError = this.state.error instanceof PartnerEnvInjectionError

			return (
				<div style={{ padding: 24 }}>
					<Result
						status={isEnvError ? 'warning' : 'error'}
						title={isEnvError ? '服務維護中' : '畫面錯誤'}
						subTitle={
							isEnvError
								? '系統暫時無法載入分潤夥伴頁面，請稍後再試或聯絡管理員。'
								: '發生未預期的錯誤，請重新整理頁面再試一次。'
						}
						extra={
							<Button type="primary" onClick={this.handleReload}>
								重新整理
							</Button>
						}
					/>
				</div>
			)
		}
		return this.props.children
	}
}
