/**
 * Partner Portal Dashboard（最小 placeholder）
 *
 * Phase 4-B1.3 僅提供登入後的最小可見頁面，
 * 真正的 KPI / 趨勢 / 結算列表將在 Phase 4-B2 / 4-B3 補完。
 */

import { Alert, Button, Card, Space, Typography } from 'antd'
import { memo } from 'react'

import { useAuth } from '../auth/useAuth'

/** Dashboard 元件（Phase 4-B1.3 placeholder） */
const DashboardComponent = () => {
	const { partner, logout } = useAuth()

	return (
		<div
			style={{
				maxWidth: 1080,
				margin: '0 auto',
				padding: 24,
			}}
		>
			<Card>
				<div
					style={{
						display: 'flex',
						justifyContent: 'space-between',
						alignItems: 'center',
						flexWrap: 'wrap',
						gap: 12,
					}}
				>
					<Typography.Title level={3} style={{ margin: 0 }}>
						歡迎，{partner?.partner_name ?? ''}
					</Typography.Title>
					<Space>
						<Button onClick={() => void logout()}>登出</Button>
					</Space>
				</div>
				{partner?.contact_email && (
					<Typography.Paragraph
						type="secondary"
						style={{ marginTop: 8, marginBottom: 0 }}
					>
						聯絡信箱：{partner.contact_email}
					</Typography.Paragraph>
				)}
			</Card>
			<div style={{ height: 16 }} />
			<Alert
				type="info"
				showIcon
				message="儀表板開發中"
				description="KPI / 趨勢 / 結算列表將於 Phase 4-B2 與 4-B3 陸續上線。"
			/>
		</div>
	)
}

export const Dashboard = memo(DashboardComponent)
