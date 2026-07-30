/**
 * 分潤賣場前台 API 層
 *
 * **單一通道**：全部走本外掛自訂的 REST namespace
 * `/wp-json/power-shop/profit-shop-cart*`（`env.cartApiUrl`）。
 *
 * | 動作 | Method | 路徑 |
 * |------|--------|------|
 * | 取新 nonce | GET | `/nonce` |
 * | 讀購物車 | GET | `` （根路徑） |
 * | 加購 | POST | `/add-item` |
 * | 改量 | POST | `/update-item` |
 * | 移除 | POST | `/remove-item` |
 * | 選運費 | POST | `/select-shipping-rate` |
 *
 * 三條紀律：
 * 1. **一律送 `X-WP-Nonce`**：WordPress 的 `rest_cookie_check_errors()` 在「沒送 nonce」時
 *    會把請求降級成 guest（`wp_set_current_user(0)`），已登入使用者就會操作到另一份購物車。
 *    後端 `FrontCartApi::verify_front_nonce()` 因此明確要求 nonce 必然存在（缺了就 403），
 *    不再倚賴 core 的「有送才驗」——那條路徑正是跨站請求可以繞過的地方
 * 2. **一律送 cookie**（`withCredentials`）：guest 購物車靠 WooCommerce session cookie 識別
 * 3. **後端統一回 `{ code, message, data }`**，本層一律**剝掉外殼只回 `data`**，
 *    呼叫端拿到的永遠是 payload 本身
 *
 * ⚠ 本 bundle 不使用 Refine dataProvider，與 admin SPA 完全隔離。
 */

import axios from 'axios'

import { cartApiUrl, frontNonce, shopId } from './env'
import type {
	TFrontApiResponse,
	TFrontCart,
	TFrontCartMutationResult,
	TFrontNoncePayload,
	TProductVariationAttribute,
} from './types'

/** 請求逾時（毫秒） */
const TIMEOUT = 30000

/**
 * 購物車 REST 共用 axios instance
 *
 * `withCredentials: true` 讓 WordPress 登入 cookie 與 WooCommerce session cookie
 * 都帶上去（前者決定 nonce 是否有效，後者決定 guest 購物車是哪一份）。
 */
export const cartClient = axios.create({
	baseURL: cartApiUrl,
	timeout: TIMEOUT,
	withCredentials: true,
	headers: {
		'Content-Type': 'application/json',
		Accept: 'application/json',
	},
})

/**
 * 當前使用的 nonce
 *
 * 初始值來自 PHP localize，但**可能已經過期**：賣場頁在 `status=publish` 時
 * 刻意不送 nocache header（Phase 5-B 的 CDN 快取優化），使用者拿到的 HTML
 * 有可能是數小時前被快取的版本，裡面的 nonce 早已失效。
 *
 * 因此 `request` 遇到 403 `rest_cookie_invalid_nonce` 會自動換新 nonce 重試一次，
 * 讓「頁面可被快取」與「寫入有 CSRF 防護」兩件事同時成立。
 */
let currentNonce = frontNonce

/**
 * 向後端索取新鮮 nonce
 *
 * 刻意**不帶 `X-WP-Nonce`**：帶了過期 nonce 反而會被 403 擋下，變成
 * 「要換 nonce 得先有有效 nonce」的死結。後端的 nonce endpoint 會先從
 * 登入 cookie 還原使用者身分再簽發，所以不帶 nonce 也拿得到正確的值。
 *
 * 這支 endpoint 因此是唯一不驗 nonce 的，改由後端的**同源檢查**把關
 * （`Origin` 存在且非本站就 403）。同源 XHR 不會送 `Origin`，本函式不受影響。
 *
 * @return {Promise} 新的 nonce；取不到時回空字串
 */
const refreshNonce = async (): Promise<string> => {
	const response =
		await cartClient.get<TFrontApiResponse<TFrontNoncePayload>>('/nonce')

	return response.data?.data?.nonce || ''
}

/**
 * 判斷是否為「nonce 已過期」錯誤
 *
 * 判斷條件是 WordPress REST cookie 認證的錯誤碼 `rest_cookie_invalid_nonce`
 * （不是舊 admin-ajax 時代自訂的 `invalid_nonce`）。
 *
 * @param  error axios 拋出的錯誤
 * @return {boolean} 是否應該換 nonce 重試
 */
const isInvalidNonceError = (error: unknown): boolean => {
	if (!axios.isAxiosError(error)) return false
	if (error.response?.status !== 403) return false

	const data = error.response?.data as { code?: string } | undefined
	return data?.code === 'rest_cookie_invalid_nonce'
}

/** 單次請求的設定（`url` 為相對於 `cartApiUrl` 的子路徑） */
type TRequestConfig = {
	method: 'get' | 'post'
	url: string
	data?: unknown
}

/**
 * 送出一筆購物車 REST 請求
 *
 * 自動補上 `X-WP-Nonce` header 並剝掉 `{ code, message, data }` 外殼。
 * nonce 過期（403 `rest_cookie_invalid_nonce`）時自動取新 nonce 並重試**一次**；
 * 第二次仍失敗就把錯誤往上拋，交由呼叫端顯示訊息。
 *
 * @param  config 請求設定
 * @return {Promise} 後端回傳的 `data` payload
 */
const request = async <T>(config: TRequestConfig): Promise<T> => {
	const send = (nonce: string) =>
		cartClient.request<TFrontApiResponse<T>>({
			...config,
			headers: { 'X-WP-Nonce': nonce },
		})

	try {
		const response = await send(currentNonce)
		return response.data.data
	} catch (error) {
		if (!isInvalidNonceError(error)) throw error

		currentNonce = await refreshNonce()
		const retried = await send(currentNonce)
		return retried.data.data
	}
}

/**
 * 讀取購物車
 *
 * @return {Promise} 完整購物車（含 totals / shipping_rates）
 */
export const fetchCart = async (): Promise<TFrontCart> =>
	request<TFrontCart>({ method: 'get', url: '' })

/**
 * 加入購物車
 *
 * `profit_shop_id` 由本函式統一補上，呼叫端不需（也不該）自己帶——
 * 這是後端注入分潤標記與價格覆寫簽章的唯一依據，漏送會讓分潤失效。
 *
 * `variation` 送**物件陣列**（不是 JSON 字串），由後端逐欄 sanitize；
 * `variation_id` 只有在真的選了變體時才送出。
 *
 * @param  args 加購參數
 * @return {Promise} 加購後的完整購物車（外加 `cart_item_key`）
 */
export const addToCart = async (args: {
	productId: number
	quantity: number
	variationId?: number | null
	variation?: TProductVariationAttribute[]
}): Promise<TFrontCartMutationResult> =>
	request<TFrontCartMutationResult>({
		method: 'post',
		url: '/add-item',
		data: {
			profit_shop_id: shopId,
			id: args.productId,
			quantity: args.quantity,
			...(args.variationId ? { variation_id: args.variationId } : {}),
			variation: args.variation ?? [],
		},
	})

/**
 * 從購物車移除單一項目
 *
 * @param  cartItemKey WC cart item key
 * @return {Promise} 移除後的完整購物車
 */
export const removeCartItem = async (
	cartItemKey: string
): Promise<TFrontCartMutationResult> =>
	request<TFrontCartMutationResult>({
		method: 'post',
		url: '/remove-item',
		data: { cart_item_key: cartItemKey },
	})

/**
 * 更新購物車項目數量
 *
 * `quantity` 傳 0 等同移除該項目（後端契約）。
 *
 * @param  args 更新參數
 * @return {Promise} 更新後的完整購物車
 */
export const updateCartItem = async (args: {
	cartItemKey: string
	quantity: number
}): Promise<TFrontCartMutationResult> =>
	request<TFrontCartMutationResult>({
		method: 'post',
		url: '/update-item',
		data: { cart_item_key: args.cartItemKey, quantity: args.quantity },
	})

/**
 * 選擇運送方式
 *
 * 後端會驗證 `rate_id` 確實屬於該 package，再寫進 WooCommerce session
 * 並重算整車稅金與總額，因此回傳的是**重算後**的完整購物車。
 *
 * @param  args 選擇參數
 * @return {Promise} 重算後的完整購物車
 */
export const selectShippingRate = async (args: {
	packageId: number | string
	rateId: string
}): Promise<TFrontCart> =>
	request<TFrontCart>({
		method: 'post',
		url: '/select-shipping-rate',
		data: { package_id: args.packageId, rate_id: args.rateId },
	})
