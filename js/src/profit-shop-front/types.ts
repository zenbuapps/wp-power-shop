/**
 * 分潤賣場前台（/profit-shop/{slug}/）型別契約
 *
 * 型別來源只有一組（前綴一律 `TFront*`）：PHP 透過 `window.power_shop_front_data`
 * localize 的資料（**未加密純物件**）。
 *
 * ⚠ 本前台**不打任何購物車 API**。加入購物車走 WooCommerce 原生表單送出
 * （見 `ProductModal/AddToCartButton.tsx`），購物車與結帳一律由 WooCommerce
 * 自己的頁面負責，因此這裡沒有任何購物車 / 運費 / 總計的型別。
 *
 * ⚠ 本 bundle 與 admin SPA 完全隔離：不得 import `@refinedev/*` / dataProvider / admin 型別。
 */

/* ------------------------------------------------------------------ *
 * PHP localize 契約（window.power_shop_front_data）
 * ------------------------------------------------------------------ */

/** 前台環境變數（由 PHP 注入，未加密） */
export type TFrontEnv = {
	siteUrl: string // 站台網址（untrailingslashit）

	/** 當前分潤賣場 post ID（加購時以 `profit_shop_id` 送出，分潤定價的唯一依據） */
	shopId: number

	/** 當前分潤賣場 slug */
	shopSlug: string
}

/** 前台顯示設定（由賣場 / 全域設定決定） */
export type TShopStatus = 'published' | 'coming' | 'closed'

export type TFrontSettings = {
	colorPrimary: string // 主色（antd ConfigProvider colorPrimary 與 --ps-primary 同源）

	/** 加入購物車是否撒彩帶 */
	showConfetti: boolean

	/** 是否顯示庫存資訊 */
	showStock: boolean

	/** 是否顯示購買人數 */
	showBuyerCount: boolean

	/** 開賣時間（毫秒時間戳；0 表示未設定） */
	startTime: number

	/** 結束時間（毫秒時間戳；0 表示未設定） */
	endTime: number

	/**
	 * 賣場販售狀態
	 *
	 * 照搬舊版 `TShopStatus`，但**由 PHP 依起訖時間與伺服器時間算好後帶入**，
	 * 不在前端用 `dayjs()` 重算——否則使用者的瀏覽器時鐘偏移會造成
	 * 「後台已開賣、前台仍顯示尚未開始」這類無法重現的客訴。
	 */
	shopStatus: TShopStatus
}

/** 庫存資訊（商品層或變體層共用） */
export type TStockInfo = {
	manageStock: boolean // 是否啟用庫存管理

	/** 庫存數量（未啟用庫存管理時為 null） */
	stockQuantity: number | null

	/** instock / outofstock / onbackorder */
	stockStatus: string
}

/** 變體圖片（WC `variation_form` 格式的子集） */
export type TFrontVariationImage = {
	src?: string
	full_src?: string
	alt?: string
}

/** 商品變體（已套用賣場價格覆寫） */
export type TFrontVariation = {
	variation_id: number // 變體 post ID

	/** 原價（已套用賣場覆寫），字串保留十進位精度 */
	regularPrice: string | number | null

	/** 特價（已套用賣場覆寫），字串保留十進位精度 */
	salesPrice: string | number | null

	/** 例：{ attribute_pa_color: 'red' } */
	attributes: Record<string, string>

	/** 變體庫存 */
	stock: TStockInfo

	/** 賣場設定的灌水購買人數 */
	extraBuyerCount: number
	display_price?: number
	display_regular_price?: number
	image?: TFrontVariationImage
	max_qty?: string | number
	min_qty?: number
	is_in_stock?: boolean
	is_purchasable?: boolean
	sku?: string
	variation_description?: string
	price_html?: string
}

/** 賣場商品（已套用賣場價格覆寫） */
export type TFrontProduct = {
	id: number

	/** 'simple' | 'variable' | 'grouped' | ... */
	type: string
	name: string

	/** 已過 wpautop 的 HTML */
	description: string

	/** 已過 wpautop 的 HTML */
	shortDescription: string

	/** 主圖在 [0]，其後為 gallery */
	images: string[]
	sku: string
	stock: TStockInfo
	is_sold_individually: boolean
	is_in_stock: boolean
	is_purchasable: boolean
	backorders: 'no' | 'notify' | 'yes'
	total_sales: number

	/** 原價（已套用賣場覆寫） */
	regularPrice: string | number | null

	/** 特價（已套用賣場覆寫） */
	salesPrice: string | number | null

	/** 賣場設定的灌水購買人數 */
	extraBuyerCount: number
	variations?: TFrontVariation[]

	/** 例：{ pa_color: ['red', 'blue'] } */
	variation_attributes?: Record<string, string[]>
}

/** window.power_shop_front_data 完整形狀 */
export type TFrontData = {
	env: TFrontEnv
	settings: TFrontSettings
	products: TFrontProduct[]
}

/**
 * 使用者在 Modal 內已選的單一變體屬性
 *
 * ⚠ `name` **沒有** `attribute_` 前綴（例：`pa_color`）——它直接取自
 * `TFrontProduct.variation_attributes` 的鍵，而那是 WooCommerce
 * `get_variation_attributes()` 的原始輸出。真正要送給 WooCommerce 的欄位名是
 * `attribute_{name}`，但**不要**自行加前綴組出來：自訂（非 taxonomy）屬性的名稱
 * 經 WC `sanitize_title()` 後可能與原始名稱不同（中文屬性會變成 percent-encoded），
 * 自行組鍵會在那些商品上安靜地對不上。加購時一律以選中變體的 `attributes` 鍵為準，
 * 見 `ProductModal/AddToCartButton.tsx`。
 */
export type TProductVariationAttribute = {
	name: string
	value: string
}
