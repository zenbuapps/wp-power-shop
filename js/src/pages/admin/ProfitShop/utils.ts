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
 * null / 非數字 → `undefined`，讓 InputNumber 顯示 placeholder（＝沿用原價）。
 * ⚠️ 不可回 0 —— `'0'`（免費贈品）與「沒設定」是兩件事。
 */
export const toInputValue = (price: string | null): number | undefined => {
	if (price === null) return undefined
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
