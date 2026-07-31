/**
 * 庫存資訊
 *
 * 照搬舊版 `components/StockInfo/index.tsx`：依 stockStatus / manageStock / stockQuantity
 * 顯示「還有庫存 / 只剩最後 N 件 / 剩餘 N 件 / 已售完 / 延期交貨」。
 *
 * `showStock` 為 false 時整個元件不輸出（賣場設定關閉庫存顯示）。
 *
 * ⚠ 與舊版的差異：拿掉「，已加入購物車 N 件」這段附註。加購改走 WooCommerce 原生
 * 表單後，前台不再持有購物車狀態（沒有 `cartDataAtom`，也不打任何購物車 API），
 * 這個數字沒有資料來源。硬要顯示就得為了一句附註把整條購物車讀取通道接回來，
 * 代價遠大於效益——使用者要看購物車內容，WooCommerce 自己的購物車頁就是正解。
 */

import {
	CheckCircleFilled,
	CloseCircleFilled,
	ExclamationCircleFilled,
} from '@ant-design/icons'
import { memo } from 'react'

import { showStock } from '../env'
import type { TFrontProduct, TStockInfo } from '../types'
import { defaultStockInfo } from '../utils'

/** 已售完 */
const SoldOut: React.FC = () => (
	<>
		<CloseCircleFilled className="mr-2 text-red-500" />
		此商品已售完
	</>
)

/** 有庫存 */
const InStock: React.FC<{
	qty?: number
	text?: string
}> = ({ qty, text = '此商品還有庫存' }) => {
	return (
		<>
			<CheckCircleFilled className="mr-2 text-green-500" />
			{text}
			{qty !== undefined ? ` ${qty} 件` : ''}
		</>
	)
}

/** 延期交貨 */
const Onbackorder: React.FC = () => (
	<>
		<ExclamationCircleFilled className="mr-2 text-orange-500" />
		此商品為延期交貨商品
	</>
)

/**
 * 依庫存狀態組出顯示節點
 *
 * @param  product             商品
 * @param  selectedVariationId 已選變體 ID
 * @return {React.ReactNode} 庫存文案節點
 */
function getStockText(
	product: TFrontProduct,
	selectedVariationId: number | null
) {
	let stock: TStockInfo = defaultStockInfo
	if (!selectedVariationId) {
		stock = product?.stock ?? defaultStockInfo
	} else {
		const variation = product?.variations?.find(
			(v) => v.variation_id === selectedVariationId
		)
		stock = variation?.stock ?? defaultStockInfo
	}

	const { manageStock, stockQuantity, stockStatus } = stock

	switch (stockStatus) {
		case 'instock':
			if (!manageStock) return <InStock />
			if (manageStock && !!stockQuantity) {
				if (stockQuantity <= 10)
					return <InStock qty={stockQuantity} text="此商品只剩最後" />
				return <InStock qty={stockQuantity} text="此商品剩餘" />
			}
			return <SoldOut />

		case 'outofstock':
			return <SoldOut />
		case 'onbackorder':
			return <Onbackorder />
		default:
			return <InStock />
	}
}

/** StockInfo props */
type TStockInfoProps = {
	product: TFrontProduct
	selectedVariationId: number | null
}

/** 庫存資訊元件 */
const StockInfoComponent: React.FC<TStockInfoProps> = ({
	product,
	selectedVariationId,
}) => {
	if (!showStock) return <></>

	const stockText = getStockText(product, selectedVariationId)

	return <p className="m-0 text-gray-500 text-xs">{stockText}</p>
}

const StockInfo = memo(StockInfoComponent)

export default StockInfo
