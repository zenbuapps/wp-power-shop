import {
	DownloadOutlined,
	ExclamationCircleOutlined,
	RollbackOutlined,
	StopOutlined,
	WalletOutlined,
} from '@ant-design/icons'
import { Link } from '@refinedev/core'
import {
	App,
	Button,
	Empty,
	Form,
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
import { useWoocommerce } from 'antd-toolkit/wp'
import { memo, useCallback, useMemo, useState, type ReactNode } from 'react'

import { useProfitPartnerList } from '@/pages/admin/ProfitPartner/hooks'
import type { TProfitPartner } from '@/pages/admin/ProfitPartner/types'
import { BatchActionModal } from '@/pages/admin/ProfitSettlement/components/BatchActionModal'
import {
	useSettlementBatchAction,
	useSettlementExport,
	useSettlementList,
	useSettlementSummary,
} from '@/pages/admin/ProfitSettlement/hooks'
import { Filter } from '@/pages/admin/ProfitSettlement/List/Filter'
import { SummaryCards } from '@/pages/admin/ProfitSettlement/List/SummaryCards'
import {
	SETTLEMENT_BATCH_ACTION,
	SETTLEMENT_BATCH_ACTION_META,
	SETTLEMENT_STATUS,
	SETTLEMENT_STATUS_META,
	type TSettlementBatchAction,
	type TSettlementBatchResult,
	type TSettlementFilterValues,
	type TSettlementRow,
	type TSettlementStatus,
} from '@/pages/admin/ProfitSettlement/types'
import {
	downloadBase64File,
	formatUnixSeconds,
	toSettlementQuery,
} from '@/pages/admin/ProfitSettlement/utils'
import { useProfitShopList } from '@/pages/admin/ProfitShop/hooks'
import type { TProfitShop } from '@/pages/admin/ProfitShop/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Text } = Typography

/**
 * Table `scroll.x`：各欄 width 總和
 *
 * 訂單 100 + 商品 240 + 夥伴 150 + 賣場 150 + 實際單價 110 + 應分潤 140
 * + 狀態 130 + 訂單日期 120 + 操作 190。改動任一欄寬度要一起更新。
 */
const COLUMNS_TOTAL_WIDTH = 1330

/** FilterTags 的欄位名稱對映 */
const keyLabelMapper = (key: keyof TSettlementFilterValues): string =>
	({
		partner_term_id: '分潤夥伴',
		shop_id: '分潤賣場',
		status: '結算狀態',
		date_range: '訂單日期',
	})[key] ?? (key as string)

/**
 * 從 Refine mutation 的錯誤取出可讀訊息
 *
 * ⚠️ Refine 的 dataProvider 會把 axios error 轉成自己的 `HttpError`，
 * **只保留 `{ message, statusCode }`**——原始的 `error.response.data`
 * （後端的 `{code, message, data}`）已經不存在了，`statusCode` 甚至可能與真實
 * HTTP 狀態碼不符（CLAUDE.md Pitfall #18）。因此解析不出 `code` 時要 fallback
 * 到 `error.message`，而不是退化成一句沒有資訊的「操作失敗」——
 * 在結算頁，後端訊息往往正是站長需要的（例如「不支援的結算狀態」）。
 */
const resolveErrorMessage = (err: unknown): string => {
	const mapped = mapProfitShopException(err)
	if (mapped.code) return mapped.toastMessage

	const raw = err instanceof Error ? err.message : ''
	return raw || mapped.toastMessage
}

const ListComponent = () => {
	const { notification, message } = App.useApp()
	const { currency } = useWoocommerce()
	const [form] = Form.useForm<TSettlementFilterValues>()

	/** 已按下「篩選」的條件 */
	const [applied, setApplied] = useState<TSettlementFilterValues>({})
	const [page, setPage] = useState(1)
	const [perPage, setPerPage] = useState(20)

	/**
	 * 跨頁累積的選取
	 *
	 * 用 Map 而非 antd 給的 `selectedRows`：後者只涵蓋**當前頁**的資料，
	 * 站長翻到第 2 頁再勾幾筆，第 1 頁勾的就會從 `selectedRows` 消失，
	 * 送出時只撥了一半的款——而畫面上的勾選看起來還在。
	 */
	const [selectedMap, setSelectedMap] = useState<Map<number, TSettlementRow>>(
		new Map()
	)

	/** 目前開啟的批次操作（null = 未開啟）與它要處理的列 */
	const [pendingAction, setPendingAction] =
		useState<TSettlementBatchAction | null>(null)
	const [pendingRows, setPendingRows] = useState<TSettlementRow[]>([])

	const query = useMemo(
		() => toSettlementQuery(applied, { page, perPage }),
		[applied, page, perPage]
	)

	const { data, isLoading, isFetching } = useSettlementList(query)
	const { data: summaryData, isLoading: isSummaryLoading } =
		useSettlementSummary(query)

	// 後端 V2Api 統一裹 {code, data} → axios body 在 result.data → 真正資料在 result.data.data
	const rows: TSettlementRow[] = useMemo(() => {
		const items = data?.data?.data?.items
		return Array.isArray(items) ? items : []
	}, [data])

	const total = data?.data?.data?.total ?? 0
	const summary = summaryData?.data?.data

	// 三個 action 各自一個 mutation hook：hook 數量必須固定，不可依 pendingAction 條件呼叫
	const markPaid = useSettlementBatchAction(SETTLEMENT_BATCH_ACTION.MARK_PAID)
	const markCancelled = useSettlementBatchAction(
		SETTLEMENT_BATCH_ACTION.MARK_CANCELLED
	)
	const reversePaid = useSettlementBatchAction(
		SETTLEMENT_BATCH_ACTION.REVERSE_PAID
	)
	const exporter = useSettlementExport()

	const runners = useMemo(
		() => ({
			[SETTLEMENT_BATCH_ACTION.MARK_PAID]: markPaid,
			[SETTLEMENT_BATCH_ACTION.MARK_CANCELLED]: markCancelled,
			[SETTLEMENT_BATCH_ACTION.REVERSE_PAID]: reversePaid,
		}),
		[markPaid, markCancelled, reversePaid]
	)

	const activeRunner = pendingAction ? runners[pendingAction] : null

	/** 夥伴 / 賣場 id → 名稱（只給 FilterTags 顯示用；列表的名稱由後端解析） */
	const { data: partnerData } = useProfitPartnerList()
	const { data: shopData } = useProfitShopList()

	const partnerMap = useMemo(() => {
		const list = partnerData?.data?.data
		const partners: TProfitPartner[] = Array.isArray(list) ? list : []
		return new Map(partners.map((p) => [p.id, p.name]))
	}, [partnerData])

	const shopMap = useMemo(() => {
		const list = shopData?.data?.data
		const shops: TProfitShop[] = Array.isArray(list) ? list : []
		return new Map(shops.map((s) => [s.id, s.title]))
	}, [shopData])

	const valueLabelMapper = useMemo(
		() =>
			(value: string, key?: keyof TSettlementFilterValues): string => {
				if (key === 'partner_term_id') {
					return partnerMap.get(Number(value)) ?? `#${value}`
				}
				if (key === 'shop_id') {
					return shopMap.get(Number(value)) ?? `#${value}`
				}
				if (key === 'status') {
					return (
						SETTLEMENT_STATUS_META[value as TSettlementStatus]?.label ?? value
					)
				}
				return value
			},
		[partnerMap, shopMap]
	)

	/**
	 * 套用新的篩選條件
	 *
	 * 一併回到第 1 頁並**清空選取**：條件變了之後，先前勾選的紀錄可能已經不在
	 * 結果集裡，留著它們會讓站長對著「已選 38 筆」卻只看得到 5 筆的畫面，
	 * 而按下撥款時撥的是他看不見的那 33 筆。
	 */
	const handleApply = useCallback((values: TSettlementFilterValues) => {
		setApplied(values)
		setPage(1)
		setSelectedMap(new Map())
	}, [])

	const selectedRowKeys = useMemo(
		() => Array.from(selectedMap.keys()),
		[selectedMap]
	)
	const selectedRows = useMemo(
		() => Array.from(selectedMap.values()),
		[selectedMap]
	)

	const rowSelection: TableProps<TSettlementRow>['rowSelection'] = {
		selectedRowKeys,
		preserveSelectedRowKeys: true,
		onSelect: (record, selected) => {
			setSelectedMap((prev) => {
				const next = new Map(prev)
				if (selected) {
					next.set(record.order_item_id, record)
				} else {
					next.delete(record.order_item_id)
				}
				return next
			})
		},
		onSelectAll: (selected, _rows, changeRows) => {
			setSelectedMap((prev) => {
				const next = new Map(prev)
				changeRows.forEach((r) => {
					if (selected) {
						next.set(r.order_item_id, r)
					} else {
						next.delete(r.order_item_id)
					}
				})
				return next
			})
		},
	}

	/**
	 * 呈現批次操作結果
	 *
	 * 🔴 **`skipped` 與 `audit_log_failed_partner_ids` 一定要顯示出來。**
	 * 只說「操作成功」會讓第二個管理員以為自己那次也生效了——而這是真實金流，
	 * 他很可能真的再去銀行轉一次帳。兩則警告都用 `duration: 0` 不自動消失，
	 * 否則站長轉頭去看別的東西回來就再也看不到了。
	 */
	const reportBatchResult = useCallback(
		(action: TSettlementBatchAction, result: TSettlementBatchResult) => {
			const meta = SETTLEMENT_BATCH_ACTION_META[action]
			const skippedEntries = Object.entries(result.skipped ?? {})

			notification.success({
				message: `${meta.label}完成`,
				description: (
					<>
						<div>
							已異動 <Text strong>{result.updated_count}</Text> 筆，金額合計{' '}
							<Text strong>
								{currency?.symbol}
								{result.total_amount}
							</Text>
						</div>
						{skippedEntries.length > 0 && (
							<div className="mt-1 text-amber-600">
								另有 {skippedEntries.length} 筆未被異動，詳見下方提示
							</div>
						)}
					</>
				),
			})

			if (skippedEntries.length > 0) {
				notification.warning({
					message: `有 ${skippedEntries.length} 筆未被異動`,
					description: (
						<>
							<div className="mb-2">
								這些紀錄維持原本的狀態（常見原因：已被其他管理員或分頁改過）。
								<Text strong>請勿重複操作</Text>，先重新整理列表確認目前狀態。
							</div>
							<ul className="m-0 pl-4 max-h-40 overflow-auto">
								{skippedEntries.map(([itemId, reason]) => (
									<li key={itemId}>
										品項 #{itemId}：{reason}
									</li>
								))}
							</ul>
						</>
					),
					duration: 0,
				})
			}

			if (result.audit_log_failed_partner_ids?.length) {
				notification.warning({
					message: '撥款稽核紀錄寫入失敗',
					description: `結算狀態已成功變更，但夥伴 #${result.audit_log_failed_partner_ids.join(
						' / #'
					)} 的撥款稽核紀錄沒有寫入。狀態不需要重做，但請通知工程人員補登稽核紀錄。`,
					duration: 0,
				})
			}
		},
		[notification, currency?.symbol]
	)

	const handleConfirmBatch = useCallback(
		async (orderItemIds: number[]) => {
			if (!pendingAction || !activeRunner || orderItemIds.length === 0) return

			try {
				const res = await activeRunner.mutateAsync(orderItemIds)
				const result = res?.data?.data

				if (!result) {
					notification.error({
						message: '操作結果無法解析',
						description:
							'伺服器回應的格式與預期不符。請重新整理列表確認實際狀態後再決定是否重試——切勿直接重試，可能造成重複撥款。',
						duration: 0,
					})
					return
				}

				reportBatchResult(pendingAction, result)

				setPendingAction(null)
				setPendingRows([])

				/*
				 * 只把「本次送出的那幾筆」移出選取，不是整批清空。
				 * 站長可能跨頁勾了 50 筆、卻先用單列按鈕處理其中一筆特殊狀況；
				 * 清空全部會讓他前面的勾選白費，而且他不一定會發現。
				 */
				setSelectedMap((prev) => {
					const next = new Map(prev)
					orderItemIds.forEach((id) => next.delete(id))
					return next
				})
			} catch (err) {
				notification.error({
					message: `${SETTLEMENT_BATCH_ACTION_META[pendingAction].label}失敗`,
					description: resolveErrorMessage(err),
					duration: 0,
				})

				// 不關閉 Modal：讓站長看得到自己剛才要處理哪幾筆，決定是否重試
			}
		},
		[pendingAction, activeRunner, notification, reportBatchResult]
	)

	const openBatch = useCallback(
		(action: TSettlementBatchAction, targetRows: TSettlementRow[]) => {
			setPendingAction(action)
			setPendingRows(targetRows)
		},
		[]
	)

	/** 匯出目前篩選範圍的 CSV */
	const handleExport = useCallback(async () => {
		try {
			const res = await exporter.mutateAsync(query)
			const result = res?.data?.data

			if (!result?.content_base64) {
				notification.error({
					message: '匯出失敗',
					description: '伺服器沒有回傳檔案內容，請稍後再試。',
				})
				return
			}

			downloadBase64File(
				result.filename,
				result.content_base64,
				result.mime_type
			)

			message.success(
				result.row_count > 0
					? `已匯出 ${result.row_count} 筆結算明細`
					: '目前篩選範圍沒有資料，已匯出只含標題列的檔案'
			)
		} catch (err) {
			notification.error({
				message: '匯出失敗',
				description: resolveErrorMessage(err),
			})
		}
	}, [exporter, query, notification, message])

	/**
	 * 單列操作：與批次走完全相同的確認流程與端點
	 *
	 * 刻意不做「單列直接送出、不確認」的捷徑——單筆一樣是真實金流，
	 * 而且共用同一條路徑才不會出現「批次會擋、單筆不擋」的漏洞。
	 */
	const renderRowActions = (record: TSettlementRow) => {
		const actions: TSettlementBatchAction[] =
			record.status === SETTLEMENT_STATUS.PENDING
				? [
						SETTLEMENT_BATCH_ACTION.MARK_PAID,
						SETTLEMENT_BATCH_ACTION.MARK_CANCELLED,
					]
				: record.status === SETTLEMENT_STATUS.PAID
					? [SETTLEMENT_BATCH_ACTION.REVERSE_PAID]
					: []

		if (actions.length === 0) {
			return <Text type="secondary">—</Text>
		}

		return (
			<Space size={0}>
				{actions.map((action) => (
					<Button
						key={action}
						type="link"
						size="small"
						danger={SETTLEMENT_BATCH_ACTION_META[action].danger}
						onClick={() => openBatch(action, [record])}
					>
						{SETTLEMENT_BATCH_ACTION_META[action].label}
					</Button>
				))}
			</Space>
		)
	}

	const columns: TableProps<TSettlementRow>['columns'] = [
		{
			title: '訂單',
			dataIndex: 'order_id',
			width: 100,
			render: (orderId: number) => (
				<Link to={`/orders/edit/${orderId}`}>#{orderId}</Link>
			),
		},
		{
			title: '商品',
			dataIndex: 'product_name',
			width: 240,

			// NameId 同時呈現商品名稱與訂單品項編號（結算的實際主鍵），
			// 因此不另開 order_item_id 欄；連結指向該筆所屬的訂單編輯頁。
			render: (productName: string, record: TSettlementRow) => (
				<Link to={`/orders/edit/${record.order_id}`}>
					<NameId
						name={productName || '（商品名稱未紀錄）'}
						id={String(record.order_item_id)}
					/>
				</Link>
			),
		},
		{
			title: '分潤夥伴',
			dataIndex: 'partner_name',
			width: 150,
			ellipsis: true,

			// 名稱由後端解析（含 [已停用夥伴] 佔位文字），前端只負責上色
			render: (name: string, record: TSettlementRow) => (
				<Tooltip title={`term #${record.partner_term_id}`}>
					<Text type={name.startsWith('[') ? 'secondary' : undefined}>
						{name}
					</Text>
				</Tooltip>
			),
		},
		{
			title: '分潤賣場',
			dataIndex: 'shop_name',
			width: 150,
			ellipsis: true,
			render: (name: string, record: TSettlementRow) => (
				<Tooltip title={`shop #${record.shop_id}`}>
					<Text type={name.startsWith('[') ? 'secondary' : undefined}>
						{name}
					</Text>
				</Tooltip>
			),
		},
		{
			title: '實際單價',
			dataIndex: 'actual_price',
			width: 110,
			align: 'right',

			// 十進位字串原樣顯示，不要 Number() 往返（見 types.ts 檔頭）
			render: (price: string) => (
				<Text>
					{currency?.symbol}
					{price}
				</Text>
			),
		},
		{
			title: '應分潤',
			dataIndex: 'profit_amount',
			width: 140,
			align: 'right',
			render: (amount: string, record: TSettlementRow) => (
				<div className="flex flex-col items-end">
					<Text strong>
						{currency?.symbol}
						{amount}
					</Text>
					{record.needs_manual_rate ? (
						/*
						 * 賣場在結帳當下已被刪除 ⇒ 後端刻意寫下完整歸屬但 rate = 0 / amount = 0.00，
						 * 好讓這筆錢仍看得見、可人工補救。這類紀錄**不可**被過濾掉，
						 * 也不可只顯示 0 而不解釋——站長會以為是資料錯誤而忽略它。
						 */
						<Tooltip title="結帳當下賣場已被刪除，系統算不出分潤比例。這筆錢仍需支付，請人工確認金額後再撥款。">
							<Tag
								color="red"
								icon={<ExclamationCircleOutlined />}
								className="m-0 mt-1"
							>
								需人工設定比例
							</Tag>
						</Tooltip>
					) : (
						<Text type="secondary" className="text-xs">
							{record.rate}%
						</Text>
					)}
				</div>
			),
		},
		{
			title: '狀態',
			dataIndex: 'status',
			width: 130,
			render: (status: TSettlementStatus, record: TSettlementRow) => {
				const meta = SETTLEMENT_STATUS_META[status]
				const settledAt = formatUnixSeconds(record.settled_at)

				return (
					<div className="flex flex-col items-start">
						<Tag color={meta?.color} className="m-0">
							{meta?.label ?? status}
						</Tag>
						{settledAt && (
							<Text type="secondary" className="text-xs mt-1">
								{settledAt}
							</Text>
						)}
					</div>
				)
			},
		},
		{
			title: '訂單日期',
			dataIndex: 'order_date',
			width: 120,
			render: (d: string) => <Text type="secondary">{d || '—'}</Text>,
		},
		{
			title: '操作',
			key: 'actions',
			width: 190,
			fixed: 'right',
			render: (_: unknown, record: TSettlementRow) => renderRowActions(record),
		},
	]

	/** 批次操作按鈕（沒有選取時停用，並用 Tooltip 說明原因） */
	const renderBatchButton = (
		action: TSettlementBatchAction,
		icon: ReactNode
	) => {
		const meta = SETTLEMENT_BATCH_ACTION_META[action]
		const eligibleCount = selectedRows.filter((r) =>
			meta.allowedFrom.includes(r.status)
		).length

		const blockedReason =
			selectedRows.length === 0
				? '請先勾選要處理的結算紀錄'
				: eligibleCount === 0
					? `選取的項目中沒有狀態為「${meta.allowedFrom
							.map((s) => SETTLEMENT_STATUS_META[s].label)
							.join('、')}」的紀錄`
					: null

		/*
		 * ⚠️ antd v5 的 <Tooltip> 直接包 disabled <Button> **不會顯示**
		 * （v5 移除了 v4 的相容包裝，瀏覽器也不對 disabled button 派發滑鼠事件）。
		 * 必須外包一層 <span> 承接事件、按鈕加 pointer-events-none 讓事件穿透。
		 * 見 react.rule.md §2。
		 */
		return blockedReason ? (
			<Tooltip key={action} title={blockedReason}>
				<span className="inline-block cursor-not-allowed">
					<Button
						icon={icon}
						danger={meta.danger}
						className="pointer-events-none"
						disabled
					>
						{meta.label}
					</Button>
				</span>
			</Tooltip>
		) : (
			<Button
				key={action}
				icon={icon}
				danger={meta.danger}
				onClick={() => openBatch(action, selectedRows)}
			>
				{meta.label}（{eligibleCount}）
			</Button>
		)
	}

	return (
		<>
			<Card title="篩選" className="mb-4">
				<Filter form={form} onFinish={handleApply} />
				<div className="mt-2">
					<FilterTags<TSettlementFilterValues>
						form={{ ...form } as FormInstance<TSettlementFilterValues>}
						keyLabelMapper={keyLabelMapper}
						valueLabelMapper={valueLabelMapper}
					/>
				</div>
			</Card>

			<SummaryCards
				summary={summary}
				isLoading={isSummaryLoading}
				currencySymbol={currency?.symbol ?? ''}
			/>

			<Card>
				<div className="mb-4 flex flex-wrap items-center justify-between gap-2">
					<Space wrap>
						{renderBatchButton(
							SETTLEMENT_BATCH_ACTION.MARK_PAID,
							<WalletOutlined />
						)}
						{renderBatchButton(
							SETTLEMENT_BATCH_ACTION.MARK_CANCELLED,
							<StopOutlined />
						)}
						{renderBatchButton(
							SETTLEMENT_BATCH_ACTION.REVERSE_PAID,
							<RollbackOutlined />
						)}
						{selectedRows.length > 0 && (
							<Button type="link" onClick={() => setSelectedMap(new Map())}>
								清除選取（{selectedRows.length}）
							</Button>
						)}
					</Space>

					<Tooltip title="匯出目前篩選範圍的全部結算明細（不只當前頁）">
						<Button
							icon={<DownloadOutlined />}
							loading={exporter.isLoading}
							onClick={handleExport}
						>
							匯出 CSV
						</Button>
					</Tooltip>
				</div>

				<Table<TSettlementRow>
					{...(defaultTableProps as unknown as TableProps<TSettlementRow>)}
					rowKey="order_item_id"
					dataSource={rows}
					columns={columns}
					loading={isLoading || isFetching}
					rowSelection={rowSelection}
					pagination={{
						current: page,
						pageSize: perPage,
						total,

						// server-side 分頁：total 來自後端，不能讓 antd 自己切
						...getDefaultPaginationProps({ label: '結算紀錄' }),
						onChange: (nextPage, nextPageSize) => {
							setPage(nextPage)
							if (nextPageSize && nextPageSize !== perPage) {
								setPerPage(nextPageSize)
								setPage(1)
							}
						},
					}}
					scroll={{ x: COLUMNS_TOTAL_WIDTH }}
					locale={{
						emptyText: (
							<Empty
								image={Empty.PRESENTED_IMAGE_SIMPLE}
								description="目前篩選範圍沒有結算紀錄"
							/>
						),
					}}
				/>
			</Card>

			<BatchActionModal
				action={pendingAction}
				rows={pendingRows}
				open={pendingAction !== null}
				isLoading={!!activeRunner?.isLoading}
				onCancel={() => {
					setPendingAction(null)
					setPendingRows([])
				}}
				onConfirm={handleConfirmBatch}
			/>
		</>
	)
}

export const ProfitSettlementList = memo(ListComponent)
