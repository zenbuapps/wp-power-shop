import { ExportOutlined, ImportOutlined } from '@ant-design/icons'
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
import { Card, useEnv } from 'antd-toolkit'
import { memo, useMemo, useState } from 'react'

import { useLegacyShopList } from '@/pages/admin/ProfitMigration/hooks'
import { ImportModal } from '@/pages/admin/ProfitMigration/ImportModal'
import type { TLegacyShop } from '@/pages/admin/ProfitMigration/types'

const { Text, Paragraph } = Typography

/**
 * 舊版一頁賣場的 wp-admin 管理介面
 *
 * 舊版是 `power-shop` CPT（含連字號），與新版 `powershop` 是兩個不同的 post type。
 */
const LEGACY_ADMIN_PATH = '/wp-admin/edit.php?post_type=power-shop'

/**
 * 新舊版差異說明
 *
 * 原本住在獨立的「一頁賣場」選單頁（`Marketing/OneShop.tsx`）——那頁是一個雙入口
 * 導覽頁（新版 Profit Shop / 舊版 wp-admin 各一顆按鈕），但「介紹新版」這件事對已經
 * 站在後台選單裡的站長沒有價值（分潤賣場就在隔壁），真正有用的只有「舊版管理介面在哪」
 * 與「為什麼該遷移」。因此整頁併進本頁，選單項目移除。
 */
const NEW_VERSION_FEATURES = [
	'可指定分潤夥伴與分潤比例，並自動記錄每筆訂單的分潤金額',
	'價格覆寫支援到「商品變體」層級，另可設定灌水購買人數',
	'加購鏈路有三道閘門與簽章保護，前台無法竄改分潤價',
	'夥伴可自助登入查看自己的結算報表',
]

const ListComponent = () => {
	const { data, isLoading, isFetching } = useLegacyShopList()
	const { SITE_URL } = useEnv()

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
			width: 180,
			render: (slug: string) => <code>{slug}</code>,
		},
		{
			// 匯入是不可逆的，站長按下去之前應該先知道「會帶進去幾個商品」。
			// 0 筆通常代表舊版賣場本來就是空的，仍可匯入，但值得提醒。
			title: '商品數',
			dataIndex: 'product_count',
			width: 100,
			align: 'right',
			render: (count: number) =>
				count > 0 ? (
					<Text>{count}</Text>
				) : (
					<Tooltip title="此舊版賣場沒有商品，匯入後會是一個空賣場">
						<Text type="secondary">0</Text>
					</Tooltip>
				),
		},
		{
			title: '可遷入',
			dataIndex: 'importable',
			width: 110,
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
			render: (reason: string | null) =>
				reason ? (
					<Tooltip title={reason}>
						<Text type="warning">{reason}</Text>
					</Tooltip>
				) : (
					<Text type="secondary">—</Text>
				),
		},
		{
			title: '操作',
			key: 'actions',
			width: 130,
			fixed: 'right',
			render: (_: unknown, record: TLegacyShop) =>
				record.importable ? (
					<Space size="small">
						<Button
							type="link"
							size="small"
							icon={<ImportOutlined />}
							onClick={() => openImportModal(record)}
						>
							遷入
						</Button>
					</Space>
				) : (
					// antd v5 不會對 disabled <button> 派發滑鼠事件，Tooltip 直接包 disabled
					// Button 提示永遠不會出現（見 react.rule.md §2）。外層 span 承接事件、
					// 內層 pointer-events-none 讓事件穿透，停用原因才顯示得出來。
					<Tooltip title={record.reason ?? '此商店無法遷入'}>
						<span className="inline-block cursor-not-allowed">
							<Button
								type="link"
								size="small"
								icon={<ImportOutlined />}
								disabled
								className="pointer-events-none"
							>
								遷入
							</Button>
						</span>
					</Tooltip>
				),
		},
	]

	return (
		<>
			<List title="遷入舊版一頁賣場" headerButtons={() => null}>
				<Card title="關於遷入舊版" className="mb-4">
					<Paragraph type="secondary" className="mb-2">
						舊版「一頁賣場」將逐步淘汰。遷入會<strong>新建</strong>
						一個分潤賣場並帶入舊版的商品與價格設定，舊版資料保留不動；
						新賣場預設為草稿，確認無誤後再自行發佈。
					</Paragraph>
					<Paragraph className="mb-2">
						遷入後可使用的新功能：
						<ul className="list-disc pl-5 mt-1 mb-0">
							{NEW_VERSION_FEATURES.map((feature) => (
								<li key={feature}>{feature}</li>
							))}
						</ul>
					</Paragraph>
					<Button
						size="small"
						icon={<ExportOutlined />}
						href={`${SITE_URL}${LEGACY_ADMIN_PATH}`}
						target="_blank"
						rel="noopener noreferrer"
					>
						開啟舊版一頁賣場管理介面
					</Button>
				</Card>

				<Table<TLegacyShop>
					rowKey="legacy_id"
					dataSource={shops}
					columns={columns}
					loading={isLoading || isFetching}
					pagination={{ pageSize: 20, showSizeChanger: false }}
					scroll={{ x: 1050 }}
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
