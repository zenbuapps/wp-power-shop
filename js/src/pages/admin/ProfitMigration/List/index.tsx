import { ImportOutlined } from '@ant-design/icons'
import { List } from '@refinedev/antd'
import {
	Button,
	Empty,
	Space,
	Table,
	Tag,
	Tooltip,
	Typography,
	type TableProps,
} from 'antd'
import { memo, useMemo, useState } from 'react'

import { useLegacyShopList } from '@/pages/admin/ProfitMigration/hooks'
import { ImportModal } from '@/pages/admin/ProfitMigration/ImportModal'
import type { TLegacyShop } from '@/pages/admin/ProfitMigration/types'

const { Text } = Typography

const ListComponent = () => {
	const { data, isLoading, isFetching } = useLegacyShopList()

	// V2Api 統一裹 {code, data}
	const shops: TLegacyShop[] = useMemo(() => {
		const list = data?.data?.data
		return Array.isArray(list) ? list : []
	}, [data])

	const [activeShop, setActiveShop] = useState<TLegacyShop | null>(null)
	const [modalOpen, setModalOpen] = useState(false)

	const openImportModal = (shop: TLegacyShop) => {
		setActiveShop(shop)
		setModalOpen(true)
	}

	const closeImportModal = () => {
		setModalOpen(false)

		// activeShop 保留到 modal 動畫結束（destroyOnClose 會處理 inner state）
	}

	const columns: TableProps<TLegacyShop>['columns'] = [
		{
			title: 'Legacy ID',
			dataIndex: 'legacy_id',
			width: 110,
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
			width: 200,
			render: (slug: string) => <code>{slug}</code>,
		},
		{
			title: '可遷入',
			dataIndex: 'importable',
			width: 130,
			render: (importable: boolean, record) =>
				importable ? (
					<Tag color="green">可遷入</Tag>
				) : (
					<Tooltip title={record.reason ?? '未知原因'}>
						<Tag color="red">不可遷入</Tag>
					</Tooltip>
				),
		},
		{
			title: '原因（不可遷入時）',
			dataIndex: 'reason',
			ellipsis: true,
			render: (reason: string | undefined) =>
				reason ? (
					<Text type="warning">{reason}</Text>
				) : (
					<Text type="secondary">—</Text>
				),
		},
		{
			title: '操作',
			key: 'actions',
			width: 130,
			fixed: 'right',
			render: (_: unknown, record: TLegacyShop) => (
				<Space size="small">
					<Tooltip
						title={
							record.importable
								? undefined
								: (record.reason ?? '此商店無法遷入')
						}
					>
						<Button
							type="link"
							size="small"
							icon={<ImportOutlined />}
							disabled={!record.importable}
							onClick={() => openImportModal(record)}
						>
							遷入
						</Button>
					</Tooltip>
				</Space>
			),
		},
	]

	return (
		<>
			<List title="遷入舊版一頁賣場" headerButtons={() => null}>
				<Table<TLegacyShop>
					rowKey="legacy_id"
					dataSource={shops}
					columns={columns}
					loading={isLoading || isFetching}
					pagination={{ pageSize: 20, showSizeChanger: false }}
					scroll={{ x: 950 }}
					locale={{
						emptyText: (
							<Empty
								image={Empty.PRESENTED_IMAGE_SIMPLE}
								description="目前沒有可遷入的舊版一頁賣場"
							/>
						),
					}}
				/>
			</List>
			<ImportModal
				legacyShop={activeShop}
				open={modalOpen}
				onClose={closeImportModal}
			/>
		</>
	)
}

export const ProfitMigrationList = memo(ListComponent)
