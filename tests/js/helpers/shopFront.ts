/**
 * 賣場前台 vanilla JS 的測試載具
 *
 * ── 為什麼是「建 DOM → 執行 IIFE」而不是 import 純函式 ──────────
 *
 * `inc/assets/js/profit-shop-front.js` 是一支 IIFE、零 export，
 * `findBestVariation()` / `matchVariation()` / `isAllAttributesChosen()`
 * 巢狀在 `bindVariations()` 的閉包內，**無法被 import**。
 *
 * 要用「純函式單元測試」就得先把它們提到頂層並加 export——那是**對一段零測試
 * 覆蓋的收錢路徑動產品碼**，順序做反了（Pitfall #35 訂的純重構驗收標準
 * 「既有測試零修改全綠」在這裡根本不適用，因為沒有既有測試）。
 *
 * 改成從外部驅動真實 DOM，好處有三：
 *   1. **零產品改動** —— 不必先動一段沒有安全網的邏輯
 *   2. **測到的是真正的契約** —— 「最後送進購物車的是哪一個 variation_id」，
 *      而不是某個內部函式的回傳值
 *   3. **順帶覆蓋 Pitfall #23 的 DOM 契約** —— `data-ps-*` 屬性被改名時測試會紅
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const SCRIPT_PATH = resolve(__dirname, '../../../inc/assets/js/profit-shop-front.js')

/**
 * WooCommerce `get_available_variations()` 回傳的變體形狀（只取本測試需要的欄位）
 *
 * 🔴 `id` 與 `variation_id` **兩個都要有**，不是二選一。
 *
 * `WC_Product_Variable::get_available_variation()` 的回傳是
 * `array_merge( $variation->get_data(), array( 'variation_id' => …, … ) )`，
 * 而 `WC_Data::get_data()` 會帶出 `id` ⇒ 真實 payload 兩個欄位並存。
 *
 * 受測 JS 讀的是 **`variation.id`**（`:839` hidden input、`:1052` AJAX 守門、
 * `:1059` AJAX payload 三處），只給 `variation_id` 會讓它讀到 `undefined`。
 * 第一版 fixture 就是這樣寫的，11 條測試全紅——差點被誤判成產品缺陷。
 *
 * 佐證：Phase 15 真實站台實測「AJAX 送出 `product_id=730`（變體 ID）」，
 * 而那條路徑取的就是 `variation.id`。
 */
export type TVariation = {
	/** `WC_Data::get_data()` 帶出的 id，受測 JS 實際讀的就是這個 */
	id: number
	/** `get_available_variation()` 另外明確塞的欄位，PHP 端 `collect_variations()` 讀它 */
	variation_id: number
	attributes: Record<string, string>
	display_price?: string
	is_purchasable?: boolean
	max_qty?: number
}

export type TBuildOptions = {
	/** 畫面上要印出的屬性群組：{ 屬性名: 可選值[] } */
	attributes: Record<string, string[]>
	/** 變體 JSON（等同 `<script class="ps-variations-data">` 的內容） */
	variations: TVariation[]
	/** 賣場是否在販售期間內（對應 `data-ps-shop-open`） */
	shopOpen?: boolean
	/** 商品型別（對應 `data-ps-type`；非 variable 時不會綁定變體邏輯） */
	type?: string
}

/**
 * 建出與 `templates/partials/profit-shop-modal.php` 同構的 DOM
 *
 * ⚠ 屬性名稱逐字對齊 PHP template。任何一個 `data-ps-*` 被改名，這裡就要跟著改，
 * 而那正是我們要的——DOM 契約是 PHP↔JS 之間唯一的介面，改了必須有人知道。
 */
export function buildShopDom(options: TBuildOptions): void {
	const { attributes, variations, shopOpen = true, type = 'variable' } = options

	const optionButtons = Object.entries(attributes)
		.map(([name, values]) =>
			values
				.map(
					(value) =>
						`<button type="button" aria-pressed="false" data-ps-attr-option ` +
						`data-ps-attr-name="${name}" data-ps-attr-value="${value}">` +
						`<span>${value}</span></button>`
				)
				.join('')
		)
		.join('')

	document.body.innerHTML = `
<div class="ps-profit-shop-front" data-ps-shop="1">
  <div data-ps-product-id="65" data-ps-disabled="0">
    <form method="get"><button type="submit">加入購物車</button></form>
  </div>

  <dialog data-ps-modal-for="65" data-ps-shop-open="${shopOpen ? '1' : '0'}" data-ps-type="${type}">
    <form method="post" data-ps-form>
      ${optionButtons}

      <p data-ps-attr-status><span data-ps-attr-status-label>未選擇商品屬性</span></p>
      <span data-ps-price></span>
      <span data-ps-stock></span>
      <span data-ps-buyer></span>

      <input type="number" value="1" data-ps-qty />
      <input type="hidden" name="variation_id" value="" data-ps-variation-id />
      <span data-ps-attr-fields></span>

      <button type="submit" data-ps-submit${shopOpen ? '' : ' disabled'}>
        <span data-ps-submit-spinner></span>
        <span data-ps-submit-label>加入購物車</span>
      </button>
      <p data-ps-submit-error><span data-ps-submit-error-label></span></p>
    </form>

    <script type="application/json" class="ps-variations-data">${JSON.stringify(variations)}</script>
  </dialog>

  <!--
    toast 由 PHP 印在**賣場容器內**（不是 dialog 內）——原生 <dialog> 開啟時進入
    top layer，body 層元素不論 z-index 多大都在它之下且因 inert 不可點，所以
    成功時的順序是「先關 Modal 再顯示 toast」。JS 只負責移除 tw-hidden，
    元素本身必須已經存在。
  -->
  <div class="pc-toast tw-hidden" data-ps-toast>
    <div class="pc-alert" role="status" data-ps-toast-body>
      <svg data-ps-icon="success"></svg>
      <svg class="tw-hidden" data-ps-icon="error"></svg>
      <span data-ps-toast-label></span>
    </div>
  </div>
</div>`
}

/**
 * 執行受測的 IIFE
 *
 * 用 `new Function` 而非 `import`：那支檔案沒有任何 export，且會在載入當下
 * 立即掃描 `document`（`init()`），必須在 DOM 建好之後才執行。
 */
export function runShopFrontScript(): void {
	const source = readFileSync(SCRIPT_PATH, 'utf8')
	// eslint-disable-next-line no-new-func
	new Function(source)()
}

/** 建 DOM 並執行腳本（多數測試的起手式） */
export function setupShop(options: TBuildOptions): void {
	buildShopDom(options)
	runShopFrontScript()
}

/**
 * 設定 `wp_localize_script` 注入的兩個全域
 *
 * `canAjax()` 要求 `CONFIG.addToCartUrl` 有值才會註冊 submit 監聽器——
 * 沒設定的話 AJAX 路徑整段不會綁定（那是**正確**的降級，見「四道關卡」測試）。
 */
export function setFrontConfig(config: Record<string, string> | null): void {
	if (config === null) {
		delete (window as unknown as Record<string, unknown>).power_shop_front_config
		return
	}
	;(window as unknown as Record<string, unknown>).power_shop_front_config = config
}

export type TFetchCall = { url: string; init: RequestInit }

/** 一個可觀察、可控制回應的 fetch 替身 */
export function stubFetch(handler: () => Promise<unknown>): TFetchCall[] {
	const calls: TFetchCall[] = []
	;(window as unknown as Record<string, unknown>).fetch = (url: string, init: RequestInit) => {
		calls.push({ url, init })
		return handler()
	}
	return calls
}

/** 建一個 `Response` 替身（受測程式先 `.text()` 再自行 JSON.parse） */
export function jsonResponse(body: unknown, ok = true, status = 200): Response {
	return {
		ok,
		status,
		text: () => Promise.resolve(JSON.stringify(body)),
	} as unknown as Response
}

/** 送出加購表單 */
export function submitAddToCart(): void {
	const form = document.querySelector<HTMLFormElement>('[data-ps-form]')
	if (!form) {
		throw new Error('找不到 [data-ps-form]')
	}
	form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }))
}

/** 送出加購表單並回傳該事件是否被 preventDefault（= 走 AJAX 而非原生送出） */
export function submitAndWasPrevented(): boolean {
	const form = document.querySelector<HTMLFormElement>('[data-ps-form]')
	if (!form) {
		throw new Error('找不到 [data-ps-form]')
	}
	const event = new window.Event('submit', { bubbles: true, cancelable: true })
	form.dispatchEvent(event)
	return event.defaultPrevented
}

/** 讓 microtask queue 跑完（fetch 的 .then 鏈） */
export function flush(): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

/** 加購按鈕是否處於忙碌狀態 */
export function submitBusy(): boolean {
	const submit = document.querySelector<HTMLButtonElement>('[data-ps-submit]')
	return !!submit && submit.getAttribute('aria-busy') === 'true'
}

/** 選出當前 Modal（供斷言 open 狀態用） */
export function modalEl(): HTMLElement {
	const modal = document.querySelector<HTMLElement>('[data-ps-modal-for]')
	if (!modal) {
		throw new Error('找不到 [data-ps-modal-for]')
	}
	return modal
}

/** 點選某個屬性值（模擬使用者操作） */
export function chooseAttribute(name: string, value: string): void {
	const button = document.querySelector<HTMLButtonElement>(
		`[data-ps-attr-option][data-ps-attr-name="${name}"][data-ps-attr-value="${value}"]`
	)
	if (!button) {
		throw new Error(`找不到屬性選項按鈕：${name}=${value}`)
	}
	button.dispatchEvent(new window.MouseEvent('click', { bubbles: true }))
}

/** 讀出目前要送進購物車的 variation_id（空字串代表尚未選出變體） */
export function currentVariationId(): string {
	const input = document.querySelector<HTMLInputElement>('[data-ps-variation-id]')
	return input ? input.value : ''
}

/** 加購按鈕目前是否可按 */
export function submitEnabled(): boolean {
	const submit = document.querySelector<HTMLButtonElement>('[data-ps-submit]')
	return !!submit && !submit.disabled
}

/** 讀出目前送出的 `attribute_*` hidden input（鍵取自選中變體自己的 attributes） */
export function currentAttributeFields(): Record<string, string> {
	const host = document.querySelector('[data-ps-attr-fields]')
	if (!host) {
		return {}
	}
	const out: Record<string, string> = {}
	host.querySelectorAll<HTMLInputElement>('input[type="hidden"]').forEach((input) => {
		out[input.name] = input.value
	})
	return out
}
