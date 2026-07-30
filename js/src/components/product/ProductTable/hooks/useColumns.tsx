import { useNavigation } from '@refinedev/core'
import { useWindowSize } from '@uidotdev/usehooks'
import { Table, TableProps, Tag } from 'antd'
import {
	ProductName,
	ProductPrice,
	ProductTotalSales,
	ProductCat,
	ProductType,
	ProductStock,
	POST_STATUS,
	isVariation,
} from 'antd-toolkit/wp'

import { ProductActions } from '@/components/product/ProductTable/ProductActions'
import { TProductRecord, TProductVariation } from '@/components/product/types'
import { useProductsOptions } from '@/hooks'

/**
 * useColumns 參數
 *
 * `showActions`：是否顯示逐列「操作」欄。預設 `true` ——
 * `#/products` 不傳參數即維持原行為；ProductPickerModal 只做勾選、
 * 不做逐列操作，需明確傳 `false`。
 */
type TUseColumnsOpts = {
	showActions?: boolean
}

export const useColumns = ({ showActions = true }: TUseColumnsOpts = {}) => {
	const { width } = useWindowSize()
	const { edit } = useNavigation()
	const { product_cats = [], product_tags = [] } = useProductsOptions()
	const columns: TableProps<TProductRecord | TProductVariation>['columns'] = [
		Table.SELECTION_COLUMN,
		Table.EXPAND_COLUMN,
		{
			title: '商品名稱',
			dataIndex: 'name',
			width: 300,
			fixed: (width || 400) > 768 ? 'left' : undefined,

			/*
			 * `min-w-0 [&_.at-name]:truncate` 是長商品名稱不跑版的必要條件，勿刪。
			 *
			 * antd-toolkit 的 `NameId` 把截斷寫成 `[&_*]:at-truncate`（只作用在**後代元素**），
			 * 但 `ProductName` 傳進去的 `name` 是 `record.name` 純字串、沒有後代元素，
			 * 那條 truncate 因此從未生效 —— 名稱一長就會橫向溢出、蓋住右邊欄位。
			 * 這裡直接對 `.at-name`（antd-toolkit 的穩定 class）補上 truncate。
			 *
			 * `min-w-0` 則是給「ProductName 被放進 flex 容器」的情境用的（例如
			 * ProductPickerModal 為了外掛「已加入」Tag 而包了一層 flex）：flex item 的
			 * `min-width` 預設是 `auto`，不加這個就會拒絕收縮到小於內容寬度，
			 * 內層所有 `min-w-0` / truncate 都會一起失效。放在 `<td>` 裡則無副作用。
			 */
			render: (_, record) => (
				<ProductName<TProductRecord>
					record={record}
					className="min-w-0 [&_.at-name]:truncate"
					onClick={() => edit('products', record?.id)}
				/>
			),
		},
		{
			title: '商品類型',
			dataIndex: 'type',
			width: 180,
			render: (_, record) => <ProductType record={record as any} />,
		},
		{
			title: '狀態',
			dataIndex: 'status',
			width: 80,
			align: 'center',
			render: (_, record) => {
				const status = POST_STATUS.find((item) => item.value === record?.status)
				return <Tag color={status?.color}>{status?.label}</Tag>
			},
		},
		{
			title: '總銷量',
			dataIndex: 'total_sales',
			width: 80,
			align: 'center',
			render: (_, record) => (
				<ProductTotalSales record={record} max_sales={10} />
			),
		},
		{
			title: '價格',
			dataIndex: 'price',
			width: 150,
			render: (_, record) => <ProductPrice record={record} />,
		},
		{
			title: '庫存',
			dataIndex: 'stock',
			width: 150,
			align: 'center',
			render: (_, record) => <ProductStock<TProductRecord> record={record} />,
		},
		{
			title: '商品分類 / 商品標籤',
			dataIndex: 'category_ids',
			render: (_, { category_ids = [], tag_ids = [] }) => {
				const categories = product_cats.filter(({ value }) =>
					category_ids.includes(value)
				)
				const tags = product_tags.filter(({ value }) =>
					tag_ids?.includes(value)
				)
				return <ProductCat categories={categories} tags={tags} />
			},
		},
	]

	if (showActions) {
		columns.push({
			title: '操作',
			dataIndex: '_actions',
			align: 'center',
			width: 100,
			render: (_, record) =>
				!isVariation(record?.type as string) && (
					<ProductActions record={record} />
				),
		})
	}

	return columns
}
