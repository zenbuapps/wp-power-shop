/**
 * Profit Shop 前端型別定義
 *
 * 對齊 specs/api/api.yml 的 ProfitShopOutput / ProfitShopInput / OverrideItem* / SlugConflict
 * 以及 ProfitShopExceptionError.code enum（spec §7：13 種 Domain Exception → 12 種 code，
 * 多個 Domain Exception 可 mapping 到同一 code，未列舉者 fallback 為 validation_failed）
 */

/** 賣場狀態 */
export const PROFIT_SHOP_STATUS = {
	PUBLISH: 'publish',
	DRAFT: 'draft',
	TRASH: 'trash',
} as const

export type TProfitShopStatus =
	(typeof PROFIT_SHOP_STATUS)[keyof typeof PROFIT_SHOP_STATUS]

/** 賣場呈現模式 */
export const PROFIT_SHOP_MODE = {
	PAGE: 'page',
	SHORTCODE: 'shortcode',
} as const

export type TProfitShopMode =
	(typeof PROFIT_SHOP_MODE)[keyof typeof PROFIT_SHOP_MODE]

/**
 * 賣場商品覆寫項目（Output）
 *
 * 對應 OpenAPI OverrideItemOutput（OverrideItemInput + 唯讀 sku/name）。
 * 注意：`price_override` 是 integer | null（單位元；null 代表沿用原價），
 * 並非 nested object（spec/api/api.yml line 861-868）。
 */
export type TOverrideItem = {
	product_id: number
	variation_id: number
	price_override: number | null
	inflated_count: number
	sku?: string
	name?: string
}

/** 賣場商品覆寫項目（Input） */
export type TOverrideItemInput = {
	product_id: number
	variation_id?: number
	price_override?: number | null
	inflated_count?: number
}

/**
 * Slug 衝突來源（spec §6.11，5 類）
 *
 * 對應 OpenAPI SlugConflict schema（line 832-850）。
 * 欄位名為 `conflict_kind` / `conflicting_slug` / `conflicting_id` / `conflicting_label`。
 */
export type TSlugConflict = {
	conflict_kind: string
	conflicting_slug: string
	conflicting_id: number | null
	conflicting_label: string
}

/** 分潤賣場讀取 DTO（Output） */
export type TProfitShop = {
	id: number
	title: string
	slug: string
	status: TProfitShopStatus
	mode: TProfitShopMode
	partner_term_id: number
	rate: number
	items: TOverrideItem[]
	settings: Record<string, unknown>
	created_at: string
	updated_at: string
}

/** 分潤賣場寫入 DTO（Input；建立 / 更新共用） */
export type TProfitShopInput = {
	id?: number | null
	title: string
	slug: string
	status?: TProfitShopStatus
	mode?: TProfitShopMode
	partner_term_id: number
	rate: number
	items?: TOverrideItemInput[]
	settings?: Record<string, unknown>
}

/**
 * Profit Shop ExceptionMapper code（spec §7，12 種，含 fallback validation_failed）
 *
 * 對齊 OpenAPI ProfitShopExceptionError.code enum；後端 13 種 Domain Exception 經
 * ExceptionMapper 收斂為以下 12 種 HTTP-friendly code（多對一 mapping）。
 */
export type TProfitShopErrorCode =
	| 'not_found'
	| 'product_not_in_shop'
	| 'validation_failed'
	| 'unauthorized'
	| 'forbidden'
	| 'slug_conflict'
	| 'partner_in_use'
	| 'product_already_in_shop'
	| 'invalid_state_transition'
	| 'legacy_unimportable'
	| 'rate_limited'
	| 'internal_error'

/** Profit Shop API 錯誤回應形狀（V2Api 統一裹 {code, data} → 失敗時 code 即為 ErrorCode） */
export type TProfitShopErrorBody = {
	code: TProfitShopErrorCode | string
	message: string
	conflicts?: TSlugConflict[]
	reason?: string
	retry_after?: number
	error_id?: string
}

/** Slug 驗證 endpoint 的 data payload */
export type TSlugValidationOutput = {
	slug: string
	available: boolean
	conflicts: TSlugConflict[]
}
