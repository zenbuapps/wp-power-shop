/**
 * 購物車數量調整
 *
 * 照搬舊版一頁商店 `pages/PowerShopProducts/Cart/UpdateCartInputNumber.tsx`：
 * 使用者按 `-` / `+` 或直接輸入時**先樂觀更新** `cartDataAtom`（畫面立刻反應），
 * 700ms debounce 後才真的送出請求；失敗則回復成伺服器數量（`quantity_raw`）。
 *
 * 資料層差異：
 * - 更新走 `updateCartItem()`（`POST /profit-shop-cart/update-item`），取代舊版的
 *   `useUpdate({ resource: 'cart/update-item', dataProvider: 'wc-store' })`——
 *   nonce 由 `api.ts` 以 `X-WP-Nonce` 統一帶上，本元件不持有任何 nonce 狀態
 * - 庫存上限從 `env.products` 查，取代舊版的 `window.appData.products_info.products`
 * - notification 走 `App.useApp()`（provider 在 `main.tsx`），不再自帶 `contextHolder`
 */

import { useMutation } from '@tanstack/react-query'
import { App as AntdApp, InputNumber } from 'antd'
import type { AxiosError } from 'axios'
import { useAtom } from 'jotai'
import { createElement, memo, useEffect } from 'react'

import { updateCartItem } from '../api'
import { cartDataAtom } from '../atoms'
import { products } from '../env'
import { useInvalidateCart } from '../hooks/useCart'
import type {
	TCartData,
	TCartItem,
	TFrontApiErrorBody,
	TFrontCartMutationResult,
} from '../types'
import { getStockQty } from '../utils'

/** debounce 延遲（毫秒）：使用者連按 +/- 時只送最後一次 */
const DELAY = 700

/** 數量下限 */
const MIN_QTY = 1

/** 數量變更指令：加一 / 減一 / 直接指定 / 清空 */
type TQuantityAction = number | null | 'INCREMENT' | 'DECREMENT'

/**
 * 依操作算出新數量（夾在 `MIN_QTY` ~ 庫存之間）
 *
 * 照搬舊版 `getQuantity`。`stockQty` 為 `Infinity` 時所有比較都不會觸發上限。
 *
 * @param  value    變更指令
 * @param  item     當前購物車項目
 * @param  stockQty 庫存上限
 * @return {number} 夾範圍後的數量
 */
const getQuantity = (
	value: TQuantityAction,
	item: TCartItem,
	stockQty: number
): number => {
	const currentQty = Number(item.quantity)

	switch (value) {
		case 'DECREMENT':
			return currentQty <= MIN_QTY ? MIN_QTY : currentQty - 1
		case 'INCREMENT':
			return currentQty >= stockQty ? stockQty : currentQty + 1
		case null:
			return MIN_QTY
		default:
			return value >= stockQty ? stockQty : value
	}
}

/**
 * 以購物車項目 id 查庫存上限
 *
 * 對應舊版 `findStockQtyById`：先當商品 ID 找，再當變體 ID 找，都查不到視為無上限
 * （購物車可能殘留已從賣場移除的商品，此時不該把數量鎖死）。
 *
 * ⚠ 與舊版一處差異：舊版變體看 `backorders_allowed`，但新契約的 `TFrontVariation`
 * 沒有這個欄位（WC 變體的延期交貨預設繼承母商品），因此改由**母商品**的
 * `backorders` 判斷——這正是 Phase 1 `getStockQty()` 已內建的邏輯，直接複用。
 *
 * @param  id 購物車項目的 id（simple = product_id、變體 = variation_id）
 * @return {number} 庫存上限；無上限時為 Infinity
 */
const findStockQtyById = (id: number): number => {
	const product = products.find((p) => p.id === id)
	if (product) return getStockQty(product, null)

	const owner = products.find((p) =>
		(p.variations ?? []).some((v) => v.variation_id === id)
	)
	if (owner) return getStockQty(owner, id)

	return Infinity
}

/**
 * 把單一項目回復成 rollback 快照（伺服器數量）
 *
 * @param  prev     當前購物車狀態
 * @param  rollBack 送出前的快照（`quantity` 已換成 `quantity_raw`）
 * @return {TCartData} 已還原的購物車狀態
 */
const restoreQuantity = (prev: TCartData, rollBack?: TCartItem): TCartData => {
	if (!rollBack) return { ...prev, isMutating: false }

	return {
		...prev,
		isMutating: false,
		items: prev.items.map((i) => (i.key === rollBack.key ? rollBack : i)),
	}
}

/**
 * 產生 `-` / `+` 熱區節點
 *
 * DOM 必須是 `<span class="ps-addon">`：`styles.css` 的
 * `.ant-input-number-group-addon .ps-addon` 用絕對定位把它撐滿整個 addon 區塊，
 * 換成 `<button>` 會被 antd / theme 的按鈕樣式破版。
 *
 * ⚠ 這裡用 `createElement` 而非 JSX，與 Phase 1 的 `hooks/usePlusMinusInput.ts`
 * 保持一致：兩處產生的是同一份 markup，必須同時修改才不會樣式脫鉤。
 * 附帶效果是不會觸發 `jsx-a11y/click-events-have-key-events`——
 * 這兩顆是滑鼠專用的便利熱區，鍵盤使用者可直接在 InputNumber 上按方向鍵或輸入數字，
 * 功能不會缺失；若日後要補真正的鍵盤支援（`role` + `tabIndex` + `onKeyDown`），
 * **必須兩處一起改**，否則購物車與商品 Modal 的 tab 行為會不一致。
 *
 * @param  label   顯示文字（`-` 或 `+`）
 * @param  onClick 點擊行為
 * @return {React.ReactElement} addon 節點
 */
const renderAddon = (label: string, onClick: () => void) =>
	createElement('span', { className: 'ps-addon', onClick }, label)

/** UpdateCartInputNumber props */
type TUpdateCartInputNumberProps = {
	item: TCartItem
}

/** 購物車單列的數量輸入框（含 `-` / `+` 熱區） */
const UpdateCartInputNumberComponent: React.FC<TUpdateCartInputNumberProps> = ({
	item,
}) => {
	const [cartData, setCartData] = useAtom(cartDataAtom)
	const invalidateCart = useInvalidateCart()
	const { notification } = AntdApp.useApp()

	const qtyInCart = item?.quantity || MIN_QTY
	const quantityRaw = item?.quantity_raw
	const key = item?.key || ''
	const stockQty = findStockQtyById(item.id)

	const { mutate } = useMutation<
		TFrontCartMutationResult,
		AxiosError<TFrontApiErrorBody>,
		{ cartItemKey: string; quantity: number },
		TCartItem | undefined
	>({
		mutationFn: (variables) => updateCartItem(variables),
		onMutate: (variables) => {
			setCartData((prev) => ({
				...prev,
				isMutating: true,
				items: prev.items.map((i) =>
					i.key === variables.cartItemKey ? { ...i, isMutating: true } : i
				),
			}))

			const rollBack = cartData.items.find(
				(i) => i.key === variables.cartItemKey
			)
			if (!rollBack) return undefined

			return { ...rollBack, quantity: rollBack.quantity_raw }
		},
		onSuccess: () => {
			// 刻意不顯示成功通知：使用者連續調整數量會被通知洗版（舊版亦註解掉）
			invalidateCart()
		},
		onError: (error, _variables, rollBack) => {
			notification.error({
				message: error?.response?.data?.message || '修改購物車失敗',
			})
			setCartData((prev) => restoreQuantity(prev, rollBack))
		},
	})

	/** 樂觀更新本列數量（僅改 atom，實際送出由下方 debounce effect 觸發） */
	const applyQuantity = (value: TQuantityAction) => {
		setCartData((prev) => {
			if (!prev.items.some((i) => i.key === key)) return prev

			return {
				...prev,
				items: prev.items.map((i) =>
					i.key === key
						? { ...i, quantity: getQuantity(value, i, stockQty) }
						: i
				),
			}
		})
	}

	const handleDecrement = () => {
		applyQuantity('DECREMENT')
	}

	const handleIncrement = () => {
		applyQuantity('INCREMENT')
	}

	/**
	 * 直接輸入數量
	 *
	 * ⚠ 一定要取整：後端把「解析不出整數」的 quantity 視為格式錯誤（400），而
	 * `2.5` 這種值在 antd `onChange` 是會原樣傳出來的。取整後使用者輸入小數會被
	 * 當成整數處理，而不是送出一筆註定失敗（或在修正前會被當成移除）的請求。
	 */
	const handleChange = (v: number | null) => {
		if (!v) return

		const next = Math.floor(v)
		if (next < MIN_QTY) return

		applyQuantity(next)
	}

	// 數量與伺服器值不一致時才送出；DELAY 內的後續變更會 clearTimeout 取消前一次
	useEffect(() => {
		if (qtyInCart === quantityRaw) return

		const timer = setTimeout(() => {
			mutate({ cartItemKey: key, quantity: qtyInCart })
		}, DELAY)

		return () => clearTimeout(timer)
	}, [qtyInCart, quantityRaw, key, mutate])

	return (
		<InputNumber
			className="w-full"
			min={MIN_QTY}
			max={stockQty}
			precision={0}
			addonBefore={renderAddon('-', handleDecrement)}
			addonAfter={renderAddon('+', handleIncrement)}
			value={qtyInCart}
			onChange={handleChange}
		/>
	)
}

const UpdateCartInputNumber = memo(UpdateCartInputNumberComponent)

export default UpdateCartInputNumber
