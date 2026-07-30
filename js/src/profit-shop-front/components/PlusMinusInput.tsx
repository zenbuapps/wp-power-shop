/**
 * 數量選擇器
 *
 * 照搬舊版 `components/PlusMinusInput/index.tsx`：
 * 傳入 `stockQty` 時自動當成 `max`（負數視為無效，不設上限）。
 * `-` / `+` 兩顆 addon 由 `usePlusMinusInput` 提供（樣式見 styles.css 的 `.ps-addon`）。
 *
 * 與舊版差異：舊版 `import './style.scss'`，新版樣式統一收在 `profit-shop-front/styles.css`。
 */

import { InputNumber, type InputNumberProps } from 'antd'
import { memo } from 'react'

/** PlusMinusInput props：antd InputNumber props + 庫存上限 */
type TPlusMinusInputProps = InputNumberProps<number> & {
	stockQty?: number | null // 庫存上限；null / undefined 代表不設上限
}

/** 帶加減按鈕的數量輸入元件 */
const PlusMinusInputComponent: React.FC<TPlusMinusInputProps> = ({
	stockQty,
	...inputNumberProps
}) => {
	const getProps = (v: number | undefined | null) => {
		if (v === undefined || v === null) return inputNumberProps

		if (v >= 0) {
			return {
				...inputNumberProps,
				max: v,
			}
		}
		return inputNumberProps
	}
	const props = getProps(stockQty)

	return <InputNumber {...props} />
}

const PlusMinusInput = memo(PlusMinusInputComponent)

export default PlusMinusInput
