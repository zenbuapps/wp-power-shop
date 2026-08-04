/**
 * Profit Partner CRUD hooks
 *
 * 採與 ProfitShop 相同 pattern：
 *   - useCustom / useCustomMutation 直打點，dataProviderName: 'power-shop'
 *   - 不走 useTable / useForm（避免 dataProvider 反序列化 {code, data} 形狀問題）
 *
 * 密碼是一般欄位，隨 create / update 一起送，沒有獨立的密碼 hook。
 * 後端仍保留 `set-password` / `regenerate-password` 兩個端點（含強度驗證與一次性明文顯示），
 * 只是前端目前不使用——它們是給日後「站長不該看到密碼」的部署情境留的退路，不是死碼。
 *
 * ⚠️ 快取失效**必須**用 `useInvalidateProfitQueries`，**不可**用 Refine 的 `useInvalidate`：
 * 後者產生的 queryKey 在 resource 命名空間，而本檔的查詢全走 `useCustom`（custom 命名空間），
 * 兩者前綴對不上、invalidate 打不中。配上 `main.tsx` 的全域 `staleTime: 10 分鐘`，
 * 症狀是「改完資料回列表頁，看到的還是改之前的內容」且無任何錯誤訊息。
 * 完整成因見該 hook 的 docblock。
 */

import { useApiUrl, useCustom, useCustomMutation } from '@refinedev/core'

import { useInvalidateProfitQueries } from '@/hooks'
import type {
	TProfitPartner,
	TProfitPartnerInput,
} from '@/pages/admin/ProfitPartner/types'
import type { TWrappedResponse } from '@/pages/admin/ProfitShop/hooks'

const PROFIT_PARTNER_RESOURCE = 'profit-partners'

/** 取得分潤夥伴列表 */
export const useProfitPartnerList = () => {
	const apiUrl = useApiUrl('power-shop')
	const url = `${apiUrl}/${PROFIT_PARTNER_RESOURCE}`

	return useCustom<TWrappedResponse<TProfitPartner[]>>({
		url,
		method: 'get',
		dataProviderName: 'power-shop',
	})
}

/** 取得單一分潤夥伴 */
export const useProfitPartnerOne = (id: number | string | undefined) => {
	const apiUrl = useApiUrl('power-shop')

	// N-1: id 不存在時直接給空 url，避免出現 `${apiUrl}/profit-partners/`
	// （末尾斜線會觸發 WP REST 邊界 routing 異常 / 404）；queryOptions.enabled 控制不發 request。
	const url = id ? `${apiUrl}/${PROFIT_PARTNER_RESOURCE}/${id}` : ''

	return useCustom<TWrappedResponse<TProfitPartner>>({
		url,
		method: 'get',
		queryOptions: {
			enabled: id !== undefined && id !== null && id !== '',

			// 雙保險：避免 window focus 觸發 refetch 蓋掉編輯中內容
			refetchOnWindowFocus: false,
		},
		dataProviderName: 'power-shop',
	})
}

/** 建立分潤夥伴（admin_only） */
export const useProfitPartnerCreate = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidateProfitQueries = useInvalidateProfitQueries()
	const mutation = useCustomMutation<TWrappedResponse<TProfitPartner>>()

	const mutateAsync = (values: TProfitPartnerInput) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_PARTNER_RESOURCE}`,
				method: 'post',
				values,
				dataProviderName: 'power-shop',
			},
			{
				onSuccess: () => {
					void invalidateProfitQueries(PROFIT_PARTNER_RESOURCE)
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 更新分潤夥伴（admin_only） */
export const useProfitPartnerUpdate = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidateProfitQueries = useInvalidateProfitQueries()
	const mutation = useCustomMutation<TWrappedResponse<TProfitPartner>>()

	const mutateAsync = (id: number, values: TProfitPartnerInput) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_PARTNER_RESOURCE}/${id}`,
				method: 'put',
				values,
				dataProviderName: 'power-shop',
			},
			{
				onSuccess: () => {
					// 一次涵蓋 list 與 detail：predicate 比對的是 endpoint 路徑，
					// `/profit-partners` 與 `/profit-partners/{id}` 都會命中。
					void invalidateProfitQueries(PROFIT_PARTNER_RESOURCE)
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 刪除分潤夥伴（admin_only；is_in_use 預檢由後端執行，409 partner_in_use 攔下） */
export const useProfitPartnerDelete = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidateProfitQueries = useInvalidateProfitQueries()
	const mutation =
		useCustomMutation<TWrappedResponse<{ id: number; deleted: boolean }>>()

	const mutateAsync = (id: number) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_PARTNER_RESOURCE}/${id}`,
				method: 'delete',
				values: {},
				dataProviderName: 'power-shop',
			},
			{
				onSuccess: () => {
					void invalidateProfitQueries(PROFIT_PARTNER_RESOURCE)
				},
			}
		)

	return { ...mutation, mutateAsync }
}
