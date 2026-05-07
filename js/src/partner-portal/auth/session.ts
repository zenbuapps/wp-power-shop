/**
 * Partner Portal Session Storage
 *
 * 安全紀律（**不可放寬**）：
 * - 永不存 token：partner token 由 HttpOnly cookie 管理，前端 JS 讀不到也不該讀
 * - 只存「展示用 metadata」：partner_id / name / slug / expires_at
 * - expires_at 僅供 UI 提示（例：剩餘時間提醒），**不是**安全控制
 *   後端 cookie 的 Max-Age 才是真正的有效期
 * - sessionStorage（非 localStorage）：tab 關閉即清除，符合 partner 自助查詢場景
 */

const SESSION_KEY = 'profit_partner_session'

/**
 * 儲存於 sessionStorage 的 partner 展示用 metadata
 *
 * 欄位說明：
 * - partner_id:   partner term_id（純展示用，後端 API 會從 cookie 重新驗證）
 * - partner_name: 顯示名稱（用於 UI 歡迎詞）
 * - partner_slug: partner slug（用於跨 partner 偵測）
 * - expires_at:   後端回傳的過期時間（unix timestamp，秒），UI 提示用
 *
 * 注意：刻意不包含 token / refresh_token / 任何敏感欄位。
 */
export type TPartnerSession = {
	partner_id: number
	partner_name: string
	partner_slug: string
	expires_at: number
}

/** 對外的 sessionStorage 操作介面 */
export const session = {
	/**
	 * 儲存 partner 展示用 metadata
	 *
	 * @param data 要儲存的 session 物件
	 */
	save(data: TPartnerSession): void {
		try {
			sessionStorage.setItem(SESSION_KEY, JSON.stringify(data))
		} catch {
			// sessionStorage 可能因 incognito / quota 等原因失敗，靜默忽略
			// 真實認證仍由後端 cookie 維護，此 storage 只是 UI 輔助
		}
	},

	/**
	 * 讀取 partner 展示用 metadata
	 *
	 * 防呆：JSON parse 失敗或 shape 不對皆回 null。
	 *
	 * @return {TPartnerSession | null} 解析後的 session，無資料 / shape 不符回 null
	 */
	read(): TPartnerSession | null {
		try {
			const raw = sessionStorage.getItem(SESSION_KEY)
			if (!raw) return null
			const parsed = JSON.parse(raw) as unknown
			if (!parsed || typeof parsed !== 'object') return null
			const obj = parsed as Record<string, unknown>
			if (
				typeof obj.partner_id !== 'number' ||
				typeof obj.partner_name !== 'string' ||
				typeof obj.partner_slug !== 'string' ||
				typeof obj.expires_at !== 'number'
			) {
				return null
			}
			return {
				partner_id: obj.partner_id,
				partner_name: obj.partner_name,
				partner_slug: obj.partner_slug,
				expires_at: obj.expires_at,
			}
		} catch {
			return null
		}
	},

	/** 清除 sessionStorage 內的 partner metadata */
	clear(): void {
		try {
			sessionStorage.removeItem(SESSION_KEY)
		} catch {
			// 同 save，靜默忽略
		}
	},
}
