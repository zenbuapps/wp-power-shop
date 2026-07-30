/**
 * 加入購物車按鈕
 *
 * 照搬舊版一頁商店 `pages/PowerShopProducts/ProductModal/AddToCartButton/index.tsx`
 * 的全部行為，只把資料層從 legacy `useAjax`（`action: 'handle_add_cart'`）換成新契約
 * `api.addToCart()`（`POST /profit-shop-cart/add-item`，**內建 `profit_shop_id`**）。
 *
 * `profit_shop_id` 是後端注入分潤標記與價格覆寫簽章的唯一依據，漏送會讓整筆分潤失效；
 * 它由 `api.ts` 的 `addToCart()` 從 `env.shopId` 統一補上，本元件不需要（也不該）自己帶。
 *
 * 樂觀更新（照搬舊版，全部發生在 `onMutate`，不等伺服器回應）：
 * 1. 立刻彈「加入購物車成功」通知 + 撒彩帶 + 關閉 Modal（讓使用者可以馬上加下一件）
 * 2. 立刻把商品寫進 `cartDataAtom`（同商品同變體則累加數量），並標記 `isMutating`
 * 3. 回傳 rollback 資訊；`onError` 用它還原、`onSuccess` 讓購物車查詢失效以校正
 *
 * 與舊版的六處差異：
 * 1. **notification 改用 `App.useApp()`**（舊版是 `notification.useNotification()` +
 *    `contextHolder`）。`main.tsx` 已提供 `<AntdApp component={false}>` provider，
 *    靜態匯入的 notification 在本專案是禁用的（見 `.claude/rules/react.rule.md` §2）
 * 2. **「前往購物車」改用 inline `style={{ color: colorPrimary }}`**：舊版的
 *    `text-primary` 依賴 legacy 自己的 tailwind config（`colors.primary = #1677ff`），
 *    本專案 `tailwind.config.cjs` 沒有 `primary` 色，該 class 會安靜地不生效
 *    （見 `.claude/rules/react.rule.md` §10）。改吃賣場設定的主色，行為更正確
 * 3. **「前往購物車」補 `role="button"` / `tabIndex` / `onKeyDown`**：舊版是
 *    `<p onClick>`，`jsx-a11y` 不允許。刻意用 `<div role="button">` 而非原生
 *    `<button>`——通知是 portal 到 `document.body`，會吃到**佈景主題**的 CSS，而本專案
 *    Tailwind `preflight: false`（無 `button { font: inherit }` reset），主題對 `button`
 *    設的 font-weight / text-transform / letter-spacing 會蓋進來，字就跟舊版長不一樣了。
 *    `<div className="m-0">` 與舊版 `<p className="m-0">` 的算繪結果完全相同
 * 4. **修掉舊版 rollback 漏刪 bug**：舊版失敗時以 `variables.id`（= product_id）過濾，
 *    但樂觀項目的 id 對變體商品是 `variation_id`，導致變體加購失敗後樂觀項目殘留在
 *    購物車。此處把樂觀項目的 id 一併放進 mutation context，用同一個值刪除
 * 5. **修掉舊版短描述漏帶 bug**：舊版從 product 解構 `short_description`，但商品型別
 *    上的欄位名是 `shortDescription`，樂觀項目的短描述因此永遠是 undefined。
 *    此處顯式對映 `short_description: shortDescription`
 * 6. **`canAddToCart` 移除未使用的 `qtyInCart` 參數**（舊版傳了但函式沒用到，
 *    tsconfig `noUnusedParameters` 會擋）；`window.jQuery` 改為 optional call；
 *    移除舊版的 `console.log('Error', error)`（`no-console` 規則 + 不對顧客洩漏內部錯誤）
 */

import { useMutation } from '@tanstack/react-query'
import { App, Button } from 'antd'
import type { AxiosError } from 'axios'
import confetti from 'canvas-confetti'
import { useAtom, useAtomValue, useSetAtom } from 'jotai'
import { nanoid } from 'nanoid'
import { memo } from 'react'

import { addToCart } from '../api'
import {
	cartDataAtom,
	isProductModalOpenAtom,
	selectedAttributesAtom,
	selectedVariationIdAtom,
	shopStatusAtom,
} from '../atoms'
import { defaultImage } from '../defaultImage'
import { colorPrimary, showConfetti } from '../env'
import { useInvalidateCart } from '../hooks/useCart'
import type {
	TCartItem,
	TFrontApiErrorBody,
	TFrontCartMutationResult,
	TFrontProduct,
	TProductVariationAttribute,
	TShopStatus,
} from '../types'
import { getStockQty } from '../utils'

/**
 * `window.jQuery` 型別補充
 *
 * WooCommerce 前台的 jQuery（佈景主題可能沒載入，故宣告為 optional 並以 `?.()` 呼叫）。
 * 只用來觸發 `wc_fragment_refresh` 更新 mini-cart，取不到時靜默略過即可。
 */
declare global {
	interface Window {
		jQuery?: (selector: string) => { trigger: (eventName: string) => void }
	}
}

/** 成功與失敗共用同一組 notification key，避免兩則通知同時堆疊 */
const NOTIFICATION_KEY = 'cart_add'

/** 彩帶噴發次數（舊版連噴 3 次，origin 只算一次故三次同源） */
const CONFETTI_BURSTS = 3

/** 購物車表格的 DOM id（與 `Cart/index.tsx` 共用的契約） */
const CART_TABLE_ID = 'ps-cart-table'

/** 以鍵盤觸發按鈕的按鍵 */
const ACTIVATION_KEYS = [
	'Enter',
	' ',
]

/** 加入購物車的 mutation variables（對齊 `api.addToCart()` 的參數） */
type TAddToCartVariables = {
	productId: number
	quantity: number
	variationId: number | null
	variation: TProductVariationAttribute[]
}

/** `onMutate` 交給 `onError` 的回復資訊 */
type TAddToCartContext = {
	rollBack?: TCartItem // 樂觀更新前該項目的原始內容；undefined 代表本次是全新項目

	/** 本次樂觀寫入的 cart item id（變體商品為 variation_id，否則為 product_id） */
	optimisticId: number
}

/** AddToCartButton props */
type TAddToCartButtonProps = {
	product: TFrontProduct

	/** 使用者在數量選擇器選的件數 */
	quantity: number
}

/**
 * 是否為購物車中的同一項（同商品 / 變體且同屬性組合）
 *
 * 以 `JSON.stringify` 比對 variation 陣列——`selectedAttributesAtom` 已依
 * `variation_attributes` 的鍵順序排序，故同一組屬性的序列化結果必定一致。
 *
 * @param  a 購物車項目 A
 * @param  b 購物車項目 B
 * @return {boolean} 是否為同一項
 */
const isSameCartItem = (a: TCartItem, b: TCartItem) =>
	a.id === b.id && JSON.stringify(a.variation) === JSON.stringify(b.variation)

/** 加入購物車按鈕元件 */
const AddToCartButtonComponent: React.FC<TAddToCartButtonProps> = ({
	product,
	quantity,
}) => {
	const { notification } = App.useApp()
	const setIsProductModalOpen = useSetAtom(isProductModalOpenAtom)
	const [cartData, setCartData] = useAtom(cartDataAtom)
	const selectedVariationId = useAtomValue(selectedVariationIdAtom)
	const selectedAttributes = useAtomValue(selectedAttributesAtom)
	const shopStatus = useAtomValue(shopStatusAtom)
	const invalidateCart = useInvalidateCart()
	const productId = product?.id

	const handleGoToCart = () => {
		const cartTable = document.getElementById(CART_TABLE_ID)
		if (!cartTable) return
		cartTable.scrollIntoView({ behavior: 'smooth' })
	}

	const handleGoToCartKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
		if (!ACTIVATION_KEYS.includes(e.key)) return
		e.preventDefault()
		handleGoToCart()
	}

	const { mutate } = useMutation<
		TFrontCartMutationResult,
		AxiosError<TFrontApiErrorBody>,
		TAddToCartVariables,
		TAddToCartContext
	>({
		mutationFn: addToCart,
		onMutate: (variables) => {
			notification.success({
				key: NOTIFICATION_KEY,
				message: (
					<div>
						<p className="m-0">加入購物車成功</p>
						<div
							role="button"
							tabIndex={0}
							className="m-0 cursor-pointer"
							style={{ color: colorPrimary }}
							onClick={handleGoToCart}
							onKeyDown={handleGoToCartKeyDown}
						>
							前往購物車
						</div>
					</div>
				),
			})

			if (showConfetti) {
				const confettiArgs = {
					particleCount: 150,
					scalar: 0.75,
					ticks: 60,
					startVelocity: 70,
					spread: 360,
					origin: { x: randomInRange(0.1, 0.9), y: randomInRange(-0.2, 0.8) },
				}

				for (let index = 0; index < CONFETTI_BURSTS; index++) {
					void confetti(confettiArgs)
				}
			}

			const { id, name, shortDescription, description, images } = product
			const variation = variables.variation ?? []

			setIsProductModalOpen(false)

			const newCartItem: TCartItem = {
				key: nanoid(),
				id: variables.variationId ? Number(variables.variationId) : id,
				quantity: variables.quantity,
				quantity_raw: variables.quantity,
				name,
				short_description: shortDescription,
				description,
				images: images.map((src) => ({ src: src || defaultImage })),
				variation: variation.map((item) => ({
					attribute: item.name,
					value: item.value,
				})),
				isMutating: true,
			}

			setCartData((prev) => {
				if (prev.items.some((item) => isSameCartItem(item, newCartItem))) {
					return {
						...prev,
						isMutating: true,
						items: prev.items.map((item) => {
							if (!isSameCartItem(item, newCartItem)) return item

							return {
								...item,
								quantity: Number(item.quantity) + Number(newCartItem.quantity),
								quantity_raw: Number(item.quantity),
								isMutating: true,
							}
						}),
					}
				}

				return {
					...prev,
					isMutating: true,
					items: [
						...prev.items,
						newCartItem,
					],
				}
			})

			// 從「樂觀更新前」的 cartData 找原始項目：找到代表本次是累加既有項目，
			// 失敗時要還原成這份原始內容；找不到代表是新增，失敗時直接移除即可。
			const rollBack = cartData.items.find((item) =>
				isSameCartItem(item, newCartItem)
			)

			return {
				rollBack,
				optimisticId: newCartItem.id,
			}
		},
		onSuccess: () => {
			void invalidateCart()

			// 讓佈景主題的 WooCommerce 購物車小工具 / mini-cart 一起更新
			window.jQuery?.('body').trigger('wc_fragment_refresh')
		},
		onError: (error, variables, context) => {
			notification.error({
				key: NOTIFICATION_KEY,
				message: (
					<div>
						<p className="m-0">OOPS! 出了點問題</p>
						<p className="m-0">
							{error?.response?.data?.message || '加入購物車失敗'}
						</p>
					</div>
				),
			})

			const rollBack = context?.rollBack
			const optimisticId =
				context?.optimisticId ??
				(variables.variationId
					? Number(variables.variationId)
					: variables.productId)

			if (rollBack) {
				setCartData((prev) => ({
					...prev,
					isMutating: false,
					items: prev.items.map((item) =>
						item.key === rollBack.key ? rollBack : item
					),
				}))
				return
			}

			setCartData((prev) => ({
				...prev,
				isMutating: false,
				items: prev.items.filter((item) => item.id !== optimisticId),
			}))
		},
	})

	const handleClick = () => {
		mutate({
			productId,
			quantity,
			variationId: selectedVariationId,
			variation: selectedAttributes ?? [],
		})
	}

	const stockQty = getStockQty(product, selectedVariationId)
	const cartItems = cartData?.items ?? []
	const qtyInCart =
		cartItems.find((item) => item.id === product?.id)?.quantity ?? 0
	const qtyAvailable = Number(stockQty) - Number(qtyInCart)

	return (
		<Button
			className="w-full mt-4"
			type="primary"
			onClick={handleClick}
			disabled={
				!qtyAvailable || !canAddToCart(product, selectedVariationId, shopStatus)
			}
		>
			加入購物車
		</Button>
	)
}

/**
 * 取指定範圍內的隨機數（彩帶噴發位置用）
 *
 * @param  min 最小值
 * @param  max 最大值
 * @return {number} min ~ max 之間的隨機數
 */
function randomInRange(min: number, max: number) {
	return Math.random() * (max - min) + min
}

/**
 * 是否允許加入購物車
 *
 * - 賣場非 `published`（尚未開賣 / 已結束）一律不可加購
 * - variable：必須先選齊屬性（拿到 variation_id）且該變體不是 outofstock
 * - simple：商品本身不是 outofstock 即可
 * - 其他類型（grouped 等）：不在此把關，交給 WooCommerce 自己判斷
 *
 * @param  product             商品
 * @param  selectedVariationId 已選變體 ID（null = 尚未選齊）
 * @param  shopStatus          賣場販售狀態
 * @return {boolean} 是否允許加入購物車
 */
function canAddToCart(
	product: TFrontProduct,
	selectedVariationId: number | null,
	shopStatus: TShopStatus
) {
	if (shopStatus !== 'published') return false

	if (product.type === 'variable') {
		const variation = product?.variations?.find(
			(v) => v.variation_id === selectedVariationId
		)

		return (
			!!selectedVariationId && variation?.stock?.stockStatus !== 'outofstock'
		)
	}

	if (product.type === 'simple') {
		return product?.stock?.stockStatus !== 'outofstock'
	}

	return true
}

const AddToCartButton = memo(AddToCartButtonComponent)

export default AddToCartButton
