import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons'
import { CreateButton } from '@refinedev/antd'
import { Link, useGo } from '@refinedev/core'
import {
	App,
	Button,
	Empty,
	Form,
	Popconfirm,
	Space,
	Table,
	Tag,
	Tooltip,
	Typography,
	type FormInstance,
	type TableProps,
} from 'antd'
import {
	Card,
	defaultTableProps,
	getDefaultPaginationProps,
	NameId,
} from 'antd-toolkit'
import { FilterTags } from 'antd-toolkit/refine'
import dayjs from 'dayjs'
import { memo, useMemo, useState } from 'react'

import { useProfitPartnerList } from '@/pages/admin/ProfitPartner/hooks'
import type { TProfitPartner } from '@/pages/admin/ProfitPartner/types'
import {
	useProfitShopDelete,
	useProfitShopList,
} from '@/pages/admin/ProfitShop/hooks'
import {
	Filter,
	type TProfitShopFilterValues,
} from '@/pages/admin/ProfitShop/List/Filter'
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

/** FilterTags 的欄位名稱對映 */
const keyLabelMapper = (key: keyof TProfitShopFilterValues): string =>
	({
		search: '關鍵字',
		status: '狀態',
		partner_term_id: '分潤夥伴',
	})[key] ?? (key as string)

const ListComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitShopFilterValues>()

	/** 已按下「篩選」的條件（打字當下不即時過濾，與 useTable 的 submit 行為一致） */
	const [applied, setApplied] = useState<TProfitShopFilterValues>({})

	const { data, isLoading, isFetching } = useProfitShopList()
	const { data: partnerData } = useProfitPartnerList()
	const deleter = useProfitShopDelete()

	// 後端 V2Api 統一裹 {code, data} → axios body 在 result.data 裡 → 真正資料在 result.data.data
	const shops: TProfitShop[] = useMemo(() => {
		const list = data?.data?.data
		return Array.isArray(list) ? list : []
	}, [data])

	/** partner_term_id → 夥伴名稱（列表欄位與 FilterTags 共用） */
	const partnerNameMap = useMemo(() => {
		const list = partnerData?.data?.data
		const partners: TProfitPartner[] = Array.isArray(list) ? list : []
		return new Map(partners.map((p) => [p.id, p.name]))
	}, [partnerData])

	/**
	 * client-side 過濾
	 *
	 * 後端 `GET /profit-shops` 目前只支援 partner_term_id、且無分頁，
	 * 一次回傳全部賣場，因此篩選在前端做（見 `.claude/rules/admin-list-ui.rule.md` §3.2）。
	 * 待後端補 server-side 查詢後改送 query param。
	 */
	const filteredShops = useMemo(() => {
		const keyword = applied.search?.trim().toLowerCase() ?? ''
		const statuses = applied.status ?? []
		const partnerId = applied.partner_term_id

		return shops.filter((shop) => {
			if (statuses.length && !statuses.includes(shop.status)) return false
			if (partnerId && shop.partner_term_id !== partnerId) return false
			if (keyword) {
				const haystack = [
					String(shop.id),
					shop.title ?? '',
					shop.slug ?? '',
				]
					.join(' ')
					.toLowerCase()
				if (!haystack.includes(keyword)) return false
			}
			return true
		})
	}, [shops, applied])

	/** FilterTags 的值顯示對映（把 partner id / status 換成看得懂的文字） */
	const valueLabelMapper = useMemo(
		() =>
			(value: string, key?: keyof TProfitShopFilterValues): string => {
				if (key === 'partner_term_id') {
					return partnerNameMap.get(Number(value)) ?? `#${value}`
				}
				if (key === 'status') {
					return STATUS_TAG[value as TProfitShopStatus]?.label ?? value
				}
				return value
			},
		[partnerNameMap]
	)

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
			title: '名稱',
			dataIndex: 'title',
			width: 260,

			// NameId 已同時呈現名稱與 #id，因此不另設 ID 欄；
			// 用 Link 而非 onClick，讓使用者能中鍵 / Ctrl 點擊開新分頁
			render: (title: string, record: TProfitShop) => (
				<Link to={`/profit-shop/edit/${record.id}`}>
					<NameId name={title} id={String(record.id)} />
				</Link>
			),
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
			title: '分潤夥伴',
			dataIndex: 'partner_term_id',
			width: 160,
			ellipsis: true,
			render: (partnerTermId: number) => {
				const name = partnerNameMap.get(partnerTermId)
				return name ? (
					<Tooltip title={`#${partnerTermId}`}>
						<Text>{name}</Text>
					</Tooltip>
				) : (
					<Text type="secondary">#{partnerTermId}</Text>
				)
			},
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
		<>
			<Card title="篩選" className="mb-4">
				<Filter form={form} onFinish={setApplied} />
				<div className="mt-2">
					<FilterTags<TProfitShopFilterValues>
						form={{ ...form } as FormInstance<TProfitShopFilterValues>}
						keyLabelMapper={keyLabelMapper}
						valueLabelMapper={valueLabelMapper}
					/>
				</div>
			</Card>
			<Card>
				<CreateButton
					resource="profit-shop"
					icon={<PlusOutlined />}
					className="mb-4"
				>
					新增賣場
				</CreateButton>
				<Table<TProfitShop>
					{...(defaultTableProps as unknown as TableProps<TProfitShop>)}
					rowKey="id"
					dataSource={filteredShops}
					columns={columns}
					loading={isLoading || isFetching}
					pagination={{
						pageSize: 20,
						...getDefaultPaginationProps({ label: '賣場' }),
					}}
					scroll={{ x: 1210 }}
					locale={{
						emptyText: (
							<Empty
								image={Empty.PRESENTED_IMAGE_SIMPLE}
								description={
									shops.length
										? '沒有符合篩選條件的賣場'
										: '尚未建立任何分潤賣場'
								}
							>
								{!shops.length && (
									<CreateButton resource="profit-shop" icon={<PlusOutlined />}>
										建立第一個賣場
									</CreateButton>
								)}
							</Empty>
						),
					}}
				/>
			</Card>
		</>
	)
}

export const ProfitShopList = memo(ListComponent)
