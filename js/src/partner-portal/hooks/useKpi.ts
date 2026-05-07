/**
 * Partner KPI 資料 Hook
 *
 * 封裝 /partner-reports/kpi 的 useQuery：
 * - retry: 0（與 main.tsx defaultOptions 一致；明示讓行為清晰）
 * - staleTime 5 分鐘：partner 操作頻率不高，避免切回視窗就重打
 * - 401 由 axios interceptor 統一處理（清 session + redirect /login）
 *
 * date 變動時 queryKey 會變，自動觸發新查詢。
 */

import { useQuery } from '@tanstack/react-query'
import type { AxiosError } from 'axios'

import { fetchKpi, type TKpiOutput } from '../api/reports'

import { usePartnerEnv } from './usePartnerEnv'

/** useKpi 參數：unix timestamp（秒）起訖點 */
type TUseKpiArgs = {
	date_start: number
	date_end: number
}

/**
 * 取得 partner KPI 資料的 hook
 *
 * @param  args 期間範圍
 * @return {Object} TanStack Query v4 result，含 data / isLoading / isError / refetch 等
 */
export const useKpi = (args: TUseKpiArgs) => {
	const { API_URL } = usePartnerEnv()
	const { date_start, date_end } = args

	return useQuery<TKpiOutput, AxiosError>({
		queryKey: ['partner-kpi', date_start, date_end],
		queryFn: async () => {
			const res = await fetchKpi(API_URL, { date_start, date_end })
			return res.data
		},
		retry: 0,
		staleTime: 5 * 60 * 1000,
		refetchOnWindowFocus: false,
	})
}
