/**
 * 賣場篩選深連結 `?shop_ids=` 的解析與清除
 *
 * 對應實作：js/src/partner-portal/utils/shopIdsParam.ts
 * 對應動線：站長在 `#/profit-shop` 按「報表」→ 夥伴收到連結 → 登入後
 *           Dashboard 直接預先篩選到那個賣場。
 *
 * ── 🔴 為什麼這個純函式值得一整份測試 ──────────────────────
 *
 * 它的輸入**完全由連結提供者控制**，而輸出決定夥伴看到的是「單一賣場的數字」
 * 還是「全部賣場的合計」。兩者都會正常渲染、都沒有錯誤訊息，唯一的差別是
 * 數字大小——夥伴不會知道自己看錯了。
 *
 * 具體的三種失效，每一種都是靜默的：
 *   1. 解析太寬鬆（例如直接 `parseInt`）：`'1932abc'` 會被吃成 1932，
 *      `'1e3'` 會被吃成 1 —— 篩到一個**不相干的賣場**。
 *   2. 解析太嚴格 / 沒 trim：連結被通訊軟體插入空白後整段失效，
 *      退化成「不過濾」＝ 全部賣場合計。
 *   3. 沒去重：`'1932,1932'` 與 Select 產生的 `'1932'` 會撞成兩個
 *      react-query cache key（見 `api/reports.ts` 的 `toShopIdsKey`）。
 *
 * ── 空輸入有兩種，語意相反，斷言時不可混為一談 ──────────────
 *
 *   - 完全沒帶 `shop_ids`  → `ids: []`, `droppedCount: 0` → 不過濾（正確的預設檢視）
 *   - 帶了但清洗後空       → `ids: []`, `droppedCount > 0` → 前端仍送出，
 *                             後端以哨符讓 EXISTS 恆假、誠實回 0
 *
 * 兩者的 `ids` 都是空陣列，**只能靠 `droppedCount` 分辨**。因此本檔每一格
 * 都同時斷言兩個欄位，不只看 `ids`。
 */
import * as fs from 'node:fs'
import * as path from 'node:path'

import { describe, it, expect, afterEach } from 'vitest'

import {
	SHOP_IDS_MAX,
	SHOP_IDS_PARAM,
	clearShopIdsFromLocation,
	parseShopIdsParam,
	readShopIdsFromLocation,
} from '@/partner-portal/utils/shopIdsParam'

describe('parseShopIdsParam — 合法輸入', () => {
	it.each([
		['?shop_ids=1932', [1932]],
		['shop_ids=1932', [1932]], // 不帶開頭的 `?` 也要能解析
		['?shop_ids=1932,1933', [1932, 1933]],
		['?shop_ids=1932, 1933', [1932, 1933]], // 通訊軟體常在逗號後插空白
		['?shop_ids=%201932%20', [1932]], // 前後空白（%20）
		['?foo=1&shop_ids=5', [5]], // 與其他 query 並存
		['?shop_ids=007', [7]], // 前導零：`/^\d+$/` 放行，parseInt 得 7
	])('%s → %j（droppedCount 為 0）', (search, expected) => {
		expect(parseShopIdsParam(search)).toEqual({
			ids: expected,
			droppedCount: 0,
		})
	})

	it('🔴 完全沒有 shop_ids 參數 ≠ 有參數但清洗後為空', () => {
		// 前者是「沒有深連結需求」，不該產生任何提示；後者是「連結壞掉了」，
		// 必須讓夥伴知道。兩者的 ids 都是 []，唯一的分辨依據是 droppedCount。
		expect(parseShopIdsParam('')).toEqual({ ids: [], droppedCount: 0 })
		expect(parseShopIdsParam('?foo=bar')).toEqual({ ids: [], droppedCount: 0 })

		// 對照組：連結被截斷成 `?shop_ids=`（通訊軟體很常這樣切）
		expect(parseShopIdsParam('?shop_ids=')).toEqual({ ids: [], droppedCount: 1 })
	})
})

describe('parseShopIdsParam — 非法輸入一律丟棄並計數', () => {
	// 🔴 這一組是「解析太寬鬆」的守門，而守門其實由**兩道獨立的關卡**組成。
	// 下面每個 token 是被哪一道擋下的，是實測值（`node -e` 逐一量過），
	// 不是從註解推想的——寫錯的話下一個人會刪掉「用不到」的那一道：
	//
	//   (a) `/^\d+$/` 前置檢查
	//       擋掉 `parseInt` 會**部分解析成功**、且結果是正整數的輸入：
	//         parseInt('1932abc', 10) → 1932
	//         parseInt('19px',    10) → 19
	//         parseInt('1e3',     10) → 1      （不是 1000）
	//         parseInt('1.9',     10) → 1
	//       這四個少了 regex 就會被靜默吃成一個**看起來合理的正整數**，
	//       夥伴被篩到一個不相干的賣場，畫面正常、數字錯誤。
	//
	//   (b) `parsed > 0`
	//       擋掉解析結果非正的輸入：parseInt('0x1F', 10) → 0
	//       （base 10 在 'x' 就停）。⚠️ `0x1F` 是被**這一道**擋下的，
	//       不是 regex——別把它當成 regex 的證據。
	//
	// 兩道缺一不可，本表因此兩類都收。
	it.each([
		['abc', '純文字（parseInt → NaN）'],
		['1932abc', '🔴 只有 regex 擋得住：parseInt → 1932'],
		['19px', '🔴 只有 regex 擋得住：parseInt → 19'],
		['1e3', '🔴 只有 regex 擋得住：parseInt → 1（不是 1000）'],
		['1.9', '🔴 只有 regex 擋得住：parseInt → 1'],
		['0x1F', '十六進位：parseInt base 10 → 0，由 `> 0` 擋下'],
		['%2B1', '正號（%2B 是真正的 + 字元）'],
		['-1', '負數'],
		['0', '零（post ID 不可能是 0）'],
		['99999999999999999999', '超出 Number.MAX_SAFE_INTEGER'],
	])('?shop_ids=%s（%s）→ 丟棄', (token) => {
		expect(parseShopIdsParam(`?shop_ids=${token}`)).toEqual({
			ids: [],
			droppedCount: 1,
		})
	})

	it('部分合法：保留合法的、丟棄非法的', () => {
		expect(parseShopIdsParam('?shop_ids=1932,abc')).toEqual({
			ids: [1932],
			droppedCount: 1,
		})
		expect(parseShopIdsParam('?shop_ids=abc,1932,-5,1933')).toEqual({
			ids: [1932, 1933],
			droppedCount: 2,
		})
	})

	it('尾端逗號會產生一個空 token，計入 droppedCount（刻意 fail-loud）', () => {
		// 這是已知且刻意接受的輕微噪音。反過來把空 token 一律忽略，
		// 會讓「連結被截斷成 ?shop_ids=」這種**真正該讓人知道**的情況
		// 完全無聲無息——那個代價遠大於偶爾多一句提示。
		expect(parseShopIdsParam('?shop_ids=1932,')).toEqual({
			ids: [1932],
			droppedCount: 1,
		})
	})

	it('⚠️ `+` 在 query string 是「空白」的舊式編碼，因此 `?shop_ids=+1` 得到 1', () => {
		// 這不是解析器的漏洞，是 URLSearchParams 的標準行為：
		//   new URLSearchParams('?shop_ids=+1').get('shop_ids') === ' 1'
		// 解析器接著 trim() 掉那個空白，於是得到合法的 1。
		//
		// 記錄成測試是為了避免下一個人看到「拒正負號」的註解後，
		// 以為這裡有 bug 而去「修」它——真正的正號要寫成 %2B（見上一組）。
		expect(parseShopIdsParam('?shop_ids=+1')).toEqual({
			ids: [1],
			droppedCount: 0,
		})
	})
})

describe('parseShopIdsParam — 去重與上限', () => {
	it('🔴 重複值必須去重，且**不**計入 droppedCount', () => {
		// 去重的理由在 `api/reports.ts` 的 toShopIdsKey：深連結產生的
		// '1932,1932' 與使用者從 Select 選出的 '1932' 若沒收斂成同一個 key，
		// 會變成兩個 react-query 查詢、發兩次網路請求、拿到同一份資料。
		//
		// 不計入 droppedCount 的理由：重複不是「無法辨識」。
		// 報成「已忽略 1 個無法辨識的編號」只會讓夥伴以為連結壞了。
		expect(parseShopIdsParam('?shop_ids=1932,1932')).toEqual({
			ids: [1932],
			droppedCount: 0,
		})
		expect(parseShopIdsParam('?shop_ids=5,5,6,5,6')).toEqual({
			ids: [5, 6],
			droppedCount: 0,
		})
	})

	it('超過上限時截斷，並把被截掉的數量計入 droppedCount', () => {
		const ids = Array.from({ length: SHOP_IDS_MAX + 1 }, (_, i) => i + 1)
		const result = parseShopIdsParam(`?shop_ids=${ids.join(',')}`)

		expect(result.ids).toHaveLength(SHOP_IDS_MAX)
		expect(result.ids[0]).toBe(1)
		expect(result.ids[SHOP_IDS_MAX - 1]).toBe(SHOP_IDS_MAX)
		expect(result.droppedCount).toBe(1)
	})

	it('🔴 去重必須發生在套上限**之前**', () => {
		// 順序顛倒的話，重複值會白白吃掉額度：下面這筆有 50 個重複的 `1`，
		// 先 cap 再去重會讓 51～100 這些合法 id 被靜默擠掉，
		// 而 ids 長度看起來仍然「有值」，非常難察覺。
		const duplicates = Array.from({ length: 50 }, () => 1)
		const distinct = Array.from({ length: SHOP_IDS_MAX }, (_, i) => i + 1)
		const result = parseShopIdsParam(
			`?shop_ids=${[...duplicates, ...distinct].join(',')}`
		)

		expect(result.ids).toHaveLength(SHOP_IDS_MAX)
		expect(result.ids).toEqual(distinct)
		expect(result.droppedCount).toBe(0)
	})

	it('恰好等於上限時不截斷、不回報丟棄', () => {
		const ids = Array.from({ length: SHOP_IDS_MAX }, (_, i) => i + 1)
		expect(parseShopIdsParam(`?shop_ids=${ids.join(',')}`)).toEqual({
			ids,
			droppedCount: 0,
		})
	})
})

describe('🔴 回傳型別刻意不含原始字串（社交工程防線）', () => {
	it('無論輸入多惡意，回傳值裡都不會出現任何一段原始文字', () => {
		// 這條在**型別層**已經由 `TParsedShopIds`（number[] + number）擋住，
		// 讓「不小心把原字串回顯進提示文案」變成 tsc 編譯錯誤。
		//
		// 但 tsconfig 的 include 只有 js/src，tests/js 不進 tsc，
		// 而型別在 runtime 會被完全抹除 —— 因此這裡再用執行期斷言補一道：
		// 若日後有人把 droppedCount 改成 `dropped: string[]` 之類的形狀，
		// 型別閘門可能因為呼叫端一起改而全綠，這條會紅。
		//
		// 為什麼在意：這個值被讀取的地方是「夥伴已經登入、且信任」的報表頁。
		// 保留原始字串等於在可信頁面內放上一塊攻擊者可控的文字，
		// 足以做社交工程（「系統偵測異常，請致電 02-xxxx 核對您的密碼」）。
		const evil = '請致電0900000000核對密碼'
		const result = parseShopIdsParam(`?shop_ids=${encodeURIComponent(evil)}`)

		expect(result).toEqual({ ids: [], droppedCount: 1 })
		expect(JSON.stringify(result)).not.toContain('致電')
		expect(Object.keys(result).sort()).toEqual(['droppedCount', 'ids'])
		expect(typeof result.droppedCount).toBe('number')
		expect(result.ids.every((id) => 'number' === typeof id)).toBe(true)
	})
})

describe('readShopIdsFromLocation / clearShopIdsFromLocation', () => {
	const origin = window.location.origin
	const restore = () => window.history.replaceState(null, '', '/')

	afterEach(restore)

	/**
	 * 🔴 這裡刻意把 query 放在 hash **之前**
	 *
	 * partner portal 用的是 HashRouter，react-router 的 `useLocation().search`
	 * 讀到的是 hash **內部**的 query（`#/login?reason=...`）。
	 * 而 `report_url` 帶的 `?shop_ids=` 在 hash 之前：
	 *
	 *     https://example.com/profit-report/alpha/?shop_ids=1932#/
	 *     └──────────── window.location.search ────┘└ hash ┘
	 *
	 * 照抄 Login.tsx 的 `useLocation().search` 寫法會讀到空字串、深連結靜默失效。
	 * 下面這組 fixture 就是那個真實形狀。
	 */
	it('讀的是 hash 之前的 query（HashRouter 下與 useLocation().search 不同）', () => {
		window.history.replaceState(null, '', '/profit-report/alpha/?shop_ids=1932#/')

		expect(window.location.search).toBe('?shop_ids=1932')
		expect(readShopIdsFromLocation()).toEqual({ ids: [1932], droppedCount: 0 })
	})

	it('沒有 shop_ids 時回傳不過濾的預設值', () => {
		window.history.replaceState(null, '', '/profit-report/alpha/#/')
		expect(readShopIdsFromLocation()).toEqual({ ids: [], droppedCount: 0 })
	})

	it('清除後只移除 shop_ids，其餘 query 與 hash 原樣保留', () => {
		window.history.replaceState(
			null,
			'',
			'/profit-report/alpha/?foo=bar&shop_ids=1932#/dashboard'
		)

		clearShopIdsFromLocation()

		expect(window.location.href).toBe(
			`${origin}/profit-report/alpha/?foo=bar#/dashboard`
		)
		expect(readShopIdsFromLocation().ids).toEqual([])
	})

	it('🔴 不得改動 hash（改到 hash 會觸發 HashRouter 跳轉）', () => {
		window.history.replaceState(null, '', '/profit-report/alpha/?shop_ids=1932#/')

		clearShopIdsFromLocation()

		expect(window.location.hash).toBe('#/')
		expect(window.location.pathname).toBe('/profit-report/alpha/')
	})

	it('沒有 shop_ids 時不動網址（不製造無意義的 replaceState）', () => {
		window.history.replaceState(null, '', '/profit-report/alpha/?foo=bar#/')
		const before = window.location.href

		clearShopIdsFromLocation()

		expect(window.location.href).toBe(before)
	})
})

describe('常數契約', () => {
	it('🔴 參數名是三方硬契約的其中一端', () => {
		// admin 的 PROFIT_SHOP_REPORT_FILTER_PARAM ↔ 本常數 ↔ 後端
		// `GET /partner-reports/*` 讀的 query key，三者必須逐字相同。
		// 對不齊的症狀是「報表開得起來、篩選靜默失效」，零錯誤訊息。
		// 另一端的比對在 reportUrl.test.ts。
		expect(SHOP_IDS_PARAM).toBe('shop_ids')
	})

	it('🔴 上限必須與後端 V2Api::SHOP_IDS_MAX 相同', () => {
		// 前端多送的部分後端會截掉，兩邊數字不同會讓
		// 「畫面顯示 N 個、實際只篩了 M 個」——而畫面上的數字看起來完全正常。
		// 這是本專案唯一守著這條跨語言常數的地方。
		//
		// ⚠️ 這裡刻意**去 PHP 原始碼把數字讀出來**，而不是寫死 `toBe(100)`。
		// 寫死的話，後端把 cap 改成 200 時這條依然全綠，而它的註解卻宣稱
		// 自己在守這個契約——那正是 CLAUDE.md Pitfall #34 記載的失效模式
		//（註解的宣稱與實際行為相反，lint / tsc / 測試一個都攔不到）。
		//
		// 註解必須先剝掉：`SHOP_IDS_MAX` 這個字在同一個檔案的註解裡也出現
		//（見 V2Api.php:1878「去重後再 cap（見 SHOP_IDS_MAX）」），
		// 不剝的話正則可能命中註解而不是宣告——與 componentWiring.test.ts
		// 踩過的是同一個坑。
		const php = fs
			.readFileSync(
				path.resolve(
					process.cwd(),
					'inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php'
				),
				'utf-8'
			)
			.replace(/\/\*[\s\S]*?\*\//g, '')
			.replace(/(^|[^:])\/\/.*$/gm, '$1')

		const matched = php.match(/const\s+SHOP_IDS_MAX\s*=\s*(\d+)\s*;/)

		expect(matched, '後端 V2Api 找不到 SHOP_IDS_MAX 宣告').not.toBeNull()
		expect(Number(matched?.[1])).toBe(SHOP_IDS_MAX)
	})
})
