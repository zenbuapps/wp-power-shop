/**
 * 夥伴報表網址附掛「預先篩選到某個賣場」的 query string
 *
 * 對應實作：js/src/pages/admin/ProfitShop/types.ts（`buildReportUrlWithShop`）
 * 對應動線：站長在 `#/profit-shop` 某一列按「報表」→ 開啟該夥伴的報表頁，
 *           且畫面已經預先篩選到**這一列的賣場**。
 *
 * ── 🔴 為什麼「不可 throw」比「附掛正確」更重要 ─────────────────
 *
 * `new URL(str)` 對任何**非絕對** URL 一律 throw `TypeError`（不是回傳 null）。
 * 這個函式的呼叫點在 antd Table 的 render callback 內，而 admin SPA 那一段
 * **沒有 error boundary** —— 一次 throw 就是整個 `#/profit-shop` 白屏。
 *
 * 改動前那行 `href={partner?.report_url ?? undefined}` 是零 throw 的。
 * 若為了一個「預先篩選」的附加功能，把整頁的可用性賠進去，那是不划算的交換：
 * 附掛失敗只要退化成「報表開得起來、只是沒有預先篩選」，
 * 使用者仍能自己操作篩選器；白屏則什麼都不能做。
 *
 * `report_url` 的契約是 **nullable**、值由後端 `home_url()` 衍生，正常情況必為
 * 絕對網址——但那是「正常情況」，不是型別保證。站台設定異常、或後端哪天改成
 * 回傳相對路徑，都會讓那個假設失效。
 *
 * ── 這份測試同時鎖住一條三方硬契約 ────────────────────────
 *
 *   admin 產生連結（PROFIT_SHOP_REPORT_FILTER_PARAM）
 *     ↔ partner portal 解析（SHOP_IDS_PARAM）
 *     ↔ 後端 `GET /partner-reports/*` 讀的 query key
 *
 * 三者任一改名，症狀都是「報表開得起來、篩選靜默失效」，零錯誤訊息。
 * 前兩者住在**不同的 bundle**（admin SPA / partner portal SPA，production 完全
 * 隔離、互不 import），因此沒有任何編譯期關聯——只有測試能把它們綁在一起。
 */
import { describe, it, expect } from 'vitest'

import {
	SHOP_IDS_PARAM,
	parseShopIdsParam,
} from '@/partner-portal/utils/shopIdsParam'
import {
	PROFIT_SHOP_REPORT_FILTER_PARAM,
	buildReportUrlWithShop,
} from '@/pages/admin/ProfitShop/types'

const REPORT_URL = 'https://example.com/profit-report/partner-a/'

describe('buildReportUrlWithShop — 正常附掛', () => {
	it('乾淨的絕對網址：附上 ?shop_ids=', () => {
		expect(buildReportUrlWithShop(REPORT_URL, 1932)).toBe(
			'https://example.com/profit-report/partner-a/?shop_ids=1932'
		)
	})

	it('已帶 query string：附加而不是覆蓋既有參數', () => {
		expect(buildReportUrlWithShop(`${REPORT_URL}?foo=bar`, 1932)).toBe(
			'https://example.com/profit-report/partner-a/?foo=bar&shop_ids=1932'
		)
	})

	it('🔴 已帶 hash：query 必須插在 hash **之前**', () => {
		// partner portal 是 HashRouter。若把 shop_ids 接到 hash 後面
		//（`#/?shop_ids=1932`），`window.location.search` 會是空字串，
		// 解析端讀不到——深連結靜默失效，連結看起來卻完全正常。
		expect(buildReportUrlWithShop(`${REPORT_URL}#/`, 1932)).toBe(
			'https://example.com/profit-report/partner-a/?shop_ids=1932#/'
		)
	})

	it('同時帶 query 與 hash 時兩者都保留', () => {
		expect(buildReportUrlWithShop(`${REPORT_URL}?foo=bar#/dashboard`, 1932)).toBe(
			'https://example.com/profit-report/partner-a/?foo=bar&shop_ids=1932#/dashboard'
		)
	})

	it('既有的 shop_ids 會被覆蓋（用 set 而非 append，避免同名參數重複）', () => {
		expect(buildReportUrlWithShop(`${REPORT_URL}?shop_ids=1`, 1932)).toBe(
			'https://example.com/profit-report/partner-a/?shop_ids=1932'
		)
	})
})

describe('🔴 buildReportUrlWithShop — 絕不 throw，失敗一律 fallback 回原值', () => {
	// 每一格都同時斷言「不 throw」與「回傳原值」。只驗回傳值不夠：
	// 若實作改成 throw，`expect(...).toBe(...)` 也會紅，但錯誤訊息會是
	// TypeError 而不是「白屏風險」，下一個人不會知道這條在守什麼。
	it.each([
		['', '空字串'],
		['/profit-report/partner-a/', '絕對路徑（無 origin）'],
		['profit-report/partner-a/', '相對路徑'],
		['   ', '純空白'],
		['not a url at all', '根本不是網址'],
	])('%s（%s）→ 原樣回傳且不 throw', (input) => {
		expect(() => buildReportUrlWithShop(input, 1932)).not.toThrow()
		expect(buildReportUrlWithShop(input, 1932)).toBe(input)
	})

	it.each([
		[0, '零'],
		[-1, '負數'],
		[1.5, '小數'],
		[Number.NaN, 'NaN'],
		[Number.POSITIVE_INFINITY, 'Infinity'],
	])('shopId 為 %s（%s）→ 不附掛，原樣回傳', (shopId) => {
		// 不合法的 id 寧可不附掛：`?shop_ids=NaN` 會讓後端走進
		// 「清洗後為空」的分支，那條路的語意判定（fail-closed 哨符）遠比
		// 「根本沒送這個參數」複雜，沒有理由主動製造它。
		expect(() => buildReportUrlWithShop(REPORT_URL, shopId)).not.toThrow()
		expect(buildReportUrlWithShop(REPORT_URL, shopId)).toBe(REPORT_URL)
	})

	it('非絕對網址 + 非法 id 同時發生也只是原樣回傳', () => {
		expect(buildReportUrlWithShop('', 0)).toBe('')
	})
})

describe('🔴 跨 bundle 契約：admin 產生的連結，partner portal 必須解析得出來', () => {
	// admin SPA 與 partner portal SPA 在 production 是**完全隔離的兩個 bundle**
	//（隔離驗收：partner bundle grep `App1` / `Refine` 為 0 命中），
	// 彼此沒有任何 import 關係，因此這條契約在編譯期毫無保護。
	//
	// 測試是唯一能把兩端綁在一起的地方——這也是本檔存在的主要理由，
	// 而不只是為了驗證字串拼接。
	it('參數名兩端逐字相同', () => {
		expect(PROFIT_SHOP_REPORT_FILTER_PARAM).toBe(SHOP_IDS_PARAM)
	})

	it('🔴 round-trip：build 出來的網址，解析端讀回同一個賣場 ID', () => {
		// 這是整條動線唯一的自動化訊號。它同時涵蓋：
		//   - 參數名對得上
		//   - query 放在 hash 之前（放錯的話 search 會是空字串）
		//   - 值的格式通得過解析端嚴格的 `/^\d+$/`
		const built = buildReportUrlWithShop(REPORT_URL, 1932)
		const { search } = new URL(built)

		expect(parseShopIdsParam(search)).toEqual({ ids: [1932], droppedCount: 0 })
	})

	it('round-trip 在「已有 hash」的網址上同樣成立', () => {
		const built = buildReportUrlWithShop(`${REPORT_URL}#/dashboard`, 1932)
		const url = new URL(built)

		expect(url.hash).toBe('#/dashboard')
		expect(parseShopIdsParam(url.search)).toEqual({
			ids: [1932],
			droppedCount: 0,
		})
	})

	it('🔴 fallback 路徑不會產生「解析得出東西」的假象', () => {
		// 附掛失敗時回傳的是原始網址，解析端讀到的必須是「完全沒帶參數」
		//（droppedCount 0 = 不過濾 = 全部賣場合計），而不是
		// 「帶了但壞掉」（droppedCount > 0 = 前端會顯示忽略提示）。
		// 兩者的 ids 都是空陣列，差別只在夥伴看不看得到一句錯誤提示。
		const built = buildReportUrlWithShop('/profit-report/partner-a/', 1932)

		expect(built).toBe('/profit-report/partner-a/')
		expect(parseShopIdsParam('')).toEqual({ ids: [], droppedCount: 0 })
	})
})
