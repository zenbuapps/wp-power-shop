/**
 * Profit Shop 賣場商品覆寫的共用工具
 *
 * 商品層（ItemsEditor）與變體層（VariationOverrideEditor）**必須共用同一份**，
 * 否則「留空 ＝ null ＝ 沿用商品原價」與「填 0 ＝ '0' ＝ 覆寫為 0 元（免費贈品）」
 * 這組語意很容易在其中一層被寫成另一種轉換，兩者互相吃掉之後
 * 使用者設定的值會在存檔 / 讀回之間安靜地變樣。
 */

import dayjs, { type Dayjs } from 'dayjs'

import {
	PROFIT_SHOP_DEFAULT_DISPLAY_PARTS,
	PROFIT_SHOP_DISPLAY_PART,
	type TPriceOverride,
	type TProfitShopDisplayPart,
	type TVariationOverride,
} from '@/pages/admin/ProfitShop/types'

/** 空的價格覆寫：三欄皆 null ＝ 完全沿用商品原價 */
export const emptyPriceOverride = (): TPriceOverride => ({
	regular_price: null,
	sale_price: null,
	signup_fee: null,
})

/** 空的變體覆寫：價格三欄 null（沿用商品原價）＋ 灌水購買人數 0 */
export const emptyVariationOverride = (): TVariationOverride => ({
	override: emptyPriceOverride(),
	inflated_count: 0,
})

/**
 * 後端十進位字串 → InputNumber 值
 *
 * null / 空字串 / 非數字 → `undefined`，讓 InputNumber 顯示 placeholder（＝沿用原價）。
 * ⚠️ 不可回 0 —— `'0'`（免費贈品）與「沒設定」是兩件事。
 *
 * 🔴 **空字串必須與 null 同義**，不可交給 `Number()` 判斷：`Number('')` 是 **0**
 * （不是 NaN），少了這道守門「沒設定」會被還原成「覆寫為 0 元」——站長按下儲存
 * 就把商品變成免費贈品，而畫面上只是欄位裡多了一個 0。
 *
 * 後端對此早有一致的語意（`ProfitShopHydrator` 把空字串正規化為 null，見
 * `ProfitShopHydratorTest::test_empty_string_regular_price_is_treated_as_null`），
 * 前端這一側原本沒有對齊。回歸鎖：
 * `tests/js/priceOverrideUtils.test.ts::非數字字串視為沒設定`
 */
export const toInputValue = (price: string | null): number | undefined => {
	if (price === null || price === '') return undefined
	const parsed = Number(price)
	return Number.isFinite(parsed) ? parsed : undefined
}

/**
 * InputNumber 值 → 後端十進位字串
 *
 * 清空 / 非數字 → `null`（＝沿用商品原價）。
 * ⚠️ `0` 是合法輸入，會轉成 `'0'`，不可被當成「沒填」吃掉。
 */
export const toPriceString = (value: number | null): string | null =>
	typeof value === 'number' && Number.isFinite(value) ? String(value) : null

/**
 * 該筆覆寫是否違反「特價不得高於原價覆寫」
 *
 * 與後端 `PriceOverride` VO 同語意，但後端才是唯一真相 —— 此處只是前端第一道閘門。
 * 後端用 `bccomp` 走字串十進位比較，前端以 Number 比較足以擋住一般誤輸入。
 * 任一欄為 null（沿用原價）時不比較：真正的高低由商品原價決定，後端才判得準。
 */
export const isSaleOverRegular = (override: TPriceOverride): boolean => {
	const regular = Number(override.regular_price)
	const sale = Number(override.sale_price)
	if (override.regular_price === null || override.sale_price === null) {
		return false
	}
	if (!Number.isFinite(regular) || !Number.isFinite(sale)) return false
	return sale > regular
}

/* ------------------------------------------------------------------ *
 * 開啟 / 關閉時間（settings 的排程欄位）
 * ------------------------------------------------------------------ */

/** ScheduleTab 的表單值形狀（時間為 Dayjs，送出前才換算回毫秒） */
export type TScheduleFormValues = {
	startTime?: Dayjs | null
	endTime?: Dayjs | null
	beforeDisplay?: TProfitShopDisplayPart[]
	beforeText?: string
	afterDisplay?: TProfitShopDisplayPart[]
	afterText?: string
}

/** 後端可能回字串型別的毫秒時間戳（post meta / REST 往返會把大整數變字串） */
const toTimestampMs = (value: unknown): number => {
	const parsed = Number(value)
	return Number.isFinite(parsed) && parsed > 0 ? parsed : 0
}

/** 白名單順序即輸出順序，讓送出的內容與後端 `to_display_parts()` 一致 */
const ALL_DISPLAY_PARTS: TProfitShopDisplayPart[] = [
	PROFIT_SHOP_DISPLAY_PART.COUNTDOWN,
	PROFIT_SHOP_DISPLAY_PART.TEXT,
	PROFIT_SHOP_DISPLAY_PART.PRODUCTS,
]

/**
 * 呈現區塊正規化（與 PHP `to_display_parts()` 同一條規則）
 *
 * ⚠️ **空陣列是合法值**（站長把三個勾選框全部取消＝什麼都不顯示），與「這個 key
 * 根本不存在」（既有賣場，應退回預設的「只顯示商品」）是兩件事。判斷一定要用
 * `Array.isArray()`，**不可**用 `value?.length` 之類的真值判斷 —— 後者會讓
 * 「刻意全部取消勾選」被靜默還原成「顯示商品」，站長改了設定卻沒有反應。
 *
 * 相容舊格式：本設定最初是單選字串（`hidden` / `countdown` / `browse`），
 * 開發中的資料庫可能還留著。對映成等價的區塊組合，而不是丟掉。
 */
const toDisplayParts = (value: unknown): TProfitShopDisplayPart[] => {
	if ('string' === typeof value) {
		if ('hidden' === value) return []
		if ('countdown' === value)
			return [PROFIT_SHOP_DISPLAY_PART.COUNTDOWN, PROFIT_SHOP_DISPLAY_PART.TEXT]
		return [...PROFIT_SHOP_DEFAULT_DISPLAY_PARTS]
	}

	if (!Array.isArray(value)) return [...PROFIT_SHOP_DEFAULT_DISPLAY_PARTS]

	return ALL_DISPLAY_PARTS.filter((part) => value.includes(part))
}

/**
 * `settings` → ScheduleTab 表單值
 *
 * 時間戳 0 / 缺值 / 壞資料一律轉成 `null`（DatePicker 顯示 placeholder ＝「未設定」）。
 * ⚠️ 不可轉成 `dayjs(0)`（1970-01-01）—— 那會讓「沒設定開賣時間」看起來像
 * 「開賣時間設在 1970 年」，站長按下儲存就把垃圾值寫回去了。
 */
export const toScheduleFormValues = (
	settings: Record<string, unknown> | undefined
): TScheduleFormValues => {
	const raw = settings ?? {}
	const start = toTimestampMs(raw.startTime)
	const end = toTimestampMs(raw.endTime)

	return {
		startTime: start > 0 ? dayjs(start) : null,
		endTime: end > 0 ? dayjs(end) : null,
		beforeDisplay: toDisplayParts(raw.beforeDisplay),
		beforeText: 'string' === typeof raw.beforeText ? raw.beforeText : '',
		afterDisplay: toDisplayParts(raw.afterDisplay),
		afterText: 'string' === typeof raw.afterText ? raw.afterText : '',
	}
}

/**
 * ScheduleTab 表單值 → 合併回完整的 `settings`
 *
 * ⚠️ **必須以 `base` 展開為底**：`settings` 是自由 array，裡面還有 `btnColor` /
 * `showStock` / `showBuyerCount` / `showConfetti` 等本頁不管的欄位（以及 legacy
 * 匯入帶進來的未知 key）。只送排程欄位會把它們整批清掉，而且沒有任何錯誤訊息。
 *
 * 清空的時間一律寫 `0`（而非刪除 key）：`base` 裡可能已經有舊值，刪 key 不會覆蓋它，
 * 站長會發現「時間清掉了但存檔後又跑回來」。PHP 端把 0 正規化為「未設定」。
 */
export const mergeScheduleSettings = (
	base: Record<string, unknown> | undefined,
	values: TScheduleFormValues | undefined
): Record<string, unknown> => {
	const settings: Record<string, unknown> = { ...(base ?? {}) }
	const form = values ?? {}

	const start = form.startTime
	const end = form.endTime

	settings.startTime = start && dayjs.isDayjs(start) ? start.valueOf() : 0
	settings.endTime = end && dayjs.isDayjs(end) ? end.valueOf() : 0
	settings.beforeDisplay = toDisplayParts(form.beforeDisplay)
	settings.beforeText = (form.beforeText ?? '').trim()
	settings.afterDisplay = toDisplayParts(form.afterDisplay)
	settings.afterText = (form.afterText ?? '').trim()

	return settings
}

/**
 * 賣場商品列的顯示狀態
 *
 * 五個分支各自對應不同的畫面，**不可混為一談**：
 *   ok      補齊查詢查到了 → 名稱 / SKU / 類型 / 狀態 / 價格 / 庫存全顯示
 *   partial 剛從 Modal 加入、補齊還沒回來 → 先用 Modal 帶的名稱 / SKU 撐著
 *   pending 補齊查詢進行中 → #id + Skeleton
 *   missing 查詢**成功但查無此 id** → 商品已失效，標 Tag、移除鈕仍可用
 *   failed  查詢**失敗** → 只降級顯示 #id，不擋編輯與儲存
 */
export type TShopItemDisplayState =
	| 'ok'
	| 'partial'
	| 'pending'
	| 'missing'
	| 'failed'

/** `useShopItemProducts` 的回傳中，推導顯示狀態需要用到的部分 */
export type TEnrichResult<TProduct> = {
	map: Map<number, TProduct>
	isLoading: boolean
	isError: boolean
}

/**
 * 逐列推導顯示狀態
 *
 * 刻意**只在渲染時推導、不寫回 form value**——寫回會觸發 `onChange` 造成迴圈，
 * 且 `_` 前綴欄位本來就不屬於表單狀態機。
 *
 * 🔴 `missing` 與 `failed` 必須分開：前者是「這個商品真的不存在了」（站長該把它
 * 移除），後者是「我們這次沒查到」（商品可能好好的，只是網路或權限出問題）。
 * 混為一談會讓站長在一次查詢失敗後，把整批仍然有效的商品從賣場裡刪掉。
 */
export const resolveShopItemDisplay = <TProduct>(
	row: { product_id: number; _name?: string; _sku?: string },
	enrich: TEnrichResult<TProduct>
): { state: TShopItemDisplayState; product: TProduct | undefined } => {
	const found = enrich.map.get(row.product_id)
	if (found) return { state: 'ok', product: found }

	// 剛從 Modal 加入、補齊查詢還沒回來時，先用 Modal 已帶的名稱 / SKU 撐著。
	// 此時沒有完整 record，類型 / 狀態 / 價格 / 庫存欄仍顯示 —
	if (row._name || row._sku) {
		return { state: 'partial', product: undefined }
	}
	if (enrich.isLoading) {
		return { state: 'pending', product: undefined }
	}

	// 查詢失敗 → 只降級顯示 #id；查詢成功但查無此 id → 商品已失效。
	return {
		state: enrich.isError ? 'failed' : 'missing',
		product: undefined,
	}
}

/**
 * 變體展開列的單列
 *
 * `record` 為 `undefined` 代表**孤兒鍵**——賣場存了這個 variation_id 的覆寫，
 * 但 WC 已經沒有這個變體了（站長在商品編輯頁刪掉了它）。
 */
export type TVariationRowLike<TRecord> = {
	key: string
	variationId: number
	record?: TRecord
}

/**
 * 合併「WC 實際存在的變體」與「賣場存了但已不存在的孤兒鍵」
 *
 * 🔴 **孤兒鍵必須顯示出來，不可靜默隱藏。**
 * 它送到後端會被 `ItemValidator` 以 `InvalidVariation` 打回 400；
 * 若在這裡隱藏，使用者只會看到「儲存失敗」，卻永遠找不到、也清不掉源頭——
 * 那個賣場會從此存不了檔，而畫面上完全看不出原因。
 *
 * 孤兒排在實際變體之後：正常情況下它們是少數，排前面會把常用的列擠下去。
 *
 * @param variations WC 回傳的變體清單
 * @param value      賣場已存的變體覆寫（key 是 variation_id 字串）
 */
export const mergeVariationRows = <TRecord extends { id: number | string }>(
	variations: TRecord[],
	value: Record<string, unknown> | undefined | null
): TVariationRowLike<TRecord>[] => {
	const fromWc = variations.map((record) => ({
		key: String(record.id),
		variationId: Number(record.id),
		record,
	}))

	const known = new Set(fromWc.map((row) => row.key))
	const orphans = Object.keys(value ?? {})
		.filter((key) => !known.has(key))
		.map((key) => ({ key, variationId: Number(key) }))

	return [...fromWc, ...orphans]
}
