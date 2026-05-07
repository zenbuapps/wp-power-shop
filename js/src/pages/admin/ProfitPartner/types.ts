/**
 * Profit Partner 前端型別定義
 *
 * 對齊 specs/api/api.yml 的 PartnerOutput / PartnerInput / RegeneratePasswordOutput。
 *
 * 安全紀律：
 *   - TProfitPartner（對應 PartnerOutput）**絕無** password 欄位，避免 list / detail 流程
 *     不慎透過 React state / DevTools / 其他元件讀到密碼明文。
 *   - 重新產生密碼的明文僅在 RegeneratePasswordButton 的 modal 父元件 useState 暫存，
 *     不寫入任何 query cache、不寫 sessionStorage / localStorage、不 console.log，
 *     5 分鐘 auto-clear，modal close 立即 setState(null)。
 */

/**
 * Partner 讀取 DTO（Output；對應後端 PartnerOutput）
 *
 * 注意：後端 PartnerOutput 不含 password / is_in_use / updated_at；
 * `is_in_use` 在 UI 上若需要呈現需另外打 endpoint 取得（4-A2 不擴張此 endpoint，
 * Delete 操作仍走「先嘗試 → 後端 409 partner_in_use 攔下」流程）。
 */
export type TProfitPartner = {
	id: number
	name: string
	slug: string
	contact_email: string | null
	created_at: string
}

/**
 * Partner 寫入 DTO（Input；建立 / 更新共用）
 *
 * `password` 標 optional：
 *   - Create 表單會強制要求填入（前端 form rule required）
 *   - Edit 表單**不傳** password（要改密碼請走 RegeneratePasswordButton）
 *
 * `id` 為 nullable，建立時為 null（與 OpenAPI 對齊）。
 */
export type TProfitPartnerInput = {
	id?: number | null
	name: string
	slug: string
	contact_email?: string | null
	password?: string
}

/**
 * 重新產生 Partner 密碼回應（裸 payload，不裹 {code, data}）
 *
 * 對應 OpenAPI RegeneratePasswordOutput。response header 會帶
 * `Cache-Control: no-store, no-cache, must-revalidate` + `Pragma: no-cache`，
 * 不會被 proxy / browser 快取。
 */
export type TRegeneratePasswordOutput = {
	partner_id: number
	password: string
}
