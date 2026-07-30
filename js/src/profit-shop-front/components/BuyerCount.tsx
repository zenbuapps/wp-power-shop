/**
 * 購買人數
 *
 * 照搬舊版 `components/BuyerCount/index.tsx`：
 * 顯示 `total_sales + extraBuyerCount`（extraBuyerCount = 賣場設定的灌水購買人數）。
 *
 * - simple：直接顯示（火焰 icon 紅色）
 * - variable：只有選定變體後才顯示（火焰 icon 藍色，沿用舊版配色差異）
 * - 其他類型：不顯示
 *
 * `showBuyerCount` 為 false 時整個元件不輸出。
 */

import { FireFilled } from '@ant-design/icons'
import { memo } from 'react'

import { showBuyerCount } from '../env'
import type { TFrontProduct } from '../types'

/** BuyerCount props */
type TBuyerCountProps = {
	product: TFrontProduct
	selectedVariationId: number | null
}

/** 購買人數元件 */
const BuyerCountComponent: React.FC<TBuyerCountProps> = ({
	product,
	selectedVariationId,
}) => {
	if (!showBuyerCount) return <></>
	const { type } = product
	const count = product?.total_sales || 0

	if (type === 'simple') {
		const extraBuyerCount = product?.extraBuyerCount || 0
		return (
			<p className="m-0 text-gray-500 text-xs">
				<FireFilled className="mr-2 text-red-400" />
				已有 {count + extraBuyerCount} 人購買
			</p>
		)
	}

	if (type === 'variable') {
		if (selectedVariationId) {
			const variation = product?.variations?.find(
				(v) => v.variation_id === selectedVariationId
			)
			const extraBuyerCount = variation?.extraBuyerCount || 0
			return (
				<p className="m-0 text-gray-500 text-xs">
					<FireFilled className="mr-2 text-blue-500" />
					已有 {count + extraBuyerCount} 人購買
				</p>
			)
		}
		return <></>
	}

	return <></>
}

const BuyerCount = memo(BuyerCountComponent)

export default BuyerCount
