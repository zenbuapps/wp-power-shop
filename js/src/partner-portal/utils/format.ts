/**
 * Partner Portal 格式化工具（5-C.4 DRY）
 *
 * 將 KpiSummary / TrendChart / SettlementsTable 三處重複的 formatAmount 抽出，
 * 統一金額顯示規則：千分位 + 0~2 位小數，無有效數字時 fallback 為 '0'。
 *
 * 注意：不含 'NT$' prefix——prefix 由呼叫端（antd Statistic 或 inline render）處理。
 */

/**
 * 金額格式化（千分位 + 0~2 小數位）
 *
 * 用於 KPI / Trend / Settlements 卡片與表格。
 *
 * @param  value 後端字串（保留精度）或已轉好的 number
 * @return {string} 千分位格式化字串（無 prefix）
 */
export const formatAmount = (value: string | number | undefined): string => {
	const num = typeof value === 'number' ? value : parseFloat(value ?? '0')
	const safe = Number.isNaN(num) ? 0 : num
	return safe.toLocaleString('zh-TW', {
		minimumFractionDigits: 0,
		maximumFractionDigits: 2,
	})
}
