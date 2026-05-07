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

/** ErrorBoundary props（受保護的子樹） */
type TErrorBoundaryProps = {
	children: ReactNode
}

/** ErrorBoundary 內部 state（hasError 表示子樹是否拋錯） */
type TErrorBoundaryState = {
	hasError: boolean
}

/** 攔截子樹 React 錯誤的 Error Boundary（class 元件，框架限制） */
export class ErrorBoundary extends Component<
	TErrorBoundaryProps,
	TErrorBoundaryState
> {
	constructor(props: TErrorBoundaryProps) {
		super(props)
		this.state = { hasError: false }
	}

	static getDerivedStateFromError(): TErrorBoundaryState {
		return { hasError: true }
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
			return (
				<div style={{ padding: 24 }}>
					<Result
						status="error"
						title="畫面錯誤"
						subTitle="發生未預期的錯誤，請重新整理頁面再試一次。"
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
