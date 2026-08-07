/**
 * 賣場排程設定的表單值 ↔ settings 轉換
 *
 * 對應規格：specs/features/profit-shop/schedule-shop-availability.feature
 *           （前端（後台）- 排程分頁的送出必須合併既有設定）
 *           specs/features/profit-shop/save-profit-shop.feature
 *           （後置（設定）- settings 為自由欄位，送出時必須以既有內容為底合併）
 * 對應實作：js/src/pages/admin/ProfitShop/utils.ts
 *
 * ── 🔴 為什麼這幾個純函式值得測 ──────────────────────────────
 *
 * `settings` 是**自由 array**，裡面除了排程欄位還有 `btnColor` / `showStock` /
 * `showBuyerCount` / `showConfetti`，以及 legacy 匯入帶進來的未知 key。
 * 送出時若沒有以既有內容為底合併，那些欄位會被**整批清掉，且沒有任何錯誤訊息**
 * （HTTP 200、前端顯示儲存成功）——與 settings 未 wp_slash 是同一種資料遺失。
 *
 * 另一條同樣沒有徵兆的是「空陣列 vs 沒設定」：空陣列是**合法值**
 * （站長刻意把三個勾選框全部取消 ＝ 什麼都不顯示），用真值判斷會讓這個選擇被
 * 靜默還原成預設的「顯示商品」。
 */

import dayjs from 'dayjs'
import { describe, expect, it } from 'vitest'

import {
	PROFIT_SHOP_DEFAULT_DISPLAY_PARTS,
	PROFIT_SHOP_DISPLAY_PART,
} from '@/pages/admin/ProfitShop/types'
import { mergeScheduleSettings, toScheduleFormValues } from '@/pages/admin/ProfitShop/utils'

describe('Rule: 後置（設定）- settings 為自由欄位，送出時必須以既有內容為底合併', () => {
	/**
	 * 🔴 本檔最重要的一條：失效 = 站長的賣場設定被整批清空
	 */
	it('本頁不管的欄位必須原樣保留', () => {
		const base = {
			btnColor: '#ff0000',
			showStock: true,
			showBuyerCount: false,
			showConfetti: true,
			legacyUnknownKey: 'from-import',
		}

		const merged = mergeScheduleSettings(base, {
			startTime: null,
			endTime: null,
			beforeDisplay: [],
			beforeText: '',
			afterDisplay: [],
			afterText: '',
		})

		expect(merged.btnColor).toBe('#ff0000')
		expect(merged.showStock).toBe(true)
		expect(merged.showBuyerCount).toBe(false)
		expect(merged.showConfetti).toBe(true)
		expect(merged.legacyUnknownKey).toBe('from-import')
	})

	/**
	 * 清空的時間必須寫 0，不是刪 key
	 *
	 * `base` 裡可能已有舊值，刪 key 不會覆蓋它——症狀是「時間清掉了，
	 * 存檔後又跑回來」。PHP 端把 0 正規化為「未設定」。
	 */
	it('清空時間時寫入 0 而非刪除 key', () => {
		const merged = mergeScheduleSettings(
			{ startTime: 1786000000000, endTime: 1786999999999 },
			{
				startTime: null,
				endTime: null,
				beforeDisplay: [],
				beforeText: '',
				afterDisplay: [],
				afterText: '',
			}
		)

		expect(merged).toHaveProperty('startTime')
		expect(merged).toHaveProperty('endTime')
		expect(merged.startTime).toBe(0)
		expect(merged.endTime).toBe(0)
	})

	it('有設定時間時寫入毫秒時間戳', () => {
		const start = dayjs('2026-09-01T00:00:00Z')
		const merged = mergeScheduleSettings(undefined, {
			startTime: start,
			endTime: null,
			beforeDisplay: [],
			beforeText: '',
			afterDisplay: [],
			afterText: '',
		})

		expect(merged.startTime).toBe(start.valueOf())
		expect(merged.endTime).toBe(0)
	})

	it('自訂文字前後空白必須 trim', () => {
		const merged = mergeScheduleSettings(undefined, {
			startTime: null,
			endTime: null,
			beforeDisplay: [PROFIT_SHOP_DISPLAY_PART.TEXT],
			beforeText: '  即將開賣  ',
			afterDisplay: [],
			afterText: '',
		})

		expect(merged.beforeText).toBe('即將開賣')
	})
})

describe('Rule: 前置（呈現）- 呈現區塊為白名單過濾的多選陣列', () => {
	/**
	 * 🔴 空陣列是**合法值**，不可被還原成預設
	 *
	 * 站長刻意把三個勾選框全部取消 ＝「什麼都不顯示」。若用 `value?.length` 之類的
	 * 真值判斷（而非 `Array.isArray`），這個選擇會被靜默還原成「顯示商品」——
	 * 站長改了設定卻沒有反應，而且沒有任何錯誤訊息。
	 */
	it('空陣列必須原樣保留，不得還原成預設值', () => {
		const merged = mergeScheduleSettings(undefined, {
			startTime: null,
			endTime: null,
			beforeDisplay: [],
			beforeText: '',
			afterDisplay: [],
			afterText: '',
		})

		expect(merged.beforeDisplay).toEqual([])
		expect(merged.afterDisplay).toEqual([])
	})

	/** 對照組：`undefined`（真的沒設定）才退回預設 */
	it('沒設定過的欄位退回預設「只顯示商品」', () => {
		const values = toScheduleFormValues({})

		expect(values.beforeDisplay).toEqual(PROFIT_SHOP_DEFAULT_DISPLAY_PARTS)
		expect(values.afterDisplay).toEqual(PROFIT_SHOP_DEFAULT_DISPLAY_PARTS)
	})

	it('未知的區塊值必須被白名單過濾掉', () => {
		const values = toScheduleFormValues({
			beforeDisplay: ['countdown', 'evil-injection', 'products'],
		})

		expect(values.beforeDisplay).toEqual([
			PROFIT_SHOP_DISPLAY_PART.COUNTDOWN,
			PROFIT_SHOP_DISPLAY_PART.PRODUCTS,
		])
	})

	/**
	 * 舊格式（單選字串）相容
	 *
	 * 這個設定最初實作成單選字串，同一天內改成多選。開發中的資料庫可能還留著
	 * 字串值——對映成等價的區塊組合，而不是丟掉（丟掉會讓站長已設好的倒數安靜消失）。
	 */
	it('舊的單選字串格式對映成等價的區塊組合', () => {
		expect(toScheduleFormValues({ beforeDisplay: 'hidden' }).beforeDisplay).toEqual([])
		expect(toScheduleFormValues({ beforeDisplay: 'countdown' }).beforeDisplay).toEqual([
			PROFIT_SHOP_DISPLAY_PART.COUNTDOWN,
			PROFIT_SHOP_DISPLAY_PART.TEXT,
		])
		expect(toScheduleFormValues({ beforeDisplay: 'browse' }).beforeDisplay).toEqual(
			PROFIT_SHOP_DEFAULT_DISPLAY_PARTS
		)
	})
})

describe('Rule: 前置（正規化）- 壞掉的時間資料視為未設定而不誤關店', () => {
	/**
	 * 🔴 時間戳 0 / 壞資料必須轉成 `null`，不可轉成 `dayjs(0)`
	 *
	 * `dayjs(0)` 是 1970-01-01——會讓「沒設定開賣時間」看起來像「開賣時間設在
	 * 1970 年」，站長按下儲存就把那個垃圾值寫回去了。
	 */
	it('時間戳 0 轉成 null（不可轉成 1970-01-01）', () => {
		const values = toScheduleFormValues({ startTime: 0, endTime: 0 })

		expect(values.startTime).toBeNull()
		expect(values.endTime).toBeNull()
	})

	it('壞掉的時間資料一律視為未設定', () => {
		for (const bad of ['', 'not-a-number', null, undefined, {}, [], -1, false]) {
			const values = toScheduleFormValues({ startTime: bad })
			expect(values.startTime).toBeNull()
		}
	})

	it('字串型的毫秒時間戳可被還原（REST 往返會變字串）', () => {
		const ms = 1786000000000
		const values = toScheduleFormValues({ startTime: String(ms) })

		expect(values.startTime).not.toBeNull()
		expect(values.startTime!.valueOf()).toBe(ms)
	})
})

describe('round-trip：讀出→送回不得改變既有設定', () => {
	/**
	 * 站長只改了名稱、完全沒碰排程分頁時，settings 必須逐字不變
	 *
	 * Edit 頁每次送出都會跑一次 `mergeScheduleSettings(record.settings, form)`，
	 * 這條確保那個路徑不會在使用者沒有意圖時偷偷改動資料。
	 */
	it('未修改排程時 round-trip 保持等值', () => {
		const original = {
			btnColor: '#00ff00',
			showStock: false,
			startTime: 1786000000000,
			endTime: 1786999999999,
			beforeDisplay: [PROFIT_SHOP_DISPLAY_PART.COUNTDOWN],
			beforeText: '即將開賣',
			afterDisplay: [],
			afterText: '已結束',
		}

		const merged = mergeScheduleSettings(original, toScheduleFormValues(original))

		expect(merged.startTime).toBe(original.startTime)
		expect(merged.endTime).toBe(original.endTime)
		expect(merged.beforeDisplay).toEqual(original.beforeDisplay)
		expect(merged.beforeText).toBe(original.beforeText)
		expect(merged.afterDisplay).toEqual(original.afterDisplay)
		expect(merged.afterText).toBe(original.afterText)
		expect(merged.btnColor).toBe(original.btnColor)
		expect(merged.showStock).toBe(false)
	})
})
