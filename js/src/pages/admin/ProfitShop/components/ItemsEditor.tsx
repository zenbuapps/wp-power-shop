import {
	DeleteOutlined,
	HolderOutlined,
	InfoCircleOutlined,
	MinusSquareOutlined,
	PlusOutlined,
	PlusSquareOutlined,
} from '@ant-design/icons'
import {
	DndContext,
	PointerSensor,
	useSensor,
	useSensors,
	type DragEndEvent,
} from '@dnd-kit/core'
import { restrictToVerticalAxis } from '@dnd-kit/modifiers'
import {
	SortableContext,
	arrayMove,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
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
	isVariable,
} from 'antd-toolkit/wp'
import {
	createContext,
	memo,
	useCallback,
	useContext,
	useMemo,
	useState,
	type ComponentProps,
	type CSSProperties,
	type HTMLAttributes,
} from 'react'

import { TProductRecord } from '@/components/product/types'
import { ProductPickerModal } from '@/pages/admin/ProfitShop/components/ProductPickerModal'
import { useProductPickerModal } from '@/pages/admin/ProfitShop/components/ProductPickerModal/hooks'
import { VariationOverrideEditor } from '@/pages/admin/ProfitShop/components/VariationOverrideEditor'
import { useShopItemProducts } from '@/pages/admin/ProfitShop/hooks'
import type {
	TPriceOverride,
	TShopItemRow,
} from '@/pages/admin/ProfitShop/types'
import {
	emptyPriceOverride,
	emptyVariationOverride,
	isSaleOverRegular,
	resolveShopItemDisplay,
	toInputValue,
	toPriceString,
} from '@/pages/admin/ProfitShop/utils'

/**
 * 賣場商品數量軟上限
 *
 * 超過只警示不阻擋：ItemsEditor 每次按鍵重建整個 items 陣列，
 * 列數過多會讓價格欄輸入明顯延遲，但這是效能問題不是正確性問題。
 */
const SOFT_ITEM_LIMIT = 500

/** 已加入商品表格的預設每頁筆數 */
const DEFAULT_PAGE_SIZE = 20

type TItemsEditorProps = {
	value?: TShopItemRow[] // 受 antd Form.Item 控制的目前值
	onChange?: (value: TShopItemRow[]) => void // 由 antd Form.Item 注入
	disabled?: boolean
}

/** 商品資料補不到時，類型 / 狀態 / 價格 / 庫存欄一律顯示這個 */
const DASH = <span className="text-gray-400">—</span>

/**
 * 可變商品 / 可變訂閱在「商品層」那幾格顯示的提示
 *
 * 這類商品的庫存、覆寫價格、灌水購買人數一律改到展開列逐變體設定 ——
 * 父商品本身沒有單一價格、也沒有單一庫存數字，在商品層填那三格
 * 對站長而言是沒有對應物的操作。
 */
const VARIATION_LEVEL_HINT = (
	<span className="text-xs text-gray-400">依變體設定</span>
)

/** 商品層價格覆寫是否還留著值（三欄任一非 null） */
export const hasProductLevelOverride = (override: TPriceOverride): boolean =>
	null !== override.regular_price ||
	null !== override.sale_price ||
	null !== override.signup_fee

/** 把商品層殘留的覆寫值描述成一行人話，例如「原價 300、特價 240」 */
export const describeOverride = (override: TPriceOverride): string => {
	const parts: string[] = []
	if (null !== override.regular_price) parts.push(`原價 ${override.regular_price}`)
	if (null !== override.sale_price) parts.push(`特價 ${override.sale_price}`)
	if (null !== override.signup_fee) parts.push(`註冊費 ${override.signup_fee}`)
	return parts.join('、')
}

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

/** 驗證訊息最多列出幾處違規，其餘以「等 N 處」帶過 */
const MAX_LISTED_VIOLATIONS = 5

/**
 * 賣場商品價格覆寫的 antd Form 驗證器
 *
 * 掛在 ProfitShopForm 的 `items` Form.Item 上，讓「特價 > 原價覆寫」在送出前就被擋下，
 * 避免整張表單送到後端才被 `InvalidPriceOverride` 拒絕（E-10）。
 *
 * **商品層與變體層都要驗** —— 變體覆寫藏在展開列裡，若只驗商品層，
 * 使用者會在送出後才吃到後端 400，而錯誤訊息又因為 Refine HttpError
 * 吃掉 response body 而難以定位到底是哪一個變體。
 *
 * ⚠️ 訊息必須同時指出商品與變體（`商品 #101 變體 #301`），只寫商品 id
 * 等於叫使用者自己一個一個展開去找。
 */
export const validateItemsPriceOverride = async (
	_rule: unknown,
	value: TShopItemRow[] | undefined
): Promise<void> => {
	const violations: string[] = []

	;(value ?? []).forEach((it) => {
		if (isSaleOverRegular(it.override)) {
			violations.push(`商品 #${it.product_id}`)
		}

		Object.entries(it.variations ?? {}).forEach(([variationId, variation]) => {
			if (variation?.override && isSaleOverRegular(variation.override)) {
				violations.push(`商品 #${it.product_id} 變體 #${variationId}`)
			}
		})
	})

	if (0 === violations.length) return

	const listed = violations.slice(0, MAX_LISTED_VIOLATIONS).join('、')
	const rest =
		violations.length > MAX_LISTED_VIOLATIONS
			? ` 等 ${violations.length} 處`
			: ''

	throw new Error(`${listed}${rest} 的特價覆寫不得高於原價覆寫`)
}

/** `useSortable` 回傳的把手事件綁定 */
type TSortableListeners = ReturnType<typeof useSortable>['listeners']

/**
 * 把整列的拖曳把手綁定往下傳給把手欄
 *
 * antd Table 的 `components.body.row` 只能換掉 `<tr>`，無法把 props 塞進特定 `<td>`，
 * 所以 `useSortable` 拿到的 `listeners` 只能靠 Context 傳給 DragHandle。
 */
const RowContext = createContext<{
	setActivatorNodeRef?: (element: HTMLElement | null) => void
	listeners?: TSortableListeners
}>({})

/**
 * 拖曳把手
 *
 * **只有按住把手才能拖動整列** —— 列內有 InputNumber / 清除 / 移除按鈕，
 * 若把 listeners 綁在整個 `<tr>` 上，PointerSensor 會攔截指標事件讓輸入框無法操作。
 */
const DragHandle = ({ disabled }: { disabled?: boolean }) => {
	const { setActivatorNodeRef, listeners } = useContext(RowContext)
	return (
		<Button
			type="text"
			size="small"
			icon={<HolderOutlined />}
			style={{ cursor: disabled ? 'not-allowed' : 'move' }}
			ref={setActivatorNodeRef}
			disabled={disabled}
			{...listeners}
		/>
	)
}

/** antd Table 傳給 body row 的 props（有資料的列才帶 `data-row-key`） */
type TBodyRowProps = HTMLAttributes<HTMLTableRowElement> & {
	'data-row-key'?: number
}

/** 可拖曳的 `<tr>`，僅用於真的有資料的列 */
const SortableRow = ({
	'data-row-key': rowKey,
	...props
}: TBodyRowProps & { 'data-row-key': number }) => {
	const {
		attributes,
		listeners,
		setNodeRef,
		setActivatorNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable({ id: rowKey })

	const style: CSSProperties = {
		...props.style,
		transform: CSS.Translate.toString(transform),
		transition,
		...(isDragging ? { position: 'relative', zIndex: 9999 } : {}),
	}

	const context = useMemo(
		() => ({ setActivatorNodeRef, listeners }),
		[setActivatorNodeRef, listeners]
	)

	return (
		<RowContext.Provider value={context}>
			<tr
				{...props}
				{...attributes}
				data-row-key={rowKey}
				ref={setNodeRef}
				style={style}
			/>
		</RowContext.Provider>
	)
}

/**
 * body row 分流
 *
 * 空資料時 antd 仍會用這個元件渲染 `ant-table-placeholder` 那一列，
 * 它**沒有** `data-row-key` —— 直接丟給 `useSortable` 會拿到 `id: undefined`，
 * 所以在這層先擋掉（hooks 不能寫在條件式裡，因此拆成兩個元件）。
 *
 * ⚠️ **變體展開列也走這條 fallback，所以 expandable 與 dnd-kit 拖曳排序完全相容，
 * BodyRow / SortableRow / DndContext / SortableContext 一行都不用改**：
 * rc-table 渲染展開列時只傳 `className` 與 `style` 給自訂 row component，
 * **不傳 `data-row-key`**（`rc-table/lib/Body/ExpandedRow.js`），
 * 因此自動落進上面那個守門，不會進 `useSortable`。
 * `SortableContext` 的 items 也只取 pagedItems 的 product_id，展開列不在其中，
 * 不會被誤算進拖曳序列。
 */
const BodyRow = (props: TBodyRowProps) => {
	if (undefined === props['data-row-key']) return <tr {...props} />
	return (
		<SortableRow {...(props as TBodyRowProps & { 'data-row-key': number })} />
	)
}

/**
 * OverrideItem 列表編輯器
 *
 * 範圍限制：
 *   - `signup_fee`（訂閱型註冊費）保留契約但不開放 UI（商品層與變體層皆然）
 *   - 不允許重複 product_id（由前端 dedupe，防呆即可，後端會做最終驗證）
 *   - 變體只能在「已加入賣場的父商品」展開列設定，ProductPickerModal 內仍不可勾選變體
 *
 * 設計：
 *   - 上方：「選擇商品」按鈕 → ProductPickerModal（完整篩選 + 分頁 + 跨頁勾選）
 *   - 下方：Table 列出已加入的 items，可編輯原價 / 特價覆寫、浮報數、移除
 *   - 可變商品 / 可變訂閱：商品圖片左側有展開鈕，展開後可逐一設定每個變體的
 *     原價 / 特價覆寫與灌水購買人數（VariationOverrideEditor）
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

	const [page, setPage] = useState(1)
	const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

	/**
	 * 目前展開的商品（product_id）
	 *
	 * 受控 —— 因為展開鈕是自己刻在「商品名稱」欄裡的（antd 預設的展開欄被
	 * `showExpandColumn: false` 關掉了），非受控的話點自刻的按鈕不會有反應。
	 */
	const [expandedKeys, setExpandedKeys] = useState<number[]>([])

	const toggleExpanded = (productId: number) => {
		setExpandedKeys((prev) =>
			prev.includes(productId)
				? prev.filter((key) => key !== productId)
				: [...prev, productId]
		)
	}

	/**
	 * 夾住頁碼，不用 `useEffect` 同步
	 *
	 * 移除商品會讓總頁數縮水，若使用者正停在最後一頁（例如第 100 頁只剩 1 筆，
	 * 移除後只剩 99 頁），非受控的 antd Table 會停在已不存在的頁碼顯示空表。
	 * 這裡在 render 時直接夾住，比 `useEffect` 少一次 render，也不會閃空白。
	 */
	const maxPage = Math.max(1, Math.ceil(items.length / pageSize))
	const currentPage = Math.min(page, maxPage)

	/** 逐列推導顯示狀態（實作見 `../utils`，兩者的分支語意見該處 docblock） */
	const resolveDisplay = useCallback(
		(row: TShopItemRow) => resolveShopItemDisplay(row, enrich),
		[enrich]
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

			// 新列附加在末端，分頁後會落在最後一頁；直接跳過去，
			// 否則使用者停在第 1 頁會以為「按了沒反應」
			setPage(Math.ceil(next.length / pageSize))

			notification.success({ message: `已加入 ${toAdd.length} 筆` })

			if (next.length > SOFT_ITEM_LIMIT) {
				notification.warning({
					message: `賣場商品已達 ${next.length} 筆`,
					description: `超過 ${SOFT_ITEM_LIMIT} 筆後價格欄輸入可能變慢，建議拆分賣場。仍可正常儲存。`,
					duration: 8,
				})
			}
		},
		[items, onChange, notification, pageSize]
	)

	const picker = useProductPickerModal({
		existingProductIds,
		onConfirm: handleBatchAdd,
	})

	const handleRemove = (productId: number) => {
		// 一併清掉展開狀態，否則 expandedKeys 會留著一個已不存在的 product_id
		setExpandedKeys((prev) => prev.filter((key) => key !== productId))
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

	/**
	 * patch 單一變體的價格覆寫
	 *
	 * 只重建「該商品那一列 + 該變體那一筆」，其餘 item 的 object reference
	 * 完全不動 —— 賣場可以到 500 筆商品，每次按鍵都重建全部會讓輸入明顯延遲。
	 * 該變體還沒有任何設定時補上一份空殼（價格三欄 null + 灌水 0），
	 * 避免 InputNumber 讀到 undefined 從受控變非受控。
	 */
	const handlePatchVariationOverride = (
		productId: number,
		variationId: string,
		patch: Partial<TPriceOverride>
	) => {
		onChange?.(
			items.map((it) => {
				if (it.product_id !== productId) return it
				const current = it.variations?.[variationId] ?? emptyVariationOverride()
				return {
					...it,
					variations: {
						...it.variations,
						[variationId]: {
							...current,
							override: { ...current.override, ...patch },
						},
					},
				}
			})
		)
	}

	/** patch 單一變體的灌水購買人數（最小重建範圍同上） */
	const handlePatchVariationInflated = (
		productId: number,
		variationId: string,
		count: number
	) => {
		onChange?.(
			items.map((it) => {
				if (it.product_id !== productId) return it
				const current = it.variations?.[variationId] ?? emptyVariationOverride()
				return {
					...it,
					variations: {
						...it.variations,
						[variationId]: { ...current, inflated_count: count },
					},
				}
			})
		)
	}

	/**
	 * 移除孤兒鍵：賣場存了這個 variation_id，但 WC 端已經沒有這個變體
	 *
	 * 不移除的話送出時會被後端 `ItemValidator` 以 `InvalidVariation` 打回 400。
	 * 用 filter 重建而非 `delete`，避免動到原本的 variations 物件。
	 */
	const handleRemoveOrphanVariation = (
		productId: number,
		variationId: string
	) => {
		onChange?.(
			items.map((it) => {
				if (it.product_id !== productId) return it
				if (!it.variations?.[variationId]) return it
				return {
					...it,
					variations: Object.fromEntries(
						Object.entries(it.variations).filter(([key]) => key !== variationId)
					),
				}
			})
		)
	}

	/**
	 * 拖曳感測器
	 *
	 * `distance: 1` 讓「單純點擊把手」不會被判定為拖曳，
	 * 避免在把手上誤觸時整列跳動。
	 */
	const sensors = useSensors(
		useSensor(PointerSensor, { activationConstraint: { distance: 1 } })
	)

	/**
	 * 當前頁的列
	 *
	 * `SortableContext` 只能認得畫面上實際渲染的列，所以要跟 Table 的分頁切齊 ——
	 * 這也代表**拖曳只能在同一頁內進行**（跨頁請先調整每頁筆數）。
	 */
	const pagedItems = useMemo(
		() => items.slice((currentPage - 1) * pageSize, currentPage * pageSize),
		[items, currentPage, pageSize]
	)

	const sortableIds = useMemo(
		() => pagedItems.map((it) => it.product_id),
		[pagedItems]
	)

	/**
	 * 套用拖曳結果
	 *
	 * index 一律**回全域 `items` 陣列查**（product_id 唯一），不是頁內相對位置 ——
	 * 這樣不必自己換算分頁 offset，也不會在第 2 頁以後算錯位置。
	 */
	const handleDragEnd = useCallback(
		({ active, over }: DragEndEvent) => {
			if (!over || active.id === over.id) return

			const from = items.findIndex((it) => it.product_id === Number(active.id))
			const to = items.findIndex((it) => it.product_id === Number(over.id))
			if (from < 0 || to < 0) return

			onChange?.(arrayMove(items, from, to))
		},
		[items, onChange]
	)

	const columns: TableProps<TShopItemRow>['columns'] = [
		{
			title: '排序',
			key: 'sort',
			width: 60,
			align: 'center',
			render: () => <DragHandle disabled={disabled} />,
		},
		{
			title: '商品名稱',
			dataIndex: 'product_id',
			width: 320,
			render: (productId: number, record) => {
				const { state, product } = resolveDisplay(record)

				/*
				 * 只有可變商品 / 可變訂閱才給展開鈕。
				 * enrich 還沒回來 / 查詢失敗 / 商品已刪除時 `product` 是 undefined，
				 * 判斷不出商品型別就**不給展開**（不知道型別就不該讓人展開）。
				 */
				const canExpand = !!isVariable(product?.type as string)
				const expanded = expandedKeys.includes(record.product_id)

				/*
				 * ⚠️ 外層 flex 容器的 `min-w-0` 缺一不可：flex item 的 min-width
				 * 預設是 `auto`（拒絕收縮到小於內容寬度），少了它長商品名稱會撐破欄位、
				 * 橫向蓋掉右邊的「商品類型 / 狀態 / 價格」等欄位，而且**不會有任何錯誤訊息**。
				 * 展開鈕與佔位塊則要 `flex-none`，否則會被 flex-shrink 壓扁變形。
				 *
				 * 不可展開的列渲染一個等寬佔位塊，讓所有列的商品名稱
				 * 都對齊在同一條垂直線上（不會因為有沒有展開鈕而左右錯開）。
				 */
				return (
					<div className="flex items-center gap-2 min-w-0">
						{canExpand ? (
							<Button
								type="text"
								size="small"
								className="flex-none"
								aria-label={expanded ? '收合變體' : '展開變體'}
								icon={
									expanded ? <MinusSquareOutlined /> : <PlusSquareOutlined />
								}
								onClick={() => toggleExpanded(record.product_id)}
								disabled={disabled}
							/>
						) : (
							<span className="w-6 flex-none" />
						)}

						{/*
						 * 有完整 record 時直接用 #/products 那顆 ProductName，
						 * 名稱 / #id / SKU / 縮圖的呈現自然一致（不另外刻一套）。
						 * `[&_.at-name]:truncate` 是必要的：ProductName 內建的截斷寫成
						 * `[&_*]:at-truncate`（後代選擇器），對純字串 name 從來沒生效過。
						 */}
						{product ? (
							<ProductName
								record={toWpRecord(product)}
								className="min-w-0 [&_.at-name]:truncate"
							/>
						) : (
							<div className="min-w-0">
								<div className="font-medium truncate">
									{'pending' === state ? (
										<Skeleton.Input
											active
											size="small"
											style={{ width: 140 }}
										/>
									) : (
										(record._name ?? `#${productId}`)
									)}
								</div>
								<div className="text-xs text-gray-500">
									<Tag bordered={false}>#{productId}</Tag>
									{record._sku ? <code>{record._sku}</code> : null}
									{'missing' === state ? (
										<Tag color="red">商品已失效</Tag>
									) : null}
								</div>
							</div>
						)}
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
				if (!product) return DASH

				/*
				 * 可變商品 / 可變訂閱：父層的庫存狀態多半只是「所有變體的彙總」，
				 * 站長真正需要看的是逐變體庫存 —— 改到展開列顯示。
				 */
				if (isVariable(product.type as string)) return VARIATION_LEVEL_HINT

				return <ProductStock record={product} />
			},
		},
		{
			title: '覆寫價格',
			key: 'override',
			width: 260,
			render: (_: unknown, record) => {
				const { product } = resolveDisplay(record)
				const { regular_price: regular, sale_price: sale } = record.override
				const invalid = isSaleOverRegular(record.override)

				/*
				 * 可變商品 / 可變訂閱：商品層不再提供輸入框，一律到展開列逐變體設定。
				 *
				 * ⚠️ 但**不能只是把輸入框藏起來** —— 商品層覆寫對這類商品仍然會生效：
				 * `OverridePriceResolver::resolve()` 在「該變體沒有任何覆寫」時會 fallback
				 * 回商品層 override（`Domain/Service/OverridePriceResolver.php:66-73`）。
				 * 靜默隱藏會讓站長看不到一個仍在影響售價的設定；而且
				 * `validateItemsPriceOverride` 是連商品層一起驗的，殘留值若剛好是
				 * 「特價 > 原價」，送出會被擋下卻**找不到任何欄位可以改**，形成死結。
				 * 因此殘留值一律顯示出來，並給一鍵清除。
				 */
				if (isVariable(product?.type as string)) {
					if (!hasProductLevelOverride(record.override)) {
						return VARIATION_LEVEL_HINT
					}

					return (
						<div className="text-xs leading-5">
							<div className={invalid ? 'text-red-500' : 'text-orange-500'}>
								仍有商品層覆寫：{describeOverride(record.override)}
							</div>
							<div className="text-gray-400">
								{invalid
									? '特價高於原價，送出會被擋下，請先清除。'
									: '未個別覆寫的變體會沿用這組價格。'}
							</div>
							<Button
								size="small"
								type="link"
								className="px-0"
								onClick={() =>
									handlePatchOverride(record.product_id, {
										regular_price: null,
										sale_price: null,
										signup_fee: null,
									})
								}
								disabled={disabled}
							>
								清除商品層覆寫
							</Button>
						</div>
					)
				}

				return (
					<div>
						<div className="mb-2">
							<Space size={4}>
								<InputNumber
									size="small"
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
						</div>
						<Space size={4}>
							<InputNumber
								size="small"
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
							<div className="text-xs text-red-500 mt-1">不得高於原價</div>
						) : null}
					</div>
				)
			},
		},
		{
			title: (
				<>
					灌水購買人數
					<Tooltip title="前台會顯示 真實購買人數 + 灌水購買人數">
						<InfoCircleOutlined className="ml-1 text-gray-400" />
					</Tooltip>
				</>
			),
			dataIndex: 'inflated_count',
			width: 170,
			render: (n: number, record) => {
				const { product } = resolveDisplay(record)

				/*
				 * 可變商品 / 可變訂閱：商品層不再提供輸入框（同覆寫價格）。
				 *
				 * ⚠️ 已設定的值仍會在賣場前台生效 —— 商品卡片的「已售 N 件」＝
				 * 真實銷量 + 商品層 inflated_count（`templates/profit-shop-front.php`）。
				 * 相對地，變體層的灌水人數目前**沒有任何顯示位置**（賣場前台只渲染父商品
				 * 卡片，變體選擇整段發生在 WC 單品頁，見 specs/tech-debt.md TD-15）。
				 * 因此殘留值必須顯示 + 可清除，不能靜默隱藏。
				 */
				if (isVariable(product?.type as string)) {
					if (n <= 0) return VARIATION_LEVEL_HINT

					return (
						<div className="text-xs leading-5">
							<div className="text-orange-500">仍有商品層 {n} 人</div>
							<div className="text-gray-400">
								賣場卡片的「已售 N 件」會加上這個數字。
							</div>
							<Button
								size="small"
								type="link"
								className="px-0"
								onClick={() =>
									handleUpdate(record.product_id, { inflated_count: 0 })
								}
								disabled={disabled}
							>
								清除
							</Button>
						</div>
					)
				}

				return (
					<InputNumber
						size="small"
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
				)
			},
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
		<div className="flex flex-col gap-3">
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

			{items.length > 0 ? (
				<div className="text-xs text-gray-500">
					拖曳最左側的
					<HolderOutlined className="mx-1" />
					把手可調整商品順序，此順序即賣場前台的商品排列順序（僅能在同一頁內拖曳，跨頁請先加大每頁筆數）。
				</div>
			) : null}

			<DndContext
				sensors={sensors}
				modifiers={[restrictToVerticalAxis]}
				onDragEnd={handleDragEnd}
			>
				<SortableContext
					items={sortableIds}
					strategy={verticalListSortingStrategy}
					disabled={disabled}
				>
					<Table<TShopItemRow>
						rowKey="product_id"
						dataSource={items}
						columns={columns}
						components={{ body: { row: BodyRow } }}
						expandable={{
							/*
							 * 關掉 antd 預設插在最左邊的展開欄 —— 使用者要的是
							 * 「商品**圖片左側**」，而圖片在商品名稱欄內由 ProductName 渲染，
							 * 所以展開鈕改成自己刻在那一欄的 ProductName 之前。
							 */
							showExpandColumn: false,
							expandedRowKeys: expandedKeys,
							rowExpandable: (record) =>
								!!isVariable(resolveDisplay(record).product?.type as string),
							expandedRowRender: (record) => (
								<VariationOverrideEditor
									productId={record.product_id}
									value={record.variations ?? {}}
									onPatchOverride={(variationId, patch) =>
										handlePatchVariationOverride(
											record.product_id,
											variationId,
											patch
										)
									}
									onPatchInflated={(variationId, count) =>
										handlePatchVariationInflated(
											record.product_id,
											variationId,
											count
										)
									}
									onRemoveOrphan={(variationId) =>
										handleRemoveOrphanVariation(record.product_id, variationId)
									}
									productOverride={record.override}
									disabled={disabled}
								/>
							),
						}}
						pagination={{
							current: currentPage,
							pageSize,
							total: items.length,
							size: 'small',
							showSizeChanger: true,
							pageSizeOptions: [20, 50, 100],
							showTotal: (total) => `共 ${total} 筆商品`,
							onChange: (nextPage, nextPageSize) => {
								setPage(nextPage)
								if (nextPageSize) setPageSize(nextPageSize)

								// 換頁 / 換每頁筆數後畫面上是另一批商品，
								// 展開狀態留著會讓「哪幾列是展開的」與使用者的認知脫節
								setExpandedKeys([])
							},
						}}
						size="small"
						scroll={{ x: 1430 }}
						locale={{
							emptyText: (
								<Empty
									image={Empty.PRESENTED_IMAGE_SIMPLE}
									description="尚未加入任何商品"
								/>
							),
						}}
					/>
				</SortableContext>
			</DndContext>
		</div>
	)
}

export const ItemsEditor = memo(ItemsEditorComponent)
