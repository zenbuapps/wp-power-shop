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
import { App } from 'antd'
import { useCallback } from 'react'

import { useInvalidateProfitQueries } from '@/hooks'
import type {
	TProfitPartner,
	TProfitPartnerInput,
} from '@/pages/admin/ProfitPartner/types'
import type { TWrappedResponse } from '@/pages/admin/ProfitShop/hooks'

const PROFIT_PARTNER_RESOURCE = 'profit-partners'

/**
 * 成功 / 失敗提示的顯示秒數
 *
 * antd 預設 3 秒，對這個訊息太短：站長的實際動線是「複製 → 切到 LINE →
 * 找到對話 → 貼上」，往往數分鐘後才貼；訊息裡的夥伴名稱正是他核對「我剛剛複製的是誰的」
 * 的唯一依據，3 秒內就消失等於沒有。失敗訊息同理——它要求站長改走手動路徑，
 * 來不及讀完就消失只會讓人以為按鈕沒反應。
 */
const COPY_MESSAGE_DURATION_SECONDS = 8

/**
 * 複製分潤夥伴的報表登入密碼到剪貼簿（`#/profit-partner` 與 `#/profit-shop` 共用）
 *
 * ── 為什麼成功訊息一定要帶夥伴名稱 ────────────────────────
 *
 * 剪貼簿裡的密碼**沒有任何身分標記**，而站長在 `#/profit-shop` 上很容易連按兩列
 * （兩列可能分屬不同夥伴）的複製密碼——剪貼簿只會剩下最後一次的內容。
 * 貼上動作又往往發生在數分鐘後（切到通訊軟體、找對話、貼上），
 * 中間沒有任何一步會提示他手上這串字是誰的 ⇒ 貼給 A 的可能是 B 的密碼。
 * 訊息帶上夥伴名稱（賣場列表再多帶賣場名）是唯一能事後核對的線索。
 *
 * ⚠️ 刻意**不** fallback 到 `document.execCommand('copy')`：該 API 已廢棄，
 * 且在部分瀏覽器會靜默失敗——站長以為複製到了、貼出來卻是空的，
 * 比明確要求他手動複製更糟。與 `ProfitShop/List` 的 shortcode 複製同一條紀律。
 *
 * @return {Function} `(partner, contextLabel?) => Promise<void>`；
 *                    `contextLabel` 為呼叫端的情境標籤（賣場列表傳賣場名稱）
 */
export const useCopyPartnerPassword = () => {
	const { message } = App.useApp()

	return useCallback(
		async (partner: TProfitPartner, contextLabel?: string): Promise<void> => {
			// 呼叫端理應已用 resolveCopyPartnerPasswordBlockedReason 停用按鈕，
			// 這裡是第二道守門：沒有明文時寧可什麼都不做，也不要複製一個空字串
			// 讓站長貼出空白密碼給夥伴。
			if (!partner.password) return

			try {
				// Clipboard API 需要 secure context（https / localhost）。
				// 兩種失敗都由同一個 catch 接住，因為對站長而言後續動作完全相同：
				//   1. 沒有 navigator.clipboard —— 讀 .writeText 直接同步 throw TypeError
				//      （在 async 函式的 try 內仍會被接到）
				//   2. 使用者 / 瀏覽器拒絕權限 —— writeText 回傳的 promise reject
				await navigator.clipboard.writeText(partner.password)

				message.success({
					content: contextLabel
						? `已複製賣場「${contextLabel}」所屬夥伴「${partner.name}」的密碼`
						: `已複製夥伴「${partner.name}」的密碼`,
					duration: COPY_MESSAGE_DURATION_SECONDS,
				})
			} catch {
				message.warning({
					content: `瀏覽器拒絕剪貼簿存取（非 HTTPS 連線也會如此）。請至分潤夥伴「${partner.name}」的編輯頁手動複製密碼。`,
					duration: COPY_MESSAGE_DURATION_SECONDS,
				})
			}
		},
		[message]
	)
}

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
