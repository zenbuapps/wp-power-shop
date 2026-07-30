/**
 * 分潤賣場前台（/profit-shop/{slug}/）型別契約
 *
 * 兩組型別來源，前綴一律 `TFront*`：
 * 1. PHP 透過 `window.power_shop_front_data` localize 的資料（**未加密純物件**）
 * 2. 本外掛自訂 REST（`/wp-json/power-shop/profit-shop-cart*`）的購物車契約
 *
 * ⚠ 購物車型別的**欄位名刻意沿用 WooCommerce Store API 的慣例**（`items` / `totals` /
 *   `shipping_rates` / 金額為最小單位整數字串…），讓從 Store API 換成自訂 REST 時，
 *   下游元件（Cart 表格、運費列、樂觀更新）完全不必改。但**型別名不再叫 `TStoreApi*`**，
 *   因為資料來源已經不是 Store API，留著舊名會誤導後人去查 WC 文件。
 *
 * ⚠ 本 bundle 與 admin SPA 完全隔離：不得 import `@refinedev/*` / dataProvider / admin 型別。
 */

/* ------------------------------------------------------------------ *
 * PHP localize 契約（window.power_shop_front_data）
 * ------------------------------------------------------------------ */

/** 前台環境變數（由 PHP 注入，未加密） */
export type TFrontEnv = {
	siteUrl: string // 站台網址（untrailingslashit）

	/** 結帳頁網址 */
	checkoutUrl: string

	/**
	 * 自訂購物車 REST 根網址（untrailingslashit）
	 *
	 * 例：`https://site.com/wp-json/power-shop/profit-shop-cart`
	 * （固定連結為「樸素」時 PHP 會給 `https://site.com/?rest_route=/power-shop/profit-shop-cart`，
	 * 兩種形式都能直接接上 `/nonce`、`/add-item` 這類子路徑）。
	 */
	cartApiUrl: string

	/** 當前分潤賣場 post ID */
	shopId: number

	/** 當前分潤賣場 slug */
	shopSlug: string

	/** wp_create_nonce('wp_rest')，所有購物車請求以 `X-WP-Nonce` header 帶出 */
	nonce: string
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

/* ------------------------------------------------------------------ *
 * 自訂購物車 REST 契約（/wp-json/power-shop/profit-shop-cart*）
 * ------------------------------------------------------------------ */

/** 已選變體屬性（`{ name: 'attribute_pa_color', value: 'red' }`） */
export type TProductVariationAttribute = {
	name: string
	value: string
}

/** 購物車總計（金額皆為「最小單位整數字串」，需除以 10^currency_minor_unit） */
export type TFrontCartTotals = {
	total_items: string
	total_items_tax: string
	total_fees: string
	total_fees_tax: string
	total_discount: string
	total_discount_tax: string
	total_shipping: string
	total_shipping_tax: string
	total_price: string
	total_tax: string
	currency_code: string
	currency_symbol: string
	currency_minor_unit: number
	currency_decimal_separator: string
	currency_thousand_separator: string
	currency_prefix: string
	currency_suffix: string
}

/** 單一運送方式（`shipping_rates[].shipping_rates[]`） */
export type TShippingRates = {
	rate_id: string
	name: string
	price: string
	selected: boolean
	description?: string
	delivery_time?: string
	taxes?: string
	instance_id?: number
	method_id?: string
	meta_data?: { key: string; value: string }[]
	currency_code?: string
	currency_minor_unit?: number
}

/** 運送包裹（`shipping_rates` 是「包裹」陣列，不是費率陣列） */
export type TFrontShippingPackage = {
	package_id: number
	name: string
	destination: {
		address_1: string
		address_2: string
		city: string
		state: string
		postcode: string
		country: string
	}
	items: { key: string; name: string; quantity: number }[]
	shipping_rates: TShippingRates[]
}

/** 購物車商品圖片 */
export type TCartItemImage = {
	src: string
	id?: number
	thumbnail?: string
	srcset?: string
	sizes?: string
	name?: string
	alt?: string
}

/** 購物車商品的變體標記（`{ attribute: '顏色', value: '紅' }`） */
export type TCartItemVariation = {
	attribute: string
	value: string
}

/** 購物車項目單價（金額皆為「最小單位整數字串」） */
export type TFrontCartItemPrices = {
	price: string
	regular_price: string
	sale_price: string
	currency_code: string
	currency_symbol: string
	currency_minor_unit: number
	currency_decimal_separator: string
	currency_thousand_separator: string
	currency_prefix: string
	currency_suffix: string
}

/** 購物車項目小計（金額皆為「最小單位整數字串」） */
export type TFrontCartItemTotals = {
	line_subtotal: string
	line_subtotal_tax: string
	line_total: string
	line_total_tax: string
	currency_code: string
	currency_minor_unit: number
}

/** 後端回傳的原始購物車項目 */
export type TFrontCartItem = {
	key: string
	id: number
	quantity: number
	name: string
	short_description: string
	description: string
	images: TCartItemImage[]
	variation: TCartItemVariation[]
	sku?: string
	permalink?: string
	type?: string
	prices?: TFrontCartItemPrices
	totals?: TFrontCartItemTotals
}

/** 優惠券 */
export type TFrontCoupon = {
	code: string
	discount_type?: string
}

/** 額外費用 */
export type TFrontFee = {
	id: string
	name: string
	totals?: Record<string, unknown>
}

/** `GET /profit-shop-cart` 的 `data` 內容（mutation 也回同一份完整購物車） */
export type TFrontCart = {
	items: TFrontCartItem[]
	items_count: number
	items_weight: number
	needs_shipping: boolean
	has_calculated_shipping: boolean
	shipping_rates: TFrontShippingPackage[]
	totals: TFrontCartTotals
	coupons: TFrontCoupon[]
	fees: TFrontFee[]
	errors: unknown[]
}

/**
 * 前端使用的購物車項目（照搬舊版 `TCartItem`）
 *
 * `quantity_raw` 用來儲存原始數量，樂觀更新失敗時回復用。
 * `isMutating` 讓單列可獨立顯示 pulse 動畫。
 */
export type TCartItem = {
	key: string
	id: number
	quantity: number

	/** 用來儲存原始數量，用於回復數量 */
	quantity_raw: number
	name: string
	short_description: string
	description: string
	images: TCartItemImage[]
	variation: TCartItemVariation[]
	isMutating: boolean
}

/** 前端購物車狀態（照搬舊版 `TCartData`） */
export type TCartData = Omit<TFrontCart, 'items'> & {
	items: TCartItem[]
	isMutating: boolean
}

/* ------------------------------------------------------------------ *
 * 自訂 REST 回應包裝
 * ------------------------------------------------------------------ */

/**
 * 成功回應的統一包裝
 *
 * 後端一律回 `{ code: 'success', message, data }`；`api.ts` 的每個函式都會
 * **剝掉這層外殼只回 `data`**，呼叫端拿到的永遠是 payload 本身。
 */
export type TFrontApiResponse<T> = {
	code: string
	message: string
	data: T
}

/** `GET /profit-shop-cart/nonce` 的 `data` 內容 */
export type TFrontNoncePayload = {
	nonce: string
}

/**
 * 購物車寫入類 endpoint 的 `data` 內容
 *
 * 加購 / 改量 / 移除 / 選運費一律回**完整購物車**，讓前端不必再補一次 GET；
 * 加購另外在同一層附上 `cart_item_key`（WooCommerce 產生的購物車項目鍵）。
 */
export type TFrontCartMutationResult = TFrontCart & {
	cart_item_key?: string
}

/** 失敗回應的 body（HTTP 4xx / 5xx，只有 `code` 與 `message`，沒有 `data`） */
export type TFrontApiErrorBody = {
	code?: string
	message?: string
}
