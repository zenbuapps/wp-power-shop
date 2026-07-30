/**
 * 商品圖片畫廊
 *
 * 照搬舊版 `components/Gallery/index.tsx`：主圖 + 4 欄縮圖，點縮圖換主圖；
 * `selectedImage` 變動時（選了變體）自動切到對應圖，找不到則退回第一張。
 *
 * 與舊版的兩處差異（皆為必要修正）：
 * 1. 舊版把 `if (images.length === 0) return` 寫在 `useState` / `useEffect` **之前**，
 *    違反 React hooks 規則（`react-hooks/rules-of-hooks`）。此處把 hooks 全部提到最上方。
 * 2. 補上 `alt`，並把縮圖的 `onClick` 包進 `<div role="button" tabIndex={0}>` 以滿足
 *    `jsx-a11y`（舊版是 `<img onClick>`，無鍵盤操作性）。
 *
 * ⚠ **刻意不用原生 `<button>` 包縮圖**：本專案 Tailwind `preflight: false`，沒有
 * button reset，佈景主題對 `button` 設的 `padding` / `min-height` / `min-width` /
 * `display` / `line-height` 會蓋進來。縮圖是 `grid-cols-4` 的直接子元素，一旦 grid item
 * 從 `<img>` 換成帶主題樣式的 `<button>`，`aspect-square` 的正方形排列會被撐歪，
 * focus 時還會多一圈瀏覽器預設 outline。`<div>` 沒有瀏覽器預設樣式，主題也極少對裸
 * `div` 設規則，算繪結果與舊版 `<img>` 直接當 grid item 相同。
 * 同樣的理由見 `ToggleContent` 的漸層遮罩與 `AddToCartButton` 的「前往購物車」。
 */

import { memo, useEffect, useState } from 'react'

import { defaultImage } from '../defaultImage'

/** Gallery props */
type TGalleryProps = {
	images: string[] // 圖片網址陣列，主圖在 [0]

	/** 外部指定要顯示的圖（例如選中變體的圖），不在 images 內時退回第一張 */
	selectedImage?: string
}

/** 商品圖片畫廊元件 */
const GalleryComponent: React.FC<TGalleryProps> = ({
	images,
	selectedImage,
}) => {
	const [selected, setSelected] = useState<string>(images[0] ?? '')

	useEffect(() => {
		const isInclude = images.some((image) => image === selectedImage)
		if (!!selectedImage && isInclude) {
			setSelected(selectedImage)
		} else {
			setSelected(images[0] ?? '')
		}
	}, [selectedImage, images])

	if (images.length === 0) {
		return (
			<img
				className="aspect-square w-full object-cover"
				src={defaultImage}
				alt=""
			/>
		)
	}

	const handleClick = (src: string) => () => {
		setSelected(src || defaultImage)
	}

	/** 空白鍵 / Enter 等同點擊（`<div role="button">` 需自行補鍵盤操作） */
	const handleKeyDown =
		(src: string) => (event: React.KeyboardEvent<HTMLDivElement>) => {
			if (event.key !== 'Enter' && event.key !== ' ') return
			event.preventDefault()
			handleClick(src)()
		}

	const mainSrc =
		images.find((image) => image === selected) || images[0] || defaultImage

	return (
		<>
			<img className="aspect-square w-full object-cover" src={mainSrc} alt="" />
			{images.length > 1 && (
				<div className="mt-2 w-full grid grid-cols-4 gap-2">
					{images.map((image, i) => (
						<div
							key={`${image}-${i}`}
							role="button"
							tabIndex={0}
							onClick={handleClick(image)}
							onKeyDown={handleKeyDown(image)}
						>
							<img
								className={`aspect-square cursor-pointer object-cover w-full ${
									image === selected
										? 'border-2 border-blue-500 border-solid'
										: ''
								}`}
								src={image || defaultImage}
								style={{ width: '-webkit-fill-available' }}
								alt=""
							/>
						</div>
					))}
				</div>
			)}
		</>
	)
}

const Gallery = memo(GalleryComponent)

export default Gallery
