import {
	DeleteOutlined,
	EditOutlined,
	PictureOutlined,
	PlusOutlined,
} from '@ant-design/icons'
import { CreateButton } from '@refinedev/antd'
import { Link, useGo } from '@refinedev/core'
import {
	App,
	Button,
	Empty,
	Form,
	Image,
	Popconfirm,
	Space,
	Table,
	Tooltip,
	Typography,
	type FormInstance,
	type TableProps,
} from 'antd'
import {
	Card,
	defaultImage,
	defaultTableProps,
	getDefaultPaginationProps,
	NameId,
} from 'antd-toolkit'
import { FilterTags } from 'antd-toolkit/refine'
import dayjs from 'dayjs'
import { memo, useMemo, useState } from 'react'

import {
	useProfitPartnerDelete,
	useProfitPartnerList,
} from '@/pages/admin/ProfitPartner/hooks'
import {
	Filter,
	type TProfitPartnerFilterValues,
} from '@/pages/admin/ProfitPartner/List/Filter'
import type { TProfitPartner } from '@/pages/admin/ProfitPartner/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Text } = Typography

/** FilterTags 的欄位名稱對映 */
const keyLabelMapper = (key: keyof TProfitPartnerFilterValues): string =>
	({
		search: '關鍵字',
		has_image: '夥伴圖片',
	})[key] ?? (key as string)

/** FilterTags 的值顯示對映 */
const valueLabelMapper = (
	value: string,
	key?: keyof TProfitPartnerFilterValues
): string => {
	if (key === 'has_image') {
		return 'yes' === value ? '已設定' : '未設定'
	}
	return value
}

const ListComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitPartnerFilterValues>()

	/** 已按下「篩選」的條件（打字當下不即時過濾，與 useTable 的 submit 行為一致） */
	const [applied, setApplied] = useState<TProfitPartnerFilterValues>({})

	const { data, isLoading, isFetching } = useProfitPartnerList()
	const deleter = useProfitPartnerDelete()

	// V2Api 統一裹 {code, data}
	const partners: TProfitPartner[] = useMemo(() => {
		const list = data?.data?.data
		return Array.isArray(list) ? list : []
	}, [data])

	/**
	 * client-side 過濾
	 *
	 * 後端 `GET /profit-partners` 無分頁 / 關鍵字查詢，一次回傳全部夥伴，
	 * 因此篩選在前端做（見 `.claude/rules/admin-list-ui.rule.md` §3.2 路線 B）。
	 */
	const filteredPartners = useMemo(() => {
		const keyword = applied.search?.trim().toLowerCase() ?? ''
		const hasImage = applied.has_image

		return partners.filter((partner) => {
			if (hasImage) {
				const owned = !!partner.image_id
				if ('yes' === hasImage && !owned) return false
				if ('no' === hasImage && owned) return false
			}
			if (keyword) {
				const haystack = [
					String(partner.id),
					partner.name ?? '',
					partner.slug ?? '',
					partner.contact_email ?? '',
				]
					.join(' ')
					.toLowerCase()
				if (!haystack.includes(keyword)) return false
			}
			return true
		})
	}, [partners, applied])

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
			title: '夥伴圖片',
			dataIndex: 'image_url',
			width: 100,
			align: 'center',
			render: (url: string | null) =>
				url ? (
					<Image
						src={url}
						fallback={defaultImage}
						alt="夥伴圖片"
						className="w-10 h-10 rounded-full object-cover"
						wrapperClassName="w-10 h-10 overflow-hidden rounded-full"
					/>
				) : (
					<span
						className="w-10 h-10 rounded-full bg-gray-100 text-gray-300 inline-flex items-center justify-center"
						aria-label="尚未設定夥伴圖片"
					>
						<PictureOutlined />
					</span>
				),
		},
		{
			title: '名稱',
			dataIndex: 'name',
			width: 260,

			// NameId 已同時呈現名稱與 #id，因此不另設 ID 欄；
			// 用 Link 而非 onClick，讓使用者能中鍵 / Ctrl 點擊開新分頁
			render: (name: string, record: TProfitPartner) => (
				<Link to={`/profit-partner/edit/${record.id}`}>
					<NameId name={name} id={String(record.id)} />
				</Link>
			),
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
								<div className="mt-1 text-xs text-red-500">
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
		<>
			<Card title="篩選" className="mb-4">
				<Filter form={form} onFinish={setApplied} />
				<div className="mt-2">
					<FilterTags<TProfitPartnerFilterValues>
						form={{ ...form } as FormInstance<TProfitPartnerFilterValues>}
						keyLabelMapper={keyLabelMapper}
						valueLabelMapper={valueLabelMapper}
					/>
				</div>
			</Card>
			<Card>
				<CreateButton
					resource="profit-partner"
					icon={<PlusOutlined />}
					className="mb-4"
				>
					建立夥伴
				</CreateButton>
				<Table<TProfitPartner>
					{...(defaultTableProps as unknown as TableProps<TProfitPartner>)}
					rowKey="id"
					dataSource={filteredPartners}
					columns={columns}
					loading={isLoading || isFetching}
					pagination={{
						pageSize: 20,
						...getDefaultPaginationProps({ label: '夥伴' }),
					}}
					scroll={{ x: 1100 }}
					locale={{
						emptyText: (
							<Empty
								image={Empty.PRESENTED_IMAGE_SIMPLE}
								description={
									partners.length
										? '沒有符合篩選條件的夥伴'
										: '尚未建立任何分潤夥伴'
								}
							>
								{!partners.length && (
									<CreateButton
										resource="profit-partner"
										icon={<PlusOutlined />}
									>
										建立第一個夥伴
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

export const ProfitPartnerList = memo(ListComponent)
