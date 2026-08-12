/**
 * 分潤結算 hooks
 *
 * 與 ProfitShop / ProfitPartner 同一 pattern：後端 7 個結算端點統一裹
 * `{code, message, data}`，與 antd-toolkit 預設 dataProvider 預期的形狀不相容，
 * 因此一律走 `useCustom` / `useCustomMutation` 並明示 `dataProviderName: 'power-shop'`
 * （見 CLAUDE.md Pitfall #10）。
 *
 * ⚠️ 快取失效**必須**用 `useInvalidateProfitQueries`，**不可**用 Refine 的 `useInvalidate`：
 * 後者產生的 queryKey 落在 resource 命名空間，而本檔全走 `useCustom`（custom 命名空間），
 * 兩者前綴對不上、一次都打不中。配上 `main.tsx` 的全域 `staleTime: 10 分鐘`，
 * 症狀會是「撥款完回列表，看到的還是 pending」且沒有任何錯誤訊息——
 * 在一個決定「要不要再轉一次帳」的頁面上，這個 bug 的代價是重複付款。
 *
 * ## 三個 mutation 端點需要 admin nonce
 *
 * `mark-paid` / `mark-cancelled` / `reverse-paid` 後端會驗 `X-WP-Nonce`
 * （capability 擋「你是誰」、nonce 擋「這個請求是不是你自己發的」）。
 * 這個 header 由 `antd-toolkit` 的 `AXIOS_INSTANCE` 全域帶上，本檔不需要自己塞——
 * 但若哪天改用其他 HTTP client，這條就會靜默壞掉（回 403）。
 */

import { useApiUrl, useCustom, useCustomMutation } from '@refinedev/core'

import { useInvalidateProfitQueries } from '@/hooks'
import type { TWrappedResponse } from '@/pages/admin/ProfitShop/hooks'

import type {
	TSettlementBatchAction,
	TSettlementBatchResult,
	TSettlementExport,
	TSettlementList,
	TSettlementOrderIds,
	TSettlementQuery,
	TSettlementSummary,
} from './types'

const SETTLEMENT_RESOURCE = 'profit-settlements'

/**
 * 取得結算列表（server-side 分頁）
 *
 * 與 ProfitShop / ProfitPartner 的列表不同，這裡**不做 client-side 篩選**：
 * 結算紀錄的成長速度是「每張訂單每個分潤品項一筆」，一次全撈回來再前端過濾
 * 遲早撐爆；後端本來就提供了完整的四種篩選 + 分頁，直接用。
 */
export const useSettlementList = (query: TSettlementQuery) => {
	const apiUrl = useApiUrl('power-shop')

	return useCustom<TWrappedResponse<TSettlementList>>({
		url: `${apiUrl}/${SETTLEMENT_RESOURCE}`,
		method: 'get',
		config: { query },
		dataProviderName: 'power-shop',
	})
}

/**
 * 取得結算摘要卡
 *
 * 吃**與列表相同**的過濾條件（分頁除外）——摘要與列表對不起來會讓站長
 * 無從判斷「待撥款 98 元」指的是畫面上這幾筆還是全站。
 */
export const useSettlementSummary = (query: TSettlementQuery) => {
	const apiUrl = useApiUrl('power-shop')

	// 摘要是整個篩選範圍的加總，與分頁無關；把 page / per_page 留在 queryKey 裡
	// 只會讓翻頁時白白重打一次一模一樣的查詢。
	const { page: _page, per_page: _perPage, ...rest } = query

	return useCustom<TWrappedResponse<TSettlementSummary>>({
		url: `${apiUrl}/${SETTLEMENT_RESOURCE}/summary`,
		method: 'get',
		config: { query: rest },
		dataProviderName: 'power-shop',
	})
}

/**
 * 取得符合分潤條件的 order_id 清單（供訂單列表「分潤賣場」過濾用）
 *
 * WooCommerce REST 的 `orders` 端點無法依 order item meta 篩選，因此得先拿到
 * order_id 陣列，再當成 `wc-rest` 的 `include` filter 送出（決策 D-6）。
 *
 * ⚠️ 回應的 `truncated` 為 true 時呼叫端**必須**據實告知站長，見
 * `TSettlementOrderIds.truncated` 的註解。
 *
 * @param shopId  賣場 ID；未指定（undefined / 0）時完全不發查詢
 */
export const useSettlementOrderIds = (shopId?: number) => {
	const apiUrl = useApiUrl('power-shop')
	const enabled = Number.isFinite(shopId) && Number(shopId) > 0

	return useCustom<TWrappedResponse<TSettlementOrderIds>>({
		url: `${apiUrl}/${SETTLEMENT_RESOURCE}/order-ids`,
		method: 'get',
		config: { query: enabled ? { shop_ids: String(shopId) } : {} },
		queryOptions: { enabled },
		dataProviderName: 'power-shop',
	})
}

/**
 * 批次變更結算狀態（mark-paid / mark-cancelled / reverse-paid）
 *
 * 🔴 呼叫端**必須**處理回傳的 `skipped` 與 `audit_log_failed_partner_ids`，
 * 不可只顯示「操作成功」——理由見 `TSettlementBatchResult` 的註解。
 */
export const useSettlementBatchAction = (action: TSettlementBatchAction) => {
	const apiUrl = useApiUrl('power-shop')
	const invalidateProfitQueries = useInvalidateProfitQueries()
	const mutation = useCustomMutation<TWrappedResponse<TSettlementBatchResult>>()

	const mutateAsync = (orderItemIds: number[]) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${SETTLEMENT_RESOURCE}/${action}`,
				method: 'post',
				values: { order_item_ids: orderItemIds },
				dataProviderName: 'power-shop',
			},
			{
				onSuccess: () => {
					// 列表、摘要、order-ids 的 url 都含這段路徑，一次全部失效。
					void invalidateProfitQueries(SETTLEMENT_RESOURCE)
				},
			}
		)

	return { ...mutation, mutateAsync }
}

/**
 * 匯出目前篩選範圍的結算明細為 CSV
 *
 * 過濾條件走 **body**（後端 `post_profit_settlements_export_callback` 讀 JSON body），
 * 不是 query string——多選的 partner / shop 會讓 query string 過長。
 *
 * ⚠️ 匯出範圍是**整個篩選結果**，不是當前頁，因此 body 不帶 page / per_page。
 */
export const useSettlementExport = () => {
	const apiUrl = useApiUrl('power-shop')
	const mutation = useCustomMutation<TWrappedResponse<TSettlementExport>>()

	const mutateAsync = (query: TSettlementQuery) => {
		const { page: _page, per_page: _perPage, ...rest } = query

		return mutation.mutateAsync({
			url: `${apiUrl}/${SETTLEMENT_RESOURCE}/export`,
			method: 'post',
			values: rest,
			dataProviderName: 'power-shop',
		})
	}

	return { ...mutation, mutateAsync }
}
