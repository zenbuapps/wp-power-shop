/**
 * 賣場前台商品 Modal 的變體比對
 *
 * 對應規格：specs/features/profit-shop/select-product-variation.feature
 * 對應實作：inc/assets/js/profit-shop-front.js（`bindVariations()` 閉包內）
 *
 * ── 🔴 為什麼這段邏輯值得專門的測試 ────────────────────────────
 *
 * 它決定「送哪一個 `variation_id` 進購物車」，而 Phase 9 的變體層價格覆寫
 * **以 variation_id 為鍵**。挑錯變體不是顯示瑕疵，是**收錯錢**——而且
 * 三道分潤閘門**沒有任何一道會發現拿錯了變體**（`SingleProductPriceHook` /
 * `CartPriceOverrideHook` 只會忠實按錯的那個變體算），全程零錯誤訊息。
 *
 * CLAUDE.md Pitfall #31 花了大篇幅保護它，並記載舊的「兩趟法」實測
 * **12.4% 挑到非最佳解**。在此之前它是零自動化測試。
 */

import { beforeEach, describe, expect, it } from 'vitest'
import {
	chooseAttribute,
	currentAttributeFields,
	currentVariationId,
	setupShop,
	submitEnabled,
	type TVariation,
} from './helpers/shopFront'

/**
 * 建一筆變體 fixture
 *
 * `id` 與 `variation_id` 刻意填同一個值——真實 payload 就是如此
 * （`array_merge( $variation->get_data(), [ 'variation_id' => … ] )`）。
 * 受測 JS 讀 `id`，PHP 端 `collect_variations()` 讀 `variation_id`。
 */
const v = (id: number, attributes: Record<string, string>): TVariation => ({
	id,
	variation_id: id,
	attributes,
	display_price: '100',
	is_purchasable: true,
	max_qty: 10,
})

beforeEach(() => {
	document.body.innerHTML = ''
})

describe('Rule: 前置（挑選）- 具體屬性數最多的變體勝出', () => {
	/**
	 * 🔴 Pitfall #31 的分水嶺案例
	 *
	 * `#1` 是 catch-all（兩個屬性都是「任意」），`#2` 對 color 釘死 red。
	 * 使用者選 red + l 時**兩者都匹配**，但 `#2` 的約束集是 `#1` 的嚴格超集，
	 * 語意上毫無歧義——`#2` 是唯一正解。
	 *
	 * `get_available_variations()` 的順序是 `menu_order ASC, ID ASC`，先建 catch-all
	 * 是很自然的操作順序 ⇒「命中即 return」會拿到 `#1`，於是站長為 red 設的
	 * 價格被繞過、Phase 9 的變體層覆寫整個失效。
	 *
	 * 這條是「命中即 return」與「具體度計分」的唯一分水嶺。它紅了就代表有人
	 * 為了「簡化」或「對齊官方 .shift()」把計分法改回去了。
	 */
	it('catch-all 與具體覆寫並存時，選中具體的那一個', () => {
		setupShop({
			attributes: { color: ['red', 'blue'], size: ['l', 'm'] },
			variations: [
				v(1, { color: '', size: '' }), // catch-all，陣列順序在前
				v(2, { color: 'red', size: '' }), // 具體覆寫
			],
		})

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'l')

		expect(currentVariationId()).toBe('2')
	})

	it('全具體變體勝過任何含 Any 的變體', () => {
		setupShop({
			attributes: { color: ['red'], size: ['l'] },
			variations: [
				v(10, { color: '', size: '' }),
				v(11, { color: 'red', size: '' }),
				v(12, { color: 'red', size: 'l' }), // 全具體，score 最高
			],
		})

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'l')

		expect(currentVariationId()).toBe('12')
	})

	/**
	 * 三屬性：只有計分法能挑對
	 *
	 * 已作廢的「兩趟法」只分「全具體」與「其餘」兩級。這裡 #21 與 #22 都不是
	 * 全具體，會一起落到第二趟 → 退回陣列順序 → 挑到 #21（score 1），
	 * 而正解是 #22（score 2）。
	 */
	it('都不是全具體時，仍取具體屬性數較多者（兩趟法在此會挑錯）', () => {
		setupShop({
			attributes: { color: ['red'], size: ['l'], material: ['cotton'] },
			variations: [
				v(21, { color: 'red', size: '', material: '' }), // score 1，順序在前
				v(22, { color: 'red', size: 'l', material: '' }), // score 2，正解
			],
		})

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'l')
		chooseAttribute('material', 'cotton')

		expect(currentVariationId()).toBe('22')
	})
})

describe('Rule: 前置（比對）- 空字串屬性是 wildcard，不參與否決', () => {
	/**
	 * 空字串 = WooCommerce 的「任意 (Any)」，不是「必須等於空字串」
	 *
	 * `wc_get_product_variation_attributes()` 先把父商品全部變體屬性 seed 成 `''`
	 * 再逐一覆蓋（原始碼註解：`// Add it - 'any' will be assumed.`）。
	 * 當成「必須等於空字串」比對，該商品會**沒有任何輸入組合能匹配**、按鈕永遠停用。
	 */
	it('Any 屬性接受任何值', () => {
		setupShop({
			attributes: { color: ['red', 'blue'], size: ['l'] },
			variations: [v(30, { color: '', size: 'l' })],
		})

		chooseAttribute('color', 'blue')
		chooseAttribute('size', 'l')

		expect(currentVariationId()).toBe('30')
	})

	it('全 Any 商品任何組合都匹配得到', () => {
		setupShop({
			attributes: { color: ['red', 'blue'], size: ['l', 'm'] },
			variations: [v(31, { color: '', size: '' })],
		})

		chooseAttribute('color', 'blue')
		chooseAttribute('size', 'm')

		expect(currentVariationId()).toBe('31')
	})
})

describe('Rule: 前置（選滿）- 必須選滿畫面上所有屬性才允許加購', () => {
	/**
	 * 「選滿」與「值比對」是兩件獨立的事
	 *
	 * 對應官方 `add-to-cart-variation.js` 的 `chosenCount`（`:200`）與
	 * `isMatch()`（`:575`）兩處。`WC_Cart::add_to_cart()` 的官方註解明講
	 * Any 屬性「need to be explicitly specified while adding to cart」。
	 *
	 * 混寫成一個判斷式只是把 bug 從「按鈕永遠停用」換成「按了換頁還報錯」。
	 */
	it('Any 屬性一樣要求選出具體值——只選一組時不得選出變體', () => {
		setupShop({
			attributes: { color: ['red'], size: ['l'] },
			variations: [v(40, { color: '', size: '' })],
		})

		chooseAttribute('color', 'red')

		expect(currentVariationId()).toBe('')
		expect(submitEnabled()).toBe(false)
	})

	it('選滿之後才選出變體並啟用加購', () => {
		setupShop({
			attributes: { color: ['red'], size: ['l'] },
			variations: [v(41, { color: 'red', size: 'l' })],
		})

		chooseAttribute('color', 'red')
		expect(submitEnabled()).toBe(false)

		chooseAttribute('size', 'l')
		expect(currentVariationId()).toBe('41')
		expect(submitEnabled()).toBe(true)
	})

	/**
	 * 「選滿」以 DOM 為來源，不從變體 JSON 的鍵集反推
	 *
	 * 兩道防線不可押在同一個假設上：wildcard 比對依賴「變體必含全部鍵」，
	 * 若「選滿」也從 JSON 反推，第三方 filter 剝掉空值鍵時**兩者會同時失效**。
	 * 這裡刻意讓 JSON 只有 1 個鍵、畫面有 2 組屬性——只選 1 組必須不通過。
	 */
	it('變體 JSON 缺鍵時，仍以畫面上的屬性組數為準', () => {
		setupShop({
			attributes: { color: ['red'], size: ['l'] },
			variations: [v(42, { color: 'red' })], // size 鍵被剝掉
		})

		chooseAttribute('color', 'red')

		expect(currentVariationId()).toBe('')
		expect(submitEnabled()).toBe(false)
	})
})

describe('Rule: 安全（守門）- 兩道守門不可移除', () => {
	/**
	 * `keys.length > 0` 防 vacuous truth
	 *
	 * `attributes` 為 `{}` 的空殼變體，內層迴圈一次都不跑、`matched` 保持初值
	 * `true`、`score` 保持 `0`，會在「全部候選都是 0 分」時勝出。
	 * 它**不是**「已選滿」檢查，兩者不可互相取代。
	 */
	it('attributes 為空物件的變體不得被選中', () => {
		setupShop({
			attributes: { color: ['red'], size: ['l'] },
			variations: [
				v(50, {}), // 空殼，陣列順序在前
				v(51, { color: '', size: '' }), // 合法的全 Any
			],
		})

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'l')

		expect(currentVariationId()).toBe('51')
	})

	/**
	 * `picked === null` 的第二道 fail-closed
	 *
	 * 變體宣告了某個屬性、但畫面上沒有對應的選項群組
	 * （`is_variation` 屬性未指派任何 term）→ 不得匹配。
	 * 這種商品在官方單品頁同樣選不齊，WooCommerce 後端也會拒收。
	 */
	it('變體宣告了畫面上不存在的屬性時不得被選中', () => {
		setupShop({
			attributes: { color: ['red'] },
			variations: [v(52, { color: 'red', ghost: 'x' })],
		})

		chooseAttribute('color', 'red')

		expect(currentVariationId()).toBe('')
	})
})

describe('Rule: 前置（平局）- 互不支配時取排列順序的第一個', () => {
	/**
	 * 唯一沒有客觀正解的情況
	 *
	 * `{red,''}` 與 `{'',large}` 都 score 1、誰也不是誰的超集，站長的兩條規則
	 * 本身就衝突，資料裡沒有任何欄位承載「哪條優先」。此時退回陣列順序
	 * （= WooCommerce 官方 `.shift()` 的行為）是合理且可預期的。
	 */
	it('兩個 score 相同且互不支配時取陣列第一個', () => {
		setupShop({
			attributes: { color: ['red'], size: ['large'] },
			variations: [
				v(60, { color: 'red', size: '' }),
				v(61, { color: '', size: 'large' }),
			],
		})

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'large')

		expect(currentVariationId()).toBe('60')
	})
})

describe('Rule: 後置（更新）- 選中變體後同步更新畫面與表單欄位', () => {
	/**
	 * `attribute_*` 的鍵取自**選中變體自己的** `attributes`
	 *
	 * 不是把使用者選的屬性名自行加 `attribute_` 前綴組出來——自訂（非 taxonomy）
	 * 屬性經 WC `sanitize_title()` 後可能與原始名稱不同（中文屬性會 percent-encode），
	 * 自行組鍵會在那些商品上**安靜地**對不上而被判為「缺少屬性」。
	 */
	/**
	 * 🔴 這條同時鎖住一個容易被誤刪的對齊邏輯
	 *
	 * 兩邊的屬性名**前綴不同**，這是 WooCommerce 的既有落差、不是本專案造成的：
	 *   DOM  `data-ps-attr-name`  ← `get_variation_attributes()` 的 key ⇒ **不帶**前綴（`pa_color`）
	 *   JSON `attributes` 的 key  ← `get_available_variations()`   ⇒ **帶**前綴（`attribute_pa_color`）
	 *
	 * `findBestVariation()` 的 `.replace(/^attribute_/, '')` 就是為了對齊這兩者。
	 * 拿掉它 → 兩邊永遠比不出相等 → **所有 taxonomy 屬性商品都選不出變體**，
	 * 而畫面上只會看到「按鈕永遠停用」，沒有任何錯誤訊息。
	 *
	 * 而送出的 hidden input 必須用**變體自己的**帶前綴鍵（WooCommerce 認的那個），
	 * 不是把 DOM 上的名字自行加前綴組出來。
	 */
	it('DOM 屬性名不帶前綴、變體 JSON 帶前綴，兩者必須對齊', () => {
		setupShop({
			// DOM 上是 get_variation_attributes() 的 key（不帶前綴）
			attributes: { pa_color: ['red'], pa_size: ['l'] },
			// 變體 JSON 是 get_available_variations() 的 key（帶前綴）
			variations: [v(70, { attribute_pa_color: 'red', attribute_pa_size: 'l' })],
		})

		chooseAttribute('pa_color', 'red')
		chooseAttribute('pa_size', 'l')

		expect(currentVariationId()).toBe('70')
		// 送出的鍵取自變體自己的 attributes（帶前綴），不是 DOM 上的名字
		expect(currentAttributeFields()).toEqual({
			attribute_pa_color: 'red',
			attribute_pa_size: 'l',
		})
	})

	it('改選另一個值時 variation_id 跟著改', () => {
		setupShop({
			attributes: { color: ['red', 'blue'], size: ['l'] },
			variations: [
				v(80, { color: 'red', size: 'l' }),
				v(81, { color: 'blue', size: 'l' }),
			],
		})

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'l')
		expect(currentVariationId()).toBe('80')

		chooseAttribute('color', 'blue')
		expect(currentVariationId()).toBe('81')
	})

	it('賣場非販售期間時即使選滿也不解鎖加購', () => {
		setupShop({
			attributes: { color: ['red'], size: ['l'] },
			variations: [v(90, { color: 'red', size: 'l' })],
			shopOpen: false,
		})

		chooseAttribute('color', 'red')
		chooseAttribute('size', 'l')

		expect(submitEnabled()).toBe(false)
	})
})

describe('Rule: 已知落差（屬性名）- 含空白或特殊符號的自訂屬性永遠匹配不到', () => {
	/**
	 * 🔒 現況鎖（characterization test）：**這條測的是落差確實存在**
	 *
	 * WooCommerce 對自訂（非 taxonomy）屬性，在 `get_available_variations()` 的
	 * `attributes` 鍵上會套 `sanitize_title()`——「顏色 深」之類含空白的名稱會變成
	 * `attribute_%e9%a1%8f%e8%89%b2-%e6%b7%b1`（空白 → `-`、中文 → percent-encode）。
	 *
	 * 而 DOM 的 `data-ps-attr-name` 來自 `get_variation_attributes()`，是**未經處理的
	 * 原始名稱**。`findBestVariation()` 只做 `safeDecode()` + 去 `attribute_` 前綴，
	 * 還原不出被 `sanitize_title()` 改掉的空白 ⇒ 兩邊永遠比不出相等
	 * ⇒ 該商品**沒有任何輸入組合能匹配**，按鈕永遠停用，且沒有任何錯誤訊息。
	 *
	 * ⚠️ 轉紅代表有人補上了 sanitize_title 等價的正規化——那是好事，請同步：
	 *   1. 把 select-product-variation.feature 的該 Rule 從「已知落差」改寫成正向規格
	 *   2. 把本測試改成驗證「含空白的屬性可以正常匹配」
	 * 不要為了讓它綠而把正規化拿掉。
	 */
	it('含空白的自訂屬性目前匹配不到任何變體（已知落差）', () => {
		setupShop({
			// DOM 上是原始名稱（含空白）
			attributes: { '顏色 深': ['紅'], size: ['l'] },
			// 變體 JSON 的鍵是 sanitize_title() 之後的（空白變 -、中文 percent-encode）
			variations: [
				v(200, {
					'attribute_%e9%a1%8f%e8%89%b2-%e6%b7%b1': '紅',
					size: 'l',
				}),
			],
		})

		chooseAttribute('顏色 深', '紅')
		chooseAttribute('size', 'l')

		// 現況：選滿了也匹配不到 → variation_id 仍為空、加購按鈕停用
		expect(currentVariationId()).toBe('')
		expect(submitEnabled()).toBe(false)
	})

	/**
	 * 對照組：**不含空白**的自訂中文屬性可以正常匹配
	 *
	 * `sanitize_title()` 對純中文只做 percent-encode，而 `safeDecode()` 解得回來
	 * ⇒ 這種商品是正常的。有了這條對照，上面那條紅掉時才分辨得出
	 * 「是正規化修好了」還是「連原本能用的也壞了」。
	 */
	it('不含空白的中文屬性可以正常匹配（對照組）', () => {
		setupShop({
			attributes: { 顏色: ['紅'], size: ['l'] },
			variations: [v(201, { 'attribute_%e9%a1%8f%e8%89%b2': '紅', size: 'l' })],
		})

		chooseAttribute('顏色', '紅')
		chooseAttribute('size', 'l')

		expect(currentVariationId()).toBe('201')
	})
})
