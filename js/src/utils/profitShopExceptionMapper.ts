/**
 * Profit Shop 後端例外 → 前端友善訊息對映器
 *
 * 對應 spec §7 ExceptionMapper（PHP 端）：13 種 Domain Exception → 12 種 HTTP code
 * （多個 Domain Exception 可 mapping 到同一 code，未列舉者 fallback 為 validation_failed）。
 * 用於將 axios error 解構為：
 *   - toastMessage: 給 antd notification / message 用
 *   - fieldErrors:  Form.setFields 用（以欄位 name 為 key）
 *   - modalConfig:  嚴重錯誤可彈 Modal.error 用
 *   - retryAfter:   rate_limited 時的等待秒數
 */

import type {
	TProfitShopErrorBody,
	TProfitShopErrorCode,
	TSlugConflict,
} from '@/pages/admin/ProfitShop/types'

export type TMappedException = {
	toastMessage: string // 主要使用者訊息（toast / notification）
	fieldErrors?: Record<string, string> // 欲對應到 Form.setFields 的欄位錯誤（key = field name）
	modalConfig?: { title: string; content: string } // 嚴重錯誤可改用 Modal.error 呈現
	retryAfter?: number // rate_limited 時的等待秒數（同步 Retry-After header）
	code?: TProfitShopErrorCode | string // 原始 error code，方便上層分支處理
}

/**
 * 從未知 axios error 嘗試解構出後端 ProfitShopExceptionError 結構
 */
const extractErrorBody = (error: unknown): TProfitShopErrorBody | null => {
	if (!error || typeof error !== 'object') return null

	// axios error: error.response.data
	const response = (error as { response?: { data?: unknown } }).response
	const body = response?.data
	if (!body || typeof body !== 'object') return null
	const code = (body as { code?: unknown }).code
	const message = (body as { message?: unknown }).message
	if (typeof code !== 'string' || typeof message !== 'string') return null
	return body as TProfitShopErrorBody
}

/**
 * 從 axios error 取得 Retry-After header 秒數（rate_limited 用）
 */
const extractRetryAfter = (error: unknown): number | undefined => {
	if (!error || typeof error !== 'object') return undefined
	const headers = (
		error as { response?: { headers?: Record<string, unknown> } }
	).response?.headers
	const raw = headers?.['retry-after']
	if (typeof raw === 'string') {
		const n = parseInt(raw, 10)
		return Number.isFinite(n) ? n : undefined
	}
	if (typeof raw === 'number') return raw
	return undefined
}

/**
 * 將 SlugConflict 列表組成可讀字串（中文逗號分隔）
 */
const formatSlugConflicts = (conflicts: TSlugConflict[]): string => {
	if (!conflicts.length) return 'Slug 與既有資源衝突'
	return conflicts
		.map(
			(c) =>
				`${c.conflicting_label}（${c.conflict_kind}：${c.conflicting_slug}）`
		)
		.join('；')
}

/**
 * 主要對映函式
 *
 * @param  error 任意來自 axios / Refine mutate 的失敗物件
 * @return {TMappedException} 結構化的前端錯誤訊息
 */
export const mapProfitShopException = (error: unknown): TMappedException => {
	const body = extractErrorBody(error)

	if (!body) {
		// 連結級錯誤（network / CORS / unhandled）
		const fallback =
			error instanceof Error ? error.message : '操作失敗，請稍後再試'
		return { toastMessage: fallback }
	}

	const { code, message, conflicts, reason, retry_after, error_id } = body

	switch (code) {
		case 'slug_conflict': {
			const detail = conflicts ? formatSlugConflicts(conflicts) : message
			return {
				code,
				toastMessage: 'Slug 與既有資源衝突，請更換',
				fieldErrors: { slug: detail },
			}
		}
		case 'not_found':
			return { code, toastMessage: '查無對應資源（賣場 / 夥伴 / 商品）' }
		case 'product_not_in_shop':
			return { code, toastMessage: '此商品不在賣場內' }
		case 'product_already_in_shop':
			return { code, toastMessage: '此商品已加入賣場，請勿重複新增' }
		case 'invalid_state_transition':
			return {
				code,
				toastMessage: message || '當前賣場狀態不允許此操作',
			}
		case 'partner_in_use':
			return {
				code,
				toastMessage: '此夥伴仍掛在賣場上，請先解除關聯後再刪除',
				modalConfig: {
					title: '無法刪除夥伴',
					content: message || '此夥伴仍掛在賣場上',
				},
			}
		case 'legacy_unimportable':
			return {
				code,
				toastMessage: reason
					? `Legacy 商店無法匯入：${reason}`
					: 'Legacy 商店無法匯入',
			}
		case 'rate_limited': {
			const retryAfter = retry_after ?? extractRetryAfter(error)
			return {
				code,
				toastMessage: retryAfter
					? `操作過於頻繁，請 ${retryAfter} 秒後再試`
					: '操作過於頻繁，請稍後再試',
				retryAfter,
			}
		}
		case 'unauthorized':
			return { code, toastMessage: '未登入或登入已逾期' }
		case 'forbidden':
			return { code, toastMessage: '權限不足' }
		case 'validation_failed':
			return {
				code,
				toastMessage: message || '輸入資料不正確',
			}
		case 'internal_error':
			return {
				code,
				toastMessage: error_id
					? `系統錯誤，請聯絡管理員（error_id: ${error_id}）`
					: '系統錯誤，請聯絡管理員',
			}
		default:
			return { code, toastMessage: message || '操作失敗' }
	}
}
