import { DeleteOutlined, PlusOutlined } from '@ant-design/icons'
import {
	App,
	Button,
	Empty,
	InputNumber,
	Skeleton,
	Space,
	Table,
	Tag,
	Tooltip,
	type TableProps,
} from 'antd'
import {
	ProductName,
	ProductType,
	ProductPrice,
	ProductStock,
	POST_STATUS,
} from 'antd-toolkit/wp'
import { memo, useCallback, useMemo, type ComponentProps } from 'react'

import { TProductRecord } from '@/components/product/types'
import { ProductPickerModal } from '@/pages/admin/ProfitShop/components/ProductPickerModal'
import { useProductPickerModal } from '@/pages/admin/ProfitShop/components/ProductPickerModal/hooks'
import { useShopItemProducts } from '@/pages/admin/ProfitShop/hooks'
import type {
	TPriceOverride,
	TShopItemRow,
} from '@/pages/admin/ProfitShop/types'

/**
 * 賣場商品數量軟上限
 *
 * 超過只警示不阻擋：ItemsEditor 每次按鍵重建整個 items 陣列，
 * 列數過多會讓價格欄輸入明顯延遲，但這是效能問題不是正確性問題。
 */
const SOFT_ITEM_LIMIT = 500

type TItemsEditorProps = {
	value?: TShopItemRow[] // 受 antd Form.Item 控制的目前值
	onChange?: (value: TShopItemRow[]) => void // 由 antd Form.Item 注入
	disabled?: boolean
}

/** 商品資料補不到時，類型 / 狀態 / 價格 / 庫存欄一律顯示這個 */
const DASH = <span className="tw-text-gray-400">—</span>

/**
 * 收斂 antd-toolkit 對 `type` 欄位的型別宣告落差
 *
 * `TProductRecord.type` 被宣告成 `Omit<TProductType, 'variation' | …>` —— `Omit` 套在
 * string union 上不會得到 union，所以它與 `ProductName` / `ProductType` 要求的
 * `TProductType` 不相容。執行期形狀是正確的，只是宣告有缺陷。
 *
 * `#/products` 的 `useColumns` 是用 `record as any` 繞過；這裡改成從元件本身取回它要的型別，
 * 不引入 `any`（那會多一條 `no-explicit-any` warning）。
 */
type TWpDisplayRecord = ComponentProps<typeof ProductName>['record'] &
	ComponentProps<typeof ProductType>['record']

const toWpRecord = (product: TProductRecord) =>
	product as unknown as TWpDisplayRecord

/** 空的價格覆寫：三欄皆 null ＝ 完全沿用商品原價 */
const emptyPriceOverride = (): TPriceOverride => ({
	regular_price: null,
	sale_price: null,
	signup_fee: null,
})

/** 後端十進位字串 → InputNumber 值；null / 非數字 → undefined（顯示 placeholder） */
const toInputValue = (price: string | null): number | undefined => {
	if (price === null) return undefined
	const parsed = Number(price)
	return Number.isFinite(parsed) ? parsed : undefined
}

/** InputNumber 值 → 後端十進位字串；清空 / 非數字 → null（＝沿用商品原價） */
const toPriceString = (value: number | null): string | null =>
	typeof value === 'number' && Number.isFinite(value) ? String(value) : null

/**
 * 該列是否違反「特價不得高於原價覆寫」
 *
 * 與後端 `PriceOverride` VO 同語意，但後端才是唯一真相 —— 此處只是前端第一道閘門。
 * 後端用 `bccomp` 走字串十進位比較，前端以 Number 比較足以擋住一般誤輸入。
 */
const isSaleOverRegular = (override: TPriceOverride): boolean => {
	const regular = Number(override.regular_price)
	const sale = Number(override.sale_price)
	if (override.regular_price === null || override.sale_price === null) {
		return false
	}
	if (!Number.isFinite(regular) || !Number.isFinite(sale)) return false
	return sale > regular
}

/**
 * 賣場商品價格覆寫的 antd Form 驗證器
 *
 * 掛在 ProfitShopForm 的 `items` Form.Item 上，讓「特價 > 原價覆寫」在送出前就被擋下，
 * 避免整張表單送到後端才被 `InvalidPriceOverride` 拒絕（E-10）。
 */
export const validateItemsPriceOverride = async (
	_rule: unknown,
	value: TShopItemRow[] | undefined
): Promise<void> => {
	const invalid = (value ?? []).filter((it) => isSaleOverRegular(it.override))
	if (invalid.length === 0) return
	const ids = invalid.map((it) => `#${it.product_id}`).join('、')
	throw new Error(`商品 ${ids} 的特價覆寫不得高於原價覆寫`)
}

/**
 * OverrideItem 列表編輯器
 *
 * 範圍限制：
 *   - 不支援變體層編輯：`variations` 契約結構保留並原樣攜帶，但 UI 不開放
 *   - `signup_fee`（訂閱型註冊費）同樣保留契約但不開放 UI
 *   - 不允許重複 product_id（由前端 dedupe，防呆即可，後端會做最終驗證）
 *
 * 設計：
 *   - 上方：「選擇商品」按鈕 → ProductPickerModal（完整篩選 + 分頁 + 跨頁勾選）
 *   - 下方：Table 列出已加入的 items，可編輯原價 / 特價覆寫、浮報數、移除
 *   - 價格覆寫：null = 沿用商品原價；0 = 免費贈品；正數 = 覆寫該欄位
 */
const ItemsEditorComponent = ({
	value,
	onChange,
	disabled,
}: TItemsEditorProps) => {
	const { notification } = App.useApp()
	const items: TShopItemRow[] = useMemo(() => value ?? [], [value])

	const existingProductIds = useMemo(
		() => items.map((it) => it.product_id),
		[items]
	)

	const enrich = useShopItemProducts(existingProductIds)

	/**
	 * 逐列推導顯示狀態
	 *
	 * 刻意**只在渲染時推導、不寫回 form value** —— 寫回會觸發 `onChange` 造成迴圈，
	 * 且 `_` 前綴欄位本來就不屬於表單狀態機。
	 *
	 * Phase 4 剛從 Modal 加入的列已自帶 `_name` / `_sku`（`_enrichState: 'ok'`），
	 * 優先採用，避免剛加入的列又閃一次 Skeleton。
	 */
	const resolveDisplay = useCallback(
		(row: TShopItemRow) => {
			const found = enrich.map.get(row.product_id)
			if (found) return { state: 'ok' as const, product: found }

			// 剛從 Modal 加入、補齊查詢還沒回來時，先用 Modal 已帶的名稱 / SKU 撐著。
			// 此時沒有完整 record，類型 / 狀態 / 價格 / 庫存欄仍顯示 —
			if (row._name || row._sku) {
				return { state: 'partial' as const, product: undefined }
			}
			if (enrich.isLoading) {
				return { state: 'pending' as const, product: undefined }
			}

			// 查詢失敗 → 只降級顯示 #id；查詢成功但查無此 id → 商品已失效。
			// 兩者顯示不同，不可混為一談
			return {
				state: enrich.isError ? ('failed' as const) : ('missing' as const),
				product: undefined,
			}
		},
		[enrich.map, enrich.isLoading, enrich.isError]
	)

	/**
	 * 批次加入 Modal 勾選的商品
	 *
	 * dedupe 有兩層，且**第二層以當下的 `items` 為準**（不是 Modal 開啟瞬間的
	 * `existingIds` 快照）—— Modal 開著時賣場 items 可能被其他來源改動，
	 * 用快照會讓已存在的商品被重複塞入（E-12）。
	 *
	 * 重複項一律**靜默略過不報錯**，只在成功訊息回報實際加入筆數（E-3 / E-4）。
	 */
	const handleBatchAdd = useCallback(
		(records: TProductRecord[]) => {
			const alreadyInShop = new Set(items.map((it) => it.product_id))
			const seenInBatch = new Set<number>()
			const toAdd: TShopItemRow[] = []

			records.forEach((record) => {
				// R-2 / E-11：TProductRecord.id 是 string，非有限數直接跳過
				const productId = Number(record?.id)
				if (!Number.isFinite(productId) || productId <= 0) return

				if (seenInBatch.has(productId)) return
				seenInBatch.add(productId)

				if (alreadyInShop.has(productId)) return

				toAdd.push({
					product_id: productId,
					inflated_count: 0,
					override: emptyPriceOverride(),
					variations: {},

					// 直接沿用 Modal 已取得的商品資料，免等補齊查詢
					_name: record?.name,
					_sku: record?.sku,
					_enrichState: 'ok',
				})
			})

			if (0 === toAdd.length) {
				notification.info({ message: '選取的商品都已在賣場中' })
				return
			}

			// 附加於末端：既有列的 object reference 完全不動，
			// 已設定的價格覆寫 / 浮報數因此不可能被沖掉
			const next = [...items, ...toAdd]
			onChange?.(next)

			notification.success({ message: `已加入 ${toAdd.length} 筆` })

			if (next.length > SOFT_ITEM_LIMIT) {
				notification.warning({
					message: `賣場商品已達 ${next.length} 筆`,
					description: `超過 ${SOFT_ITEM_LIMIT} 筆後價格欄輸入可能變慢，建議拆分賣場。仍可正常儲存。`,
					duration: 8,
				})
			}
		},
		[items, onChange, notification]
	)

	const picker = useProductPickerModal({
		existingProductIds,
		onConfirm: handleBatchAdd,
	})

	const handleRemove = (productId: number) => {
		onChange?.(items.filter((it) => it.product_id !== productId))
	}

	const handleUpdate = (productId: number, patch: Partial<TShopItemRow>) => {
		onChange?.(
			items.map((it) =>
				it.product_id === productId ? { ...it, ...patch } : it
			)
		)
	}

	/** 只 patch 巢狀 override 的部分欄位，其餘欄位（含 signup_fee）保持原值 */
	const handlePatchOverride = (
		productId: number,
		patch: Partial<TPriceOverride>
	) => {
		onChange?.(
			items.map((it) =>
				it.product_id === productId
					? { ...it, override: { ...it.override, ...patch } }
					: it
			)
		)
	}

	const columns: TableProps<TShopItemRow>['columns'] = [
		{
			title: '商品名稱',
			dataIndex: 'product_id',
			width: 280,
			render: (productId: number, record) => {
				const { state, product } = resolveDisplay(record)

				// 有完整 record 時直接用 #/products 那顆 ProductName，
				// 名稱 / #id / SKU / 縮圖的呈現自然一致（不另外刻一套）
				if (product) return <ProductName record={toWpRecord(product)} />

				return (
					<div>
						<div className="tw-font-medium">
							{'pending' === state ? (
								<Skeleton.Input active size="small" style={{ width: 140 }} />
							) : (
								(record._name ?? `#${productId}`)
							)}
						</div>
						<div className="tw-text-xs tw-text-gray-500">
							<Tag bordered={false}>#{productId}</Tag>
							{record._sku ? <code>{record._sku}</code> : null}
							{'missing' === state ? <Tag color="red">商品已失效</Tag> : null}
						</div>
					</div>
				)
			},
		},
		{
			title: '商品類型',
			key: 'type',
			width: 150,
			render: (_: unknown, record) => {
				const { product } = resolveDisplay(record)
				return product ? <ProductType record={toWpRecord(product)} /> : DASH
			},
		},
		{
			title: '狀態',
			key: 'status',
			width: 90,
			align: 'center',
			render: (_: unknown, record) => {
				const { product } = resolveDisplay(record)
				if (!product) return DASH
				const status = POST_STATUS.find(
					(item) => item.value === product?.status
				)
				return <Tag color={status?.color}>{status?.label}</Tag>
			},
		},
		{
			title: '價格',
			key: 'price',
			width: 160,
			render: (_: unknown, record) => {
				const { product } = resolveDisplay(record)
				return product ? <ProductPrice record={product} /> : DASH
			},
		},
		{
			title: '庫存',
			key: 'stock',
			width: 130,
			align: 'center',
			render: (_: unknown, record) => {
				const { product } = resolveDisplay(record)
				return product ? <ProductStock record={product} /> : DASH
			},
		},
		{
			title: '覆寫價格',
			key: 'override',
			width: 260,
			render: (_: unknown, record) => {
				const { regular_price: regular, sale_price: sale } = record.override
				const invalid = isSaleOverRegular(record.override)
				return (
					<div className="tw-flex tw-flex-col tw-gap-1">
						<Space size={4}>
							<InputNumber
								min={0}
								prefix="原價"
								value={toInputValue(regular)}
								placeholder="沿用原價"
								onChange={(v) =>
									handlePatchOverride(record.product_id, {
										regular_price: toPriceString(v),
									})
								}
								disabled={disabled}
								style={{ width: 150 }}
							/>
							<Button
								size="small"
								type="link"
								onClick={() =>
									handlePatchOverride(record.product_id, {
										regular_price: null,
									})
								}
								disabled={disabled || null === regular}
							>
								清除
							</Button>
						</Space>
						<Space size={4}>
							<InputNumber
								min={0}
								prefix="特價"
								value={toInputValue(sale)}
								placeholder="沿用特價"
								status={invalid ? 'error' : undefined}
								onChange={(v) =>
									handlePatchOverride(record.product_id, {
										sale_price: toPriceString(v),
									})
								}
								disabled={disabled}
								style={{ width: 150 }}
							/>
							<Button
								size="small"
								type="link"
								onClick={() =>
									handlePatchOverride(record.product_id, { sale_price: null })
								}
								disabled={disabled || null === sale}
							>
								清除
							</Button>
						</Space>
						{invalid ? (
							<div className="tw-text-xs tw-text-red-500">不得高於原價</div>
						) : null}
					</div>
				)
			},
		},
		{
			title: (
				<Tooltip title="前台會顯示 真實購買人數 + 灌水購買人數">
					<span className="tw-cursor-help tw-border-b tw-border-dashed">
						灌水購買人數
					</span>
				</Tooltip>
			),
			dataIndex: 'inflated_count',
			width: 170,
			render: (n: number, record) => (
				<InputNumber
					min={0}
					value={n}
					onChange={(v) =>
						handleUpdate(record.product_id, {
							inflated_count: typeof v === 'number' ? v : 0,
						})
					}
					disabled={disabled}
					style={{ width: 110 }}
				/>
			),
		},
		{
			title: '操作',
			key: 'actions',
			width: 90,
			fixed: 'right',
			render: (_: unknown, record) => (
				<Button
					type="link"
					danger
					size="small"
					icon={<DeleteOutlined />}
					onClick={() => handleRemove(record.product_id)}
					disabled={disabled}
				>
					移除
				</Button>
			),
		},
	]

	return (
		<div className="tw-flex tw-flex-col tw-gap-3">
			<div className="mb-4">
				<Button
					type="primary"
					icon={<PlusOutlined />}
					onClick={picker.show}
					disabled={disabled}
				>
					選擇商品
				</Button>
			</div>

			<ProductPickerModal
				modalProps={picker.modalProps}
				selectedRecords={picker.selectedRecords}
				setSelectedRecords={picker.setSelectedRecords}
				existingIds={picker.existingIds}
			/>

			<Table<TShopItemRow>
				rowKey="product_id"
				dataSource={items}
				columns={columns}
				pagination={false}
				size="small"
				scroll={{ x: 1330 }}
				locale={{
					emptyText: (
						<Empty
							image={Empty.PRESENTED_IMAGE_SIMPLE}
							description="尚未加入任何商品"
						/>
					),
				}}
			/>
		</div>
	)
}

export const ItemsEditor = memo(ItemsEditorComponent)
