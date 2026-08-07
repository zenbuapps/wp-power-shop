/**
 * 訂單頁分潤面板的資料轉換
 *
 * 對應規格：specs/features/profit-shop/view-order-profit-panel.feature
 *   Rule: 後置（呈現）- 逐一列出帶有分潤歸屬的訂單品項
 *   Rule: 後置（無資料）- 沒有任何分潤歸屬的訂單完全不顯示面板
 *
 * ── 這個面板為什麼刻意讀 order item meta 而不是打結算 API ──────
 *
 * 兩個理由，第二個是硬性的：
 *   1. 規格的「Rule: 後置（凍結）」要求這份資訊已凍結在訂單品項上——賣場改比例 /
 *      改名 / 被刪除之後，歷史訂單顯示的數字**不應該跟著變**。直接讀 meta 天然滿足。
 *   2. `SettlementFilterInput` 根本沒有 `order_id` 這個篩選條件。要用結算端點取
 *      單一訂單的明細，只能整批撈回來再前端過濾——而列表 per_page 上限 100，
 *      訂單一多就會**靜默漏資料**。
 *
 * 因此「哪些品項算有分潤歸屬」「合計怎麼算」這兩件事完全落在本檔測的純函式上。
 */
import { describe, it, expect } from 'vitest'
import * as fs from 'node:fs'
import * as path from 'node:path'
import {
	toPanelRow,
	toPanelRows,
	sumProfitAmount,
	type TProfitPanelRow,
} from '@/pages/admin/Orders/Edit/ProfitPanel/utils'
import {
	DELETED_PARTNER_LABEL,
	DELETED_SHOP_LABEL,
	SETTLEMENT_STATUS_META,
	SETTLEMENT_STATUS,
} from '@/pages/admin/ProfitSettlement/types'

/** 造一筆訂單品項，meta 以物件形式傳入 */
const item = (
	id: number,
	name: string,
	meta: Record<string, string | number | null>
) =>
	({
		id,
		name,
		meta_data: Object.entries(meta).map(([key, value], i) => ({
			id: i,
			key,
			value,
		})),
	}) as never

/** 一筆完整的分潤品項 */
const withProfit = (id = 428, over: Record<string, string | number> = {}) =>
	item(id, 'T-Shirt', {
		_profit_shop_id: 1932,
		_profit_partner_term_id: 34,
		_profit_rate: 10,
		_actual_price: '600.00',
		_profit_amount: '60.00',
		_settlement_status: 'pending',
		...over,
	})

/** 造一列面板列（只給合計用） */
const row = (amount: string): TProfitPanelRow =>
	({ profit_amount: amount }) as TProfitPanelRow

describe('toPanelRow — 判定品項有沒有分潤歸屬', () => {
	it('完整分潤 meta 的品項會被轉成面板列', () => {
		const r = toPanelRow(withProfit())
		expect(r).not.toBeNull()
		expect(r).toMatchObject({
			order_item_id: 428,
			product_name: 'T-Shirt',
			shop_id: 1932,
			partner_term_id: 34,
			rate: 10,
			actual_price: '600.00',
			profit_amount: '60.00',
			status: 'pending',
		})
	})

	it('沒有 _profit_shop_id 的一般品項回 null', () => {
		// 判定依據刻意選 _profit_shop_id：那是 OrderLineItemSettlementHook
		// **驗過 HMAC 簽章之後**才會寫入的第一個欄位
		expect(toPanelRow(item(1, '一般商品', { _qty: 1 }))).toBeNull()
	})

	it('_profit_shop_id 為 0 / 負數 / 非數字時視為沒有分潤歸屬', () => {
		for (const bad of [0, -1, 'abc', '']) {
			expect(toPanelRow(item(1, 'x', { _profit_shop_id: bad })), `shop_id=${bad}`).toBeNull()
		}
	})

	it('🔴 金額欄位原樣保留字串，不做任何算術', () => {
		// 後端是 bcmath 十進位字串。任何一次 Number() 往返都可能讓
		// '600.10' 變成 600.09999999999997，而顯示層再 toFixed 回來就晚了
		const r = toPanelRow(withProfit(1, { _actual_price: '600.10', _profit_amount: '60.01' }))
		expect(r?.actual_price).toBe('600.10')
		expect(r?.profit_amount).toBe('60.01')
	})

	it('缺金額欄位時退回 "0.00" 而非 undefined', () => {
		const r = toPanelRow(item(1, 'x', { _profit_shop_id: 5 }))
		expect(r?.actual_price).toBe('0.00')
		expect(r?.profit_amount).toBe('0.00')
	})

	it('缺結算狀態時退回 pending', () => {
		expect(toPanelRow(item(1, 'x', { _profit_shop_id: 5 }))?.status).toBe('pending')
	})

	it('🔴 rate 為 0 時標記 needs_manual_rate（結帳當下賣場已被刪除）', () => {
		// 與後端 find_settlements() 同一條判準。賣場在結帳當下已刪除時，
		// rate 沒有可信來源，後端記 0 並寫 error log；前端必須把這筆標出來，
		// 否則站長會照著 0 撥款——那位夥伴的錢就這樣消失了，且無任何提示。
		expect(toPanelRow(withProfit(1, { _profit_rate: 0 }))?.needs_manual_rate).toBe(true)
		expect(toPanelRow(withProfit(1, { _profit_rate: 10 }))?.needs_manual_rate).toBe(false)
	})

	it('settled_at 缺值或非數字時為 null（不得變成 NaN 或 0）', () => {
		expect(toPanelRow(withProfit())?.settled_at).toBeNull()
		expect(toPanelRow(withProfit(1, { _settled_at: 'x' }))?.settled_at).toBeNull()
		expect(toPanelRow(withProfit(1, { _settled_at: 1786071849 }))?.settled_at).toBe(1786071849)
	})
})

describe('toPanelRows — 決定面板顯不顯示', () => {
	it('🔴 完全沒有分潤歸屬的訂單回空陣列（元件據此整個不渲染面板）', () => {
		// Rule: 後置（無資料）。一般訂單印一個空面板只會佔版面、
		// 讓站長每次都得確認一次「這張真的沒有嗎」。
		expect(toPanelRows([item(1, 'a', {}), item(2, 'b', { _qty: 3 })])).toEqual([])
	})

	it('混合訂單只列出有分潤歸屬的品項', () => {
		const rows = toPanelRows([item(1, '一般商品', {}), withProfit(428), item(3, '另一個', {})])
		expect(rows).toHaveLength(1)
		expect(rows[0]?.order_item_id).toBe(428)
	})

	it('逐一列出多筆分潤品項，順序與訂單品項一致', () => {
		// Rule: 後置（呈現）- 逐一列出，不是合併成一筆
		const rows = toPanelRows([withProfit(11), withProfit(22), withProfit(33)])
		expect(rows.map((r) => r.order_item_id)).toEqual([11, 22, 33])
	})

	it('items 不是陣列時回空陣列，不拋錯', () => {
		// record?.items 在資料載入中會是 undefined
		for (const bad of [undefined, null, 'x', 42, {}]) {
			expect(toPanelRows(bad)).toEqual([])
		}
	})
})

describe('sumProfitAmount — 分潤合計用字串定點加法', () => {
	it('🔴 不得出現浮點誤差', () => {
		// 這正是改用定點加法的原因：48.00 + 180.00 用 float 相加會得到
		// 227.99999999999997。站長對帳時看到的是「逐列相加 ≠ 合計」——
		// 那是最先會被炸出來的東西。
		expect(sumProfitAmount([row('48.00'), row('180.00')])).toBe('228.00')

		// 對照組：確認 float 真的會錯，這條測試不是在測一個不存在的問題
		expect(0.48 + 1.8).not.toBe(2.28)
	})

	it('0.1 + 0.2 這種經典案例也正確', () => {
		expect(sumProfitAmount([row('0.10'), row('0.20')])).toBe('0.30')
		expect(0.1 + 0.2).not.toBe(0.3)
	})

	it('空陣列回 "0.00"', () => {
		expect(sumProfitAmount([])).toBe('0.00')
	})

	it('進位正確（99 分 + 2 分 = 1.01）', () => {
		expect(sumProfitAmount([row('0.99'), row('0.02')])).toBe('1.01')
	})

	it('補齊小數位：整數字串與一位小數都當成兩位處理', () => {
		expect(sumProfitAmount([row('60'), row('40')])).toBe('100.00')
		expect(sumProfitAmount([row('1.5'), row('1.5')])).toBe('3.00')
	})

	it('負數（沖回後的金額）正確處理', () => {
		expect(sumProfitAmount([row('100.00'), row('-30.50')])).toBe('69.50')
		expect(sumProfitAmount([row('-1.25')])).toBe('-1.25')
	})

	it('壞資料當 0 計，不讓整個合計變 NaN', () => {
		// 一筆壞掉的 meta 不該讓站長看到「NaN」——那比少算一筆更難懂
		expect(sumProfitAmount([row('60.00'), row('abc')])).toBe('60.00')
	})

	it('大量筆數不累積誤差（300 筆 × 0.01 = 3.00）', () => {
		const rows = Array.from({ length: 300 }, () => row('0.01'))
		expect(sumProfitAmount(rows)).toBe('3.00')
	})
})

describe('結算狀態的中文呈現與訂單頁 / 結算頁一致', () => {
	// Rule: 後置（狀態）- 結算狀態以中文呈現且與結算頁一致
	//
	// 「一致」靠的是兩個頁面 import 同一份 SETTLEMENT_STATUS_META（結構保證），
	// 本區塊守的是另外兩件光靠共用常數保證不了的事：
	//   (a) 四個狀態鍵必須與後端 PHP 寫進 _settlement_status 的字面值相同
	//   (b) 佔位文字必須與 PHP 的常數逐字相同

	it('四個狀態都有中文標籤，沒有漏掉任何一個', () => {
		expect(Object.keys(SETTLEMENT_STATUS_META).sort()).toEqual([
			'cancelled',
			'paid',
			'pending',
			'refunded',
		])
		Object.values(SETTLEMENT_STATUS_META).forEach(({ label }) => {
			expect(label).toMatch(/^[\u4e00-\u9fff]+$/)
		})
	})

	it('標籤文字符合站長在兩個頁面看到的用詞', () => {
		expect(SETTLEMENT_STATUS_META[SETTLEMENT_STATUS.PENDING].label).toBe('待結算')
		expect(SETTLEMENT_STATUS_META[SETTLEMENT_STATUS.PAID].label).toBe('已撥款')
		expect(SETTLEMENT_STATUS_META[SETTLEMENT_STATUS.REFUNDED].label).toBe('已退款')
		expect(SETTLEMENT_STATUS_META[SETTLEMENT_STATUS.CANCELLED].label).toBe('已取消')
	})

	it('🔴 狀態鍵必須與後端 SettlementRecord 的字面值相同', () => {
		// toPanelRow 直接把 _settlement_status 的原始字串當成 key 查標籤。
		// 前後端字面值一旦分家，畫面會顯示 undefined——而 TS 完全攔不到，
		// 因為那個值是從 meta 讀出來的 string、只是被 as 成了聯合型別。
		const php = fs.readFileSync(
			path.resolve(
				process.cwd(),
				'inc/classes/Domains/ProfitShop/Domain/Entity/SettlementRecord.php'
			),
			'utf-8'
		)
		Object.keys(SETTLEMENT_STATUS_META).forEach((key) => {
			expect(php, `後端 SettlementRecord 找不到狀態 "${key}"`).toContain(`'${key}'`)
		})
	})

	it('🔴 已刪除佔位文字必須與 PHP WpAdminSettlementQuery 的常數逐字相同', () => {
		// 結算頁的名稱由後端解析後送來，訂單頁卻是前端自己解析凍結的 meta。
		// 兩邊字串分家時，站長會在兩個頁面看到同一筆紀錄有不同的賣場名稱，
		// 而且沒有任何錯誤訊息。
		const php = fs.readFileSync(
			path.resolve(
				process.cwd(),
				'inc/classes/Domains/ProfitShop/Infrastructure/Persistence/WpAdminSettlementQuery.php'
			),
			'utf-8'
		)
		// ⚠️ 刻意不用正則：佔位文字含 `[` `]`，那是正則的字元類別 metacharacter，
		// 不跳脫的話 `[已刪除賣場]` 會被解讀成「這幾個字裡的任一個」而**意外通過**
		// ——一個永遠不會紅的測試比沒有測試更糟。改成先把 PHP 的空白正規化，
		// 再用純字串比對，既躲開跳脫問題，也不會因為 phpcbf 調整對齊而假紅。
		const normalized = php.replace(/[ \t]+/g, ' ')
		expect(normalized).toContain(`const DELETED_SHOP_LABEL = '${DELETED_SHOP_LABEL}';`)
		expect(normalized).toContain(
			`const DELETED_PARTNER_LABEL = '${DELETED_PARTNER_LABEL}';`
		)

	})
})
