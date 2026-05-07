/**
 * Profit Partner CRUD + 特殊操作（regenerate-password）共用 hooks
 *
 * 採與 ProfitShop 相同 pattern：
 *   - useCustom / useCustomMutation 直打點，dataProviderName: 'power-shop'
 *   - 不走 useTable / useForm（避免 dataProvider 反序列化 {code, data} 形狀問題）
 *
 * 安全紀律：
 *   - useRegeneratePartnerPassword **必須** 用 useCustomMutation（不可用 useCustom），
 *     避免明文密碼進入 React Query cache。
 *   - 後端 regenerate-password 已強制 Cache-Control: no-store，前端不需重複設定。
 */

import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	useInvalidate,
} from '@refinedev/core'

import type {
	TProfitPartner,
	TProfitPartnerInput,
	TRegeneratePasswordOutput,
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
	})
}

/** 取得單一分潤夥伴 */
export const useProfitPartnerOne = (id: number | string | undefined) => {
	const apiUrl = useApiUrl('power-shop')
	const url = `${apiUrl}/${PROFIT_PARTNER_RESOURCE}/${id ?? ''}`

	return useCustom<TWrappedResponse<TProfitPartner>>({
		url,
		method: 'get',
		queryOptions: {
			enabled: id !== undefined && id !== null && id !== '',

			// 雙保險：避免 window focus 觸發 refetch 蓋掉編輯中內容
			refetchOnWindowFocus: false,
		},
	})
}

/** 建立分潤夥伴（admin_only） */
export const useProfitPartnerCreate = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const mutation = useCustomMutation<TWrappedResponse<TProfitPartner>>()

	const mutateAsync = (values: TProfitPartnerInput) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_PARTNER_RESOURCE}`,
				method: 'post',
				values,
			},
			{
				onSuccess: () => {
					invalidate({
						resource: PROFIT_PARTNER_RESOURCE,
						invalidates: ['list'],
						dataProviderName: 'power-shop',
					})
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 更新分潤夥伴（admin_only） */
export const useProfitPartnerUpdate = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const mutation = useCustomMutation<TWrappedResponse<TProfitPartner>>()

	const mutateAsync = (id: number, values: TProfitPartnerInput) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_PARTNER_RESOURCE}/${id}`,
				method: 'put',
				values,
			},
			{
				onSuccess: () => {
					invalidate({
						resource: PROFIT_PARTNER_RESOURCE,
						invalidates: ['list', 'detail'],
						id,
						dataProviderName: 'power-shop',
					})
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 刪除分潤夥伴（admin_only；is_in_use 預檢由後端執行，409 partner_in_use 攔下） */
export const useProfitPartnerDelete = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const mutation =
		useCustomMutation<TWrappedResponse<{ id: number; deleted: boolean }>>()

	const mutateAsync = (id: number) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_PARTNER_RESOURCE}/${id}`,
				method: 'delete',
				values: {},
			},
			{
				onSuccess: () => {
					invalidate({
						resource: PROFIT_PARTNER_RESOURCE,
						invalidates: ['list'],
						dataProviderName: 'power-shop',
					})
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/**
 * 重新產生 Partner 密碼（admin_only + nonce 驗證）
 *
 * 安全紀律：
 *   - 用 useCustomMutation（非 useCustom）→ 結果不進 React Query cache
 *   - response 是「裸 payload」TRegeneratePasswordOutput，**不**裹 {code, data}
 *   - 呼叫者（RegeneratePasswordButton）負責立即顯示 + 5 分鐘 auto-clear + 不持久化
 *   - 後端會 bump partner 的 password_changed_at，舊 token 全失效（Phase 3-D）
 */
export const useRegeneratePartnerPassword = () => {
	const apiUrl = useApiUrl('power-shop')
	const mutation = useCustomMutation<TRegeneratePasswordOutput>()

	const mutateAsync = (id: number) =>
		mutation.mutateAsync({
			url: `${apiUrl}/${PROFIT_PARTNER_RESOURCE}/${id}/regenerate-password`,
			method: 'post',
			values: {},
		})

	return { ...mutation, mutateAsync }
}
