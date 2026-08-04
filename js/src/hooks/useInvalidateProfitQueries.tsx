import { useQueryClient } from '@tanstack/react-query'
import { useCallback } from 'react'

/**
 * 讓 profit-* 系列的 `useCustom` 查詢失效
 *
 * ## 為什麼不能用 Refine 的 `useInvalidate`
 *
 * `useInvalidate({ resource: 'profit-partners', invalidates: ['list'] })` 產生的 queryKey
 * 落在 **resource 命名空間**（形如 `[..., 'profit-partners', 'list']`），
 * 而 profit-* 系列因為後端回 `{code, data}` 形狀不相容而一律改走 `useCustom`
 * （見 CLAUDE.md Pitfall #10），它的 queryKey 落在 **custom 命名空間**（含 method + url）。
 *
 * 兩者的前綴在第一段就分岔，`invalidateQueries` 的 prefix matching **完全打不中**——
 * 也就是說 profit-* 的 mutation 呼叫 `useInvalidate` 是**無效的**，列表不會重新查詢。
 *
 * 平常不易察覺，是因為換頁會 remount、react-query 預設 `staleTime: 0` 會順手 refetch。
 * 但本專案在 `main.tsx` 全域設了 **`staleTime: 10 分鐘`**，於是「改完資料 → 回列表頁」
 * 這條最常見的動線會在 10 分鐘內**穩定地顯示舊資料**，且沒有任何錯誤訊息。
 * （實際案例：站長設定完夥伴密碼回列表，「複製密碼」按鈕仍停用，因為 cache 裡的
 * `password` 還是設定前的 `null`。）
 *
 * ## 為什麼用 predicate 而不是明確的 queryKey
 *
 * 明確組出 `useCustom` 的 queryKey 需要複製一份 Refine 內部的 key 結構
 * （`keys().data(...).mutation('custom').params({ method, url, ... })`），
 * 那份結構是 Refine 的實作細節、升版可能改變，一旦分家就會再次靜默失效——
 * 與這個 bug 本身同一種失敗模式。
 *
 * 改以「queryKey 序列化後是否含這段 endpoint 路徑」比對：`useCustom` **必然**把 url
 * 放進 queryKey（否則不同 url 的查詢會互撞），所以這個判斷不依賴任何內部結構。
 * 它同時也會命中 resource 命名空間的 key（那裡面有 resource 名稱），
 * 因此是 `useInvalidate` 的超集，可以直接取代而不必兩者並用。
 *
 * @return {(resource: string) => Promise<void>} 傳入 endpoint 路徑片段（如 `'profit-partners'`）即失效對應查詢
 */
export const useInvalidateProfitQueries = () => {
	const queryClient = useQueryClient()

	return useCallback(
		(resource: string) =>
			queryClient.invalidateQueries({
				predicate: (query) => {
					// queryKey 內容混雜字串與物件（含 url / config），一律序列化後比對。
					// admin SPA 同時存活的 query 數量有限（數十筆），逐筆 stringify 的成本可忽略。
					try {
						return JSON.stringify(query.queryKey).includes(resource)
					} catch {
						// queryKey 理論上一定可序列化；真的失敗時寧可不失效，
						// 也不要讓一個 cache 清理動作把整個 mutation 的 onSuccess 打斷。
						return false
					}
				},
			}),
		[queryClient]
	)
}
