import {
	BarChartOutlined,
	CopyOutlined,
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
	buildProfitShopShortcode,
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
 * 「開啟」的停用原因；回傳 null 代表可開啟
 *
 * 只負責獨立頁面模式 —— Shortcode 模式的操作欄改渲染「複製」按鈕，
 * 不再走這個函式（見 columns 的 actions 欄註解）。
 */
export const resolveOpenShopBlockedReason = (shop: TProfitShop): string | null => {
	if (!shop.slug) {
		return '此賣場尚未設定永久連結，無法組出前台網址'
	}

	// 後端對 trashed 賣場一律回 404，讓按鈕可點只會把站長送到錯誤頁
	if (PROFIT_SHOP_STATUS.TRASH === shop.status) {
		return '已刪除的賣場無法開啟'
	}
	return null
}

/**
 * 「複製 shortcode」的停用原因；回傳 null 代表可複製
 *
 * 刻意**不**檢查 slug —— 短碼以 `id` 定位賣場（見 `buildProfitShopShortcode`），
 * 沒設永久連結一樣複製得出可用的短碼。這是它與「開啟」判斷條件的唯一差異，
 * 別為了 DRY 把兩個函式併成一個。
 */
export const resolveCopyShortcodeBlockedReason = (
	shop: TProfitShop
): string | null => {
	// 後端 shortcode callback 對已刪除的賣場回空字串：短碼複製得出來、貼上去卻什麼都
	// 不顯示，且頁面不會有任何錯誤提示。與「開啟」按鈕同等誠實，先在這裡擋掉。
	if (PROFIT_SHOP_STATUS.TRASH === shop.status) {
		return '已刪除的賣場無法透過短碼呈現，貼上後不會顯示任何內容'
	}
	return null
}

/**
 * 「查看報表」的停用原因；回傳 null 代表可查看
 *
 * ⚠️ 刻意**不**檢查賣場本身的 status —— 報表屬於**分潤夥伴**（`/profit-report/{partner-slug}/`），
 * 不屬於賣場。夥伴的報表涵蓋他名下所有賣場的分潤，賣場是草稿或已刪除都不影響那張報表
 * 存不存在、也不影響其他賣場的數字。這正是它與 `resolveOpenShopBlockedReason` /
 * `resolveCopyShortcodeBlockedReason` 的關鍵差異——三個函式看起來很像，
 * 別為了 DRY 併成一個，那會讓草稿 / 已刪除賣場白白失去夥伴報表的入口。
 *
 * @param partner   該賣場的分潤夥伴（找不到時為 undefined）
 * @param isLoading 夥伴列表是否仍在載入（載入中不可判定為「找不到」）
 */
export const resolveViewReportBlockedReason = (
	partner: TProfitPartner | undefined,
	isLoading: boolean
): string | null => {
	// 載入中先停用，但訊息要與「真的找不到」區分——否則每次進頁面都會閃一次
	// 「夥伴已被刪除」的假警告
	if (isLoading) {
		return '分潤夥伴資料載入中，請稍候'
	}

	if (!partner) {
		return '找不到此賣場的分潤夥伴（可能已被刪除）'
	}

	// report_url 是後端唯讀衍生欄位；理論上有 slug 就一定組得出來，
	// 但契約是 nullable，仍照實處理而非用 `!` 斷言掉
	if (!partner.report_url) {
		return '此分潤夥伴尚未產生報表網址'
	}

	return null
}

/**
 * Table `scroll.x`：各欄 width 總和
 *
 * 名稱 260 + 呈現模式 120 + 狀態 100 + 分潤夥伴 160 + 分潤 90 + 商品數 90
 * + 更新時間 170 + 操作 300。改動任一欄寬度時要一起更新，否則橫向捲軸會算錯。
 */
const COLUMNS_TOTAL_WIDTH = 1290

/** FilterTags 的欄位名稱對映 */
const keyLabelMapper = (key: keyof TProfitShopFilterValues): string =>
	({
		search: '關鍵字',
		status: '狀態',
		partner_term_id: '分潤夥伴',
	})[key] ?? (key as string)

const ListComponent = () => {
	const go = useGo()
	const { notification, message } = App.useApp()
	const { SITE_URL } = useEnv()
	const [form] = Form.useForm<TProfitShopFilterValues>()

	/** 已按下「篩選」的條件（打字當下不即時過濾，與 useTable 的 submit 行為一致） */
	const [applied, setApplied] = useState<TProfitShopFilterValues>({})

	const { data, isLoading, isFetching } = useProfitShopList()
	const { data: partnerData, isLoading: isPartnerLoading } =
		useProfitPartnerList()
	const deleter = useProfitShopDelete()

	// 後端 V2Api 統一裹 {code, data} → axios body 在 result.data 裡 → 真正資料在 result.data.data
	const shops: TProfitShop[] = useMemo(() => {
		const list = data?.data?.data
		return Array.isArray(list) ? list : []
	}, [data])

	/**
	 * partner_term_id → 夥伴物件（列表欄位、FilterTags、「查看報表」按鈕共用）
	 *
	 * 存整個物件而非只存 name：「查看報表」需要 `report_url`（後端唯讀衍生欄位），
	 * 前端不可自行以 report slug 拼接網址（見 `TProfitPartner.report_url` 的註解）。
	 */
	const partnerMap = useMemo(() => {
		const list = partnerData?.data?.data
		const partners: TProfitPartner[] = Array.isArray(list) ? list : []
		return new Map(partners.map((p) => [p.id, p]))
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
					return partnerMap.get(Number(value))?.name ?? `#${value}`
				}
				if (key === 'status') {
					return STATUS_TAG[value as TProfitShopStatus]?.label ?? value
				}
				return value
			},
		[partnerMap]
	)

	/**
	 * 複製賣場 shortcode 到剪貼簿
	 *
	 * 兩種失敗都由同一個 catch 接住，因為對站長而言後續動作完全相同（自己選取複製）：
	 *   1. 非 HTTPS / 舊瀏覽器沒有 `navigator.clipboard` —— 讀 `.writeText` 就
	 *      直接 throw TypeError（同步拋，但在 async 函式的 try 內仍會被接到）
	 *   2. API 存在但使用者 / 瀏覽器拒絕剪貼簿權限 —— `writeText` 回傳的 promise reject
	 *
	 * ⚠️ 刻意**不** fallback 到 `document.execCommand('copy')`（該 API 已廢棄，
	 * 且在部分瀏覽器會靜默失敗——使用者以為複製到了、貼出來卻是空的）。
	 * 改為把短碼原文攤在 notification 上
	 * 讓站長自行選取 —— 只丟一句「複製失敗」等於把人留在原地。
	 *
	 * `duration: 0` 讓通知不自動消失，否則站長還沒選取完字就被收走。
	 * 顯示用的 `<Text code>` 也刻意不使用 antd `Typography` 的 `copyable` prop：
	 * 那個 prop 內部走 `copy-to-clipboard`，會偷偷把 execCommand fallback 帶回來。
	 */
	const handleCopyShortcode = async (shop: TProfitShop) => {
		const shortcode = buildProfitShopShortcode(shop.id)

		try {
			await navigator.clipboard.writeText(shortcode)
			message.success('已複製 shortcode')
		} catch {
			notification.error({
				message: '無法自動複製 shortcode',
				description: (
					<>
						<div>
							瀏覽器不支援或拒絕了剪貼簿存取（非 HTTPS
							連線也會如此）。請手動選取下方短碼複製：
						</div>
						<div className="mt-2">
							<Text code copyable={false}>
								{shortcode}
							</Text>
						</div>
					</>
				),
				duration: 0,
			})
		}
	}

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

	/**
	 * 操作欄的第一顆按鈕：依呈現模式二分
	 *
	 * - 獨立頁面模式 → 「開啟」（連到 `/profit-shop/{slug}/` 前台網址）
	 * - Shortcode 模式 → 「複製」（把短碼複製到剪貼簿，站長自行貼進頁面）
	 *
	 * ⚠️ Shortcode 模式不顯示「開啟」，**不代表該賣場沒有前台網址**：後端
	 * `ProfitShopRenderer::maybe_render` 只看 slug 與 status、**完全不檢查 mode**，
	 * 所以 Shortcode 賣場的 `/profit-shop/{slug}/` 一樣開得起來、也一樣會被搜尋引擎索引
	 * （`components/tabs/BasicTab.tsx` 的據實告知警語因此仍然成立，勿因為這裡少了按鈕
	 * 就把那段警語拿掉）。這裡只是不提供「直接開啟」的捷徑——UI 入口有無，與網址本身
	 * 是否存在，是兩件不能混為一談的事。
	 */
	const renderPrimaryAction = (record: TProfitShop) => {
		if (PROFIT_SHOP_MODE.SHORTCODE === record.mode) {
			const copyBlockedReason = resolveCopyShortcodeBlockedReason(record)

			// 停用分支的 span + pointer-events-none 與下方「開啟」同一個 antd v5 陷阱：
			// Tooltip 直接包 disabled Button 不會顯示（見下方註解第 2 點）。
			// 可用分支的 Tooltip 則是讓站長先看到自己即將複製的內容，
			// 避免「按了、但不確定到底複製到什麼」。
			return copyBlockedReason ? (
				<Tooltip title={copyBlockedReason}>
					<span className="inline-block cursor-not-allowed">
						<Button
							type="link"
							size="small"
							icon={<CopyOutlined />}
							className="pointer-events-none"
							disabled
						>
							複製
						</Button>
					</span>
				</Tooltip>
			) : (
				<Tooltip title={`複製短碼 ${buildProfitShopShortcode(record.id)}`}>
					<Button
						type="link"
						size="small"
						icon={<CopyOutlined />}
						onClick={() => handleCopyShortcode(record)}
					>
						複製
					</Button>
				</Tooltip>
			)
		}

		const blockedReason = resolveOpenShopBlockedReason(record)

		// 與 PHP `RewriteRules::SHOP_REWRITE_PREFIX` 對齊的單一真相，勿 hardcode 前綴
		const shopUrl = `${SITE_URL}/${PROFIT_SHOP_URL_PREFIX}/${record.slug}/`

		/*
		 * 「開啟」拆成停用 / 可用兩條分支，而不是同一顆 Button 併給
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
		return blockedReason ? (
			<Tooltip title={blockedReason}>
				<span className="inline-block cursor-not-allowed">
					<Button
						type="link"
						size="small"
						icon={<ExportOutlined />}
						className="pointer-events-none"
						disabled
					>
						開啟
					</Button>
				</span>
			</Tooltip>
		) : (
			<Button
				type="link"
				size="small"
				icon={<ExportOutlined />}
				href={shopUrl}
				target="_blank"
				rel="noopener noreferrer"
			>
				開啟
			</Button>
		)
	}

	/**
	 * 操作欄的「查看報表」：開啟該賣場所屬**分潤夥伴**的報表頁
	 *
	 * 網址一律取自後端衍生的 `partner.report_url`，前端零拼接 —— 前綴的真相來源只有
	 * PHP 端（`RewriteRules::get_report_slug()`，即註冊 rewrite rule 所用的同一個來源），
	 * 在前端複製一份遲早分家（詳見 `TProfitPartner.report_url`）。
	 *
	 * ⚠️ 報表頁是 **partner 自助入口**（cookie 認證），站長點進去會看到夥伴的登入畫面，
	 * 而不是報表內容 —— 這是預期行為，此按鈕的用途是「確認網址 / 把連結交給夥伴」。
	 * 站長端若要看分潤數據，目前沒有對應頁面（partner-reports/* 三個 endpoint
	 * 全部鎖 partner_token，admin nonce 走不了，見 `profit-shop.rule.md` §6 雙軌制）。
	 *
	 * 停用 / 可用拆兩條 render 分支的理由與「開啟」完全相同（antd v5 的兩個陷阱），
	 * 見下方 `renderPrimaryAction` 的註解。
	 */
	const renderReportAction = (record: TProfitShop) => {
		const partner = partnerMap.get(record.partner_term_id)
		const blockedReason = resolveViewReportBlockedReason(
			partner,
			isPartnerLoading
		)

		return blockedReason ? (
			<Tooltip title={blockedReason}>
				<span className="inline-block cursor-not-allowed">
					<Button
						type="link"
						size="small"
						icon={<BarChartOutlined />}
						className="pointer-events-none"
						disabled
					>
						報表
					</Button>
				</span>
			</Tooltip>
		) : (
			<Tooltip title={`開啟「${partner?.name}」的分潤報表頁（夥伴需自行登入）`}>
				<Button
					type="link"
					size="small"
					icon={<BarChartOutlined />}
					href={partner?.report_url ?? undefined}
					target="_blank"
					rel="noopener noreferrer"
				>
					報表
				</Button>
			</Tooltip>
		)
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
				const name = partnerMap.get(partnerTermId)?.name
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
			width: 300,
			fixed: 'right',
			render: (_: unknown, record: TProfitShop) => {
				return (
					<Space size="small">
						{renderPrimaryAction(record)}
						{renderReportAction(record)}
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
