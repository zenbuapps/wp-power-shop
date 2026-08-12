/**
 * 賣場商品列的顯示狀態推導
 *
 * 對應規格：specs/features/profit-shop/enrich-shop-item-display.feature
 *   Rule: 後置（回應）- 補齊後每筆賣場商品應呈現名稱與 SKU
 *   Rule: 後置（失效）- 商品已刪除時必須標記失效且仍可移除
 *   Rule: 後置（降級）- 補齊查詢失敗不得阻擋賣場編輯
 *
 * ── 這三條 Rule 的分歧點只有一個：五個分支不能混 ──────────────
 *
 * 元件那一層做的事只是「照 state 選要畫什麼」，真正決定行為的是這個推導。
 * 其中 `missing` 與 `failed` 的區別是本檔最重要的一條：
 *   missing = 這個商品**真的不存在了**（站長該把它從賣場移除）
 *   failed  = **我們這次沒查到**（商品可能好好的，只是網路或權限出問題）
 * 混為一談會讓站長在一次查詢失敗之後，把整批仍然有效的商品從賣場裡刪掉。
 */
import { describe, it, expect } from 'vitest'
import {
	resolveShopItemDisplay,
	type TEnrichResult,
} from '@/pages/admin/ProfitShop/utils'

type TFakeProduct = { id: number; name: string; sku: string }

const enrich = (
	over: Partial<TEnrichResult<TFakeProduct>> = {}
): TEnrichResult<TFakeProduct> => ({
	map: new Map(),
	isLoading: false,
	isError: false,
	...over,
})

const product = (id: number): TFakeProduct => ({
	id,
	name: `商品 ${id}`,
	sku: `SKU-${id}`,
})

describe('resolveShopItemDisplay — 五個分支', () => {
	it('補齊查到了 → ok，並帶出完整商品', () => {
		// Rule: 後置（回應）- 補齊後每筆賣場商品應呈現名稱與 SKU
		const found = product(101)
		const r = resolveShopItemDisplay(
			{ product_id: 101 },
			enrich({ map: new Map([[101, found]]) })
		)
		expect(r.state).toBe('ok')
		expect(r.product).toBe(found)
	})

	it('查詢成功但查無此 id → missing（商品已失效）', () => {
		// Rule: 後置（失效）- 商品已刪除時必須標記失效且仍可移除
		const r = resolveShopItemDisplay({ product_id: 999 }, enrich())
		expect(r.state).toBe('missing')
		expect(r.product).toBeUndefined()
	})

	it('🔴 查詢失敗 → failed，不得與 missing 混為一談', () => {
		// Rule: 後置（降級）- 補齊查詢失敗不得阻擋賣場編輯
		//
		// 兩者在畫面上完全不同：missing 顯示「商品已失效」Tag（提示站長移除），
		// failed 只降級顯示 #id。若把 failed 也標成「已失效」，站長會在一次
		// 網路異常之後，把整批仍然有效的商品從賣場裡刪掉——而且是他自己動手刪的，
        // 事後完全查不出原因。
		const r = resolveShopItemDisplay({ product_id: 101 }, enrich({ isError: true }))
		expect(r.state).toBe('failed')
	})

	it('查詢進行中且無 Modal 帶入的資料 → pending', () => {
		expect(
			resolveShopItemDisplay({ product_id: 101 }, enrich({ isLoading: true })).state
		).toBe('pending')
	})

	it('剛從 Modal 加入、補齊還沒回來 → partial（先用 Modal 帶的名稱撐著）', () => {
		// 少了這個分支，剛加入的列會閃一次 Skeleton——使用者剛剛才在 Modal 裡
		// 看到那個商品的名稱，下一秒卻變成骨架，看起來像加錯了
		expect(
			resolveShopItemDisplay(
				{ product_id: 101, _name: '剛加入的商品' },
				enrich({ isLoading: true })
			).state
		).toBe('partial')

		expect(
			resolveShopItemDisplay(
				{ product_id: 101, _sku: 'SKU-101' },
				enrich({ isLoading: true })
			).state
		).toBe('partial')
	})
})

describe('分支優先序（同時成立時誰勝出）', () => {
	it('查到了就是 ok，即使還有其他查詢在載入中', () => {
		// 分 5 個 chunk 查詢，某一塊已回、另一塊還在跑是常態。
		// 若讓 isLoading 蓋過 map，已經查到的列會一直卡在 Skeleton。
		const r = resolveShopItemDisplay(
			{ product_id: 101, _name: 'x' },
			enrich({ map: new Map([[101, product(101)]]), isLoading: true, isError: true })
		)
		expect(r.state).toBe('ok')
	})

	it('查不到但有 Modal 帶入的名稱時，partial 勝過 pending 與 missing', () => {
		expect(
			resolveShopItemDisplay(
				{ product_id: 101, _name: 'x' },
				enrich({ isLoading: false, isError: true })
			).state
		).toBe('partial')
	})

	it('🔴 isLoading 勝過 missing——載入中不得先標成「已失效」', () => {
		// 反過來的話，每次開編輯頁都會先閃一排「商品已失效」再變回正常。
		// 站長只要在那一瞬間按下移除，就真的把有效商品刪掉了。
		expect(
			resolveShopItemDisplay(
				{ product_id: 101 },
				enrich({ isLoading: true, isError: false })
			).state
		).toBe('pending')
	})

	it('isError 與 isLoading 同時為真時，仍以 pending 為準（查詢尚未結束）', () => {
		expect(
			resolveShopItemDisplay(
				{ product_id: 101 },
				enrich({ isLoading: true, isError: true })
			).state
		).toBe('pending')
	})
})

describe('降級不阻斷：四個非 ok 分支都必須留得住 product_id', () => {
	it.each(['missing', 'failed', 'pending', 'partial'] as const)(
		'%s 狀態下 product 為 undefined，但呼叫端仍拿得到 product_id 可以移除',
		(expected) => {
			const cases = {
				missing: { row: { product_id: 77 }, e: enrich() },
				failed: { row: { product_id: 77 }, e: enrich({ isError: true }) },
				pending: { row: { product_id: 77 }, e: enrich({ isLoading: true }) },
				partial: { row: { product_id: 77, _name: 'x' }, e: enrich() },
			}
			const { row, e } = cases[expected]
			const r = resolveShopItemDisplay(row, e)
			expect(r.state).toBe(expected)
			expect(r.product).toBeUndefined()

			// row 本身不被修改——移除鈕靠 row.product_id 定位，
			// 推導過程若動到它，移除會刪錯列
			expect(row.product_id).toBe(77)
		}
	)
})
