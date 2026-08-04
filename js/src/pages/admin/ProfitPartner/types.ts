import type { TImage } from 'antd-toolkit/wp'

/**
 * Profit Partner 前端型別定義
 *
 * 對齊 specs/api/api.yml 的 PartnerOutput / PartnerInput。
 *
 * ⚠️ 密碼在本模組是**一般欄位**（站長要求）：後端額外保存一份明文
 * （`_partner_password_plain` termmeta），因此列表 / 編輯頁讀得到、也複製得走。
 * 雜湊仍是登入驗證的唯一依據，明文只供顯示。背景、風險與可逆性見
 * `inc/.../Persistence/PartnerTermRepository.php` 的 class docblock。
 *
 * 這代表 `TProfitPartner.password` 會進 React Query cache 與 React DevTools——
 * 這是明文功能的必然結果，不是疏漏；若哪天要收回明文功能，請從後端 termmeta 下手，
 * 在前端做遮蔽只是障眼法。
 */

/**
 * Partner 讀取 DTO（Output；對應後端 PartnerOutput）
 *
 * 注意：後端 PartnerOutput 不含 is_in_use / updated_at；
 * `is_in_use` 在 UI 上若需要呈現需另外打 endpoint 取得（4-A2 不擴張此 endpoint，
 * Delete 操作仍走「先嘗試 → 後端 409 partner_in_use 攔下」流程）。
 */
export type TProfitPartner = {
	id: number
	name: string
	slug: string
	contact_email: string | null
	created_at: string

	/**
	 * 報表登入密碼明文（admin-only）
	 *
	 * `null` 代表這位夥伴建立於「保存明文」功能上線之前——DB 裡只有雜湊、無法還原明文。
	 * 這是**合法狀態**，UI 需顯示「無法顯示，請重設密碼」而不是空白或空字串，
	 * 否則站長會誤以為該夥伴的密碼是空的。重設一次密碼後明文即補上。
	 */
	password: string | null

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
 *   - Create：必填（前端 form rule required）
 *   - Edit：**只在站長真的改動了密碼時才帶**。沒改動就整個欄位不送——
 *     後端 `save()` 收到 password 就會 bump `password_changed_at`，
 *     那會讓該夥伴**當下所有登入 session 立即失效**。若每次存檔（哪怕只改名稱）
 *     都原封不動送回同一組密碼，夥伴會莫名其妙被踢出報表頁。
 *     比對邏輯見 `Edit/index.tsx` 的 `initialPasswordRef`。
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

/** 前端「產生密碼」按鈕的長度（與後端 regenerate-password 的 12 字元一致） */
export const GENERATED_PASSWORD_LENGTH = 12
