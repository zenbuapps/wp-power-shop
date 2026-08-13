import {
	BarChartOutlined,
	DeleteOutlined,
	EditOutlined,
	KeyOutlined,
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
	useCopyPartnerPassword,
	useProfitPartnerDelete,
	useProfitPartnerList,
} from '@/pages/admin/ProfitPartner/hooks'
import {
	Filter,
	type TProfitPartnerFilterValues,
} from '@/pages/admin/ProfitPartner/List/Filter'
import type { TProfitPartner } from '@/pages/admin/ProfitPartner/types'
import { resolveCopyPartnerPasswordBlockedReason } from '@/pages/admin/ProfitPartner/utils'
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

/**
 * 「報表」的停用原因；回傳 null 代表可開啟
 *
 * ⚠️ 這一頁的報表按鈕**不帶賣場篩選**——本列就是夥伴本人，涵蓋他名下所有賣場。
 * （`#/profit-shop` 那一頁的報表按鈕會附掛 `shop_ids` 預先篩選到該賣場，
 * 見 `ProfitShop/List` 的 `renderReportAction`。）
 *
 * `report_url` 是後端唯讀衍生欄位；理論上有 slug 就一定組得出來，
 * 但契約是 nullable，仍照實處理而非用 `!` 斷言掉。
 *
 * @param {TProfitPartner} record 夥伴資料
 * @return {string|null} 不可用的原因；可用時回 null
 */
const resolveOpenReportBlockedReason = (
	record: TProfitPartner
): string | null => {
	if (!record.report_url) {
		return '此分潤夥伴尚未產生報表網址'
	}
	return null
}

/**
 * Table `scroll.x`：各欄 width 總和
 *
 * 夥伴圖片 100 + 名稱 260 + Slug 200 + 聯絡 Email 220 + 建立時間 170 + 操作 340。
 * 改動任一欄寬度時要一起更新，否則橫向捲軸會算錯。
 *
 * ⚠️ 改動前先讀 `ACTIONS_COLUMN_WIDTH` 的註解——操作欄寬度算錯的後果不是「有點擠」，
 * 是最右邊的按鈕被裁掉、點不到，而且沒有任何錯誤訊息。
 */
const COLUMNS_TOTAL_WIDTH = 1290

/**
 * 操作欄寬度：[報表][複製密碼][編輯][刪除] 四顆 type="link" size="small" 按鈕
 *
 * 依 antd 5.26.1 實算：paddingInline 7×2 + icon 14 + gap 8 + 14px×字數
 *   → 2 字 = 64px、4 字 = 92px
 * 加總：64（報表）+ 92（複製密碼）+ 64（編輯）+ 64（刪除）
 *     + Space size="small" 的 3×8 gap + Table size="small" 的 cell padding 16
 *     = 324 → 取 340，留一顆 2 字按鈕的餘裕（不是留 4px 這種一加東西就爆的餘裕）。
 *
 * ⚠️ 算不夠時的實際行為（不是自動換行、也不是欄位自己變寬）：
 * rc-table 在 `fixed` 欄配數字 `scroll.x` 時解析為 `table-layout: fixed`，
 * colgroup 寬度是硬的；`Space` 是 inline-flex 且未設 `flex-wrap` ⇒ 不換行；
 * `<td>` 沒有 `overflow: hidden` ⇒ 內容直接向右溢出 `position: sticky; right: 0`
 * 的儲存格，被捲動容器裁掉 ⇒ **「刪除」被切一半、點不到**。
 * 而且只在「容器寬度 < scroll.x」時才會出現（1440px 筆電配 WP 側邊欄就會踩到），
 * 開發者在寬螢幕上完全看不到。
 */
const ACTIONS_COLUMN_WIDTH = 340

const ListComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitPartnerFilterValues>()

	/** 已按下「篩選」的條件（打字當下不即時過濾，與 useTable 的 submit 行為一致） */
	const [applied, setApplied] = useState<TProfitPartnerFilterValues>({})

	const { data, isLoading, isFetching } = useProfitPartnerList()
	const deleter = useProfitPartnerDelete()
	const copyPartnerPassword = useCopyPartnerPassword()

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
					/*
					 * 刻意用原生 <img> 而非 antd <Image>：列表只需要「看到頭像」。
					 * antd Image 會額外掛上點擊放大的預覽層（遮罩 + portal + 鍵盤事件），
					 * 在密集列表裡那是誤觸來源而非功能，也多出好幾層無謂的 DOM。
					 */
					<img
						src={url}
						alt="夥伴圖片"
						className="w-10 h-10 rounded-full object-cover inline-block"
						onError={(e) => {
							// 換 src 之前**必須先清掉 handler**：fallback 圖若本身也載入失敗，
							// onError 會再次觸發、再換上同一張失敗的圖，形成無限迴圈
							// （畫面看起來只是圖沒出來，實際上瀏覽器在狂打同一個請求）。
							e.currentTarget.onerror = null
							e.currentTarget.src = defaultImage
						}}
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
			/*
			 * 按鈕順序：[報表][複製密碼][編輯][刪除]
			 *
			 * 與 `#/profit-shop` 共用同一條不變量——**分享群組在前、編輯次之、
			 * 刪除永遠最後**。兩頁的「編輯與刪除永遠是最後兩顆」是站長最容易記住、
			 * 也最不會因未來再加按鈕而位移的錨點（新按鈕一律往前面的群組加）。
			 */
			title: '操作',
			key: 'actions',
			width: ACTIONS_COLUMN_WIDTH,
			fixed: 'right',
			render: (_: unknown, record: TProfitPartner) => {
				const reportBlockedReason = resolveOpenReportBlockedReason(record)

				// 本頁一列就是一個夥伴，record 必然存在（沒資料時不會 render 任何列），
				// 因此查詢狀態恆為 'ready'。三態是為了跨頁共用同一個判定函式
				// （`#/profit-shop` 需要區分載入中 / 查詢失敗 / 真的找不到），
				// 見 resolveCopyPartnerPasswordBlockedReason 的 docblock。
				const copyBlockedReason = resolveCopyPartnerPasswordBlockedReason(
					record,
					'ready'
				)

				return (
					<Space size="small">
						{/*
						 * ⚠️ antd v5 的 <Tooltip> 直接包 disabled <Button> **不會顯示**
						 * （v5 拿掉了 v4 的 disabled 相容包裝，瀏覽器又不對 disabled button
						 * 派發滑鼠事件）。停用分支必須外包一層 <span> 承接事件，
						 * 按鈕本身加 pointer-events-none 讓事件穿透上去。
						 * 詳見 .claude/rules/react.rule.md §2。
						 *
						 * 停用分支也**整顆不帶 href** —— DOM 裡沒有網址，左鍵 / 中鍵 /
						 * Ctrl 點擊 / 右鍵「在新分頁開啟」全都無從觸發。antd 雖然目前
						 * 會在 disabled 時自行拿掉 href，但那是內部實作，
						 * 版本行為一變就是個安靜的「停用卻仍可點開」漏洞。
						 *
						 * ⚠️ 「報表」的兩條分支**刻意不對稱**（站長指定，2026-08-13）：
						 * 可用分支**不掛 Tooltip**（滑過每一列都彈說明很吵），
						 * 停用分支**保留**——那是「為什麼這顆是灰的」的唯一揭露管道，
						 * 拿掉就只剩一顆沒有理由的灰按鈕（`admin-list-ui.rule.md`
						 * §1「8 的補充」明文反對）。**不是漏改。**
						 *
						 * 範圍僅限「報表」：`#/profit-shop` 的「報表」同此處理，
						 * 但**同一列「複製密碼」的 tooltip 兩條分支都保留**——
						 * 站長只指定了報表，而複製密碼的可用分支 tooltip 帶著夥伴名稱，
						 * 在賣場列表上是必要的身分揭露（一列是賣場、密碼卻屬於夥伴）。
						 */}
						{reportBlockedReason ? (
							<Tooltip title={reportBlockedReason}>
								<span className="inline-block cursor-not-allowed">
									<Button
										type="link"
										size="small"
										disabled
										icon={<BarChartOutlined />}
										className="pointer-events-none"
									>
										報表
									</Button>
								</span>
							</Tooltip>
						) : (
							<Button
								type="link"
								size="small"
								icon={<BarChartOutlined />}
								href={record.report_url ?? undefined}
								target="_blank"
								rel="noopener noreferrer"
							>
								報表
							</Button>
						)}

						{/*
						 * 密碼鈕用 KeyOutlined 而非 CopyOutlined：`#/profit-shop` 的操作欄
						 * 第一顆在 shortcode 模式下也是「複製」（CopyOutlined），
						 * 兩顆同 icon、同「複製…」開頭只隔一顆按鈕，按錯的代價**不對稱**——
						 * 誤按短碼只是貼出無害短碼，誤按密碼則是把明文密碼放進剪貼簿，
						 * 而站長當下的下一步正是「貼進公開頁面並發佈」。
						 * 兩頁必須用同一個 icon，否則同一個動作在兩頁長得不一樣。
						 */}
						{copyBlockedReason ? (
							<Tooltip title={copyBlockedReason}>
								<span className="inline-block cursor-not-allowed">
									<Button
										type="link"
										size="small"
										disabled
										icon={<KeyOutlined />}
										className="pointer-events-none"
									>
										複製密碼
									</Button>
								</span>
							</Tooltip>
						) : (
							<Tooltip title={`複製「${record.name}」的報表登入密碼`}>
								<Button
									type="link"
									size="small"
									icon={<KeyOutlined />}
									onClick={() => copyPartnerPassword(record)}
								>
									複製密碼
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
				)
			},
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
					scroll={{ x: COLUMNS_TOTAL_WIDTH }}
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
