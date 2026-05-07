/**
 * Partner KPI 彙總卡片
 *
 * 4 張 antd Statistic 卡片：
 * - 總銷售（藍）
 * - 待結算分潤（橙）
 * - 已結算分潤（綠）
 * - 已退款分潤（紅）
 *
 * Responsive：
 * - xs（mobile）: 1 column stack
 * - md（tablet）: 2 column
 * - lg（desktop）: 4 column
 *
 * 狀態：
 * - loading：每張卡片內部 Skeleton.Button
 * - error：上方 Alert + retry button
 * - empty：data 存在但全為 0 → 「此期間無交易」提示卡
 *
 * 金額顯示：
 * - 後端字串（保留精度）→ parseFloat → Statistic value={number} + formatter
 * - 用 Statistic prefix='NT$'，與 admin SPA Dashboard/DashboardCards 慣用做法一致
 */

import { Alert, Button, Card, Col, Empty, Row, Skeleton, Statistic } from 'antd'
import { memo, useMemo } from 'react'

import { useKpi } from '../hooks/useKpi'
import { mapPartnerException } from '../utils/partnerExceptionMapper'

/** KpiSummary props：dateStart / dateEnd 為 unix timestamp（秒） */
type TKpiSummaryProps = {
	dateStart: number
	dateEnd: number
}

/** 4 張卡片資料源 */
type TStatCard = {
	title: string
	rawValue: string | undefined
	color: string
}

/** Statistic value formatter：千分位 + 最多 2 位小數 */
const amountFormatter = (v: string | number | undefined): string =>
	Number(v ?? 0).toLocaleString('zh-TW', {
		minimumFractionDigits: 0,
		maximumFractionDigits: 2,
	})

/** Partner KPI 彙總卡片元件 */
const KpiSummaryComponent: React.FC<TKpiSummaryProps> = ({
	dateStart,
	dateEnd,
}) => {
	const { data, isLoading, isError, error, refetch, isFetching } = useKpi({
		date_start: dateStart,
		date_end: dateEnd,
	})

	const cards: TStatCard[] = useMemo(
		() => [
			{
				title: '總銷售',
				rawValue: data?.total_sales,
				color: '#1677ff',
			},
			{
				title: '待結算分潤',
				rawValue: data?.profit_pending,
				color: '#fa8c16',
			},
			{
				title: '已結算分潤',
				rawValue: data?.profit_paid,
				color: '#52c41a',
			},
			{
				title: '已退款分潤',
				rawValue: data?.profit_refunded,
				color: '#ff4d4f',
			},
		],
		[data]
	)

	// empty 偵測：data 存在且四個金額皆為 0
	const isEmpty = useMemo(() => {
		if (!data) return false
		const values = [
			data.total_sales,
			data.profit_pending,
			data.profit_paid,
			data.profit_refunded,
		]
		return values.every((v) => parseFloat(v ?? '0') === 0)
	}, [data])

	if (isError) {
		return (
			<Alert
				type="error"
				showIcon
				message="載入 KPI 失敗"
				description={mapPartnerException(error)}
				action={
					<Button
						size="small"
						onClick={() => void refetch()}
						loading={isFetching}
					>
						重試
					</Button>
				}
			/>
		)
	}

	return (
		<>
			<Row gutter={[16, 16]}>
				{cards.map((card) => {
					const num = parseFloat(card.rawValue ?? '0')
					const safeValue = Number.isNaN(num) ? 0 : num
					return (
						<Col key={card.title} xs={24} md={12} lg={6}>
							<Card>
								{isLoading ? (
									<Skeleton active paragraph={{ rows: 1 }} title />
								) : (
									<Statistic
										title={card.title}
										value={safeValue}
										precision={2}
										formatter={amountFormatter}
										prefix="NT$"
										valueStyle={{ color: card.color, fontWeight: 600 }}
									/>
								)}
							</Card>
						</Col>
					)
				})}
			</Row>
			{isEmpty && (
				<div style={{ marginTop: 16 }}>
					<Card>
						<Empty
							image={Empty.PRESENTED_IMAGE_SIMPLE}
							description="此期間無交易"
						/>
					</Card>
				</div>
			)}
		</>
	)
}

export const KpiSummary = memo(KpiSummaryComponent)
