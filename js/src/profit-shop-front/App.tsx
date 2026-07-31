/**
 * 分潤賣場前台 App
 *
 * 對應舊版 `App3.tsx` + `pages/PowerShopProducts/index.tsx` + `Main.tsx`：
 * PHP 已印出整個商品 grid，React 只負責掛上互動層——
 * 掃描 `div[data-ps-product-id]`、移除 `ps-not-ready`、綁 click 開商品 Modal。
 *
 * 與舊版的四處差異（皆為刻意修正）：
 *
 * 1. **cleanup 真的會執行**：舊版把 `return () => el.removeEventListener(...)` 寫在
 *    `els.forEach()` 的 callback 裡——那是 forEach callback 的回傳值，`useEffect`
 *    完全拿不到，等於沒有 cleanup。此處改為蒐集 `{ el, handler }` 後，在
 *    `useEffect` 的 return 統一解綁。
 * 2. **不需要 loading 態**：舊版靠 AJAX 取回 settings 成功後才移除 `ps-not-ready`；
 *    新版 settings 由 PHP `wp_localize_script` 直接帶入，mount 當下即可解鎖。
 * 3. **不渲染 Empty**：商品 grid 與空狀態都由 PHP 負責，React 再畫一份會重複。
 * 4. **不渲染購物車**：賣場前台只做「展示 + 商品 Modal」，加購一律交給
 *    WooCommerce 原生表單流程（見 `ProductModal/AddToCartButton.tsx`），
 *    購物車與結帳走 WooCommerce 自己的頁面。
 */

import { useAtom, useSetAtom } from 'jotai'
import { memo, useEffect } from 'react'

import { isProductModalOpenAtom, modalProductIdAtom } from './atoms'
import { products } from './env'
import ProductModal from './ProductModal'

/** PHP 商品卡片的 selector（DOM 契約，不可更動） */
const PRODUCT_CARD_SELECTOR = 'div[data-ps-product-id]'

/** PHP 預先加上的「尚未就緒」class，React mount 後移除以解鎖點擊 */
const NOT_READY_CLASS = 'ps-not-ready'

/** 分潤賣場前台互動層 */
const AppComponent = () => {
	const setIsProductModalOpen = useSetAtom(isProductModalOpenAtom)
	const [modalProductId, setModalProductId] = useAtom(modalProductIdAtom)
	const modalProduct = products.find((p) => p.id === modalProductId)

	useEffect(() => {
		const els = Array.from(
			document.querySelectorAll<HTMLElement>(PRODUCT_CARD_SELECTOR)
		)
		const bindings: { el: HTMLElement; handler: (e: Event) => void }[] = []

		els.forEach((el) => {
			el.classList.remove(NOT_READY_CLASS)

			const productId = parseInt(
				el.getAttribute('data-ps-product-id') ?? '0',
				10
			)
			if (!productId) return

			const handler = (e: Event) => {
				e.preventDefault()
				e.stopPropagation()
				setIsProductModalOpen(true)
				setModalProductId(productId)
			}

			el.addEventListener('click', handler)
			bindings.push({ el, handler })
		})

		return () => {
			bindings.forEach(({ el, handler }) => {
				el.removeEventListener('click', handler)
			})
		}
	}, [setIsProductModalOpen, setModalProductId])

	return (
		<div className="power-shop-products">
			{!!modalProduct && <ProductModal product={modalProduct} />}
		</div>
	)
}

const App = memo(AppComponent)

export default App
