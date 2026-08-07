import { Alert, ConfigProvider, Tag, type TableProps } from 'antd'
import { SimpleModal } from 'antd-toolkit'
import { memo, useMemo, type ReactNode } from 'react'

import {
	useValueLabelMapper,
	useColumns,
} from '@/components/product/ProductTable/hooks'
import { ProductTableBody } from '@/components/product/ProductTable/ProductTableBody'
import { useProductTableQuery } from '@/components/product/ProductTable/useProductTableQuery'
import { TProductRecord, TProductVariation } from '@/components/product/types'
import { useProductsOptions } from '@/hooks'
import {
	MAX_BATCH_SIZE,
	dropSelection,
	mergeSelection,
	resolveCheckboxState,
} from './utils'
import {
	type TProductPickerModalState,
} from '@/pages/admin/ProfitShop/components/ProductPickerModal/hooks'

type TPickerRecord = TProductRecord | TProductVariation

type TColumn = NonNullable<TableProps<TPickerRecord>['columns']>[number]

type TBodyProps = Pick<
	TProductPickerModalState,
	'selectedRecords' | 'setSelectedRecords' | 'existingIds'
>

/**
 * Modal 內的商品列表主體
 *
 * 刻意獨立成元件、且只在 `SimpleModal` 的 children 位置渲染 ——
 * 搭配 `destroyOnHidden` 才能讓 `useTable` 在 Modal 關閉時完全不掛載，
 * 否則賣場編輯頁一載入就會空跑一次商品查詢。
 */
const ProductPickerBodyComponent = ({
	selectedRecords,
	setSelectedRecords,
	existingIds,
}: TBodyProps) => {
	// ⚠️ 必須明確傳 syncWithLocation: false（風險 R-1）：
	// 全域設定是 true，繼承的話 Modal 的篩選 / 分頁會寫進賣場編輯頁的網址
	const { searchFormProps, tableProps } = useProductTableQuery({
		syncWithLocation: false,
	})
	const { valueLabelMapper } = useValueLabelMapper()
	const options = useProductsOptions()
	const baseColumns = useColumns({ showActions: false })

	const selectedCount = selectedRecords.length
	const isAtLimit = selectedCount >= MAX_BATCH_SIZE

	const selectedKeys = useMemo(
		() => selectedRecords.map((record) => record.id),
		[selectedRecords]
	)

	const selectedKeySet = useMemo(() => new Set(selectedKeys), [selectedKeys])

	/** 商品名稱欄外掛「已加入」Tag（不動 useColumns 本體，避免影響 #/products） */
	const columns = useMemo(
		() =>
			baseColumns?.map((col: TColumn) => {
				if (!('dataIndex' in col) || 'name' !== col.dataIndex) return col
				const originalRender = col.render
				return {
					...col,

					/*
					 * `min-w-0` + Tag 的 `flex-none` 缺一不可，勿刪：
					 * 這層 flex 讓 ProductName 變成 flex item，而 flex item 的 `min-width`
					 * 預設是 `auto`（拒絕收縮到小於內容寬度）—— 少了 `min-w-0`，長商品名稱
					 * 會把這格撐破、橫向蓋住右邊的「商品類型 / 狀態 / 價格」等欄位。
					 * 名稱本身的截斷由 `useColumns` 的 `[&_.at-name]:truncate` 負責。
					 */
					render: (
						value: unknown,
						record: TPickerRecord,
						index: number
					): ReactNode => (
						<div className="flex items-center gap-2 min-w-0">
							{originalRender?.(value, record, index) as ReactNode}
							{existingIds.has(Number(record?.id)) ? (
								<Tag color="green" className="m-0 flex-none">
									已加入
								</Tag>
							) : null}
						</div>
					),
				}
			}),
		[baseColumns, existingIds]
	)

	/**
	 * 以 record 物件累積選取，**不依賴當前頁 dataSource**
	 *
	 * 換頁後 `dataSource` 就沒有前一頁的 record 了，若靠 `onChange` 的 `selectedRows`
	 * 回推會直接掉資料。改用 `onSelect` / `onSelectAll` 拿到的增減量自行累積。
	 */
	const addRecords = (records: TPickerRecord[]) =>
		setSelectedRecords((prev) => mergeSelection(prev, records as TProductRecord[]))

	const removeRecords = (records: TPickerRecord[]) =>
		setSelectedRecords((prev) => dropSelection(prev, records as TProductRecord[]))

	const rowSelection: TableProps<TProductRecord>['rowSelection'] = {
		selectedRowKeys: selectedKeys,
		getCheckboxProps: (record) => {
			const { disabled, reason } = resolveCheckboxState(record, {
				existingIds,
				selectedKeys: selectedKeySet,
			})

			return {
				disabled,
				className: 'variation' === reason ? 'tw-hidden' : '',
			}
		},
		onSelect: (record, selected) =>
			selected ? addRecords([record]) : removeRecords([record]),
		onSelectAll: (selected, _rows, changeRows) =>
			selected ? addRecords(changeRows) : removeRecords(changeRows),
	}

	return (
		<ProductTableBody
			searchFormProps={searchFormProps}
			tableProps={tableProps}
			columns={columns}
			valueLabelMapper={valueLabelMapper}
			options={options}
			rowSelection={rowSelection}
			tableHeader={
				isAtLimit ? (
					<Alert
						type="warning"
						showIcon
						className="mb-4"
						message={`單次最多加入 ${MAX_BATCH_SIZE} 筆`}
						description="請先加入目前已選的商品，再重新開啟選擇商品。"
					/>
				) : undefined
			}
			scrollX={1300}
			showCard={false}
		/>
	)
}

const ProductPickerBody = memo(ProductPickerBodyComponent)

/**
 * `SimpleModal` 的預設遮罩 zIndex（antd-toolkit 寫死在元件內，沒有匯出）
 *
 * 只在 `modalProps.zIndex` 未指定時當 fallback 用；呼叫端若自訂了 zIndex，
 * 下面的 popup 基準會跟著那個值走，不會因為這個常數過時而失準。
 */
const SIMPLE_MODAL_DEFAULT_Z_INDEX = 2000

type TProductPickerModalProps = Pick<
	TProductPickerModalState,
	'modalProps' | 'selectedRecords' | 'setSelectedRecords' | 'existingIds'
>

/**
 * 商品選擇 Modal
 *
 * 開關由 `useProductPickerModal()` 的 `show()` / `close()` 控制
 * （`SimpleModal` 沒有 `open` prop，靠 opacity + pointerEvents 切換）。
 */
const ProductPickerModalComponent = ({
	modalProps,
	selectedRecords,
	setSelectedRecords,
	existingIds,
}: TProductPickerModalProps) => (
	<SimpleModal {...modalProps}>
		{/*
		 * ⚠️ 這層 ConfigProvider 不能拿掉，否則 Modal 內所有 antd 浮層
		 * （篩選器的 Select 下拉、DatePicker 面板、Tooltip…）會整個看不見。
		 *
		 * antd 的浮層預設 `zIndex = zIndexPopupBase(1000) + 50 = 1050`，
		 * 而 SimpleModal 的遮罩是 `zIndex: 2000` —— 浮層 portal 到 body 後，
		 * 剛好被 Modal 的白色內容框整片蓋住，看起來就是「點了完全沒反應」，
		 * 而且不會有任何錯誤訊息（DOM 其實有渲染出來，只是在 Modal 底下）。
		 *
		 * antd 自己的 `<Modal>` 靠內部的 zIndexContext 自動把子浮層疊上去，
		 * 但 SimpleModal 是 antd-toolkit 自刻的 div、不提供那個 context，
		 * 所以必須由使用端把基準值抬到遮罩之上。
		 *
		 * 用 token 而非逐個 Select 傳 `getPopupContainer` 的理由有二：
		 * 1. `ProductFilter` 來自 antd-toolkit，沒有開放 `getPopupContainer`
		 * 2. Modal 內容區是 `overflow-auto`，把浮層塞進去會被捲動容器裁掉
		 *
		 * ConfigProvider 的 theme 是淺層合併，只覆寫 zIndexPopupBase，
		 * `main.tsx` 設的 colorPrimary / borderRadius 不受影響。
		 */}
		<ConfigProvider
			theme={{
				token: {
					zIndexPopupBase:
						(modalProps?.zIndex ?? SIMPLE_MODAL_DEFAULT_Z_INDEX) + 100,
				},
			}}
		>
			{/*
			 * ⚠️ 這層 stopPropagation 不能拿掉，否則 Modal 內的「篩選」按鈕會失效。
			 *
			 * SimpleModal 的背景遮罩對所有冒泡上來的 click 做 `e.preventDefault()`
			 * （見 antd-toolkit SimpleModal 的 onClick），而 ProductFilter 的「篩選」是
			 * `type="submit"` —— preventDefault 打在它的 click 上等同取消表單送出，
			 * 結果就是按了沒反應、連請求都不會發。
			 *
			 * 在這裡截斷冒泡即可讓原生 submit 正常運作；遮罩的「點背景關閉」判斷是
			 * `e.target === e.currentTarget`（只認直接點在遮罩上的 click），不受影響。
			 * 同時這也保留了原本 preventDefault 想達成的效果：Portal 的 React 事件不會
			 * 往上冒到賣場編輯頁的外層 <Form>。
			 *
			 * 這層也順帶保護了浮層：Select 下拉在 DOM 上 portal 到 body、不在此 div 內，
			 * 但 React 合成事件是沿 **React tree** 冒泡，選項的 click 仍會經過這裡被攔下。
			 */}

			{/*
			 * 這個 div 是純事件邊界、不是可互動元素，沒有對應的鍵盤操作可言，
			 * 故關閉 jsx-a11y 的互動元素檢查（鍵盤操作由裡層真正的按鈕 / 輸入框負責）。
			 */}
			{/* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events */}
			<div onClick={(e) => e.stopPropagation()}>
				<ProductPickerBody
					selectedRecords={selectedRecords}
					setSelectedRecords={setSelectedRecords}
					existingIds={existingIds}
				/>
			</div>
		</ConfigProvider>
	</SimpleModal>
)

export const ProductPickerModal = memo(ProductPickerModalComponent)
