/**
 * Partner Portal Dashboard
 *
 * Phase 4-B2 範圍：
 * - Partner header（partner_name + 登出按鈕）
 * - DateRangeFilter（state 在 Dashboard 階層，避免 KPI / Trend 不同步）
 * - KpiSummary（4 張卡）
 * - Trend / Settlements 仍 placeholder（4-B3 補）
 * - 整個內容用 ErrorBoundary 包住，避免 KPI 元件出錯炸掉整頁
 */

import { Alert, Button, Card, Space, Typography } from 'antd'
import { memo, useState } from 'react'

import { useAuth } from '../auth/useAuth'
import {
	DateRangeFilter,
	getDefaultDateRange,
	type TDateRange,
} from '../components/DateRangeFilter'
import { ErrorBoundary } from '../components/ErrorBoundary'

import { KpiSummary } from './KpiSummary'

/** Dashboard 元件 */
const DashboardComponent = () => {
	const { partner, logout } = useAuth()
	const [range, setRange] = useState<TDateRange>(() => getDefaultDateRange())

	return (
		<div
			style={{
				maxWidth: 1080,
				margin: '0 auto',
				padding: 24,
			}}
		>
			<ErrorBoundary>
				<Space direction="vertical" size={16} style={{ width: '100%' }}>
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
							<div>
								<Typography.Title level={3} style={{ margin: 0 }}>
									歡迎，{partner?.partner_name ?? ''}
								</Typography.Title>
								{partner?.contact_email && (
									<Typography.Paragraph
										type="secondary"
										style={{ marginTop: 4, marginBottom: 0 }}
									>
										聯絡信箱：{partner.contact_email}
									</Typography.Paragraph>
								)}
							</div>
							<Space>
								<Button onClick={() => void logout()}>登出</Button>
							</Space>
						</div>
					</Card>

					<Card title="期間">
						<DateRangeFilter value={range} onChange={setRange} />
					</Card>

					<KpiSummary dateStart={range.date_start} dateEnd={range.date_end} />

					<Alert
						type="info"
						showIcon
						message="趨勢與結算列表開發中"
						description="趨勢圖表與結算紀錄將於 Phase 4-B3 上線。"
					/>
				</Space>
			</ErrorBoundary>
		</div>
	)
}

export const Dashboard = memo(DashboardComponent)
