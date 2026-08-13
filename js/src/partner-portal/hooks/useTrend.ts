/**
 * Partner Trend 趨勢資料 Hook
 *
 * 封裝 /partner-reports/trend 的 useQuery：
 * - retry: 0（與 main.tsx defaultOptions 一致；明示讓行為清晰）
 * - staleTime 5 分鐘：partner 操作頻率不高，避免切回視窗就重打
 * - 401 由 axios interceptor 統一處理（清 session + redirect /login）
 *
 * date / interval 變動時 queryKey 會變，自動觸發新查詢。
 *
 * ═══ 為何需要 keepPreviousData ═══
 *
 * 少了它，切換 interval（日 / 週 / 月）時 queryKey 換成一個沒有 cache 的鍵，
 * TanStack Query v4 會把 `data` 打回 `undefined`、`isLoading` 轉 true
 * ⇒ 圖表整塊被抽掉，只剩一片空白等著伺服器回應。
 *
 * 開啟後切換期間 `data` 維持**上一個粒度的資料**（`isPreviousData` 為 true），
 * 圖表留在原地不跳動，由呼叫端疊一層 `<Spin>` 表達「正在更新」——
 * 這比「整塊消失再長回來」更能讓人知道系統在動而不是壞了。
 *
 * ⚠️ 代價：切換後到新資料回來之前，畫面上是**舊粒度**的曲線。
 *    因此呼叫端的 Spin 遮罩不可省略，否則使用者會誤讀成新粒度的資料。
 */

import { useQuery } from '@tanstack/react-query'
import type { AxiosError } from 'axios'

import {
	fetchTrend,
	toShopIdsKey,
	type TTrendInterval,
	type TTrendPoint,
} from '../api/reports'

/** useTrend 參數 */
type TUseTrendArgs = {
	date_start: number
	date_end: number
	interval: TTrendInterval

	/** 賣場篩選；省略或空陣列 = 全部賣場 */
	shop_ids?: number[]
}

/**
 * 取得 partner 趨勢資料的 hook
 *
 * BUG-2 修補：normalize 後端 `{series: [...]}` 為裸 `TTrendPoint[]`，
 * 並對任何非陣列 shape（null / undefined / object 漂移）fallback 為 `[]`，
 * 確保 component 端可安全呼叫 `.every()` / `.map()` / `.length`，
 * 不再因 shape 不一致觸發 `TypeError: a.every is not a function`。
 *
 * 防禦縱深：
 *   1. 後端正常 → 解開 `series` 屬性
 *   2. 後端漂移（回 array / null / object missing series）→ fallback `[]`
 *   3. component 收到永遠是 array，不需自己再判斷
 *
 * @param  args 期間範圍與粒度
 * @return {Object} TanStack Query v4 result，data 永遠是 `TTrendPoint[]`（成功時）
 */
export const useTrend = (args: TUseTrendArgs) => {
	const { date_start, date_end, interval, shop_ids } = args

	return useQuery<TTrendPoint[], AxiosError>({
		// shop_ids 排序後序列化，避免選取順序不同造成同一組條件重打（見 toShopIdsKey）
		queryKey: [
			'partner-trend',
			date_start,
			date_end,
			interval,
			toShopIdsKey(shop_ids),
		],
		queryFn: async () => {
			const res = await fetchTrend({ date_start, date_end, interval, shop_ids })
			const payload: unknown = res.data

			// 防禦性 normalize：對齊 spec/api.yml `TrendOutput = { series: TrendPoint[] }`
			// 任何 shape 漂移皆 fallback 為空陣列，避免 component 端 `.every()` / `.map()` 炸錯
			if (
				payload !== null &&
				typeof payload === 'object' &&
				'series' in payload &&
				Array.isArray((payload as { series: unknown }).series)
			) {
				return (payload as { series: TTrendPoint[] }).series
			}
			return []
		},
		retry: 0,
		staleTime: 5 * 60 * 1000,
		refetchOnWindowFocus: false,

		// 切換 interval 時保留上一份資料，避免圖表整塊消失（見檔頭說明）
		keepPreviousData: true,
	})
}
