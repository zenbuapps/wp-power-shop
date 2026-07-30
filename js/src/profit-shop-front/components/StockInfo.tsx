/**
 * 庫存資訊
 *
 * 照搬舊版 `components/StockInfo/index.tsx`：依 stockStatus / manageStock / stockQuantity
 * 顯示「還有庫存 / 只剩最後 N 件 / 剩餘 N 件 / 已售完 / 延期交貨」，並附上「已加入購物車 N 件」。
 *
 * `showStock` 為 false 時整個元件不輸出（賣場設定關閉庫存顯示）。
 */

import {
	CheckCircleFilled,
	CloseCircleFilled,
	ExclamationCircleFilled,
} from '@ant-design/icons'
import { useAtomValue } from 'jotai'
import { memo } from 'react'

import { cartDataAtom } from '../atoms'
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

/** 已加入購物車 N 件（qty 為 0 / undefined 時不輸出） */
const InCart: React.FC<{ qty?: number; text?: string }> = ({
	qty,
	text = '，已加入購物車',
}) => {
	if (!qty) return <></>
	return (
		<>
			{text}
			{qty !== undefined ? ` ${qty} 件` : ''}
		</>
	)
}

/** 有庫存 */
const InStock: React.FC<{
	qty?: number
	text?: string
	qtyInCart?: number
}> = ({ qty, text = '此商品還有庫存', qtyInCart }) => {
	return (
		<>
			<CheckCircleFilled className="mr-2 text-green-500" />
			{text}
			{qty !== undefined ? ` ${qty} 件` : ''}
			<InCart qty={qtyInCart} />
		</>
	)
}

/** 延期交貨 */
const Onbackorder: React.FC<{ qtyInCart?: number }> = ({ qtyInCart }) => (
	<>
		<ExclamationCircleFilled className="mr-2 text-orange-500" />
		此商品為延期交貨商品
		<InCart qty={qtyInCart} />
	</>
)

/**
 * 依庫存狀態組出顯示節點
 *
 * @param  product             商品
 * @param  selectedVariationId 已選變體 ID
 * @param  qtyInCart           購物車既有數量
 * @return {React.ReactNode} 庫存文案節點
 */
function getStockText(
	product: TFrontProduct,
	selectedVariationId: number | null,
	qtyInCart: number
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
			if (!manageStock) return <InStock qtyInCart={qtyInCart} />
			if (manageStock && !!stockQuantity) {
				if (stockQuantity <= 10)
					return (
						<InStock
							qty={stockQuantity}
							text="此商品只剩最後"
							qtyInCart={qtyInCart}
						/>
					)
				return (
					<InStock
						qty={stockQuantity}
						text="此商品剩餘"
						qtyInCart={qtyInCart}
					/>
				)
			}
			return <SoldOut />

		case 'outofstock':
			return <SoldOut />
		case 'onbackorder':
			return <Onbackorder qtyInCart={qtyInCart} />
		default:
			return <InStock qtyInCart={qtyInCart} />
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
	const cartData = useAtomValue(cartDataAtom)

	if (!showStock) return <></>

	const cartItems = cartData?.items ?? []
	const qtyInCart =
		cartItems.find((item) => item.id === product?.id)?.quantity ?? 0

	const stockText = getStockText(product, selectedVariationId, qtyInCart)

	return <p className="m-0 text-gray-500 text-xs">{stockText}</p>
}

const StockInfo = memo(StockInfoComponent)

export default StockInfo
