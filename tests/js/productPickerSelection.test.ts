/**
 * 賣場商品選擇 Modal 的勾選規則
 *
 * 對應規格：specs/features/profit-shop/select-shop-products.feature
 *   Rule: 前置（勾選）- 已在賣場中的商品不可重複勾選
 *   Rule: 前置（勾選）- 變體列不可被勾選
 *   Rule: 中置（跨頁）- 勾選狀態必須跨分頁與篩選條件變更保留
 *   Rule: 中置（隔離）- Modal 的勾選狀態不得與商品列表頁的批次選取互相污染
 *
 * ── 「跨頁保留」為什麼是純函式問題 ────────────────────────────
 *
 * antd 的 `onChange` 給的 `selectedRows` **只涵蓋當前頁**——換頁後 dataSource
 * 就沒有前一頁的 record 了，靠它回推會直接掉資料。因此 Modal 改用
 * `onSelect` / `onSelectAll` 拿到的**增減量**自行累積，而累積邏輯就是
 * `mergeSelection` / `dropSelection` 這兩個純函式。
 */
import { describe, it, expect } from 'vitest'
import * as fs from 'node:fs'
import * as path from 'node:path'
import {
	MAX_BATCH_SIZE,
	dropSelection,
	mergeSelection,
	resolveCheckboxState,
} from '@/pages/admin/ProfitShop/components/ProductPickerModal/utils'

/** 造一筆商品列（id 刻意是 string，與 API 回應一致） */
const rec = (id: number | string, type = 'simple') =>
	({ id: String(id), type }) as never

const ctx = (existing: number[] = [], selected: (string | number)[] = []) => ({
	existingIds: new Set(existing),
	selectedKeys: new Set(selected),
})

describe('resolveCheckboxState — 哪些列不能勾', () => {
	it('一般商品可勾選', () => {
		expect(resolveCheckboxState(rec(101), ctx())).toEqual({
			disabled: false,
			reason: null,
		})
	})

	it('變體列不可被勾選', () => {
		// 賣場商品以父商品為單位；變體覆寫走 ItemsEditor 的展開列，是不同的操作入口
		expect(resolveCheckboxState(rec(101, 'variation'), ctx())).toEqual({
			disabled: true,
			reason: 'variation',
		})
	})

	it('已在賣場中的商品不可重複勾選', () => {
		expect(resolveCheckboxState(rec(101), ctx([101]))).toEqual({
			disabled: true,
			reason: 'already-in-shop',
		})
	})

	it('🔴 id 是字串也要能與 number 的 existingIds 比對上（R-2）', () => {
		// TProductRecord.id 是 **string**、product_id 是 **number**。
		// 不轉型直接 Set.has 永遠回 false → 已加入的商品不會被禁選 → 可重複加入。
		// 後端雖然會以 409 ProductAlreadyInShop 擋下（見 ProfitShopKnownGapsTest），
		// 但使用者會看到「勾了、按了加入、然後整批失敗」——毫無線索指向哪一筆重複。
		expect(resolveCheckboxState({ id: '101', type: 'simple' } as never, ctx([101])).disabled).toBe(true)
	})

	it('達單次批次上限後，未選的列被停用', () => {
		const selected = Array.from({ length: MAX_BATCH_SIZE }, (_, i) => String(i))
		expect(resolveCheckboxState(rec(9999), ctx([], selected))).toEqual({
			disabled: true,
			reason: 'batch-limit',
		})
	})

	it('🔴 達上限時，**已選**的列仍必須可以取消勾選', () => {
		// 少了 `!selectedKeys.has(...)` 這個條件，使用者選滿 100 筆之後會發現
		// 自己被鎖死：想換掉其中一筆卻連取消都做不到，只能關掉 Modal 從頭來過。
		const selected = Array.from({ length: MAX_BATCH_SIZE }, (_, i) => String(i))
		expect(resolveCheckboxState(rec(5), ctx([], selected)).disabled).toBe(false)
	})

	it('停用條件的優先序：變體 > 已在賣場 > 批次上限', () => {
		// 同時成立時回報最根本的那個原因，日後接 tooltip 才不會說錯話
		const selected = Array.from({ length: MAX_BATCH_SIZE }, (_, i) => String(i))
		expect(
			resolveCheckboxState(rec(101, 'variation'), ctx([101], selected)).reason
		).toBe('variation')
		expect(resolveCheckboxState(rec(101), ctx([101], selected)).reason).toBe(
			'already-in-shop'
		)
	})
})

describe('mergeSelection / dropSelection — 跨頁累積', () => {
	it('新勾選的列被併進既有選取集合', () => {
		expect(mergeSelection([rec(1)], [rec(2), rec(3)]).map((r) => r.id)).toEqual([
			'1',
			'2',
			'3',
		])
	})

	it('🔴 換頁後前一頁的選取必須保留', () => {
		// 這正是不用 antd `onChange` 的 selectedRows 的原因：那個陣列只涵蓋當前頁，
		// 用它回推會讓第 1 頁的勾選在翻到第 2 頁的瞬間全部消失。
		const page1 = mergeSelection([], [rec(1), rec(2)])
		const page2 = mergeSelection(page1, [rec(50)]) // 第 2 頁勾一筆
		expect(page2.map((r) => r.id)).toEqual(['1', '2', '50'])
	})

	it('重複勾選同一筆不會累積兩份', () => {
		expect(mergeSelection([rec(1)], [rec(1)])).toHaveLength(1)
	})

	it('取消勾選只移除指定的列', () => {
		expect(
			dropSelection([rec(1), rec(2), rec(3)], [rec(2)]).map((r) => r.id)
		).toEqual(['1', '3'])
	})

	it('取消一筆不在集合裡的列不影響結果', () => {
		expect(dropSelection([rec(1)], [rec(99)]).map((r) => r.id)).toEqual(['1'])
	})

	it('全選一次帶進超過剩餘額度的列時，多的被丟棄（第二道防線）', () => {
		const prev = Array.from({ length: 98 }, (_, i) => rec(i))
		const bulk = Array.from({ length: 10 }, (_, i) => rec(1000 + i))
		expect(mergeSelection(prev, bulk)).toHaveLength(MAX_BATCH_SIZE)
	})

	it('已滿時再加完全不變', () => {
		const full = Array.from({ length: MAX_BATCH_SIZE }, (_, i) => rec(i))
		expect(mergeSelection(full, [rec(9999)])).toHaveLength(MAX_BATCH_SIZE)
	})

	it('不修改傳入的陣列（React state 必須是新物件）', () => {
		const prev = [rec(1)]
		mergeSelection(prev, [rec(2)])
		dropSelection(prev, [rec(1)])
		expect(prev).toHaveLength(1)
	})
})

describe('🔴 Modal 的勾選狀態與商品列表頁隔離', () => {
	// Rule: 中置（隔離）- Modal 的勾選狀態不得與商品列表頁的批次選取互相污染
	//
	// 這條的保證是**結構性**的：Modal 用元件區域 useState，不碰
	// components/product/ProductTable 的 module-level 全域 atom。
	// 那個 atom 是全域單例，而 ProductTable mount 時會無條件把它清空——
	// 接了會雙向污染：在 Modal 裡勾選會清掉 #/products 的選取，反之亦然。
	//
	// 因此這裡驗的是「有沒有把那個 atom 接進來」，而不是行為。

	const dir = path.resolve(
		process.cwd(),
		'js/src/pages/admin/ProfitShop/components/ProductPickerModal'
	)
	const sources = fs
		.readdirSync(dir)
		.filter((f) => /\.tsx?$/.test(f))
		.map((f) => fs.readFileSync(path.join(dir, f), 'utf-8'))
		.join('\n')

	it('不 import 商品列表頁的全域選取 atom', () => {
		// 比對 import 來源路徑，不比對識別字——識別字改名時這條仍然守得住
		expect(sources).not.toMatch(/from\s+['"][^'"]*ProductTable\/atom['"]/)
		expect(sources).not.toMatch(/from\s+['"][^'"]*components\/product\/atom['"]/)
	})

	it('選取狀態走元件區域 useState', () => {
		expect(sources).toMatch(/useState<TProductRecord\[\]>\(\[\]\)/)
	})
})
