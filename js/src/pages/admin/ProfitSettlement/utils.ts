/**
 * 分潤結算前端共用純函式
 */

import type {
	TSettlementFilterValues,
	TSettlementQuery,
	TSettlementRow,
	TSettlementStatus,
} from '@/pages/admin/ProfitSettlement/types'

/**
 * 把篩選表單的值轉成後端查詢參數
 *
 * 三個容易寫錯的點：
 *
 * 1. **日期是 unix 秒，不是字串**（後端 `SettlementFilterInput::normalize_timestamp()`
 *    只收數字，字串會被當成「未指定」而**靜默不過濾**——站長以為自己篩了日期，
 *    實際看到的是全部）。
 * 2. **結束日要取當天最後一秒**（`endOf('day')`）：使用者選「8/01 – 8/02」的語意是
 *    「含 8/02 一整天」，直接用 `.unix()` 會落在 8/02 00:00:00，把當天的紀錄整批漏掉。
 * 3. **多值一律送逗號字串**，理由見 `TSettlementQuery` 的註解。
 *
 * `undefined` 的欄位刻意不放進物件——`useCustom` 會把 `config.query` 直接序列化，
 * 留著空值會送出 `status=` 這種空參數，後端 `flatten()` 雖然會忽略，
 * 但也會讓 react-query 的 queryKey 多出無意義的變動。
 */
export const toSettlementQuery = (
	filters: TSettlementFilterValues,
	pagination?: { page: number; perPage: number }
): TSettlementQuery => {
	const query: TSettlementQuery = {}

	if (filters.partner_term_id) {
		query.partner_term_ids = String(filters.partner_term_id)
	}
	if (filters.shop_id) {
		query.shop_ids = String(filters.shop_id)
	}
	if (filters.status?.length) {
		query.statuses = filters.status.join(',')
	}

	const [start, end] = filters.date_range ?? [null, null]
	if (start) {
		query.date_start = start.startOf('day').unix()
	}
	if (end) {
		query.date_end = end.endOf('day').unix()
	}

	if (pagination) {
		query.page = pagination.page
		query.per_page = pagination.perPage
	}

	return query
}

/**
 * 觸發瀏覽器下載一份 base64 編碼的檔案
 *
 * 🔴 **不可以用 `atob()` 的回傳值直接組 Blob**。`atob` 回傳的是「每個字元代表
 * 一個位元組」的 binary string，把它當成一般字串丟進 `new Blob([str])` 時，
 * 瀏覽器會再以 **UTF-8 重新編碼**一次——原本的 UTF-8 位元組會被當成 Latin-1
 * 字元各自膨脹成 2 個位元組，中文欄位在 Excel 裡整片亂碼，而且 BOM 也一起壞掉。
 * 必須逐字元取 `charCodeAt` 還原成 `Uint8Array`，位元組才會原封不動。
 *
 * ⚠️ CSV 的 UTF-8 BOM **由後端加在內容裡**（`ExportSettlementsUseCase`），
 * 前端不要再加一次——多一個 BOM 會讓 Excel 的第一欄標題變成 `﻿訂單編號`。
 *
 * @param filename 建議檔名（後端已含日期與 .csv 副檔名）
 * @param base64   檔案內容的 base64
 * @param mimeType MIME type
 */
export const downloadBase64File = (
	filename: string,
	base64: string,
	mimeType: string
): void => {
	const binary = atob(base64)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}

	const blob = new Blob([bytes], { type: mimeType })
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = filename

	// 不掛進 DOM 也能點，但 Firefox 需要元素在文件內才會觸發下載
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)

	// 立即撤銷會讓部分瀏覽器來不及開始下載，延後一個 tick 釋放
	setTimeout(() => URL.revokeObjectURL(url), 0)
}

/**
 * 把選取的列依「本次操作是否適用」分成兩堆
 *
 * 用來在確認視窗裡先告訴站長「這 12 筆會被跳過」，而不是等送出後才從
 * 回應的 `skipped` 得知。後端仍會獨立驗證狀態機，這裡只是提前揭露。
 */
export const partitionByAllowedStatus = (
	rows: TSettlementRow[],
	allowedFrom: TSettlementStatus[]
): { eligible: TSettlementRow[]; ineligible: TSettlementRow[] } => {
	const eligible: TSettlementRow[] = []
	const ineligible: TSettlementRow[] = []

	rows.forEach((row) => {
		if (allowedFrom.includes(row.status)) {
			eligible.push(row)
		} else {
			ineligible.push(row)
		}
	})

	return { eligible, ineligible }
}

/**
 * 把 unix 秒轉成可讀時間字串；null / 0 回傳 null
 *
 * 後端的 `settled_at` 未撥款時是 null，撥款後是 unix 秒。
 */
export const formatUnixSeconds = (
	seconds: number | null | undefined
): string | null => {
	if (!seconds || !Number.isFinite(seconds)) return null
	const d = new Date(seconds * 1000)
	if (Number.isNaN(d.getTime())) return null

	const pad = (n: number) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}
