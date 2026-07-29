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
	useList,
} from '@refinedev/core'
import { useMemo } from 'react'

import { TProductRecord } from '@/components/product/types'
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
		dataProviderName: 'power-shop',
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
		dataProviderName: 'power-shop',
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
				dataProviderName: 'power-shop',
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
				dataProviderName: 'power-shop',
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
				dataProviderName: 'power-shop',
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
				dataProviderName: 'power-shop',
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

/** WC REST `per_page` 上限 */
const CHUNK_SIZE = 100

/** 固定的補齊查詢分塊數；100 × 5 = 500，對齊賣場商品軟上限 */
const CHUNK_COUNT = 5

/**
 * 補齊後的商品資料
 *
 * 直接保留整筆 `TProductRecord` —— wc-rest 回傳本來就是完整商品，
 * 只取 name / sku 會讓賣場商品表格想顯示類型 / 狀態 / 價格 / 庫存時得再發一輪查詢。
 */
export type TShopItemProductInfo = TProductRecord

/** 把 product_id 陣列切成固定 CHUNK_COUNT 塊，不足的塊為空陣列 */
const toChunks = (ids: number[]): number[][] => {
	const chunks: number[][] = []
	for (let i = 0; i < CHUNK_COUNT; i++) {
		chunks.push(ids.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE))
	}
	return chunks
}

/**
 * 依 product_id 批次補齊商品名稱 / SKU（走 wc-rest）
 *
 * ⚠️ **hook 數量必須固定**：這裡刻意把 5 個 `useList` 一個一個寫出來，
 * 而不是 `chunks.map((c) => useList(...))` —— 後者會讓 hook 數量隨賣場商品數
 * 變動，一違反 Rules of Hooks，items 數量一改 React 就會炸。
 * 分塊由 `queryOptions.enabled` 開關，空塊完全不發查詢（spec Rule 1）。
 *
 * 5 的由來：WC REST `per_page` 上限 100 × 賣場商品軟上限 500。
 * 超過 500 的尾段不補齊，該列退回只顯示 `#id`（R-3 已知取捨）。
 *
 * 傳入賣場 items 的 product_id 集合，回傳
 * `{ map, isLoading, isError, hasQuery }` —— map 只含查得到的商品。
 */
export const useShopItemProducts = (productIds: number[]) => {
	const chunks = useMemo(() => toChunks(productIds), [productIds])

	const makeConfig = (chunk: number[]) => ({
		resource: 'products',
		dataProviderName: 'wc-rest',
		filters: [
			{
				field: 'include',
				operator: 'eq' as const,
				value: chunk,
			},

			/*
			 * ⚠️ `per_page` 必須當成 filter 送，不能只靠 `pagination.pageSize`。
			 *
			 * 6 個 dataProvider 共用同一個 factory，分頁一律序列化成 Powerhouse 風格的
			 * `paged` / `posts_per_page`；但這條打的是 WooCommerce 自己的 `wc/v3/products`，
			 * 它只認 `page` / `per_page`，`posts_per_page` 會被忽略 → 回退成 WC 預設的
			 * 每頁 10 筆，導致一個 chunk 只補齊得到 10 筆商品。
			 */
			{
				field: 'per_page',
				operator: 'eq' as const,
				value: CHUNK_SIZE,
			},
		],
		queryOptions: { enabled: chunk.length > 0 },
	})

	const q0 = useList<TProductRecord>(makeConfig(chunks[0]))
	const q1 = useList<TProductRecord>(makeConfig(chunks[1]))
	const q2 = useList<TProductRecord>(makeConfig(chunks[2]))
	const q3 = useList<TProductRecord>(makeConfig(chunks[3]))
	const q4 = useList<TProductRecord>(makeConfig(chunks[4]))

	const queries = [
		q0,
		q1,
		q2,
		q3,
		q4,
	]

	const map = useMemo(() => {
		const result = new Map<number, TShopItemProductInfo>()
		queries.forEach((q) => {
			const rows = q?.data?.data ?? []
			rows.forEach((row) => {
				const id = Number(row?.id)
				if (!Number.isFinite(id)) return
				result.set(id, row)
			})
		})
		return result
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		q0.data,
		q1.data,
		q2.data,
		q3.data,
		q4.data,
	])

	// 只看「有啟用」的查詢；停用的 chunk 其 isLoading 恆為 true，會誤判
	const activeQueries = queries.filter((_, i) => chunks[i].length > 0)

	return {
		map,
		isLoading: activeQueries.some((q) => q.isLoading),
		isError: activeQueries.some((q) => q.isError),
		hasQuery: activeQueries.length > 0,
	}
}
