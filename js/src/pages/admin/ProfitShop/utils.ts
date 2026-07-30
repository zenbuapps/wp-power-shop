/**
 * Profit Shop 賣場商品覆寫的共用工具
 *
 * 商品層（ItemsEditor）與變體層（VariationOverrideEditor）**必須共用同一份**，
 * 否則「留空 ＝ null ＝ 沿用商品原價」與「填 0 ＝ '0' ＝ 覆寫為 0 元（免費贈品）」
 * 這組語意很容易在其中一層被寫成另一種轉換，兩者互相吃掉之後
 * 使用者設定的值會在存檔 / 讀回之間安靜地變樣。
 */

import type {
	TPriceOverride,
	TVariationOverride,
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
