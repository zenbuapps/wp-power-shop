import { Alert, Tag, type TableProps } from 'antd'
import { SimpleModal } from 'antd-toolkit'
import { isVariation } from 'antd-toolkit/wp'
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
					render: (
						value: unknown,
						record: TPickerRecord,
						index: number
					): ReactNode => (
						<div className="tw-flex tw-items-center tw-gap-2">
							{originalRender?.(value, record, index) as ReactNode}
							{existingIds.has(Number(record?.id)) ? (
								<Tag color="green">已加入</Tag>
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
	const addRecords = (records: TPickerRecord[]) => {
		setSelectedRecords((prev) => {
			const known = new Set(prev.map((record) => record.id))
			const additions = records.filter(
				(record) => !known.has(record.id)
			) as TProductRecord[]
			const room = Math.max(MAX_BATCH_SIZE - prev.length, 0)
			return [...prev, ...additions.slice(0, room)]
		})
	}

	const removeRecords = (records: TPickerRecord[]) => {
		const removing = new Set(records.map((record) => record.id))
		setSelectedRecords((prev) =>
			prev.filter((record) => !removing.has(record.id))
		)
	}

	const rowSelection: TableProps<TProductRecord>['rowSelection'] = {
		selectedRowKeys: selectedKeys,
		getCheckboxProps: (record) => {
			const isVariationRow = isVariation(record?.type as string)

			// R-2：TProductRecord.id 是 string、product_id 是 number，一律轉 number 再比對，
			// 否則 Set.has 永遠 false → 已加入的商品不會被禁選 → 可重複加入
			const productId = Number(record?.id)
			const alreadyInShop =
				Number.isFinite(productId) && existingIds.has(productId)
			const blockedByLimit = isAtLimit && !selectedKeySet.has(record?.id)

			return {
				disabled: isVariationRow || alreadyInShop || blockedByLimit,
				className: isVariationRow ? 'tw-hidden' : '',
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
	</SimpleModal>
)

export const ProductPickerModal = memo(ProductPickerModalComponent)
