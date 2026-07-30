/**
 * 商品內容展開 / 收合
 *
 * 照搬舊版 `pages/PowerShopProducts/ProductModal/ToggleContent/index.tsx`：
 * 內容高度超過 299px 時，把 `showReadMoreAtom` 設為 true（ProductModal 右側才會出現
 * 展開 / 收合的側邊把手），並在收合狀態下以白色漸層遮住底部、點擊漸層即展開。
 *
 * 與舊版差異：漸層遮罩由 `<div onClick>` 改為 `<div role="button" tabIndex={0}>` 並補
 * `onKeyDown`（`jsx-a11y` 要求可點擊元素具備鍵盤可操作性）。
 *
 * ⚠ **刻意不用原生 `<button>`**：本專案 Tailwind `preflight: false`，沒有
 * `button { font: inherit; background: none; ... }` 這類 reset，佈景主題對 `button`
 * 設的 `padding` / `min-height` / `box-shadow` / `:hover` 會直接蓋進來，把漸層遮罩
 * 撐歪或蓋掉。`<div role="button">` 的算繪結果與舊版的 `<div onClick>` 完全相同。
 * 同樣的理由見 `AddToCartButton` 的「前往購物車」與 `Gallery` 的縮圖。
 */

import { useAtom } from 'jotai'
import { memo, useEffect, useRef } from 'react'

import { isExpandAtom, showReadMoreAtom } from '../atoms'
import { renderHTML } from '../utils'

/** 超過這個高度（px）才需要「展開全部內容」 */
const READ_MORE_THRESHOLD = 299

/** ToggleContent props */
type TToggleContentProps = {
	content: string // 商品描述 HTML（已由 PHP wpautop）
}

/** 商品內容展開 / 收合元件 */
const ToggleContentComponent: React.FC<TToggleContentProps> = ({ content }) => {
	const [isExpand, setIsExpand] = useAtom(isExpandAtom)
	const [showReadMore, setShowReadMore] = useAtom(showReadMoreAtom)
	const html = renderHTML(content)

	const divRef = useRef<HTMLDivElement>(null)

	useEffect(() => {
		// setTimeout 0：等瀏覽器完成 layout 後才量得到正確 clientHeight
		const timeOut = setTimeout(() => {
			const divHeight = divRef.current?.clientHeight || 0
			setShowReadMore(divHeight > READ_MORE_THRESHOLD)
		}, 0)

		return () => {
			clearTimeout(timeOut)
		}
	}, [content, setShowReadMore])

	const handleExpand = () => {
		setIsExpand(true)
	}

	/** 空白鍵 / Enter 等同點擊（`<div role="button">` 需自行補鍵盤操作） */
	const handleKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
		if (event.key !== 'Enter' && event.key !== ' ') return
		event.preventDefault()
		handleExpand()
	}

	return (
		<div ref={divRef} className="h-full overflow-hidden relative">
			<div className={isExpand ? 'h-full' : 'max-h-[300px]'}>{html}</div>
			{!isExpand && showReadMore && (
				<div
					role="button"
					tabIndex={0}
					aria-label="展開全部內容"
					onClick={handleExpand}
					onKeyDown={handleKeyDown}
					className="absolute bottom-0 text-center w-full pb-4 pt-12 bg-gradient-to-t from-white to-white/0 cursor-pointer"
				/>
			)}
		</div>
	)
}

const ToggleContent = memo(ToggleContentComponent)

export default ToggleContent
