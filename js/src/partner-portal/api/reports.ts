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
 * 參考 spec/api.yml KpiOutput schema（金額用字串保存以保留精度）。
 */

import { apiClient } from './client'

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
 * @param apiUrl 解密後的 API_URL（含 /wp-json/power-shop）
 * @param query  查詢參數
 */
export const fetchKpi = (apiUrl: string, query: TKpiQuery) =>
	apiClient.get<TKpiOutput>(`${apiUrl}/partner-reports/kpi`, {
		params: query,
	})
