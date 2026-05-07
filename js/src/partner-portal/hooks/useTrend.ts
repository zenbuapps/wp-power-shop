/**
 * Partner Trend 趨勢資料 Hook
 *
 * 封裝 /partner-reports/trend 的 useQuery：
 * - retry: 0（與 main.tsx defaultOptions 一致；明示讓行為清晰）
 * - staleTime 5 分鐘：partner 操作頻率不高，避免切回視窗就重打
 * - 401 由 axios interceptor 統一處理（清 session + redirect /login）
 *
 * date / interval 變動時 queryKey 會變，自動觸發新查詢。
 */

import { useQuery } from '@tanstack/react-query'
import type { AxiosError } from 'axios'

import {
	fetchTrend,
	type TTrendInterval,
	type TTrendOutput,
} from '../api/reports'

/** useTrend 參數 */
type TUseTrendArgs = {
	date_start: number
	date_end: number
	interval: TTrendInterval
}

/**
 * 取得 partner 趨勢資料的 hook
 *
 * @param  args 期間範圍與粒度
 * @return {Object} TanStack Query v4 result，含 data / isLoading / isError / refetch 等
 */
export const useTrend = (args: TUseTrendArgs) => {
	const { date_start, date_end, interval } = args

	return useQuery<TTrendOutput, AxiosError>({
		queryKey: ['partner-trend', date_start, date_end, interval],
		queryFn: async () => {
			const res = await fetchTrend({ date_start, date_end, interval })
			return res.data
		},
		retry: 0,
		staleTime: 5 * 60 * 1000,
		refetchOnWindowFocus: false,
	})
}
