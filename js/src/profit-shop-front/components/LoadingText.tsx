/**
 * 文字骨架載入態
 *
 * 照搬舊版 `components/PureComponents/LoadingText.tsx`：
 * `isLoading` 為 true 時顯示一段灰色 pulse 方塊，否則顯示 `content`。
 * 購物車小計 / 運費 / 合計都用它避免數字閃動。
 *
 * 與舊版差異：舊版直接 `return content`（ReactNode），在 React 18 型別下不合法，
 * 改為 `return <>{content}</>`（行為完全相同）。
 */

import { memo } from 'react'

/** LoadingText props */
type TLoadingTextProps = {
	height?: string // Tailwind 高度 class

	/** Tailwind 寬度 class */
	width?: string

	/** 是否顯示骨架 */
	isLoading?: boolean

	/** 載入完成後要顯示的內容 */
	content?: React.ReactNode

	/** 額外 class */
	className?: string
}

/** 文字骨架載入態元件 */
const LoadingTextComponent: React.FC<TLoadingTextProps> = ({
	height = 'h-[1rem]',
	width = 'w-[8rem]',
	isLoading = true,
	content = '',
	className = '',
}) => {
	return isLoading ? (
		<div
			className={`${height} ${width}  bg-slate-300 animate-pulse rounded-sm relative inline-block top-[2px] ${className}`}
		></div>
	) : (
		<>{content}</>
	)
}

const LoadingText = memo(LoadingTextComponent)

export default LoadingText
