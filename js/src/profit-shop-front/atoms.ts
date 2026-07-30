/**
 * 分潤賣場前台的 Jotai atom
 *
 * 照搬舊版一頁商店 `pages/PowerShopProducts/atom.tsx`
 * 與 `pages/PowerShopProducts/ProductModal/atom.tsx`（兩檔合併為單一 atoms.ts）。
 *
 * ⚠ 這些 atom 是 module-level 全域單例，只服務 `#profit_shop_front_app` 這棵樹；
 *    與 admin SPA 的 atom（例如 `selectedProductsAtom`）完全隔離，不共用任何一顆。
 */

import { atom } from 'jotai'

import { shopStatus } from './env'
import type {
	TCartData,
	TCartItem,
	TProductVariationAttribute,
	TShopStatus,
} from './types'

/* ------------------------------------------------------------------ *
 * 購物車 / 商品 Modal 狀態（原 PowerShopProducts/atom.tsx）
 * ------------------------------------------------------------------ */

/** 商品 Modal 是否開啟 */
export const isProductModalOpenAtom = atom<boolean>(false)

/** 當前 Modal 顯示的商品 ID（0 = 未選擇） */
export const modalProductIdAtom = atom<number>(0)

/** 當前選中的變體 ID（null = 尚未選齊屬性） */
export const selectedVariationIdAtom = atom<number | null>(null)

/** 當前已選的變體屬性組合（依 `variation_attributes` 的鍵順序排序） */
export const selectedAttributesAtom = atom<TProductVariationAttribute[]>([])

/**
 * 賣場販售狀態（`published` 以外都不可加入購物車）
 *
 * 對照舊版 `shopStatusAtom`，但**初始值直接取自 PHP 算好的 `settings.shopStatus`**，
 * 不像舊版還要在 `PowerShopProducts/index.tsx` 用 `dayjs()` 重算一次再 `useSetAtom` 寫入：
 * - 少一個 effect、少一次 render
 * - 避免使用者瀏覽器時鐘偏移導致前後台判斷不一致
 *
 * 仍保留成 atom（而非直接讀 env 常數），是為了讓未來「開賣倒數結束自動解鎖」
 * 這類需求可以在不動下游元件的情況下寫入新值。
 */
export const shopStatusAtom = atom<TShopStatus>(shopStatus)

/**
 * 購物車預設值
 *
 * 照搬舊版 `defaultCart`：先給滿一份完整結構，讓下游元件不必到處 optional chaining，
 * 也避免第一次 render 時 `totals` 為 undefined 造成的 NaN。
 */
export const defaultCartData: TCartData = {
	isMutating: false,
	coupons: [],
	shipping_rates: [],
	items: [],
	items_count: 0,
	items_weight: 0,
	needs_shipping: false,
	has_calculated_shipping: false,
	fees: [],
	totals: {
		total_items: '0',
		total_items_tax: '0',
		total_fees: '0',
		total_fees_tax: '0',
		total_discount: '0',
		total_discount_tax: '0',
		total_shipping: '0',
		total_shipping_tax: '0',
		total_price: '0',
		total_tax: '0',
		currency_code: 'TWD',
		currency_symbol: 'NT$',
		currency_minor_unit: 0,
		currency_decimal_separator: '.',
		currency_thousand_separator: ',',
		currency_prefix: 'NT$',
		currency_suffix: '',
	},
	errors: [],
}

/** 前端購物車狀態（樂觀更新的單一真相來源） */
export const cartDataAtom = atom<TCartData>(defaultCartData)

/* ------------------------------------------------------------------ *
 * 商品內容展開 / 收合（原 ProductModal/atom.tsx）
 * ------------------------------------------------------------------ */

/** 商品描述是否展開全部內容 */
export const isExpandAtom = atom<boolean>(false)

/** 商品描述是否高過門檻、需要顯示「展開全部內容」按鈕 */
export const showReadMoreAtom = atom<boolean>(false)

/** re-export 讓 Phase 2 只需 import 這一支就拿到型別（DX 便利，非必要） */
export type { TCartData, TCartItem, TProductVariationAttribute, TShopStatus }
