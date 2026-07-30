import {
	DeleteOutlined,
	EditOutlined,
	ExportOutlined,
	PlusOutlined,
} from '@ant-design/icons'
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
	useEnv,
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
	PROFIT_SHOP_MODE,
	PROFIT_SHOP_STATUS,
	PROFIT_SHOP_URL_PREFIX,
	type TProfitShop,
	type TProfitShopMode,
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

/** 呈現模式顯示對映 */
const MODE_TAG: Record<TProfitShopMode, { color: string; label: string }> = {
	[PROFIT_SHOP_MODE.PAGE]: { color: 'blue', label: '獨立頁面' },
	[PROFIT_SHOP_MODE.SHORTCODE]: { color: 'purple', label: 'Shortcode' },
}

/**
 * 「開啟商店」的停用原因；回傳 null 代表可開啟
 *
 * ⚠️ Shortcode 的文案刻意**不**寫成「沒有前台網址」：後端
 * `ProfitShopRenderer::maybe_render` 只看 slug 與 status、**完全不檢查 mode**，
 * 所以 Shortcode 模式的 `/profit-shop/{slug}/` 一樣開得起來、也會被索引
 * （同 `components/tabs/BasicTab.tsx` 的據實告知警語）。這裡只是不提供「直接開啟」
 * 的入口，不能暗示站長該網址不存在。
 */
const resolveOpenShopBlockedReason = (shop: TProfitShop): string | null => {
	if (!shop.slug) {
		return '此賣場尚未設定永久連結，無法組出前台網址'
	}

	// 後端對 trashed 賣場一律回 404，讓按鈕可點只會把站長送到錯誤頁
	if (PROFIT_SHOP_STATUS.TRASH === shop.status) {
		return '已刪除的賣場無法開啟'
	}
	if (PROFIT_SHOP_MODE.SHORTCODE === shop.mode) {
		return 'Shortcode 模式的賣場以短碼嵌入頁面呈現，如需直接開啟公開網址請改為獨立頁面模式'
	}
	return null
}

/**
 * Table `scroll.x`：各欄 width 總和
 *
 * 名稱 260 + 呈現模式 120 + 狀態 100 + 分潤夥伴 160 + 分潤 90 + 商品數 90
 * + 更新時間 170 + 操作 240。改動任一欄寬度時要一起更新，否則橫向捲軸會算錯。
 */
const COLUMNS_TOTAL_WIDTH = 1230

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
	const { SITE_URL } = useEnv()
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
			title: '呈現模式',
			dataIndex: 'mode',
			width: 120,
			render: (mode: TProfitShopMode) => {
				const tag = MODE_TAG[mode] ?? MODE_TAG[PROFIT_SHOP_MODE.PAGE]
				return <Tag color={tag.color}>{tag.label}</Tag>
			},
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
			width: 240,
			fixed: 'right',
			render: (_: unknown, record: TProfitShop) => {
				const blockedReason = resolveOpenShopBlockedReason(record)

				// 與 PHP `RewriteRules::SHOP_REWRITE_PREFIX` 對齊的單一真相，勿 hardcode 前綴
				const shopUrl = `${SITE_URL}/${PROFIT_SHOP_URL_PREFIX}/${record.slug}/`

				/*
				 * 「開啟商店」拆成停用 / 可用兩條分支，而不是同一顆 Button 併給
				 * `href` 與 `disabled`：
				 *
				 * 1. 停用分支**整顆按鈕不帶 href** —— DOM 裡沒有網址，左鍵 / 中鍵 /
				 *    Ctrl 點擊 / 右鍵「在新分頁開啟」全都無從觸發。antd 5.26.1 雖然
				 *    確實會在 disabled 時把 href 拿掉（button.js:258），但那是內部實作，
				 *    一旦版本行為變動就是個安靜的「停用卻仍可點開」漏洞。
				 * 2. 停用分支外層的 span + `pointer-events-none` 是 antd v5 讓 Tooltip
				 *    能在停用按鈕上顯示的必要條件 —— v5 移除了 v4 的 disabled 相容包裝，
				 *    而瀏覽器不會對 disabled 的 <button> 派發滑鼠事件，少了這層就看不到
				 *    停用原因（而停用原因正是這顆按鈕的重點）。
				 * 3. 可用分支的 `rel="noopener noreferrer"` 防 reverse tabnabbing，
				 *    與 PHP 端 `CartItemMetaDisplay` 同一條紀律。
				 */
				return (
					<Space size="small">
						{blockedReason ? (
							<Tooltip title={blockedReason}>
								<span className="inline-block cursor-not-allowed">
									<Button
										type="link"
										size="small"
										icon={<ExportOutlined />}
										className="pointer-events-none"
										disabled
									>
										開啟商店
									</Button>
								</span>
							</Tooltip>
						) : (
							<Tooltip title={shopUrl}>
								<Button
									type="link"
									size="small"
									icon={<ExportOutlined />}
									href={shopUrl}
									target="_blank"
									rel="noopener noreferrer"
								>
									開啟商店
								</Button>
							</Tooltip>
						)}
						<Button
							type="link"
							size="small"
							icon={<EditOutlined />}
							onClick={() =>
								go({
									to: {
										resource: 'profit-shop',
										action: 'edit',
										id: record.id,
									},
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
				)
			},
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
					scroll={{ x: COLUMNS_TOTAL_WIDTH }}
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
