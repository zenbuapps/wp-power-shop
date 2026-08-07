import { DeleteOutlined, InfoCircleOutlined } from '@ant-design/icons'
import {
	Alert,
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
import { ProductStock } from 'antd-toolkit/wp'
import { memo, useMemo, type ComponentProps } from 'react'

import {
	VARIATION_PAGE_LIMIT,
	useProductVariations,
	type TShopVariationInfo,
} from '@/pages/admin/ProfitShop/hooks'
import type {
	TPriceOverride,
	TVariationOverrideMap,
} from '@/pages/admin/ProfitShop/types'
import {
	emptyPriceOverride,
	isSaleOverRegular,
	toInputValue,
	toPriceString,
} from '@/pages/admin/ProfitShop/utils'
import { mergeVariationRows } from '@/pages/admin/ProfitShop/utils'

type TVariationOverrideEditorProps = {
	productId: number
	value: TVariationOverrideMap // 該商品目前已設定的變體覆寫
	onPatchOverride: (variationId: string, patch: Partial<TPriceOverride>) => void
	onPatchInflated: (variationId: string, count: number) => void
	onRemoveOrphan: (variationId: string) => void
	/**
	 * 商品層的價格覆寫
	 *
	 * 只用來判斷「這個未定價的變體到底賣不賣得出去」：
	 * 變體沒有自己的覆寫時，`OverridePriceResolver` 會整組退回商品層，
	 * 商品層也沒設才真的無價可賣。
	 */
	productOverride?: TPriceOverride
	disabled?: boolean
}

/** 該覆寫是否至少設了一個欄位 */
const hasAnyOverride = (override?: TPriceOverride): boolean =>
	!!override &&
	(null !== override.regular_price || null !== override.sale_price)

/** WC 端查不到資料時的佔位 */
const DASH = <span className="text-gray-400">—</span>

/**
 * `ProductStock` 要求的 record 形狀
 *
 * 直接從元件本身取回它要的型別，不引入 `any`
 * （與 `ItemsEditor` 的 `TWpDisplayRecord` 同一手法）。
 */
type TStockRecord = ComponentProps<typeof ProductStock>['record']

/** WC REST 的數值欄位可能是字串 / null / 空字串，統一收斂成 `number | null` */
const toNullableNumber = (value: unknown): number | null => {
	if (null === value || undefined === value || '' === value) return null
	const parsed = Number(value)
	return Number.isFinite(parsed) ? parsed : null
}

/**
 * 變體 → `ProductStock` 的 record
 *
 * `ProductStock` 只讀 `stock_status` / `stock_quantity` / `low_stock_amount` 三欄，
 * 並以 `Number.isInteger(stock_quantity)` 決定要不要在標籤後面帶出庫存數量。
 *
 * ⚠️ 本地型別把 `stock_quantity` 宣告成 `string | null`（`TProductBase`，實際 API 回 number），
 * 不做這層轉型的話 `Number.isInteger('12')` 為 false ——
 * 標籤照樣顯示，但**數量永遠不會出現，而且沒有任何錯誤訊息**。
 */
const toStockRecord = (record: TShopVariationInfo): TStockRecord => ({
	stock_status: record.stock_status as TStockRecord['stock_status'],
	stock_quantity: toNullableNumber(record.stock_quantity),
	low_stock_amount: toNullableNumber(record.low_stock_amount),
})

/**
 * 表格的一列
 *
 * `record` 為 `undefined` 代表**孤兒鍵** —— 賣場存了這個 variation_id 的覆寫，
 * 但 WC 端已經沒有這個變體了（被刪掉 / 商品類型改過）。
 */
type TVariationRow = {
	key: string // variations map 的鍵，一律字串（JSON 物件鍵）
	variationId: number
	record?: TShopVariationInfo
}

/**
 * 變體覆寫編輯器（賣場商品表格的展開列內容）
 *
 * 只在使用者展開某個可變商品時才會被 render（rc-table 的 `expandedRowRender`
 * 天然 lazy），所以變體查詢寫在這裡不會有 N+1；收合只是 `display:none`
 * 不會 unmount，重新展開也不會重查。
 *
 * 價格欄留空 ＝ 不覆寫，填 0 ＝ 覆寫為 0 元。留空時的實際售價依
 * `OverridePriceResolver::resolve()` 的 fallback：該變體**完全沒有**任何覆寫
 * → 退回商品層 override → 商品層也沒設才是商品原價。
 * （注意是「整組退回」，不是逐欄混用 —— 變體只要設了原價或特價其中一欄，
 * 另一欄就維持 null，不會去撈商品層的值。）
 *
 * 四種顧客動線的價格差異（`SingleProductPriceHook` + `CartPriceOverrideHook` 負責）：
 *   - 賣場前台卡片                                → 商品層覆寫價
 *   - 從賣場點進商品單頁（URL 帶 profit_shop_id） → 變體覆寫價
 *   - 加入購物車 / 結帳                            → 變體覆寫價
 *   - 未經賣場直接開啟商品頁（無 profit_shop_id）  → 商品原始價格
 *
 * ⚠️ 最後一條是**刻意保留**的行為，不是缺口 —— 覆寫價綁定賣場，
 * 沒帶 `profit_shop_id` 就不該套用。改動 `SingleProductPriceHook` 的作用範圍時，
 * 這段說明要一併檢查（見 profit-shop.rule.md §7 記載的同型失誤模式）。
 *
 * ⚠️ 本元件位於外層 `ProfitShopForm` 那張大 `<Form>` 之內，
 * 所有按鈕一律走 `onClick`，**不得**出現 `htmlType="submit"`
 * （antd Button 預設 `htmlType="button"`，只要不主動改就安全）。
 */
const VariationOverrideEditorComponent = ({
	productId,
	value,
	onPatchOverride,
	onPatchInflated,
	onRemoveOrphan,
	productOverride,
	disabled,
}: TVariationOverrideEditorProps) => {
	const { variations, total, isLoading, isError } =
		useProductVariations(productId)

	/**
	 * WC 實際存在的變體 + 孤兒鍵
	 *
	 * 孤兒鍵**必須顯示出來**：它送到後端會被 `ItemValidator` 以
	 * `InvalidVariation` 打回 400，若在這裡靜默隱藏，使用者只會看到
	 * 「儲存失敗」卻永遠找不到、也清不掉源頭。
	 */
	const rows = useMemo<TVariationRow[]>(
		() => mergeVariationRows(variations, value),
		[variations, value]
	)

	/**
	 * 是否被 WC REST 的 `per_page` 硬上限截斷
	 *
	 * 一律以實際筆數判斷，不倚賴 `total`：antd-toolkit 在缺 `x-wp-total` header 時
	 * 會把 total fallback 成 1，拿它當條件會讓「真的被截斷」的情況靜默無提示。
	 */
	const isTruncated = variations.length >= VARIATION_PAGE_LIMIT
	const knownTotal = total > variations.length ? total : undefined

	const columns: TableProps<TVariationRow>['columns'] = [
		{
			title: '變體',
			key: 'variation',
			width: 260,
			render: (_: unknown, row) => {
				if (!row.record) {
					return (
						<div className="min-w-0">
							<Tag color="red">變體已失效</Tag>
							<Tag bordered={false}>#{row.variationId}</Tag>
						</div>
					)
				}

				// `?? []` 是必要的防呆：這是外部 API 回來的資料，
				// 在 render 裡對 undefined 呼叫 .map() 會讓整個編輯頁白屏
				const label = (row.record.attributes ?? [])
					.map((attr) => `${attr.name}：${attr.option}`)
					.join('／')

				return (
					<div className="min-w-0">
						<div className="truncate font-medium">
							{label || `變體 #${row.variationId}`}
						</div>
						<div className="text-xs text-gray-500">
							<Tag bordered={false}>#{row.variationId}</Tag>
							{row.record.sku ? <code>{row.record.sku}</code> : null}
						</div>
					</div>
				)
			},
		},
		{
			/*
			 * 庫存改在這一層顯示 —— 商品層那格已移除。
			 * 可變商品的庫存本來就是逐變體管理（父層那個狀態只是彙總），
			 * 站長真正需要判斷「這個變體還能不能賣」的資訊在這裡。
			 */
			title: '庫存',
			key: 'stock',
			width: 130,
			align: 'center',
			render: (_: unknown, row) =>
				row.record ? <ProductStock record={toStockRecord(row.record)} /> : DASH,
		},
		{
			title: '目前價格',
			key: 'current_price',
			width: 130,
			render: (_: unknown, row) => {
				if (!row.record) return DASH
				const { regular_price: regular, sale_price: sale } = row.record
				if (!regular && !sale) {
					/*
					 * 變體自己沒有定價。這在 WooCommerce 是「不可購買」——
					 * `variation_is_visible()`（class-wc-product-variation.php:593）
					 * 的條件含 `'' !== get_price()`，變體會連出現在前台的機會都沒有。
					 *
					 * 有覆寫（自己的或商品層的）時後端會補上價格，一切正常；
					 * 兩邊都沒有才是真的賣不出去。站長只看到一個「—」不會知道這件事，
					 * 因此在他設定的當下就講清楚——這是修法後**唯一**仍會靜默失敗的組合。
					 */
					const rescued =
						hasAnyOverride(value?.[row.key]?.override) ||
						hasAnyOverride(productOverride)

					if (rescued) return DASH

					return (
						<Tag color="warning" className="whitespace-normal">
							此變體未定價，且未設覆寫價 → 前台無法購買
						</Tag>
					)
				}
				return (
					<div className="text-xs leading-5">
						<div>原價 {regular || '—'}</div>
						{sale ? <div className="text-red-500">特價 {sale}</div> : null}
					</div>
				)
			},
		},
		{
			title: '覆寫價格',
			key: 'override',
			width: 260,
			render: (_: unknown, row) => {
				// 孤兒鍵不開放編輯：它指向的變體已不存在，改了也一定會被後端擋下，
				// 使用者該做的事只有「移除」
				if (!row.record) return DASH

				const override = value?.[row.key]?.override ?? emptyPriceOverride()
				const { regular_price: regular, sale_price: sale } = override
				const invalid = isSaleOverRegular(override)

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
										onPatchOverride(row.key, {
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
										onPatchOverride(row.key, { regular_price: null })
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
									onPatchOverride(row.key, { sale_price: toPriceString(v) })
								}
								disabled={disabled}
								style={{ width: 150 }}
							/>
							<Button
								size="small"
								type="link"
								onClick={() => onPatchOverride(row.key, { sale_price: null })}
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
			/*
			 * ⚠️ 文案與商品層那顆 Tooltip **刻意不同，不要「順手統一」**。
			 *
			 * 商品層寫「前台會顯示 真實購買人數 + 灌水購買人數」，那句話對商品層即將成真
			 * （賣場前台的商品卡片會接上）；但對變體層是**假的** —— 賣場前台只渲染父商品
			 * 卡片，變體選擇整段發生在 WC 單品頁，本外掛完全沒有介入該頁的渲染，
			 * 變體層購買人數目前沒有任何可顯示的位置。
			 *
			 * 這裡據實標示「只儲存、尚未顯示」，不對站長做做不到的承諾
			 * （同 BasicTab 對 shortcode 模式的處理紀律）。前台顯示補上之後再改這段文案。
			 */
			title: (
				<>
					灌水購買人數
					<Tooltip title="變體層灌水人數目前僅會儲存，賣場前台與商品單頁尚未顯示變體層購買人數。此欄位為未來功能預留。">
						<InfoCircleOutlined className="ml-1 text-gray-400" />
					</Tooltip>
				</>
			),
			key: 'inflated_count',
			width: 150,
			render: (_: unknown, row) => {
				if (!row.record) return DASH
				return (
					<InputNumber
						size="small"
						min={0}
						value={value?.[row.key]?.inflated_count ?? 0}
						onChange={(v) =>
							onPatchInflated(row.key, typeof v === 'number' ? v : 0)
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
			render: (_: unknown, row) =>
				row.record ? null : (
					<Button
						type="link"
						danger
						size="small"
						icon={<DeleteOutlined />}
						onClick={() => onRemoveOrphan(row.key)}
						disabled={disabled}
					>
						移除
					</Button>
				),
		},
	]

	/**
	 * 三條降級路徑
	 *
	 * 全部**不阻擋儲存** —— 這裡失敗只影響「看不看得到變體清單」，
	 * 已設定的覆寫值仍留在表單值裡，照樣會被送出與持久化。
	 */
	const renderBody = () => {
		if (isLoading) return <Skeleton active paragraph={{ rows: 3 }} />

		if (isError) {
			return (
				<Alert
					type="error"
					showIcon
					message="變體載入失敗"
					description="無法取得這個商品的變體清單，請稍後重新整理再試。已設定的覆寫值仍會正常儲存，不受影響。"
				/>
			)
		}

		if (0 === rows.length) {
			return (
				<Empty
					image={Empty.PRESENTED_IMAGE_SIMPLE}
					description="此商品尚未建立任何變體，請先到商品編輯頁建立變體後再回來設定覆寫"
				/>
			)
		}

		return (
			<Table<TVariationRow>
				rowKey="key"
				dataSource={rows}
				columns={columns}
				size="small"
				pagination={{
					pageSize: 10,
					size: 'small',
					hideOnSinglePage: true,
					showSizeChanger: false,
					showTotal: (count) => `共 ${count} 個變體`,
				}}
			/>
		)
	}

	return (
		<div className="flex flex-col gap-3">
			{/*
			 * 這裡原本有一張說明四種顧客動線價格差異的 info Alert，已依站長要求移除
			 * （展開列已經夠長，那段說明每展開一個商品就重複一次）。
			 * 內容沒有丟掉 —— 完整搬到本元件的 docblock，改動價格生效範圍時仍看得到。
			 */}
			{isTruncated ? (
				<Alert
					type="warning"
					showIcon
					message={`僅顯示前 ${VARIATION_PAGE_LIMIT} 個變體${
						knownTotal ? `（共 ${knownTotal} 個）` : ''
					}，其餘變體無法在此設定覆寫`}
				/>
			) : null}

			{renderBody()}
		</div>
	)
}

export const VariationOverrideEditor = memo(VariationOverrideEditorComponent)
