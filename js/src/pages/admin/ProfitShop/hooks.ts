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
	useList,
} from '@refinedev/core'
import { useMemo } from 'react'

import { TProductRecord } from '@/components/product/types'
import { useInvalidateProfitQueries } from '@/hooks'
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
	const invalidateProfitQueries = useInvalidateProfitQueries()
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
					void invalidateProfitQueries(PROFIT_SHOP_RESOURCE)
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 更新分潤賣場 */
export const useProfitShopUpdate = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidateProfitQueries = useInvalidateProfitQueries()
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
					void invalidateProfitQueries(PROFIT_SHOP_RESOURCE)
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/** 刪除（trash）分潤賣場 */
export const useProfitShopDelete = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidateProfitQueries = useInvalidateProfitQueries()
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
					void invalidateProfitQueries(PROFIT_SHOP_RESOURCE)
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
	const invalidateProfitQueries = useInvalidateProfitQueries()
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
					void invalidateProfitQueries(PROFIT_SHOP_RESOURCE)
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
 *
 * ⚠️ **匯出只為了測試**（`tests/js/shopItemChunks.test.ts`），產品程式碼唯一的
 * 呼叫端仍是同檔的 `useShopItemProducts`。`useShopItemProducts` 本身是 hook、
 * 綁死 Refine 的 `useList`，在 jsdom 下要測得架起整個 dataProvider；
 * 而這兩條 Rule（「空賣場不發查詢」「查詢範圍等於 items 的 product_id 集合」）
 * 的契約完全落在這個純函式上——`enabled: chunk.length > 0` 與 `include: chunk`
 * 都只是把它的輸出接出去而已。
 */
export const toChunks = (ids: number[]): number[][] => {
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
 * wc-rest 變體記錄
 *
 * `TProductBase.attributes` 宣告成 `TAttribute[] | TSimpleAttribute[]` 的聯集，
 * 直接 `.map((a) => a.option)` 會因為「聯集的陣列」而讀不到 `option`。
 * 變體端點實際只回 `{ id, name, option }` 這種單值形狀，這裡收窄成 `TSimpleAttribute[]`。
 */
export type TShopVariationInfo = Omit<TProductVariation, 'attributes'> & {
	attributes: TSimpleAttribute[]

	/**
	 * 低庫存門檻
	 *
	 * WC 的 variation schema 自 3.5 起就有這個欄位，但本地 `TProductBase` 只把它
	 * 宣告在 `TProduct`（父商品）上、沒宣告在 variation。補在這裡，庫存欄才能
	 * 與 `#/products` 一樣區分「庫存充足 / 低庫存」，而不是一律顯示「尚有庫存」。
	 *
	 * 標成 optional 是因為它同時也可能是 `null`（站長沒設，走站台全域設定）——
	 * `ProductStock` 對 `null` 的處理是「不判低庫存」，正是我們要的降級行為。
	 */
	low_stock_amount?: number | null
}

/**
 * 佈景主題的頁面 / 文章範本清單
 *
 * 供賣場編輯頁「頁面範本」下拉使用。清單由後端 `ThemeTemplateWhitelist::allowed()`
 * 產出，同一份清單也是 `ProfitShopRenderer::resolve_theme_template()` 的白名單——
 * 站長選不到、也送不出清單以外的檔名。
 *
 * ⚠️ **`templates` 為空陣列與 `isError` 是兩件不同的事，呼叫端必須分開呈現。**
 * 兩者都會讓下拉是空的，但成因與處置完全相反：
 *
 * | 狀態 | 意思 | 站長該做什麼 |
 * |---|---|---|
 * | `templates.length === 0` | 這個佈景主題**確實沒有**可用範本 | 什麼都不用做，賣場走內建版型 |
 * | `isError` | **還不知道**有哪些範本 | 重新整理再試 |
 *
 * 空清單是**正常且可能的**結果：後端只列 `locate_template()` 真的找得到實體檔案的
 * 項目，頁面建構器（如 Elementor）註冊的虛擬範本沒有檔案、選了也載不動，會被濾掉。
 * 把載入失敗說成「沒有範本」會讓站長以為問題出在佈景主題而白跑一趟。
 */
export const useThemeTemplates = (enabled = true) => {
	const apiUrl = useApiUrl('power-shop')

	const query = useCustom<
		TWrappedResponse<{ items: { value: string; label: string }[] }>
	>({
		url: `${apiUrl}/theme-templates`,
		method: 'get',
		dataProviderName: 'power-shop',
		queryOptions: {
			enabled,
			refetchOnWindowFocus: false,

			// 佈景主題範本清單在一次瀏覽期間不會變動，重複開合分頁時不必重抓
			staleTime: Infinity,
		},
	})

	const templates = useMemo(
		() => query.data?.data?.data?.items ?? [],
		[query.data]
	)

	/*
	 * `isLoading` 必須用 `enabled &&` 收口：query 的初始 status 是
	 * `hasData ? 'success' : 'loading'`（@tanstack/query-core 4.43.0
	 * `build/lib/query.js:473`），而 `isLoading = status === 'loading'`
	 * （同版 `build/lib/queryObserver.js:372`）——disabled query 永遠不會取得資料，
	 * 於是恆為 `true`。不加守門的話 Shortcode 模式（不發查詢）會一直回報「載入中」。
	 *
	 * `isError` 同樣加守門只是為了對稱與可預期（disabled query 不會進 error 狀態），
	 * 讓呼叫端不必去記「哪一個旗標在 disabled 時可信」。
	 */
	return {
		templates,
		isLoading: enabled && query.isLoading,
		isError: enabled && query.isError,
	}
}

/**
 * 取得單一商品的**全部**變體（走本外掛自訂 REST，非 wc-rest）
 *
 * ═══ 🔴 為什麼不用 `wc/v3/products/{id}/variations` ═══
 *
 * 那支 API 的 `per_page` 硬上限是 100（寫死在
 * `WC_REST_Controller::get_collection_params()`），而賣場的變體層價格覆寫是
 * **逐一 variation_id** 設定的 ⇒ 超過 100 個變體的商品，第 101 個之後站長
 * 在編輯頁根本點不到，那些 SKU 在賣場裡永遠只能賣原價（原 TD-16）。
 *
 * 改走 `GET /power-shop/product-variations/{id}`（`WpProductVariationQuery`），
 * 直接讀 `WC_Product_Variable::get_children()`——**無上限、不分頁**。
 *
 * ⚠️ 因此 `useList` + `per_page` filter 那一整套已經**不再適用**：
 * 那是為了繞開 6 個 dataProvider 共用序列化（`pagination` 只會變成
 * Powerhouse 風格的 `paged` / `posts_per_page`，WooCommerce 不認）而寫的。
 * 現在後端根本沒有分頁參數，改用 `useCustom` + `dataProviderName: 'power-shop'`
 * 與 profit-* 三兄弟同一條路線（CLAUDE.md Pitfall #10）。
 *
 * ⚠️ **刻意設計成「由展開列的子元件呼叫」**，不在 ItemsEditor 那一層查：
 * rc-table 的 `expandedRowRender` 天然 lazy（沒展開過的列根本不 render），
 * 所以查詢只會發生在使用者真的展開的那個商品上，不會有 N+1；
 * 收合只是 `display:none` 不會 unmount，重新展開也不會重查。
 */
export const useProductVariations = (productId: number, enabled = true) => {
	const apiUrl = useApiUrl('power-shop')
	const isEnabled = enabled && Number.isFinite(productId) && productId > 0

	const query = useCustom<TWrappedResponse<{
		items: TShopVariationInfo[]
		total: number
	}>>({
		url: `${apiUrl}/product-variations/${productId}`,
		method: 'get',
		dataProviderName: 'power-shop',
		queryOptions: {
			enabled: isEnabled,

			// 與賣場編輯頁其他查詢一致：切回視窗不重新抓，避免蓋掉編輯中的內容
			refetchOnWindowFocus: false,
		},
	})

	// 後端回 `{code, message, data}`，Refine 再包一層 ⇒ 要剝兩層
	const payload = query.data?.data?.data

	const variations = useMemo(() => payload?.items ?? [], [payload])

	return {
		variations,

		/**
		 * 總數
		 *
		 * 由後端 `count($items)` 直接算出，**與 `variations.length` 恆等**——
		 * 不像舊的 wc-rest 路徑要看 `x-wp-total` header（缺 header 時
		 * antd-toolkit 會 fallback 成 1，那個值不可信）。
		 */
		total: payload?.total ?? 0,

		// 停用中的查詢其 isLoading 恆為 true，不夾 isEnabled 會讓收合的列一直顯示骨架
		isLoading: isEnabled && query.isLoading,
		isError: query.isError,
	}
}
