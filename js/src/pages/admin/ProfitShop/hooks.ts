/**
 * Profit Shop CRUD 共用 hooks
 *
 * 由於 Phase 3-B/3-E 後端 endpoint 統一裹 `{code, data}`，
 * 而 antd-toolkit 預設 dataProvider 預期 WordPress REST API 形狀，
 * 因此這裡用 Refine 的 `useCustom` / `useCustomMutation` 直接打點，
 * 不走 useTable / useForm（避免 dataProvider 形狀不符的反序列化問題）。
 */

import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	useInvalidate,
} from '@refinedev/core'

import type {
	TProfitShop,
	TProfitShopInput,
} from '@/pages/admin/ProfitShop/types'

/** 後端統一回應包裹 */
export type TWrappedResponse<T> = {
	code: string
	message?: string
	data: T
}

const PROFIT_SHOP_RESOURCE = 'profit-shops'

/** 取得分潤賣場列表 */
export const useProfitShopList = (params?: { partner_term_id?: number }) => {
	const apiUrl = useApiUrl('power-shop')
	const url = `${apiUrl}/${PROFIT_SHOP_RESOURCE}`

	return useCustom<TWrappedResponse<TProfitShop[]>>({
		url,
		method: 'get',
		config: {
			query: params?.partner_term_id
				? { partner_term_id: params.partner_term_id }
				: undefined,
		},
	})
}

/** 取得單一分潤賣場 */
export const useProfitShopOne = (id: number | string | undefined) => {
	const apiUrl = useApiUrl('power-shop')
	const url = `${apiUrl}/${PROFIT_SHOP_RESOURCE}/${id ?? ''}`

	return useCustom<TWrappedResponse<TProfitShop>>({
		url,
		method: 'get',
		queryOptions: {
			enabled: id !== undefined && id !== null && id !== '',

			// 雙保險：避免 window focus 觸發 refetch → 蓋掉編輯中內容
			// （Edit 頁的 filledIdRef 守門是主防線；此處關閉自動 refetch 是輔防線）
			refetchOnWindowFocus: false,
		},
	})
}

/** 建立分潤賣場 */
export const useProfitShopCreate = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const mutation = useCustomMutation<TWrappedResponse<TProfitShop>>()

	const mutateAsync = (values: TProfitShopInput) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_SHOP_RESOURCE}`,
				method: 'post',
				values,
			},
			{
				onSuccess: () => {
					invalidate({
						resource: PROFIT_SHOP_RESOURCE,
						invalidates: ['list'],
						dataProviderName: 'power-shop',
					})
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 更新分潤賣場 */
export const useProfitShopUpdate = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const mutation = useCustomMutation<TWrappedResponse<TProfitShop>>()

	const mutateAsync = (id: number, values: TProfitShopInput) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_SHOP_RESOURCE}/${id}`,
				method: 'put',
				values,
			},
			{
				onSuccess: () => {
					invalidate({
						resource: PROFIT_SHOP_RESOURCE,
						invalidates: ['list', 'detail'],
						id,
						dataProviderName: 'power-shop',
					})
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 刪除（trash）分潤賣場 */
export const useProfitShopDelete = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const mutation =
		useCustomMutation<TWrappedResponse<{ id: number; deleted: boolean }>>()

	const mutateAsync = (id: number) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_SHOP_RESOURCE}/${id}`,
				method: 'delete',
				values: {},
			},
			{
				onSuccess: () => {
					invalidate({
						resource: PROFIT_SHOP_RESOURCE,
						invalidates: ['list'],
						dataProviderName: 'power-shop',
					})
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/**
 * 共用 helper：產生 status / publish / unpublish / duplicate 等
 * 「對單一賣場做動作」的 mutation。動作後失效 list + detail。
 */
const useProfitShopAction = (action: 'publish' | 'unpublish' | 'duplicate') => {
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const mutation = useCustomMutation<TWrappedResponse<TProfitShop>>()

	const mutateAsync = (id: number) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_SHOP_RESOURCE}/${id}/${action}`,
				method: 'post',
				values: {},
			},
			{
				onSuccess: () => {
					invalidate({
						resource: PROFIT_SHOP_RESOURCE,
						invalidates: ['list', 'detail'],
						id,
						dataProviderName: 'power-shop',
					})
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 上架賣場（POST /profit-shops/:id/publish） */
export const useProfitShopPublish = () => useProfitShopAction('publish')

/** 下架賣場（POST /profit-shops/:id/unpublish） */
export const useProfitShopUnpublish = () => useProfitShopAction('unpublish')

/** 複製賣場（POST /profit-shops/:id/duplicate） */
export const useProfitShopDuplicate = () => useProfitShopAction('duplicate')
