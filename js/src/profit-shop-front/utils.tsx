/**
 * 分潤賣場前台工具函式
 *
 * 照搬舊版一頁商店 `utils/functions/common.tsx`（renderHTML / getCurrencyString /
 * getPrice / formatYoutubeLinkToIframe）與 `utils/custom/index.tsx`（getStockQty）。
 */

import { createElement } from 'react'

import type { TFrontProduct, TStockInfo } from './types'

/**
 * 將後端輸出的 HTML 字串轉為 React 節點
 *
 * ⚠ 這裡刻意使用 `dangerouslySetInnerHTML`：商品名稱與描述是站長在 WordPress 編輯器
 * 撰寫、由 PHP 端 `wpautop` 處理過的 HTML（含 `<p>` / `<strong>` / 圖片等），
 * 必須以 HTML 渲染才能保留排版。內容來源是後台管理者，不是使用者輸入。
 *
 * @param  rawHTML 後端輸出的 HTML 字串
 * @return {React.ReactElement} 包住該 HTML 的 div 節點
 */
export const renderHTML = (rawHTML: string) =>
	createElement('div', { dangerouslySetInnerHTML: { __html: rawHTML } })

/**
 * 組出帶貨幣符號的價格字串
 *
 * @param  root0        參數物件
 * @param  root0.price  價格（字串保留十進位精度）
 * @param  root0.symbol 貨幣符號，預設 `NT$`
 * @return {string} 例：`NT$ 1200`；`price` 為 null / undefined 時回空字串
 */
export const getCurrencyString = ({
	price,
	symbol = 'NT$',
}: {
	price: number | string | undefined | null
	symbol?: string
}): string => {
	if (price === null || price === undefined) return ''
	if (typeof price === 'string') return `${symbol} ${price}`
	return `${symbol} ${price.toString()}`
}

/**
 * 將 Store API 的「最小單位整數」金額還原為顯示金額
 *
 * WC Store API 的 totals 一律是最小單位整數字串（例如 currency_minor_unit=2 時
 * `'120000'` 代表 1200.00），必須除以 10^minor_unit 才是給人看的數字。
 *
 * @param  cartPrice           Store API 金額（已轉 number）
 * @param  currency_minor_unit 幣別小數位數，預設 0（TWD）
 * @return {number} 顯示用金額
 */
export const getPrice = (cartPrice: number, currency_minor_unit = 0): number =>
	cartPrice / Math.pow(10, currency_minor_unit)

/**
 * 把內容中的 YouTube 連結換成 iframe 嵌入
 *
 * @param  rawContent 原始 HTML 內容
 * @return {string} 已將 YouTube 連結替換為 iframe 的 HTML
 */
export const formatYoutubeLinkToIframe = (rawContent: string): string => {
	let content = rawContent
	const youtubeRegex = /https:\/\/www\.youtube\.com\/watch\?v=([A-Za-z0-9_-]+)/g
	const fullYoutubeRegex = /https:\/\/www\.youtube\.com\/watch\?v=(.+)/g
	const youtubeLinks = content.match(youtubeRegex)
	const fullYoutubeLinks = content.match(fullYoutubeRegex)

	if (youtubeLinks && fullYoutubeLinks) {
		for (let i = 0; i < youtubeLinks.length; i++) {
			const videoLink = youtubeLinks[i]

			// 建立嵌入式 iframe
			const iframe = document.createElement('iframe')
			const styles = {
				width: '100%',
				border: 'none',
				aspectRatio: '16/9',
			}
			iframe.src =
				'https://www.youtube.com/embed/' +
				videoLink.substr(videoLink.lastIndexOf('=') + 1)
			Object.assign(iframe.style, styles)

			// 替換原始連結
			content = content.replace(fullYoutubeLinks[i], iframe.outerHTML)
		}
	}

	return content
}

/** 未啟用庫存管理時的預設庫存資訊 */
export const defaultStockInfo: TStockInfo = {
	manageStock: false,
	stockQuantity: null,
	stockStatus: 'instock',
}

/**
 * 取得可購買的庫存上限
 *
 * 「單獨銷售」（`is_sold_individually`）優先於一切，一律回 1——這對齊後端
 * `WC_Product::get_max_purchase_quantity()` 的判斷順序。少了這一條，未管理庫存的
 * 限購商品會被算成 `Infinity`，購物車的 `+` 可以一路按上去，直到結帳才被擋。
 *
 * 其餘情況：允許延期交貨（backorders !== 'no'）時視為無上限（Infinity）。
 * 有選變體時看變體庫存，否則看商品層庫存。
 *
 * @param  product             商品
 * @param  selectedVariationId 已選變體 ID（null = 尚未選）
 * @return {number} 庫存數量，無上限時為 Infinity
 */
export const getStockQty = (
	product: TFrontProduct,
	selectedVariationId: number | null
): number => {
	if (product?.is_sold_individually) return 1

	const backorders = product?.backorders ?? 'no'
	if (backorders !== 'no') return Infinity

	let stock = defaultStockInfo
	if (!selectedVariationId) {
		stock = product?.stock ?? defaultStockInfo
	} else {
		const variation = product?.variations?.find(
			(v) => v.variation_id === selectedVariationId
		)
		stock = variation?.stock ?? defaultStockInfo
	}

	const { stockQuantity } = stock

	return stockQuantity ?? Infinity
}
