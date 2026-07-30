/**
 * 數量選擇器 props hook
 *
 * 照搬舊版 `hooks/componentProps/usePlusMinusInput.tsx`：
 * 自帶 value state，並產生 `-` / `+` 兩顆 addon（樣式見 styles.css 的 `.ps-addon`）。
 * 下限固定 1，上限由 `max` 決定（未傳則 Infinity）。
 *
 * 兩處與舊版的差異：
 * 1. 舊版是 `.tsx` 用 JSX 建 addon；本檔按規格為 `.ts`，改用 `createElement`（等價輸出）
 * 2. state 型別由 `InputNumberProps` 收斂為 `InputNumberProps<number>`，
 *    讓呼叫端 `setPlusMinusInputProps({ ...props, value: 1 })` 不需要 `as` 斷言
 *    （舊版因 strictFunctionTypes 的 onChange 逆變問題必須硬轉型）
 */

import { type InputNumberProps } from 'antd'
import { createElement, useState } from 'react'

/** 數量下限 */
const MIN = 1

/**
 * 產生 PlusMinusInput 所需的 props
 *
 * @param  max 數量上限（通常是 `庫存 - 購物車既有數量`）
 * @return {Object} props 與 setter
 */
export const usePlusMinusInput = (max?: number) => {
	const [plusMinusInputProps, setPlusMinusInputProps] = useState<
		InputNumberProps<number>
	>({
		value: 1,
		defaultValue: 1,
	})

	const MAX = max ?? Infinity

	const handleMinus = () => {
		const value = Number(plusMinusInputProps?.value || 1)
		if (value <= MIN) return
		setPlusMinusInputProps({
			...plusMinusInputProps,
			value: value - 1,
		})
	}

	const handlePlus = () => {
		const value = Number(plusMinusInputProps?.value || 1)
		if (value >= MAX) return
		setPlusMinusInputProps({
			...plusMinusInputProps,
			value: value + 1,
		})
	}

	const handleChange = (v: number | null) => {
		setPlusMinusInputProps({
			...plusMinusInputProps,
			value: v || 1,
		})
	}

	return {
		plusMinusInputProps: {
			...plusMinusInputProps,
			min: MIN,
			className: 'w-full',
			addonBefore: createElement(
				'span',
				{ className: 'ps-addon', onClick: handleMinus },
				'-'
			),
			addonAfter: createElement(
				'span',
				{ className: 'ps-addon', onClick: handlePlus },
				'+'
			),
			onChange: handleChange,
		} as InputNumberProps<number>,
		setPlusMinusInputProps,
	}
}
