/**
 * 賣場商品覆寫的價格轉換與前端守門
 *
 * 對應規格：specs/features/profit-shop/save-shop-item-overrides.feature
 *           specs/features/profit-shop/save-variation-overrides.feature
 * 對應實作：js/src/pages/admin/ProfitShop/utils.ts
 *
 * ── 🔴「留空」與「填 0」是兩件完全不同的事 ────────────────────
 *
 *   留空 → null → **沿用商品原價**（不覆寫）
 *   填 0 → '0'  → **覆寫為 0 元**（免費贈品）
 *
 * 這組語意在商品層（ItemsEditor）與變體層（VariationOverrideEditor）共用同一份
 * 轉換函式，正是為了避免其中一層被寫成另一種轉換——兩者互相吃掉之後，
 * 使用者設定的值會在存檔／讀回之間安靜地變樣（設 0 元變成原價、或反過來）。
 */

import { describe, expect, it } from 'vitest'

import {
	emptyPriceOverride,
	emptyVariationOverride,
	isSaleOverRegular,
	toInputValue,
	toPriceString,
} from '@/pages/admin/ProfitShop/utils'

describe('Rule: 後置（持久化）- 未提供的價格欄位必須保存為 null 代表沿用原價', () => {
	it('null 轉成 undefined（讓 InputNumber 顯示 placeholder），不可轉成 0', () => {
		expect(toInputValue(null)).toBeUndefined()
	})

	/** 🔴 `'0'` 是覆寫為 0 元（免費贈品），不是「沒設定」 */
	it('字串 0 必須還原成數字 0，不可被當成沒設定', () => {
		expect(toInputValue('0')).toBe(0)
	})

	it('一般十進位字串正常還原', () => {
		expect(toInputValue('888')).toBe(888)
		expect(toInputValue('99.5')).toBe(99.5)
	})

	it('非數字字串視為沒設定', () => {
		expect(toInputValue('abc')).toBeUndefined()
		expect(toInputValue('')).toBeUndefined()
	})

	/** 反向：清空欄位 → null（沿用原價） */
	it('null 轉成 null（清空 ＝ 沿用原價）', () => {
		expect(toPriceString(null)).toBeNull()
	})

	/** 🔴 數字 0 必須轉成 '0'，不可被當成「沒填」吃掉 */
	it('數字 0 必須轉成字串 0，不可被吃成 null', () => {
		expect(toPriceString(0)).toBe('0')
	})

	it('一般數字轉成十進位字串', () => {
		expect(toPriceString(888)).toBe('888')
		expect(toPriceString(99.5)).toBe('99.5')
	})

	it('NaN / Infinity 視為沒填', () => {
		expect(toPriceString(Number.NaN)).toBeNull()
		expect(toPriceString(Number.POSITIVE_INFINITY)).toBeNull()
	})

	/**
	 * round-trip：兩個方向必須互為反函式
	 *
	 * 這條是「留空 vs 填 0」語意不會在存檔／讀回之間走樣的直接保證。
	 */
	it('round-trip 保持語意（含 0 這個邊界）', () => {
		for (const original of [null, '0', '888', '99.5']) {
			const back = toPriceString(toInputValue(original) ?? null)
			expect(back).toBe(original)
		}
	})
})

describe('Rule: 前置（參數）- sale_price 不得高於 regular_price', () => {
	it('特價高於原價時回報違規', () => {
		expect(isSaleOverRegular({ regular_price: '100', sale_price: '200', signup_fee: null })).toBe(
			true
		)
	})

	it('特價等於或低於原價時不違規', () => {
		expect(isSaleOverRegular({ regular_price: '100', sale_price: '100', signup_fee: null })).toBe(
			false
		)
		expect(isSaleOverRegular({ regular_price: '100', sale_price: '80', signup_fee: null })).toBe(
			false
		)
	})

	/**
	 * 任一欄為 null（沿用原價）時不比較
	 *
	 * 真正的高低由商品原價決定，只有後端判得準——前端硬比會誤擋合法輸入
	 * （例如只設特價 80、原價沿用商品的 500）。
	 */
	it('任一欄為 null 時不比較（後端才是唯一真相）', () => {
		expect(isSaleOverRegular({ regular_price: null, sale_price: '200', signup_fee: null })).toBe(
			false
		)
		expect(isSaleOverRegular({ regular_price: '100', sale_price: null, signup_fee: null })).toBe(
			false
		)
		expect(isSaleOverRegular(emptyPriceOverride())).toBe(false)
	})

	it('0 元特價是合法的（免費贈品）', () => {
		expect(isSaleOverRegular({ regular_price: '100', sale_price: '0', signup_fee: null })).toBe(
			false
		)
	})
})

describe('空覆寫的預設形狀', () => {
	it('emptyPriceOverride 三欄皆 null', () => {
		expect(emptyPriceOverride()).toEqual({
			regular_price: null,
			sale_price: null,
			signup_fee: null,
		})
	})

	/**
	 * 🔴 `emptyVariationOverride()` 產出的「空條目」是合法狀態
	 *
	 * 站長替某個變體設定**灌水購買人數**時就會建出這種條目（三個價格皆 null）。
	 * 後端 `PricelessRescuePolicy` 因此**不能**用「條目存在」當判準——那會讓
	 * 全 null 空條目被誤判為「有變體層覆寫價」（TD-66，已修）。
	 * 這條鎖住前端確實會產生這種形狀，讓後端那道判準的前提保持可見。
	 */
	it('emptyVariationOverride 是「價格全 null + 灌水 0」的合法空條目', () => {
		expect(emptyVariationOverride()).toEqual({
			override: { regular_price: null, sale_price: null, signup_fee: null },
			inflated_count: 0,
		})
	})
})
