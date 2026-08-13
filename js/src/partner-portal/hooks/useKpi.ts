/**
 * Partner KPI 資料 Hook
 *
 * 封裝 /partner-reports/kpi 的 useQuery：
 * - retry: 0（與 main.tsx defaultOptions 一致；明示讓行為清晰）
 * - staleTime 5 分鐘：partner 操作頻率不高，避免切回視窗就重打
 * - 401 由 axios interceptor 統一處理（清 session + redirect /login）
 *
 * date 變動時 queryKey 會變，自動觸發新查詢。
 *
 * 4-B3 重構：apiClient 已內建 baseURL，不再從 usePartnerEnv 拿 API_URL。
 */

import { useQuery } from '@tanstack/react-query'
import type { AxiosError } from 'axios'

import { fetchKpi, toShopIdsKey, type TKpiOutput } from '../api/reports'

/** useKpi 參數：unix timestamp（秒）起訖點 */
type TUseKpiArgs = {
	date_start: number
	date_end: number

	/** 賣場篩選；省略或空陣列 = 全部賣場 */
	shop_ids?: number[]
}

/**
 * 取得 partner KPI 資料的 hook
 *
 * @param  args 期間範圍與賣場篩選
 * @return {Object} TanStack Query v4 result，含 data / isLoading / isError / refetch 等
 */
export const useKpi = (args: TUseKpiArgs) => {
	const { date_start, date_end, shop_ids } = args

	return useQuery<TKpiOutput, AxiosError>({
		// shop_ids 排序後序列化，避免選取順序不同造成同一組條件重打（見 toShopIdsKey）
		queryKey: ['partner-kpi', date_start, date_end, toShopIdsKey(shop_ids)],
		queryFn: async () => {
			const res = await fetchKpi({ date_start, date_end, shop_ids })
			return res.data
		},
		retry: 0,
		staleTime: 5 * 60 * 1000,
		refetchOnWindowFocus: false,
	})
}
