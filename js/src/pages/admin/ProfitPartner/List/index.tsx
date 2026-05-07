import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons'
import { CreateButton, List } from '@refinedev/antd'
import { useGo } from '@refinedev/core'
import {
	App,
	Button,
	Empty,
	Popconfirm,
	Space,
	Table,
	Tooltip,
	Typography,
	type TableProps,
} from 'antd'
import dayjs from 'dayjs'
import { memo, useMemo } from 'react'

import {
	useProfitPartnerDelete,
	useProfitPartnerList,
} from '@/pages/admin/ProfitPartner/hooks'
import type { TProfitPartner } from '@/pages/admin/ProfitPartner/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Text } = Typography

const ListComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const { data, isLoading, isFetching } = useProfitPartnerList()
	const deleter = useProfitPartnerDelete()

	// V2Api 統一裹 {code, data}
	const partners: TProfitPartner[] = useMemo(() => {
		const list = data?.data?.data
		return Array.isArray(list) ? list : []
	}, [data])

	const handleDelete = async (id: number) => {
		try {
			await deleter.mutateAsync(id)
			notification.success({
				message: '已刪除夥伴',
				description: `#${id} 已從系統中移除`,
			})
		} catch (err) {
			const mapped = mapProfitShopException(err)
			notification.error({
				message: '刪除失敗',
				description: mapped.toastMessage,
			})
		}
	}

	const columns: TableProps<TProfitPartner>['columns'] = [
		{
			title: 'ID',
			dataIndex: 'id',
			width: 80,
			render: (id: number) => <Text type="secondary">#{id}</Text>,
		},
		{
			title: '名稱',
			dataIndex: 'name',
			ellipsis: true,
		},
		{
			title: 'Slug',
			dataIndex: 'slug',
			width: 200,
			render: (slug: string) => <code>{slug}</code>,
		},
		{
			title: '聯絡 Email',
			dataIndex: 'contact_email',
			width: 220,
			render: (email: string | null) =>
				email ? <Text>{email}</Text> : <Text type="secondary">—</Text>,
		},
		{
			title: '建立時間',
			dataIndex: 'created_at',
			width: 170,
			render: (t: string) =>
				t ? (
					<Tooltip title={t}>
						<Text type="secondary">{dayjs(t).format('YYYY-MM-DD HH:mm')}</Text>
					</Tooltip>
				) : (
					'-'
				),
		},
		{
			title: '操作',
			key: 'actions',
			width: 160,
			fixed: 'right',
			render: (_: unknown, record: TProfitPartner) => (
				<Space size="small">
					<Button
						type="link"
						size="small"
						icon={<EditOutlined />}
						onClick={() =>
							go({
								to: {
									resource: 'profit-partner',
									action: 'edit',
									id: record.id,
								},
							})
						}
					>
						編輯
					</Button>
					<Popconfirm
						title="確定刪除此夥伴？"
						description={
							<>
								<div>若仍有賣場掛在此夥伴上，將回傳 409 錯誤。</div>
								<div className="tw-mt-1 tw-text-xs tw-text-red-500">
									此操作無法復原。
								</div>
							</>
						}
						okText="刪除"
						okType="danger"
						cancelText="取消"
						onConfirm={() => handleDelete(record.id)}
					>
						<Button
							type="link"
							size="small"
							danger
							icon={<DeleteOutlined />}
							loading={deleter.isLoading}
						>
							刪除
						</Button>
					</Popconfirm>
				</Space>
			),
		},
	]

	return (
		<List
			title="分潤夥伴"
			headerButtons={() => (
				<CreateButton resource="profit-partner" icon={<PlusOutlined />}>
					建立夥伴
				</CreateButton>
			)}
		>
			<Table<TProfitPartner>
				rowKey="id"
				dataSource={partners}
				columns={columns}
				loading={isLoading || isFetching}
				pagination={{ pageSize: 20, showSizeChanger: false }}
				scroll={{ x: 950 }}
				locale={{
					emptyText: (
						<Empty
							image={Empty.PRESENTED_IMAGE_SIMPLE}
							description="尚未建立任何分潤夥伴"
						>
							<CreateButton resource="profit-partner" icon={<PlusOutlined />}>
								建立第一個夥伴
							</CreateButton>
						</Empty>
					),
				}}
			/>
		</List>
	)
}

export const ProfitPartnerList = memo(ListComponent)
