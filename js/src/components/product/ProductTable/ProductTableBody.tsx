import { Table, FormInstance, TableProps } from 'antd'
import {
	defaultTableProps,
	getDefaultPaginationProps,
	Card,
} from 'antd-toolkit'
import {
	ProductFilter,
	TProductFilterProps,
	FilterTags,
} from 'antd-toolkit/refine'
import { productKeyLabelMapper } from 'antd-toolkit/wp'
import { memo, type ReactNode } from 'react'

import { useValueLabelMapper } from '@/components/product/ProductTable/hooks'
import { useProductTableQuery } from '@/components/product/ProductTable/useProductTableQuery'
import { TProductRecord, TProductVariation } from '@/components/product/types'
import { useProductsOptions } from '@/hooks'

type TQueryResult = ReturnType<typeof useProductTableQuery>

type TProductTableBodyProps = {
	searchFormProps: TQueryResult['searchFormProps']
	tableProps: TQueryResult['tableProps']
	columns: TableProps<TProductRecord | TProductVariation>['columns']
	valueLabelMapper: ReturnType<typeof useValueLabelMapper>['valueLabelMapper']
	options: ReturnType<typeof useProductsOptions>
	rowSelection?: TableProps<TProductRecord>['rowSelection']

	/** 渲染在列表 Card 內、Table 之上（`#/products` 放「新增商品」；Modal 放上限提示） */
	tableHeader?: ReactNode
	scrollX?: number

	/**
	 * 是否顯示 Card 外框
	 *
	 * 預設 `true` —— `#/products` 不傳即維持 `admin-list-ui.rule.md` 的標準兩卡片版型。
	 * 內嵌在 Modal 裡時傳 `false`（Modal 本身已是一層容器，再套卡片是多餘的視覺層次）。
	 *
	 * ⚠️ `antd-toolkit` 的 `Card` 在 `showCard={false}` 時是**直接回傳 children**，
	 * 連 `title` 與 `className` 都不會渲染 —— 所以篩選卡片的 `mb-4` 也會一起消失，
	 * 底下 FilterTags 的容器要補上對應間距。
	 */
	showCard?: boolean
}

/**
 * 商品列表的呈現主體（篩選卡片 + 列表卡片）
 *
 * **presentational only** —— 不持有任何查詢或全域狀態：
 * `useTable` / Jotai / `useRowSelection` / `ActionArea` 一律留在 caller。
 * 這樣 `#/products` 外殼仍能拿到 `searchFormProps`（重設表單）與
 * `tableProps.dataSource`（Jotai 同步），不必為了共用而開洞。
 *
 * 由 `#/products` 與 ProductPickerModal 共用。
 */
const ProductTableBodyComponent = ({
	searchFormProps,
	tableProps,
	columns,
	valueLabelMapper,
	options,
	rowSelection,
	tableHeader,
	scrollX = 1400,
	showCard = true,
}: TProductTableBodyProps) => {
	return (
		<>
			<Card title="篩選" className="mb-4" showCard={showCard}>
				<ProductFilter searchFormProps={searchFormProps} options={options} />

				{/* 無卡片模式下 Card 的 mb-4 不會渲染，改由這裡墊出與列表區的間距 */}
				<div className={showCard ? 'mt-2' : 'mt-2 mb-4'}>
					<FilterTags<TProductFilterProps>
						form={
							{ ...searchFormProps?.form } as FormInstance<TProductFilterProps>
						}
						keyLabelMapper={productKeyLabelMapper}
						valueLabelMapper={valueLabelMapper}
						booleanKeys={[
							'featured',
							'downloadable',
							'virtual',
							'sold_individually',
						]}
					/>
				</div>
			</Card>
			<Card showCard={showCard}>
				{tableHeader}
				<Table
					{...(defaultTableProps as unknown as TableProps<TProductRecord>)}
					{...tableProps}
					pagination={{
						...tableProps.pagination,
						...getDefaultPaginationProps({ label: '商品' }),
					}}
					rowSelection={rowSelection}
					columns={columns}
					scroll={{
						x: scrollX,
					}}
				/>
			</Card>
		</>
	)
}

export const ProductTableBody = memo(ProductTableBodyComponent)
