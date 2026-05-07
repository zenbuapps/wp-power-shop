/**
 * Partner Portal 全頁載入畫面
 *
 * 用於 AuthGate 認證查詢中、Dashboard 初次資料抓取等情境。
 * 抽出共用元件，避免在 App.tsx 內 inline 重複定義。
 */

import { Spin } from 'antd'
import { memo } from 'react'

/** LoadingScreen props（tip 為自訂提示文字，預設「載入中...」） */
type TLoadingScreenProps = {
	tip?: string
}

/** 全頁置中的載入指示器 */
const LoadingScreenComponent: React.FC<TLoadingScreenProps> = ({
	tip = '載入中...',
}) => (
	<div
		style={{
			display: 'flex',
			alignItems: 'center',
			justifyContent: 'center',
			minHeight: '60vh',
		}}
	>
		<Spin size="large" tip={tip}>
			<div style={{ width: 200, height: 1 }} />
		</Spin>
	</div>
)

export const LoadingScreen = memo(LoadingScreenComponent)
