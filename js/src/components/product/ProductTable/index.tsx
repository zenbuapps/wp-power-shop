import { TableProps } from 'antd'
import { useRowSelection } from 'antd-toolkit'
import { ActionArea, SelectedItem } from 'antd-toolkit/refine'
import { isVariation } from 'antd-toolkit/wp'
import { useAtom } from 'jotai'
import { memo, useEffect } from 'react'

import { selectedProductsAtom } from '@/components/product/ProductTable/atom'
import BulkAction from '@/components/product/ProductTable/BulkAction'
import CreateButton from '@/components/product/ProductTable/Create'
import {
	useValueLabelMapper,
	useColumns,
} from '@/components/product/ProductTable/hooks'
import { ProductTableBody } from '@/components/product/ProductTable/ProductTableBody'
import { useProductTableQuery } from '@/components/product/ProductTable/useProductTableQuery'
import { TProductRecord } from '@/components/product/types'
import { useProductsOptions } from '@/hooks'

const ProductTableComponent = () => {
	const [selectedProducts, setSelectedProducts] = useAtom(selectedProductsAtom)
	const selectedProductIds = selectedProducts.map((product) => product.id)

	// 不傳 syncWithLocation：繼承 Refine 全域的 true，維持網址同步（R-1）
	const { searchFormProps, tableProps, filters } = useProductTableQuery()

	const { valueLabelMapper } = useValueLabelMapper()

	const currentAllKeys =
		tableProps?.dataSource?.map((record) => record?.id?.toString()) || []

	// 多選
	const { rowSelection, setSelectedRowKeys } = useRowSelection<TProductRecord>({
		getCheckboxProps: (record) => {
			// 只有可變商品可選，變體不可選
			const isVariationProduct = isVariation(record?.type as string)
			return {
				disabled: !!isVariationProduct,
				className: isVariationProduct ? 'tw-hidden' : '',
			}
		},
		onChange: (currentSelectedRowKeys: React.Key[]) => {
			setSelectedRowKeys(currentSelectedRowKeys)

			/** @type TProductRecord[] 不在這頁的已選擇商品 */
			const selectedProductsNotInCurrentPage = selectedProducts?.filter(
				(p) => !currentAllKeys?.includes(p?.id)
			)

			/** @type string[] 在這頁的已選擇商品 ids */
			const selectedProductIdsInCurrentPage = currentSelectedRowKeys?.map(
				(key) => key?.toString()
			)

			const selectedProductsInCurrentPage =
				tableProps?.dataSource?.reduce((acc, record) => {
					if (selectedProductIdsInCurrentPage?.includes(record?.id)) {
						acc.push(record)
					}
					return acc
				}, [] as TProductRecord[]) || []

			setSelectedProducts(() => {
				// 把這頁的已選商品加上 不在這頁的已選商品
				const newKeys = new Set([
					...selectedProductsNotInCurrentPage,
					...selectedProductsInCurrentPage,
				])
				return [...newKeys]
			})
		},
	})

	// 換頁時，將已加入的商品全局狀態同步到當前頁面的 selectedRowKeys 狀態
	useEffect(() => {
		if (!tableProps?.loading) {
			const filteredKey =
				currentAllKeys?.filter((id) => selectedProductIds?.includes(id)) || []
			setSelectedRowKeys(filteredKey)
		}
	}, [
		JSON.stringify(filters),
		JSON.stringify(tableProps?.pagination),
		tableProps?.loading,
	])

	useEffect(() => {
		// 如果清空已選擇的用戶，連帶清空 selectedRowKeys (畫面上的打勾)
		if (selectedProducts.length === 0) {
			setSelectedRowKeys([])
		}
	}, [selectedProducts.length])

	useEffect(() => {
		// 剛載入組件時，清空已選擇的用戶
		setSelectedProducts([])
	}, [])

	const columns = useColumns()
	const options = useProductsOptions()

	/*
	 * antd-toolkit 的 useRowSelection 型別宣告上游有誤
	 * （`rowSelection: TableProps<T_1>`，T_1 是懸空泛型），抽成 props 後型別對不上。
	 * ProductTableBody 對外維持正確的 antd 型別，這裡收斂上游的錯誤宣告；
	 * 執行期物件形狀本來就正確。
	 */
	const typedRowSelection =
		rowSelection as TableProps<TProductRecord>['rowSelection']

	return (
		<>
			<ProductTableBody
				searchFormProps={searchFormProps}
				tableProps={tableProps}
				columns={columns}
				valueLabelMapper={valueLabelMapper}
				options={options}
				rowSelection={typedRowSelection}
				tableHeader={<CreateButton className="mb-4" />}
			/>
			{!!selectedProducts.length && (
				<ActionArea>
					<BulkAction />
					<SelectedItem
						ids={selectedProductIds}
						label="商品"
						onClear={() => {
							setSelectedProducts([])
						}}
						onSelected={() => {
							const searchForm = searchFormProps?.form
							if (!searchForm) return
							searchForm.resetFields()
							searchForm.setFieldsValue({
								include: selectedProductIds,
							})
							searchForm.submit()
						}}
					/>
				</ActionArea>
			)}
		</>
	)
}

export const ProductTable = memo(ProductTableComponent)
