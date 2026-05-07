/**
 * Partner Portal Dashboard
 *
 * Phase 4-B3 範圍：
 * - Partner header（partner_name + 登出按鈕）
 * - DateRangeFilter（state 在 Dashboard 階層，避免 KPI / Trend / Settlements 不同步）
 * - KpiSummary（4 張卡）
 * - TrendChart（趨勢折線圖）
 * - SettlementsTable（結算列表）
 * - 整個內容用 ErrorBoundary 包住，避免單一元件出錯炸掉整頁
 */

import { Button, Card, Space, Typography } from 'antd'
import { memo, useState } from 'react'

import { useAuth } from '../auth/AuthContext'
import {
	DateRangeFilter,
	getDefaultDateRange,
	type TDateRange,
} from '../components/DateRangeFilter'
import { ErrorBoundary } from '../components/ErrorBoundary'

import { KpiSummary } from './KpiSummary'
import { SettlementsTable } from './SettlementsTable'
import { TrendChart } from './TrendChart'

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
									{partner ? `歡迎，${partner.partner_name}` : '歡迎'}
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

					<TrendChart dateStart={range.date_start} dateEnd={range.date_end} />

					<SettlementsTable
						dateStart={range.date_start}
						dateEnd={range.date_end}
					/>
				</Space>
			</ErrorBoundary>
		</div>
	)
}

export const Dashboard = memo(DashboardComponent)
