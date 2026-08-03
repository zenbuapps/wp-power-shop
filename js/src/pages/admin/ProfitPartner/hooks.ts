/**
 * Profit Partner CRUD + 密碼操作（regenerate-password / set-password）共用 hooks
 *
 * 採與 ProfitShop 相同 pattern：
 *   - useCustom / useCustomMutation 直打點，dataProviderName: 'power-shop'
 *   - 不走 useTable / useForm（避免 dataProvider 反序列化 {code, data} 形狀問題）
 *
 * 安全紀律：
 *   - 兩個密碼 hook **必須** 用 useCustomMutation（不可用 useCustom），
 *     避免明文密碼進入 React Query cache。
 *   - 後端兩個端點都已強制 Cache-Control: no-store，前端不需重複設定。
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
	TSetPasswordOutput,
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
	const invalidate = useInvalidate()
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
				dataProviderName: 'power-shop',
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
				dataProviderName: 'power-shop',
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
			dataProviderName: 'power-shop',
		})

	return { ...mutation, mutateAsync }
}

/**
 * 站長為 Partner 指定密碼（admin_only + nonce 驗證）
 *
 * 與 `useRegeneratePartnerPassword` 並存，用途不同：
 *   - regenerate：夥伴忘記密碼 / 疑似外洩，一鍵重設成系統產生的強密碼
 *   - 本 hook：站長要主動把一組自己知道的密碼交給夥伴
 *
 * 安全紀律：
 *   - 明文密碼**只**在 caller 的 antd Form state，經 `values` 送出後不再持有
 *   - 用 useCustomMutation（非 useCustom）→ 結果不進 React Query cache
 *   - response 是「裸 payload」TSetPasswordOutput，**不含密碼**、不裹 {code, data}
 *   - 後端會 bump partner 的 password_changed_at，該夥伴所有舊 token 立即失效
 *     → caller 必須提示站長「夥伴需用新密碼重新登入」
 *   - 密碼強度由後端 `PartnerPassword` VO 驗證（≥ 8 字元 + ≥ 1 英文 + ≥ 1 數字），
 *     不合格回 422 weak_password + data.reasons[]；前端 Form rule 只是提前回饋，
 *     **不是**唯一守門
 */
export const useSetPartnerPassword = () => {
	const apiUrl = useApiUrl('power-shop')
	const mutation = useCustomMutation<TSetPasswordOutput>()

	const mutateAsync = (id: number, password: string) =>
		mutation.mutateAsync({
			url: `${apiUrl}/${PROFIT_PARTNER_RESOURCE}/${id}/set-password`,
			method: 'post',
			values: { password },
			dataProviderName: 'power-shop',
		})

	return { ...mutation, mutateAsync }
}
