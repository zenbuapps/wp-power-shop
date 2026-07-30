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
 * 賣場前台 URL 前綴（永久連結用）
 *
 * ⚠️ 必須與 PHP `RewriteRules::SHOP_REWRITE_PREFIX` 一致
 * （`inc/classes/Domains/ProfitShop/Infrastructure/WordPress/RewriteRules.php:36`）。
 * 前台網址形如 `{SITE_URL}/profit-shop/{slug}/`。改前綴時兩邊都要動。
 *
 * 注意這與 admin SPA 的 `#/profit-shop/...` 路由**同名但無關** —— 後者是
 * `resources/index.tsx` 定義的前端路由，不是 WordPress rewrite。
 */
export const PROFIT_SHOP_URL_PREFIX = 'profit-shop'

/**
 * 價格覆寫（三欄皆 nullable，null 代表「不覆寫該欄位」＝沿用商品原價）
 *
 * 對應 OpenAPI PriceOverride 與 PHP `Domain\ValueObject\PriceOverride`。
 *
 * ⚠️ 型別是 `string | null` 而非 `number | null`：後端 VO 以十進位字串保存，
 * 並用 `bccomp` 做字串比較避免浮點精度誤差（PriceOverride.php:66）。
 */
export type TPriceOverride = {
	regular_price: string | null
	sale_price: string | null
	signup_fee: string | null
}

/**
 * 單一 variation 的覆寫設定
 *
 * 兩個欄位的型別刻意與商品層一致，語意也完全相同：
 *   - `override` 三欄皆 `string | null`（後端 `PriceOverride` VO 存十進位字串，
 *     用 `bccomp` 比較避免浮點誤差）；`null` ＝沿用商品原價，`'0'` ＝覆寫為 0 元
 *   - `inflated_count` 是 `number`（後端 `InflatedCount` VO，負數會被 `max(0, …)` 夾住）
 *
 * ⚠️ DB 內既有資料的 `variations[vid]` 只有 `override`、沒有 `inflated_count`
 * （此欄位是後來才加的）。後端反序列化用 `?? 0` 接住，前端在 Edit 填表時
 * 一併正規化（見 `Edit/index.tsx`），避免 `InputNumber` 讀到 `undefined`
 * 從受控變非受控。
 */
export type TVariationOverride = {
	override: TPriceOverride
	inflated_count: number
}

/**
 * variation_id => { override, inflated_count } 對照表
 *
 * key 以 `string` 表達：JSON 物件鍵一律是字串。後端 variations 為空時
 * PHP `wp_json_encode([])` 會序列化成 `[]`（空陣列）而非 `{}`，
 * `Object.entries([])` 同樣得到 `[]`，兩種形狀送回 PHP 都會被 `is_array()` 接受。
 */
export type TVariationOverrideMap = Record<string, TVariationOverride>

/**
 * 賣場商品覆寫項目（Output）
 *
 * 對應 OpenAPI OverrideItemOutput 與 PHP `Domain\Entity\OverrideItem::to_array()`。
 *
 * ⚠️ BUG-A 修補（2026-07-29）：本型別原先是扁平的
 * `{ product_id, variation_id, price_override: number | null, inflated_count }`，
 * 與後端形狀不符 —— `ProfitShopHydrator::build_override_item()` 只讀巢狀的
 * `override` / `variations`（ProfitShopHydrator.php:113-128），
 * 導致前端送出的價格覆寫在儲存時被靜默丟棄。扁平欄位已全面移除，勿再加回。
 *
 * ⚠️ 不含 sku / name / image：商品名稱與 SKU 屬「商品當下狀態」而非賣場設定，
 * 夾帶會造成商品改名後賣場顯示舊名的 stale 問題。前端改以 wc-rest
 * `GET /wc/v3/products?include=…` 批次補齊（見 TShopItemRow）。
 */
export type TOverrideItem = {
	product_id: number
	inflated_count: number
	override: TPriceOverride
	variations: TVariationOverrideMap
}

/** 賣場商品覆寫項目（Input）；僅 product_id 必填 */
export type TOverrideItemInput = {
	product_id: number
	inflated_count?: number
	override?: TPriceOverride
	variations?: TVariationOverrideMap
}

/**
 * ItemsEditor 專用的列型別（UI-only）
 *
 * `_` 前綴欄位是 wc-rest 補齊查詢的顯示結果，**不屬於後端契約**，
 * Edit 頁送出前必須 strip 掉（見 Edit/index.tsx 的 handleSubmit）。
 */
export type TShopItemRow = TOverrideItem & {
	_name?: string
	_sku?: string
	_enrichState?: 'pending' | 'ok' | 'missing' | 'failed'
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
