/**
 * Partner Portal 結算列表
 *
 * 桌機顯示 antd Table，行動裝置（xs）改用 Card 卡片版（表格在手機上很不友善）。
 *
 * 功能：
 * - 分頁（page + per_page，後端伺服器端分頁）
 * - 狀態篩選（多選 Select：pending / paid / refunded / cancelled）
 * - 三態：loading / error / empty
 *
 * 設計取捨：
 * - 用 Grid xs/md 切版而非 useWindowSize，避免重複 React render
 * - status 染色用 Tag preset color（不引入額外色票）
 * - 金額後端字串保存精度，渲染時 parseFloat
 */

import {
	Alert,
	Button,
	Card,
	Empty,
	Grid,
	List,
	Pagination,
	Select,
	Skeleton,
	Space,
	Table,
	Tag,
	Typography,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import dayjs from 'dayjs'
import { memo, useMemo, useState } from 'react'

import { type TSettlementItem, type TSettlementStatus } from '../api/reports'
import { useSettlements } from '../hooks/useSettlements'
import { formatAmount } from '../utils/format'
import { mapPartnerException } from '../utils/partnerExceptionMapper'

const { useBreakpoint } = Grid

/** SettlementsTable props */
type TSettlementsTableProps = {
	dateStart: number
	dateEnd: number
}

/** Status → 顯示文字 + Tag color */
const STATUS_META: Record<TSettlementStatus, { label: string; color: string }> =
	{
		pending: { label: '待結算', color: 'orange' },
		paid: { label: '已結算', color: 'green' },
		refunded: { label: '已退款', color: 'red' },
		cancelled: { label: '已取消', color: 'default' },
	}

const DEFAULT_PER_PAGE = 20

/** 格式化結算日（reviewer m-2：避免顯示原始 ISO 字串） */
const formatCreatedAt = (value: string | undefined): string => {
	if (!value) return '-'
	const d = dayjs(value)
	return d.isValid() ? d.format('YYYY-MM-DD HH:mm') : value
}

/** Partner Portal 結算列表元件 */
const SettlementsTableComponent: React.FC<TSettlementsTableProps> = ({
	dateStart,
	dateEnd,
}) => {
	const screens = useBreakpoint()
	const isMobile = !screens.md // xs / sm 視為 mobile

	const [page, setPage] = useState(1)
	const [perPage, setPerPage] = useState(DEFAULT_PER_PAGE)
	const [statusFilter, setStatusFilter] = useState<TSettlementStatus[]>([])

	const { data, isLoading, isError, error, refetch, isFetching } =
		useSettlements({
			page,
			per_page: perPage,

			// 5-C.5：傳陣列，由 fetchSettlements 內部 join 為 CSV
			statuses: statusFilter.length > 0 ? statusFilter : undefined,
			date_start: dateStart,
			date_end: dateEnd,
		})

	const items = useMemo<TSettlementItem[]>(() => data?.items ?? [], [data])

	const handleStatusChange = (values: TSettlementStatus[]): void => {
		setStatusFilter(values)
		setPage(1) // 篩選變動時 reset 到第一頁
	}

	const handlePaginationChange = (
		newPage: number,
		newPerPage: number
	): void => {
		setPage(newPage)
		setPerPage(newPerPage)
	}

	// Desktop 表格欄位定義
	const columns: ColumnsType<TSettlementItem> = useMemo(
		() => [
			{
				title: '訂單編號',
				dataIndex: 'order_id',
				key: 'order_id',
				render: (orderId: number) => `#${orderId}`,
			},
			{
				title: '商品 ID',
				dataIndex: 'order_item_id',
				key: 'order_item_id',
				responsive: ['lg'],
			},
			{
				title: '分潤金額',
				dataIndex: 'profit_amount',
				key: 'profit_amount',
				align: 'right',
				render: (value: string) => `NT$ ${formatAmount(value)}`,
			},
			{
				title: '狀態',
				dataIndex: 'status',
				key: 'status',
				render: (status: TSettlementStatus) => {
					const meta = STATUS_META[status] ?? {
						label: status,
						color: 'default',
					}
					return <Tag color={meta.color}>{meta.label}</Tag>
				},
			},
			{
				title: '結算日',
				dataIndex: 'created_at',
				key: 'created_at',
				render: (value: string | undefined) => formatCreatedAt(value),
			},
		],
		[]
	)

	if (isError) {
		return (
			<Card title="結算紀錄">
				<Alert
					type="error"
					showIcon
					message="載入結算列表失敗"
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
			</Card>
		)
	}

	const filterRow = (
		<Select<TSettlementStatus[]>
			mode="multiple"
			allowClear
			placeholder="篩選狀態（不選為全部）"
			value={statusFilter}
			onChange={handleStatusChange}
			style={{ minWidth: 240, width: isMobile ? '100%' : undefined }}
			options={[
				{ label: STATUS_META.pending.label, value: 'pending' },
				{ label: STATUS_META.paid.label, value: 'paid' },
				{ label: STATUS_META.refunded.label, value: 'refunded' },
				{ label: STATUS_META.cancelled.label, value: 'cancelled' },
			]}
		/>
	)

	const total = data?.total ?? 0

	const paginationRow = (
		<Pagination
			current={page}
			pageSize={perPage}
			total={total}
			onChange={handlePaginationChange}
			showSizeChanger
			pageSizeOptions={[10, 20, 50, 100]}
			size={isMobile ? 'small' : 'default'}
			showTotal={(t) => `共 ${t} 筆`}
		/>
	)

	/** 渲染主體：依 loading / empty / mobile 切換不同元件 */
	const renderBody = (): React.ReactNode => {
		if (isLoading) {
			return <Skeleton active paragraph={{ rows: 4 }} />
		}
		if (items.length === 0) {
			return (
				<Empty
					image={Empty.PRESENTED_IMAGE_SIMPLE}
					description={
						<Typography.Text type="secondary">
							目前期間無結算紀錄
						</Typography.Text>
					}
				/>
			)
		}

		if (isMobile) {
			// Mobile: Card list（表格在手機上太擠）
			return (
				<List<TSettlementItem>
					dataSource={items}
					loading={isFetching}
					rowKey={(item) => `${item.order_id}-${item.order_item_id}`}
					renderItem={(item) => {
						const meta = STATUS_META[item.status] ?? {
							label: item.status,
							color: 'default',
						}
						return (
							<List.Item>
								<div style={{ width: '100%' }}>
									<div
										style={{
											display: 'flex',
											justifyContent: 'space-between',
											alignItems: 'center',
											marginBottom: 4,
										}}
									>
										<Typography.Text strong>#{item.order_id}</Typography.Text>
										<Tag color={meta.color}>{meta.label}</Tag>
									</div>
									<div
										style={{
											display: 'flex',
											justifyContent: 'space-between',
											color: '#666',
											fontSize: 12,
										}}
									>
										<span>{formatCreatedAt(item.created_at)}</span>
										<span>NT$ {formatAmount(item.profit_amount)}</span>
									</div>
								</div>
							</List.Item>
						)
					}}
				/>
			)
		}

		// Desktop: Table
		return (
			<Table<TSettlementItem>
				columns={columns}
				dataSource={items}
				loading={isFetching}
				rowKey={(item) => `${item.order_id}-${item.order_item_id}`}
				pagination={false}
				size="middle"
			/>
		)
	}

	return (
		<Card title="結算紀錄">
			<Space direction="vertical" size={12} style={{ width: '100%' }}>
				{filterRow}

				{renderBody()}

				{items.length > 0 && (
					<div style={{ textAlign: 'right' }}>{paginationRow}</div>
				)}
			</Space>
		</Card>
	)
}

export const SettlementsTable = memo(SettlementsTableComponent)
