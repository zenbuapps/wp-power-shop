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
// KPI
// =====================================================

/**
 * /partner-reports/kpi 查詢參數
 *
 * - date_start / date_end: unix timestamp（秒）
 * - shop_ids:  逗號分隔賣場 ID（可選）
 * - statuses:  逗號分隔狀態 pending / paid / refunded（可選）
 */
export type TKpiQuery = {
	date_start: number
	date_end: number
	shop_ids?: string
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
export const fetchKpi = (query: TKpiQuery) =>
	apiClient.get<TKpiOutput>('/partner-reports/kpi', {
		params: query,
	})

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

/** /partner-reports/trend 後端回傳 payload（依 spec api.yml TrendOutput 為陣列） */
export type TTrendOutput = TTrendPoint[]

/**
 * 取得 partner 趨勢報表（GET /partner-reports/trend）
 *
 * @param query 查詢參數
 */
export const fetchTrend = (query: TTrendQuery) =>
	apiClient.get<TTrendOutput>('/partner-reports/trend', {
		params: query,
	})

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
	const { statuses, ...rest } = query
	const params: Record<string, unknown> = { ...rest }
	if (statuses && statuses.length > 0) {
		params.statuses = statuses.join(',')
	}
	return apiClient.get<TSettlementListOutput>('/partner-reports/settlements', {
		params,
	})
}
