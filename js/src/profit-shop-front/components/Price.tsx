/**
 * 價格顯示
 *
 * 照搬舊版 `components/Price/index.tsx`：
 * 有特價時上排原價劃線、下排紅字特價；無特價時只顯示原價。
 */

import { memo } from 'react'

import { getCurrencyString } from '../utils'

/** Price props */
type TPriceProps = {
	salePrice: number | string | undefined | null // 特價（已套用賣場覆寫）

	/** 原價（已套用賣場覆寫） */
	regularPrice: number | string | undefined | null
}

/** 價格顯示元件 */
const PriceComponent: React.FC<TPriceProps> = ({ salePrice, regularPrice }) => {
	return salePrice ? (
		<div>
			<p className="mb-0">
				<del>{getCurrencyString({ price: regularPrice })}</del>
			</p>
			<p className="mb-0 text-red-500">
				{getCurrencyString({ price: salePrice })}
			</p>
		</div>
	) : (
		<div>
			<p className="mb-0">{getCurrencyString({ price: regularPrice })}</p>
		</div>
	)
}

const Price = memo(PriceComponent)

export default Price
