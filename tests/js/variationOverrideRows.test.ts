/**
 * 變體展開列：孤兒鍵與商品層殘留值
 *
 * 對應規格：specs/features/profit-shop/save-variation-overrides.feature
 *   Rule: 前端（失效）- 賣場保存了已不存在的變體時必須顯示且可移除
 *   Rule: 前端（可變商品）- 商品層的三個欄位改到變體層設定，但殘留值必須顯示
 *
 * ── 兩條 Rule 是同一條紀律的兩個案例 ──────────────────────────
 *
 * **會影響後端行為的狀態，UI 上一定要有對應物。**
 * 靜默隱藏在兩處都會製造「存不了檔，而畫面上完全看不出原因」的死結。
 */
import { describe, it, expect } from 'vitest'
import { mergeVariationRows } from '@/pages/admin/ProfitShop/utils'
import {
	describeOverride,
	hasProductLevelOverride,
} from '@/pages/admin/ProfitShop/components/ItemsEditor'
import type { TPriceOverride } from '@/pages/admin/ProfitShop/types'

const wcVariation = (id: number) => ({ id, attributes: {} })

const override = (over: Partial<TPriceOverride> = {}): TPriceOverride => ({
	regular_price: null,
	sale_price: null,
	signup_fee: null,
	...over,
})

describe('mergeVariationRows — 孤兒鍵必須顯示出來', () => {
	it('WC 現有的變體全部列出', () => {
		const rows = mergeVariationRows([wcVariation(301), wcVariation(302)], {})
		expect(rows.map((r) => r.variationId)).toEqual([301, 302])
		expect(rows.every((r) => r.record !== undefined)).toBe(true)
	})

	it('🔴 賣場存了但 WC 已無此變體 → 仍列出，且 record 為 undefined', () => {
		// 站長在商品編輯頁刪掉某個變體，賣場的 items 卻還存著它的覆寫。
		// 那筆資料送到後端會被 ItemValidator 以 InvalidVariation 打回 400——
		// 若在這裡靜默隱藏，使用者只會看到「儲存失敗」，
		// **永遠找不到、也清不掉源頭**，那個賣場會從此存不了檔。
		const rows = mergeVariationRows([wcVariation(301)], { '999': {} })
		expect(rows.map((r) => r.variationId)).toEqual([301, 999])
		expect(rows[1]?.record).toBeUndefined()
	})

	it('孤兒排在實際變體之後', () => {
		// 正常情況下孤兒是少數，排前面會把常用的列擠下去
		const rows = mergeVariationRows([wcVariation(301)], {
			'999': {},
			'301': {},
		})
		expect(rows.map((r) => r.variationId)).toEqual([301, 999])
	})

	it('已存在於 WC 的變體不會被重複列出（即使賣場也存了它）', () => {
		const rows = mergeVariationRows([wcVariation(301)], { '301': {} })
		expect(rows).toHaveLength(1)
	})

	it('全部都是孤兒時照樣列出（商品的變體被整批刪光）', () => {
		const rows = mergeVariationRows([], { '301': {}, '302': {} })
		expect(rows.map((r) => r.variationId)).toEqual([301, 302])
	})

	it('value 為 undefined / null 時不拋錯', () => {
		expect(mergeVariationRows([wcVariation(301)], undefined)).toHaveLength(1)
		expect(mergeVariationRows([wcVariation(301)], null)).toHaveLength(1)
	})

	it('key 一律是字串、variationId 一律是數字', () => {
		// key 給 antd Table 當 rowKey（字串），variationId 給後端 payload（數字）。
		// 兩者混用會讓 Set.has 比對失效，孤兒判定就整個失準
		const [row] = mergeVariationRows([wcVariation(301)], {})
		expect(typeof row?.key).toBe('string')
		expect(typeof row?.variationId).toBe('number')
	})
})

describe('hasProductLevelOverride — 可變商品的商品層殘留值', () => {
	it('三欄皆 null 時沒有殘留', () => {
		expect(hasProductLevelOverride(override())).toBe(false)
	})

	it.each([
		'regular_price',
		'sale_price',
		'signup_fee',
	] as const)('只要 %s 有值就算殘留', (field) => {
		expect(hasProductLevelOverride(override({ [field]: '300' }))).toBe(true)
	})

	it('值為 "0" 也算殘留（0 元覆寫是合法設定：免費贈品）', () => {
		expect(hasProductLevelOverride(override({ regular_price: '0' }))).toBe(true)
		expect(hasProductLevelOverride(override({ sale_price: '0' }))).toBe(true)
	})

	it('🔴 值為空字串時仍算殘留——判斷必須是 `null !==`，不可用真值判斷', () => {
		// ⚠️ 這裡的陷阱**不是** '0'：`'0'` 是非空字串，在 JS 裡是 **truthy**，
		// 真值判斷與 `null !==` 對它的結果相同（第一版的註解把這點寫反了，
		// 探針實證才發現——正是本專案吃過三次虧的那種「註解宣稱與實際相反」）。
		//
		// 真正會分歧的是**空字串**：`Boolean('') === false`。
		// 空字串進得來（`toInputValue` 明文把 '' 與 null 分開處理），
		// 而它一樣會被送進 payload、一樣會被後端驗證。用真值判斷會讓它
		// 從畫面上消失，站長就少了唯一的清除入口。
		expect(hasProductLevelOverride(override({ regular_price: '' }))).toBe(true)
		expect(hasProductLevelOverride(override({ signup_fee: '' }))).toBe(true)
	})
})

describe('describeOverride — 把殘留值講成人話', () => {
	it('列出所有有值的欄位', () => {
		expect(
			describeOverride(override({ regular_price: '300', sale_price: '240' }))
		).toBe('原價 300、特價 240')
	})

	it('只有一欄時不出現多餘的分隔符', () => {
		expect(describeOverride(override({ sale_price: '240' }))).toBe('特價 240')
	})

	it('三欄皆 null 時回空字串', () => {
		expect(describeOverride(override())).toBe('')
	})

	it('"0" 必須被描述出來（0 元覆寫是合法設定）', () => {
		expect(describeOverride(override({ sale_price: '0' }))).toBe('特價 0')
	})

	it('註冊費也要列出（訂閱商品的第三欄）', () => {
		expect(describeOverride(override({ signup_fee: '99' }))).toBe('註冊費 99')
	})
})
