/**
 * Partner 可篩選賣場清單 Hook
 *
 * 封裝 /partner-reports/shops 的 useQuery：
 * - retry: 0（與 main.tsx defaultOptions 一致）
 * - staleTime 5 分鐘：清單變動極慢（要新增賣場、還要出單），沒必要頻繁重打
 * - 401 由 axios interceptor 統一處理
 *
 * ⚠️ 這個 query **不吃任何篩選條件**（日期 / 狀態都不吃），因此 queryKey 是常數。
 *    選單內容若隨日期區間變動，使用者切一下期間、目前選中的賣場就可能從選單裡消失，
 *    畫面上還顯示著它、篩選卻已失效。完整理由見後端
 *    `SettlementSummaryProviderInterface::shops_for_partner()` 的 docblock。
 */

import { useQuery } from '@tanstack/react-query'
import type { AxiosError } from 'axios'

import { fetchPartnerShops, type TPartnerShop } from '../api/reports'

/**
 * 取得可篩選賣場清單
 *
 * 防禦性 normalize：後端回 `{shops: [...]}`，任何 shape 漂移（null / 缺鍵 /
 * 非陣列）一律 fallback 成 `[]`，讓元件端可以無條件 `.map()`。
 * 這與 `useTrend` 的 BUG-2 修補是同一條紀律——元件不該為了後端漂移而崩。
 *
 * @return {Object} TanStack Query v4 result，data 成功時永遠是 `TPartnerShop[]`
 */
export const usePartnerShops = () =>
	useQuery<TPartnerShop[], AxiosError>({
		queryKey: ['partner-shops'],
		queryFn: async () => {
			const res = await fetchPartnerShops()
			const payload: unknown = res.data

			if (
				payload !== null &&
				typeof payload === 'object' &&
				'shops' in payload &&
				Array.isArray((payload as { shops: unknown }).shops)
			) {
				return (payload as { shops: TPartnerShop[] }).shops
			}
			return []
		},
		retry: 0,
		staleTime: 5 * 60 * 1000,
		refetchOnWindowFocus: false,
	})
