/**
 * 分潤賣場前台環境變數
 *
 * 對照舊版一頁商店 `utils/env.tsx` 的風格：export 具名常數，讀不到全域變數時退回預設值。
 *
 * 與 admin SPA / partner portal 的差異：
 * - **不加密**：`window.power_shop_front_data` 是純物件，不需要 simpleDecrypt
 * - **不 throw**：前台是 customer-facing 頁面，任何 env 缺失都必須降級而非白屏
 *   （對比 partner portal 的 `readPartnerEnv` 會 throw 交給 ErrorBoundary）
 */

import type {
	TFrontData,
	TFrontEnv,
	TFrontProduct,
	TFrontSettings,
	TShopStatus,
} from './types'

declare global {
	interface Window {
		power_shop_front_data?: TFrontData // PHP wp_localize_script 注入（未加密純物件）
	}
}

/** env 預設值：任一欄位缺失都以此退回，確保頁面不會 crash */
const DEFAULT_ENV: TFrontEnv = {
	siteUrl: '/',
	shopId: 0,
	shopSlug: '',
}

/** settings 預設值：對齊舊版 `defaultSettings` 的顯示行為（全部開啟） */
const DEFAULT_SETTINGS: TFrontSettings = {
	colorPrimary: '#1677ff',
	showConfetti: true,
	showStock: true,
	showBuyerCount: true,
	startTime: 0,
	endTime: 0,
	shopStatus: 'published',
}

/** 安全讀取全域變數（SSR / 全域未注入時回 undefined） */
const readFrontData = (): TFrontData | undefined => {
	if (typeof window === 'undefined') return undefined
	return window.power_shop_front_data
}

const frontData = readFrontData()
const rawEnv = frontData?.env
const rawSettings = frontData?.settings

/** 站台網址 */
export const siteUrl: string = rawEnv?.siteUrl || DEFAULT_ENV.siteUrl

/**
 * 當前分潤賣場 post ID
 *
 * **加入購物車時必須以 `profit_shop_id` 送出**（見 `ProductModal/AddToCartButton.tsx`）：
 * 後端 `AddToCartHook` 只從 `$_GET` / `$_POST` 讀這個值，漏送就靜默走商品原價、
 * 分潤失效，且沒有任何錯誤訊息。
 */
export const shopId: number = Number(rawEnv?.shopId ?? 0) || 0

/** 當前分潤賣場 slug */
export const shopSlug: string = rawEnv?.shopSlug || DEFAULT_ENV.shopSlug

/** 主色（同時餵給 antd ConfigProvider 與 CSS 變數 --ps-primary） */
export const colorPrimary: string =
	rawSettings?.colorPrimary || DEFAULT_SETTINGS.colorPrimary

/** 是否顯示庫存資訊 */
export const showStock: boolean =
	rawSettings?.showStock ?? DEFAULT_SETTINGS.showStock

/** 是否顯示購買人數 */
export const showBuyerCount: boolean =
	rawSettings?.showBuyerCount ?? DEFAULT_SETTINGS.showBuyerCount

/** 開賣時間（毫秒時間戳；0 表示未設定） */
export const startTime: number = Number(rawSettings?.startTime ?? 0) || 0

/** 結束時間（毫秒時間戳；0 表示未設定） */
export const endTime: number = Number(rawSettings?.endTime ?? 0) || 0

/**
 * 賣場販售狀態（`published` / `coming` / `closed`）
 *
 * PHP 端已依起訖時間與**伺服器**時間算好，前端只讀不重算——避免瀏覽器
 * 時鐘偏移造成「後台已開賣、前台仍顯示尚未開始」。加入購物車按鈕會依此 disable。
 */
export const shopStatus: TShopStatus =
	rawSettings?.shopStatus ?? DEFAULT_SETTINGS.shopStatus

/** 賣場商品（已套用價格覆寫；PHP 已印出商品 grid，此處供 Modal / 購物車查表用） */
export const products: TFrontProduct[] = Array.isArray(frontData?.products)
	? frontData.products
	: []
