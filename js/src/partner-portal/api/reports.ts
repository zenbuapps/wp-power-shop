/**
 * Partner Reports REST API wrappers
 *
 * 對應後端 V2Api 的 partner-reports endpoint（Phase 3-C）：
 * - GET /partner-reports/kpi          （4-B2 實作）
 * - GET /partner-reports/trend        （4-B3 補）
 * - GET /partner-reports/settlements  （4-B3 補）
 *
 * 重要安全提醒：
 * - partner_term_id 由後端從 token 取得，**前端永遠不傳**
 *   （傳了也會被忽略；偽造其他 partner_term_id 不會生效）
 *
 * 4-B3 重構（reviewer m-2）：apiClient 已內建 baseURL，URL 只需寫 /partner-reports/...
 *
 * 參考 spec/api.yml KpiOutput / TrendOutput / SettlementListOutput schema
 * （金額一律用字串保存以保留精度）。
 */

import { apiClient } from './client'

// =====================================================
// 共用：賣場篩選
// =====================================================

/**
 * 把 `shop_ids: number[]` 正規化成後端要的 CSV
 *
 * 🔴 **空陣列一律不送這個參數**，而不是送空字串——送 `''` 會讓三塊報表全部回 0。
 *
 * 後端 `build_filter_criteria_from_request()` 只要 `isset($params['shop_ids'])`
 * 就會進入解析分支，而它**已經是 fail-closed**：清洗後一個合法 id 都不剩時，
 * 會塞入 `V2Api::SHOP_IDS_FAIL_CLOSED_SENTINEL`（一個保證匹配不到的正整數），
 * 讓 SQL 的 EXISTS 恆假 ⇒ KPI／趨勢／結算三塊**一律回 0**。
 *
 * 這道 fail-closed 是刻意的：`?shop_ids=` 這種被通訊軟體截斷的連結，
 * 語意上仍然是「使用者指定了賣場」，退化成「不過濾」會給出一個**比正解大**的數字
 * 且畫面上毫無線索。但也因此，前端**必須**用下面那道守門確保空陣列整個不送出，
 * 否則「全部賣場」會變成「全部歸零」。
 *
 * ⚠️ 這段的行為在 2026-08 反轉過一次（舊版後端對空輸入是靜默退化成不過濾，
 *    當時本註解寫的是「送空字串不會出錯」）。守門那一行的結論沒變，但理由已完全不同——
 *    別把它當成多餘的防禦拿掉（`[].join(',') === ''`）。
 *
 * @param params   既有查詢參數
 * @param shop_ids 選中的賣場 ID（undefined / 空陣列 = 全部）
 */
const withShopIds = (
	params: Record<string, unknown>,
	shop_ids?: number[]
): Record<string, unknown> => {
	if (!shop_ids || shop_ids.length === 0) return params
	return { ...params, shop_ids: shop_ids.join(',') }
}

/**
 * 把 `shop_ids` 正規化成穩定的 queryKey 片段（**同時去重、排序**）
 *
 * ⚠️ **一定要排序**：多選 Select 的 value 順序是使用者的點選順序，
 * 選 A 再選 B 得到 `[A,B]`、反過來得到 `[B,A]`——那是**同一組篩選條件**，
 * 但直接放進 queryKey 會被 react-query 當成兩個不同的 query，
 * 造成同樣的資料重打一次、cache 也多存一份。
 *
 * ⚠️ **一定要去重**，理由與排序完全相同、只是來源不同：賣場篩選現在多了一條
 * 「從 `?shop_ids=` 深連結帶入」的入口（見 `utils/shopIdsParam.ts`），
 * `?shop_ids=1932,1932` 會產生 `'1932,1932'`，與夥伴在 Select 裡點同一個賣場
 * 得到的 `'1932'` 是**同一組條件卻是兩個 query**——正是本函式 docblock 宣稱
 * 要消滅的問題。解析端雖然也會去重，但 key 這一層必須自己冪等：
 * 只在單一端點防守，下一個新增的入口就會再把這個洞打開一次。
 *
 * 回傳空字串代表「全部賣場」，與 `withShopIds` 不送參數的語意一致。
 *
 * @param shop_ids 選中的賣場 ID
 */
export const toShopIdsKey = (shop_ids?: number[]): string =>
	!shop_ids || shop_ids.length === 0
		? ''
		: Array.from(new Set(shop_ids))
				.sort((a, b) => a - b)
				.join(',')

// =====================================================
// KPI
// =====================================================

/**
 * /partner-reports/kpi 查詢參數
 *
 * - date_start / date_end: unix timestamp（秒）
 * - shop_ids:  賣場 ID 陣列（可選；省略或空陣列 = 全部賣場）
 * - statuses:  逗號分隔狀態 pending / paid / refunded（可選）
 */
export type TKpiQuery = {
	date_start: number
	date_end: number
	shop_ids?: number[]
	statuses?: string
}

/**
 * /partner-reports/kpi 後端回傳 payload
 *
 * 注意：金額欄位採字串保存（spec §6 / §7），不直接轉 number 以免精度遺失；
 * 顯示前再以 parseFloat / toLocaleString 轉換。
 */
export type TKpiOutput = {
	total_sales: string
	profit_pending: string
	profit_paid: string
	profit_refunded: string
	period_start: string
	period_end: string
	order_count?: number
}

/**
 * 取得 partner KPI 報表（GET /partner-reports/kpi）
 *
 * @param query 查詢參數
 */
export const fetchKpi = (query: TKpiQuery) => {
	const { shop_ids, ...rest } = query
	return apiClient.get<TKpiOutput>('/partner-reports/kpi', {
		params: withShopIds({ ...rest }, shop_ids),
	})
}

// =====================================================
// Trend
// =====================================================

/**
 * /partner-reports/trend 查詢參數
 *
 * - date_start / date_end: unix timestamp（秒）
 * - interval: 粒度（day / week / month）
 */
export type TTrendInterval = 'day' | 'week' | 'month'

export type TTrendQuery = {
	date_start: number
	date_end: number
	interval: TTrendInterval

	/** 賣場 ID 陣列（可選；省略或空陣列 = 全部賣場） */
	shop_ids?: number[]
}

/**
 * 單一趨勢點
 *
 * - date 格式依 interval：day → 'YYYY-MM-DD'，week → 'YYYY-Www'，month → 'YYYY-MM'
 * - count: 訂單數
 * - profit / total: 字串保存以保留精度
 */
export type TTrendPoint = {
	date: string
	count: number
	profit: string
	total: string
}

/**
 * /partner-reports/trend 後端回傳 payload
 *
 * BUG-2 修補：對齊 OpenAPI spec `TrendOutput`（specs/api/api.yml L1200-1209）
 * 與後端 `TrendReport::to_array()`（DTO 序列化為 `{series: [...]}`）。
 * 之前誤將前端 type 寫成裸陣列，導致 TrendChart 拿到 `{series: [...]}` 物件後
 * 呼叫 `data.every(...)` 拋 `TypeError: a.every is not a function`。
 */
export type TTrendOutput = {
	series: TTrendPoint[]
}

/**
 * 取得 partner 趨勢報表（GET /partner-reports/trend）
 *
 * @param query 查詢參數
 */
export const fetchTrend = (query: TTrendQuery) => {
	const { shop_ids, ...rest } = query
	return apiClient.get<TTrendOutput>('/partner-reports/trend', {
		params: withShopIds({ ...rest }, shop_ids),
	})
}

// =====================================================
// Settlements
// =====================================================

/** Settlement 項目狀態 */
export type TSettlementStatus = 'pending' | 'paid' | 'refunded' | 'cancelled'

/**
 * /partner-reports/settlements 查詢參數
 *
 * - page / per_page: 分頁（後端預設 page=1, per_page=20）
 * - statuses: 狀態陣列（可選；fetcher 內 join 為 CSV 後送出，5-C.5 型別收斂）
 * - date_start / date_end: unix timestamp（秒，可選）
 */
export type TSettlementsQuery = {
	page: number
	per_page: number
	statuses?: TSettlementStatus[]
	date_start?: number
	date_end?: number

	/** 賣場 ID 陣列（可選；省略或空陣列 = 全部賣場） */
	shop_ids?: number[]
}

/**
 * 單筆結算紀錄
 *
 * 欄位對齊 spec api.yml SettlementItem schema。
 */
export type TSettlementItem = {
	order_id: number
	partner_term_id: number
	profit_amount: string
	status: TSettlementStatus
	order_item_id: number
	shop_id?: number
	created_at: string
}

/** /partner-reports/settlements 後端回傳 payload */
export type TSettlementListOutput = {
	items: TSettlementItem[]
	total: number
	page: number
	per_page: number
}

/**
 * 取得 partner 結算列表（GET /partner-reports/settlements）
 *
 * 5-C.5：statuses 由 string[] 在此 join 為 CSV，
 * 呼叫端不再需要自己 .join(',')，型別更嚴格（防止傳錯字）。
 *
 * @param query 查詢參數
 */
export const fetchSettlements = (query: TSettlementsQuery) => {
	const { statuses, shop_ids, ...rest } = query
	const params: Record<string, unknown> = { ...rest }
	if (statuses && statuses.length > 0) {
		params.statuses = statuses.join(',')
	}
	return apiClient.get<TSettlementListOutput>('/partner-reports/settlements', {
		params: withShopIds(params, shop_ids),
	})
}

// =====================================================
// 賣場清單（報表篩選器的選項來源）
// =====================================================

/** 單一可篩選的賣場 */
export type TPartnerShop = {
	shop_id: number
	shop_name: string
}

/** /partner-reports/shops 後端回傳 payload */
export type TPartnerShopsOutput = {
	shops: TPartnerShop[]
}

/**
 * 取得可篩選的賣場清單（GET /partner-reports/shops）
 *
 * 清單只含**該夥伴實際產生過業績**的賣場（含已被站長刪除的，名稱由後端解析成
 * `[已刪除賣場]`），因此不需要、也不應該從別處再查一次賣場名稱。
 *
 * 與其他 partner endpoint 相同：partner_term_id 由後端從 token 取，前端永遠不傳。
 */
export const fetchPartnerShops = () =>
	apiClient.get<TPartnerShopsOutput>('/partner-reports/shops')
