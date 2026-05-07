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
	Tag,
	Tooltip,
	Typography,
	type TableProps,
} from 'antd'
import dayjs from 'dayjs'
import { memo, useMemo } from 'react'

import {
	useProfitShopDelete,
	useProfitShopList,
} from '@/pages/admin/ProfitShop/hooks'
import {
	PROFIT_SHOP_STATUS,
	type TProfitShop,
	type TProfitShopStatus,
} from '@/pages/admin/ProfitShop/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Text } = Typography

/** 狀態顯示對映 */
const STATUS_TAG: Record<TProfitShopStatus, { color: string; label: string }> =
	{
		[PROFIT_SHOP_STATUS.PUBLISH]: { color: 'green', label: '已上架' },
		[PROFIT_SHOP_STATUS.DRAFT]: { color: 'default', label: '草稿' },
		[PROFIT_SHOP_STATUS.TRASH]: { color: 'red', label: '已刪除' },
	}

const ListComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const { data, isLoading, isFetching } = useProfitShopList()
	const deleter = useProfitShopDelete()

	// 後端 V2Api 統一裹 {code, data} → axios body 在 result.data 裡 → 真正資料在 result.data.data
	const shops: TProfitShop[] = useMemo(() => {
		const list = data?.data?.data
		return Array.isArray(list) ? list : []
	}, [data])

	const handleDelete = async (id: number) => {
		try {
			await deleter.mutateAsync(id)
			notification.success({
				message: '已刪除賣場',
				description: '賣場已移至垃圾桶',
			})
		} catch (err) {
			const mapped = mapProfitShopException(err)
			notification.error({
				message: '刪除失敗',
				description: mapped.toastMessage,
			})
		}
	}

	const columns: TableProps<TProfitShop>['columns'] = [
		{
			title: 'ID',
			dataIndex: 'id',
			width: 80,
			render: (id: number) => <Text type="secondary">#{id}</Text>,
		},
		{
			title: '名稱',
			dataIndex: 'title',
			ellipsis: true,
		},
		{
			title: 'Slug',
			dataIndex: 'slug',
			width: 180,
			render: (slug: string) => <code>{slug}</code>,
		},
		{
			title: '狀態',
			dataIndex: 'status',
			width: 100,
			render: (status: TProfitShopStatus) => {
				const tag = STATUS_TAG[status] ?? STATUS_TAG[PROFIT_SHOP_STATUS.DRAFT]
				return <Tag color={tag.color}>{tag.label}</Tag>
			},
		},
		{
			title: '夥伴 ID',
			dataIndex: 'partner_term_id',
			width: 100,
			align: 'center',
		},
		{
			title: '分潤',
			dataIndex: 'rate',
			width: 90,
			align: 'right',
			render: (rate: number) => `${rate}%`,
		},
		{
			title: '商品數',
			dataIndex: 'items',
			width: 90,
			align: 'right',
			render: (items: TProfitShop['items']) => items?.length ?? 0,
		},
		{
			title: '更新時間',
			dataIndex: 'updated_at',
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
			render: (_: unknown, record: TProfitShop) => (
				<Space size="small">
					<Button
						type="link"
						size="small"
						icon={<EditOutlined />}
						onClick={() =>
							go({
								to: { resource: 'profit-shop', action: 'edit', id: record.id },
							})
						}
					>
						編輯
					</Button>
					<Popconfirm
						title="確定刪除此賣場？"
						description="刪除後可在垃圾桶中還原"
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
			title="分潤賣場"
			headerButtons={() => (
				<CreateButton resource="profit-shop" icon={<PlusOutlined />}>
					建立賣場
				</CreateButton>
			)}
		>
			<Table<TProfitShop>
				rowKey="id"
				dataSource={shops}
				columns={columns}
				loading={isLoading || isFetching}
				pagination={{ pageSize: 20, showSizeChanger: false }}
				scroll={{ x: 1100 }}
				locale={{
					emptyText: (
						<Empty
							image={Empty.PRESENTED_IMAGE_SIMPLE}
							description="尚未建立任何分潤賣場"
						>
							<CreateButton resource="profit-shop" icon={<PlusOutlined />}>
								建立第一個賣場
							</CreateButton>
						</Empty>
					),
				}}
			/>
		</List>
	)
}

export const ProfitShopList = memo(ListComponent)
