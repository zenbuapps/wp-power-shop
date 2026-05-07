/**
 * Partner Settlements 結算列表 Hook
 *
 * 封裝 /partner-reports/settlements 的 useQuery：
 * - retry: 0
 * - staleTime 5 分鐘
 * - 401 由 axios interceptor 統一處理
 *
 * 任一查詢欄位變動時 queryKey 會變，自動觸發新查詢。
 */

import { useQuery } from '@tanstack/react-query'
import type { AxiosError } from 'axios'

import { fetchSettlements, type TSettlementListOutput } from '../api/reports'

/** useSettlements 參數 */
type TUseSettlementsArgs = {
	page: number
	per_page: number
	statuses?: string
	date_start?: number
	date_end?: number
}

/**
 * 取得 partner 結算列表的 hook
 *
 * @param  args 分頁與篩選條件
 * @return {Object} TanStack Query v4 result
 */
export const useSettlements = (args: TUseSettlementsArgs) => {
	const { page, per_page, statuses, date_start, date_end } = args

	return useQuery<TSettlementListOutput, AxiosError>({
		queryKey: [
			'partner-settlements',
			page,
			per_page,
			statuses ?? '',
			date_start ?? 0,
			date_end ?? 0,
		],
		queryFn: async () => {
			const res = await fetchSettlements({
				page,
				per_page,
				statuses,
				date_start,
				date_end,
			})
			return res.data
		},
		retry: 0,
		staleTime: 5 * 60 * 1000,
		refetchOnWindowFocus: false,
	})
}
