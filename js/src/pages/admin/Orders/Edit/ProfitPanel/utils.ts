import type { TOrderRecord } from '@/pages/admin/Orders/List/types'
import type { TSettlementStatus } from '@/pages/admin/ProfitSettlement/types'

/**
 * 訂單頁分潤面板的純資料轉換
 *
 * 從 `index.tsx` 抽出來，讓「哪些品項算有分潤歸屬」與「合計怎麼算」
 * 兩件事能被直接測到——它們是 view-order-profit-panel.feature 三條
 * 後置 Rule 的全部內容，元件本身只是把結果畫出來。
 */

/** 訂單品項上的分潤 meta key（對齊 PHP `OrderItemSettlementRepository` 的常數） */
export const META = {
	SHOP_ID: '_profit_shop_id',
	PARTNER_TERM_ID: '_profit_partner_term_id',
	RATE: '_profit_rate',
	ACTUAL_PRICE: '_actual_price',
	PROFIT_AMOUNT: '_profit_amount',
	SETTLEMENT_STATUS: '_settlement_status',
	SETTLED_AT: '_settled_at',
} as const

/** 面板的單列（由 line item meta 組出） */
export type TProfitPanelRow = {
	order_item_id: number
	product_name: string
	shop_id: number
	partner_term_id: number
	rate: number

	/** 十進位字串，原樣顯示 */
	actual_price: string

	/** 十進位字串，原樣顯示 */
	profit_amount: string
	status: TSettlementStatus
	settled_at: number | null
	needs_manual_rate: boolean
}

export type TOrderItem = TOrderRecord['items'][number]

/** 從 line item 的 meta_data 取出指定 key 的值（找不到回 null） */
export const readMeta = (item: TOrderItem, key: string): string | null => {
	const found = item.meta_data?.find((m) => m?.key === key)
	if (!found) return null

	const { value } = found
	if (value === null || value === undefined) return null
	return String(value)
}

/**
 * 把訂單品項轉成面板列；沒有分潤歸屬的品項回 null
 *
 * 判定「有分潤歸屬」的依據是 `_profit_shop_id` 存在且為正整數——
 * 那是 `OrderLineItemSettlementHook` 驗過 HMAC 簽章之後才會寫入的第一個欄位。
 */
export const toPanelRow = (item: TOrderItem): TProfitPanelRow | null => {
	const shopId = Number(readMeta(item, META.SHOP_ID))
	if (!Number.isFinite(shopId) || shopId <= 0) return null

	const rate = Number(readMeta(item, META.RATE) ?? 0)
	const settledAtRaw = readMeta(item, META.SETTLED_AT)
	const settledAt = settledAtRaw ? Number(settledAtRaw) : null

	return {
		order_item_id: Number(item.id),
		product_name: item.name ?? '',
		shop_id: shopId,
		partner_term_id: Number(readMeta(item, META.PARTNER_TERM_ID) ?? 0),
		rate: Number.isFinite(rate) ? rate : 0,

		// 金額一律當字串處理（後端是 bcmath 十進位字串），不做任何算術
		actual_price: readMeta(item, META.ACTUAL_PRICE) ?? '0.00',
		profit_amount: readMeta(item, META.PROFIT_AMOUNT) ?? '0.00',
		status: (readMeta(item, META.SETTLEMENT_STATUS) ??
			'pending') as TSettlementStatus,
		settled_at: settledAt && Number.isFinite(settledAt) ? settledAt : null,

		// 與後端 `find_settlements()` 同一條判準：rate 為 0 代表結帳當下賣場已被刪除
		needs_manual_rate: 0 === rate,
	}
}

/**
 * 把訂單品項陣列轉成面板列（過濾掉沒有分潤歸屬的）
 *
 * 回傳空陣列代表「這張訂單完全沒有分潤歸屬」，元件據此**整個不渲染面板**——
 * 一般訂單印一個空面板只會佔版面、讓站長每次都得確認一次「這張真的沒有嗎」。
 */
export const toPanelRows = (items: unknown): TProfitPanelRow[] => {
	const list = Array.isArray(items) ? (items as TOrderItem[]) : []
	return list
		.map(toPanelRow)
		.filter((row): row is TProfitPanelRow => row !== null)
}

/**
 * 分潤合計
 *
 * ⚠️ 這是本專案唯一在前端加總分潤金額的地方，因此刻意用**字串定點加法**
 * 而非 `Number()` 累加：金額是後端以 bcmath 產出的十進位字串，
 * 用 float 相加在筆數一多時會出現 `48.00 + 180.00 = 227.99999999999997` 這種結果。
 * 這裡把兩位小數當整數（分）相加，最後再插回小數點。
 *
 * 只支援兩位小數——WooCommerce 的 `wc_get_price_decimals()` 預設就是 2，
 * 而後端 `format_money()` 也一律輸出兩位。若站台改成 3 位以上，
 * 這個合計會少算，屆時應改為由後端提供合計欄位。
 */
export const sumProfitAmount = (rows: TProfitPanelRow[]): string => {
	const cents = rows.reduce((sum, row) => {
		const [intPart = '0', decPart = ''] = String(row.profit_amount).split('.')
		const normalizedDec = `${decPart}00`.slice(0, 2)
		const value = Number(`${intPart}${normalizedDec}`)
		return sum + (Number.isFinite(value) ? value : 0)
	}, 0)

	const sign = cents < 0 ? '-' : ''
	const abs = Math.abs(cents)
	return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}
