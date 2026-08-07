/**
 * 賣場前台商品 Modal 的 AJAX 加購
 *
 * 對應規格：specs/features/profit-shop/add-to-cart-from-modal.feature
 * 對應實作：inc/assets/js/profit-shop-front.js（`bindAddToCart()` / `buildAddToCartPayload()`）
 *
 * ── 🔴 這裡的失效後果是「顧客被重複收費」──────────────────────
 *
 * 原生表單沒有重送問題（送出後整頁導航，DOM 直接消失）。改成 AJAX 之後，
 * 「飛行中擋住第二次送出」變成必須自己維護的不變量——少了它，使用者在請求還沒
 * 回來時改選變體會觸發 `refresh()` → 把按鈕解鎖 → 再按一次 → **兩筆都成功**，
 * 而且兩次回應都正常，畫面上不會有任何異常。
 */

import { beforeEach, describe, expect, it } from 'vitest'
import {
	chooseAttribute,
	flush,
	jsonResponse,
	modalEl,
	setFrontConfig,
	setupShop,
	stubFetch,
	submitAddToCart,
	submitAndWasPrevented,
	submitBusy,
	type TFetchCall,
	type TVariation,
} from './helpers/shopFront'

const AJAX_URL = '/?wc-ajax=add_to_cart'

const v = (id: number, attributes: Record<string, string>): TVariation => ({
	id,
	variation_id: id,
	attributes,
	display_price: '100',
	is_purchasable: true,
	max_qty: 10,
})

/** 永不 resolve = 請求還在飛行中 */
const pending = () => new Promise<never>(() => {})

/**
 * 建 simple 商品賣場並回傳 fetch 呼叫紀錄
 *
 * ⚠ **fetch 替身必須在執行 IIFE 之前就位**。`canAjax()` 是在 `bindAddToCart()`
 * 當下檢查 `window.fetch` 存在與否，jsdom 預設沒有 fetch——晚一步 stub 的話
 * 監聽器根本不會註冊，測試會看到「一次 fetch 都沒發」而誤以為是產品行為。
 * （第一版測試就是這樣寫的，9 條全紅。）
 */
function setupSimpleShop(handler: () => Promise<unknown> = pending): TFetchCall[] {
	const calls = stubFetch(handler)
	setFrontConfig({ addToCartUrl: AJAX_URL, cartUrl: '/cart/', redirectAfterAdd: '' })
	setupShop({ attributes: {}, variations: [], type: 'simple' })
	// PHP template 對 simple 商品會印出 add-to-cart hidden input
	const form = document.querySelector<HTMLFormElement>('[data-ps-form]')!
	form.insertAdjacentHTML(
		'afterbegin',
		'<input type="hidden" name="add-to-cart" value="65" />' +
			'<input type="hidden" name="profit_shop_id" value="1932" />'
	)
	return calls
}

/** 建 variable 商品賣場並回傳 fetch 呼叫紀錄 */
function setupVariableShop(
	variations: TVariation[],
	handler: () => Promise<unknown> = pending,
	options: { withShopId?: boolean; type?: string } = {}
): TFetchCall[] {
	const { withShopId = true, type = 'variable' } = options
	const calls = stubFetch(handler)
	setFrontConfig({ addToCartUrl: AJAX_URL, cartUrl: '/cart/', redirectAfterAdd: '' })
	setupShop({ attributes: { color: ['red'], size: ['l'] }, variations, type })
	const form = document.querySelector<HTMLFormElement>('[data-ps-form]')!
	form.insertAdjacentHTML(
		'afterbegin',
		'<input type="hidden" name="add-to-cart" value="65" />' +
			(withShopId ? '<input type="hidden" name="profit_shop_id" value="1932" />' : '')
	)
	return calls
}

beforeEach(() => {
	document.body.innerHTML = ''
	setFrontConfig(null)
	delete (window as unknown as Record<string, unknown>).fetch
})

describe('Rule: 安全（重送）- 飛行中必須擋住第二次送出', () => {
	/**
	 * 🔴 本檔最重要的一條：失效 = 顧客被重複收費
	 *
	 * 第一次送出後請求刻意**不 resolve**（模擬還在飛行），此時再送一次——
	 * fetch 必須只被呼叫一次。
	 */
	it('第一次請求還沒回來時，第二次送出不得再發 fetch', async () => {
		const calls = setupSimpleShop()

		submitAddToCart()
		await flush()
		expect(calls.length).toBe(1)

		submitAddToCart()
		submitAddToCart()
		await flush()

		expect(calls.length).toBe(1)
	})

	it('飛行中送出的事件仍必須被 preventDefault（不得退回原生表單送出）', async () => {
		setupSimpleShop()

		submitAddToCart()
		await flush()

		// 若這裡沒被擋下，瀏覽器會執行原生送出 → 整頁導航 + 第二筆加購
		expect(submitAndWasPrevented()).toBe(true)
	})
})

describe('Rule: 後置（載入）- 送出期間必須有明確的載入狀態', () => {
	it('送出後按鈕進入忙碌狀態', async () => {
		setupSimpleShop()

		expect(submitBusy()).toBe(false)
		submitAddToCart()
		await flush()

		expect(submitBusy()).toBe(true)
	})
})

describe('Rule: 後置（成功）- 加購成功時不換頁、關閉 Modal 並顯示成功通知', () => {
	it('成功後關閉 Modal 並顯示 toast', async () => {
		setupSimpleShop(() => Promise.resolve(jsonResponse({ fragments: {}, cart_hash: 'x' })))
		const modal = modalEl() as HTMLDialogElement
		// jsdom 沒有實作 <dialog>，補最小替身
		modal.open = true
		modal.close = function () {
			this.open = false
		}

		submitAddToCart()
		await flush()
		await flush()

		// 🔴 順序不可反：原生 <dialog> 開啟時進入 top layer，body 層的 toast 不論
		// z-index 多大都在它與 ::backdrop 之下且因 inert 不可點。先關 Modal 才看得到。
		expect(modal.open).toBe(false)

		const toast = document.querySelector('[data-ps-toast]')!
		expect(toast.classList.contains('tw-hidden')).toBe(false)
		expect(document.querySelector('[data-ps-toast-label]')!.textContent).not.toBe('')
	})
})

describe('Rule: 安全（重送）- 任何失敗都不得自動重送表單', () => {
	/**
	 * 業務失敗（`{error:true}`）不得重送
	 *
	 * `wc_add_notice()` 沒有去重，重送會讓同一則錯誤顯示兩次；
	 * 而「伺服器其實已經加購成功但回應異常」時重送就是**重複加購**。
	 */
	it('業務失敗回應不得觸發第二次 fetch', async () => {
		const calls = setupSimpleShop(() =>
			Promise.resolve(jsonResponse({ error: true, product_url: '/product/x/' }))
		)

		submitAddToCart()
		await flush()
		await flush()

		expect(calls.length).toBe(1)
	})

	it('HTTP 失敗不得觸發第二次 fetch', async () => {
		const calls = setupSimpleShop(() => Promise.resolve(jsonResponse({}, false, 500)))

		submitAddToCart()
		await flush()
		await flush()

		expect(calls.length).toBe(1)
	})

	it('fetch reject 不得觸發第二次 fetch', async () => {
		const calls = setupSimpleShop(() => Promise.reject(new Error('network down')))

		submitAddToCart()
		await flush()
		await flush()

		expect(calls.length).toBe(1)
	})
})

describe('Rule: 降級（漸進增強）- 四道關卡任一不成立就退回原生表單送出', () => {
	/**
	 * 關卡 3：能力不足（缺 `CONFIG.addToCartUrl`）→ 不註冊 submit 監聽器
	 *
	 * 這是 `canAjax()` 的守門。沒有它，缺設定時會走進 fetch 並炸在 undefined URL。
	 */
	it('缺 addToCartUrl 時完全不攔截送出', async () => {
		// ⚠ fetch 必須存在，否則這條會因為「jsdom 沒有 fetch」而通過——那是假通過，
		// 測到的不是 addToCartUrl 這道守門。
		const calls = stubFetch(() => Promise.resolve(jsonResponse({})))
		setFrontConfig(null)
		setupShop({ attributes: {}, variations: [], type: 'simple' })
		const form = document.querySelector<HTMLFormElement>('[data-ps-form]')!
		form.insertAdjacentHTML(
			'afterbegin',
			'<input type="hidden" name="add-to-cart" value="65" />' +
				'<input type="hidden" name="profit_shop_id" value="1932" />'
		)

		expect(submitAndWasPrevented()).toBe(false)
		await flush()
		expect(calls.length).toBe(0)
	})

	/**
	 * 關卡 4：`buildAddToCartPayload()` 回 null → **不 preventDefault**
	 *
	 * 缺 `profit_shop_id` 時寧可換頁，也不要送出一筆沒有分潤標記的加購——
	 * 那會靜默走商品原價，站長要到對帳時才會發現。
	 */
	it('缺 profit_shop_id 時退回原生表單送出（不發 AJAX）', async () => {
		const calls = stubFetch(() => Promise.resolve(jsonResponse({})))
		setFrontConfig({ addToCartUrl: AJAX_URL, cartUrl: '/cart/', redirectAfterAdd: '' })
		setupShop({ attributes: {}, variations: [], type: 'simple' })
		const form = document.querySelector<HTMLFormElement>('[data-ps-form]')!
		form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="add-to-cart" value="65" />')

		expect(submitAndWasPrevented()).toBe(false)
		await flush()
		expect(calls.length).toBe(0)
	})

	it('商品型別不在 AJAX 白名單時退回原生表單送出', async () => {
		const calls = setupVariableShop([], () => Promise.resolve(jsonResponse({})), { type: 'grouped' })

		expect(submitAndWasPrevented()).toBe(false)
		await flush()
		expect(calls.length).toBe(0)
	})

	it('variable 商品尚未選出變體時退回原生表單送出', async () => {
		const calls = setupVariableShop(
			[v(100, { color: 'red', size: 'l' })],
			() => Promise.resolve(jsonResponse({}))
		)

		expect(submitAndWasPrevented()).toBe(false)
		await flush()
		expect(calls.length).toBe(0)
	})
})

describe('Rule: 降級（任意屬性）- 選中含任意屬性的變體必須走原生表單', () => {
	/**
	 * `WC_AJAX::add_to_cart()` **全文不讀** `$_POST['attribute_*']`，它一律自己用
	 * `get_variation_attributes()` 反查——對 Any 屬性拿回空字串後被
	 * `WC_Cart::add_to_cart()` 判為缺少必填屬性而拒收。
	 *
	 * ⇒ 含 Any 屬性的變體**必須**走原生表單（送使用者實際選的值）。
	 * 這是**正確**的降級，不是回歸——不要因為「Phase 15 明明做了 AJAX」就拿掉。
	 */
	it('選中含 Any 屬性的變體時不發 AJAX', async () => {
		// size 是「任意」屬性
		const calls = setupVariableShop(
			[v(110, { color: 'red', size: '' })],
			() => Promise.resolve(jsonResponse({}))
		)

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'l')

		expect(submitAndWasPrevented()).toBe(false)
		await flush()
		expect(calls.length).toBe(0)
	})

	/** 對照組：全具體變體必須走 AJAX，且送的是**變體 ID** */
	it('全具體變體走 AJAX，且 product_id 送的是變體 ID', async () => {
		const calls = setupVariableShop([v(120, { color: 'red', size: 'l' })])

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'l')
		submitAddToCart()
		await flush()

		expect(calls.length).toBe(1)
		const body = String(calls[0].init.body)
		// 送變體 ID（120）而非父商品 ID（65）——WC 會自己換算回父商品並反查 attribute_*
		expect(body).toContain('product_id=120')
		expect(body).toContain('profit_shop_id=1932')
		// 刻意不送 add-to-cart / variation_id / attribute_*（wc-ajax 完全不看）
		expect(body).not.toContain('attribute_')
		expect(body).not.toContain('variation_id')
	})

	it('POST body 必須是 form-urlencoded（否則 PHP 不會填 $_POST、分潤靜默失效）', async () => {
		const calls = setupSimpleShop()

		submitAddToCart()
		await flush()

		const headers = calls[0].init.headers as Record<string, string>
		expect(headers['Content-Type']).toContain('application/x-www-form-urlencoded')
	})
})
