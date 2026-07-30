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
import type { TProductVariation, TSimpleAttribute } from '@/types/wcRestApi'

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

/**
 * 把 product_id 陣列切成固定 CHUNK_COUNT 塊，不足的塊為空陣列
 *
 * ⚠️ **切塊前先排序**：`map` 是「id → 商品」的查表，結果與 items 的排列順序無關，
 * 但 chunk 內容會直接進 react-query 的 queryKey。若照 items 原順序切，
 * 使用者拖曳排序（商品集合沒變、只換位置）會讓 queryKey 變動 → 整批重新查詢，
 * 表格閃一次 Skeleton 且類型 / 狀態 / 價格 / 庫存欄短暫變成「—」。
 * 排序後，同一組 id 的任意排列都對應同一個 queryKey。
 */
const toChunks = (ids: number[]): number[][] => {
	const sorted = [...ids].sort((a, b) => a - b)
	const chunks: number[][] = []
	for (let i = 0; i < CHUNK_COUNT; i++) {
		chunks.push(sorted.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE))
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

/**
 * 單一商品的變體查詢上限
 *
 * WC REST 的 `per_page` 硬上限就是 100，超過的變體拿不到 ——
 * 這是 API 限制不是本專案的選擇，因此不做分頁載入，改為在 UI 明講被截斷（TD-16）。
 */
export const VARIATION_PAGE_LIMIT = 100

/**
 * wc-rest 變體記錄
 *
 * `TProductBase.attributes` 宣告成 `TAttribute[] | TSimpleAttribute[]` 的聯集，
 * 直接 `.map((a) => a.option)` 會因為「聯集的陣列」而讀不到 `option`。
 * 變體端點實際只回 `{ id, name, option }` 這種單值形狀，這裡收窄成 `TSimpleAttribute[]`。
 */
export type TShopVariationInfo = Omit<TProductVariation, 'attributes'> & {
	attributes: TSimpleAttribute[]
}

/**
 * 取得單一商品的變體清單（走 wc-rest）
 *
 * ⚠️ **刻意設計成「由展開列的子元件呼叫」**，不在 ItemsEditor 那一層查：
 * rc-table 的 `expandedRowRender` 天然 lazy（沒展開過的列根本不 render），
 * 所以查詢自然只會發生在使用者真的展開的那個商品上，不會有 N+1；
 * 收合只是 `display:none` 不會 unmount，重新展開也不會重查。
 * 反過來說，若把查詢寫在 ItemsEditor，就會為了固定 hook 數量而被迫像
 * `useShopItemProducts` 那樣寫死 N 個 `useList`。
 *
 * ⚠️ `per_page` **必須當 filter 送**（見 useShopItemProducts 同款註解）：
 * 6 個 dataProvider 共用同一個 factory，`pagination` 只會被序列化成 Powerhouse 風格的
 * `paged` / `posts_per_page`，而 WooCommerce 只認 `page` / `per_page` ——
 * 少了這條 filter 會靜默退回每頁 10 筆，變體一多就看不到後面的。
 */
export const useProductVariations = (productId: number, enabled = true) => {
	const isEnabled = enabled && Number.isFinite(productId) && productId > 0

	const query = useList<TShopVariationInfo>({
		resource: `products/${productId}/variations`,
		dataProviderName: 'wc-rest',
		filters: [
			{
				field: 'per_page',
				operator: 'eq' as const,
				value: VARIATION_PAGE_LIMIT,
			},
		],
		pagination: { pageSize: VARIATION_PAGE_LIMIT },
		queryOptions: {
			enabled: isEnabled,

			// 與賣場編輯頁其他查詢一致：切回視窗不重新抓，避免蓋掉編輯中的內容
			refetchOnWindowFocus: false,
		},
	})

	const variations = useMemo(() => query.data?.data ?? [], [query.data])

	return {
		variations,

		// `total` 取自 x-wp-total header；antd-toolkit 在缺 header 時 fallback 成 1，
		// 所以只拿它當「補充資訊」用，判斷是否被截斷一律以實際筆數為準
		total: query.data?.total ?? 0,

		// 停用中的查詢其 isLoading 恆為 true，不夾 isEnabled 會讓收合的列一直顯示骨架
		isLoading: isEnabled && query.isLoading,
		isError: query.isError,
	}
}
