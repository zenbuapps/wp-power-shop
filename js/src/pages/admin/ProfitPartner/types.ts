import type { TImage } from 'antd-toolkit/wp'

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

	/** 夥伴圖片 attachment ID（未設定為 null） */
	image_id: number | null

	/**
	 * 夥伴圖片 URL（後端由 image_id 即時解析的**唯讀衍生欄位**）
	 *
	 * attachment 被刪除時後端會回 null 但保留 image_id，因此
	 * 「有 image_id 但 image_url 為 null」是合法狀態，UI 需退回預設頭像。
	 */
	image_url: string | null

	/**
	 * 夥伴報表頁完整網址（`/profit-report/{partner-slug}/`，**唯讀衍生欄位**）
	 *
	 * ⚠️ **不要**在前端自行拼接這個網址。前綴目前是固定常數
	 * （`RewriteRules::DEFAULT_REPORT_SLUG`），但組成規則的真相來源只有 PHP 端；
	 * 在前端複製一份，只要哪天前綴變動（它曾經是可設定項，被移除過一次）就會分家，
	 * 而症狀是按鈕安靜地連到 404（頁面正常載入、只是找不到報表），沒有任何錯誤訊息。
	 *
	 * 後端在 `PartnerTermRepository::resolve_report_url()` 用註冊 rewrite rule 的
	 * 同一個 `RewriteRules::get_report_slug()` 組出，是唯一真相來源。
	 *
	 * 型別保留 nullable 與 `image_url` 對齊（消費端一律要處理 null）。
	 */
	report_url: string | null
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

	/** 夥伴圖片 attachment ID；`null` 明確代表「移除圖片」（後端會刪除 termmeta） */
	image_id?: number | null
}

/**
 * PartnerForm 的表單值型別
 *
 * 與送後端的 `TProfitPartnerInput` 差在圖片：表單沿用商品圖片 `Gallery` 元件的
 * `TImage[]` 契約（`{ id: string, url: string }[]`，`id` 為字串型別、可承載多張），
 * submit 時才降維成單一 `image_id`（見 `toImageId()`）。
 * 兩者不可混用——url 與陣列結構都不該進 payload。
 */
export type TPartnerFormValues = Omit<TProfitPartnerInput, 'image_id'> & {
	images?: TImage[]
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
