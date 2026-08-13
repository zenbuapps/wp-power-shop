/**
 * 賣場篩選深連結（`?shop_ids=`）解析與清除
 *
 * 用途：站長在 `#/profit-shop` 列表頁按「報表」時，連結會帶上該賣場的 ID，
 * 夥伴登入後 Dashboard 直接預先篩選到那個賣場。
 *
 * ─────────────────────────────────────────────────────────────
 * 🔴 為什麼讀 `window.location.search` 而不是 react-router 的 `useLocation().search`
 * ─────────────────────────────────────────────────────────────
 * partner portal 用的是 **HashRouter**。react-router 在 HashRouter 下看到的
 * `location.search` 是 **hash 內部**的 query（例如 `#/login?reason=password_changed`
 * 的 `?reason=...`，見 `pages/Login.tsx` 讀 `?reason` 的寫法）。
 *
 * 而 `report_url` 帶的 `?shop_ids=` 在 **hash 之前**：
 *
 *     https://example.com/profit-report/alpha/?shop_ids=1932#/
 *     └──────────── window.location.search ────┘└ hash ┘
 *
 * 照抄 Login.tsx 的寫法（`useLocation().search`）會讀到**空字串**，深連結
 * 靜默失效——夥伴收到的連結看起來正常、點開卻是全部賣場的數字，
 * 沒有任何錯誤訊息。因此本模組一律直接讀 `window.location.search`。
 *
 * 另一個連帶前提（已查證）：portal 的所有導頁（`handleLogout` /
 * `forceLogoutAndRedirect` / 401 interceptor / `navigate`）**只寫
 * `window.location.hash`，從不碰 `window.location.search`**，所以
 * `?shop_ids=` 能在「未登入 → 登入頁 → 登入成功 → Dashboard」整條路徑存活。
 *
 * ─────────────────────────────────────────────────────────────
 * 🔴 為什麼回傳型別刻意是 `number[]`，永遠不保留原始字串
 * ─────────────────────────────────────────────────────────────
 * 這個值來自 URL，**完全由連結提供者控制**，而它會被讀取的地方是「夥伴已經
 * 登入、且信任」的報表頁。若解析結果保留原始字串，實作者最自然的寫法就是把
 * 它塞進提示文案（例如「無法辨識的賣場編號：{raw}」）——那等於在一個可信頁面
 * 內放上一塊攻擊者可控的文字，足以做社交工程
 * （「系統偵測異常，請致電 02-xxxx 核對您的密碼」）。
 *
 * 因此本模組用**型別**把這條約束固定住：對外只吐 `number[]` 與一個
 * `droppedCount` 數字，讓「不小心回顯原字串」變成 tsc 編譯錯誤，
 * 而不是靠下一個人記得這條紀律。
 */

/** URL query 參數名（與 `#/profit-shop` 產生連結的那一端共用同一個字面值） */
export const SHOP_IDS_PARAM = 'shop_ids'

/**
 * 單次可套用的賣場數上限
 *
 * 與後端 `V2Api::build_filter_criteria_from_request()` 的 cap **必須一致**：
 * 前端多送的部分後端會截掉，兩邊數字不同會讓「畫面顯示 N 個、實際只篩了 M 個」。
 */
export const SHOP_IDS_MAX = 100

/**
 * `?shop_ids=` 的解析結果
 *
 * - `ids`：可用的賣場 ID（已去重、已排除非法值、已套用 `SHOP_IDS_MAX` 上限）
 * - `droppedCount`：被丟棄的 token 數量，**只給計數用，不含任何原始字串**
 */
export type TParsedShopIds = {
	ids: number[]
	droppedCount: number
}

/**
 * 嚴格的正整數字面值：拒 NaN／小數／科學記號／負號／`%2B` 正號／空白
 *
 * ⚠️ **`?shop_ids=+1` 不會被這道 regex 擋下，那是正確的**——query string 裡的
 *    裸 `+` 是「空白」的舊式編碼，`URLSearchParams` 會把它解成 `' 1'`，
 *    經 `trim()` 之後是合法的 `'1'`。真正的正號必須寫成 `%2B1`（解出 `'+1'`）才會被擋。
 *    `tests/js/shopIdsParam.test.ts` 有一條測試把這個行為釘死——看到它不要以為是 bug。
 */
const STRICT_DIGITS = /^\d+$/

/**
 * 解析 query string 中的 `shop_ids`
 *
 * 解析規則（每一條都是刻意的硬約束）：
 * 1. 以 `,` 切分，逐一 `trim()`（容忍連結被通訊軟體插入的空白 / `%20`）
 * 2. 必須完全符合 `/^\d+$/`，再 `Number.parseInt(token, 10)`
 *    —— 這道 regex 真正的價值是擋掉 `parseInt` 會「部分解析成功」的輸入：
 *    `1932abc` → 1932、`19px` → 19、`1e3` → 1、`1.9` → 1。少了它，
 *    這些 token 會變成**看起來合理的正整數**，夥伴被篩到不相干的賣場，
 *    畫面正常、數字錯誤、零錯誤訊息。
 *    （`abc` / `0x1F` 這類其實是被下一步的 `> 0` 擋下的，不是 regex。）
 * 3. 必須 `> 0` 且是 safe integer（超出 2^53 的數字不可能是真實 post ID，
 *    放行只會在後續比對時產生精度誤差）
 * 4. **去重**後才套 `SHOP_IDS_MAX` 上限（避免重複值白白吃掉額度）
 *
 * `droppedCount` 的語意是「連結裡有幾個 token 沒能變成有效的賣場編號」，
 * 包含三種來源：格式不合、空 token、超過上限被截掉的部分。
 * **重複值不計入**——重複不是「無法辨識」，報出來只會誤導。
 *
 * ⚠️ 已知的輕微噪音：`?shop_ids=1932,`（尾端逗號）會產生一個空 token，
 *    因此回報 `droppedCount = 1`。這是刻意選擇 fail-loud——反過來把空 token
 *    一律忽略，會讓「連結被截斷成 `?shop_ids=`」這種**真正該讓人知道**的情況
 *    完全無聲無息（夥伴會以為自己在看單一賣場，其實看到的是全部賣場合計）。
 *
 * @param  search location.search 字串（可帶或不帶開頭的 `?`）
 * @return {TParsedShopIds} 解析結果
 */
export const parseShopIdsParam = (search: string): TParsedShopIds => {
	const raw = new URLSearchParams(search).get(SHOP_IDS_PARAM)

	// 參數不存在 = 沒有深連結需求，不是錯誤，不該產生任何提示
	if (raw === null) {
		return { ids: [], droppedCount: 0 }
	}

	const seen = new Set<number>()
	let droppedCount = 0

	raw.split(',').forEach((token) => {
		const trimmed = token.trim()

		if (!STRICT_DIGITS.test(trimmed)) {
			droppedCount += 1
			return
		}

		const parsed = Number.parseInt(trimmed, 10)
		if (!Number.isSafeInteger(parsed) || parsed <= 0) {
			droppedCount += 1
			return
		}

		// 重複值直接吸收，不計入 droppedCount
		seen.add(parsed)
	})

	const all = Array.from(seen)
	if (all.length > SHOP_IDS_MAX) {
		droppedCount += all.length - SHOP_IDS_MAX
	}

	return { ids: all.slice(0, SHOP_IDS_MAX), droppedCount }
}

/**
 * 從目前網址讀取 `?shop_ids=`
 *
 * 見本檔頭：這裡讀的是 **hash 之前**的 query string，
 * 與 react-router 的 `useLocation().search` 不是同一個東西。
 *
 * @return {TParsedShopIds} 解析結果
 */
export const readShopIdsFromLocation = (): TParsedShopIds =>
	parseShopIdsParam(window.location.search)

/**
 * 把 `shop_ids` 從目前網址移除（不留下瀏覽歷史）
 *
 * 何時呼叫：夥伴**第一次自己動到賣場篩選器**的當下。深連結的作用是「給一個
 * 好的初始值」，一旦使用者表達了自己的意圖，控制權就交給他——否則會出現
 * 「清空篩選 → 點『修改密碼』→ 返回 → 篩選又自己跑回來」這種
 * 「這個篩選器清不掉」的體感（Dashboard 掛在 `<Route path="/">` 且頁內有
 * `<Link to="/change-password">`，返回時會重新 mount、重新讀 URL）。
 *
 * 實作注意：
 * - 只刪 `shop_ids` 一個參數，其餘 query 原樣保留
 * - **不動 hash**（改到 hash 會觸發 HashRouter 的路由跳轉）
 * - 用 `replaceState` 而非 `pushState`：這不是使用者的導覽動作，
 *   不該在上一頁按鈕留下一格
 * - 整段包 try/catch：這是純粹的網址美化，任何環境限制導致的失敗
 *   都不該連帶讓「切換篩選」這個真正的操作失敗
 */
export const clearShopIdsFromLocation = (): void => {
	try {
		const url = new URL(window.location.href)
		if (!url.searchParams.has(SHOP_IDS_PARAM)) return

		url.searchParams.delete(SHOP_IDS_PARAM)
		window.history.replaceState(
			window.history.state,
			'',
			`${url.pathname}${url.search}${url.hash}`
		)
	} catch {
		// 靜默略過：網址沒清掉不影響篩選本身的正確性
	}
}
