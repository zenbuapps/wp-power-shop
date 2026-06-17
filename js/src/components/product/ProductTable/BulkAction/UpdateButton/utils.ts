import { isVariation } from 'antd-toolkit/wp'

import { TFormValues } from '@/components/product/ProductEditTable/types'

/**
 * WooCommerce REST v3 商品 batch 更新單筆 payload
 *
 * 對應 `POST wc/v3/products/batch` 與
 * `POST wc/v3/products/{parent_id}/variations/batch` 的 `update` 陣列元素。
 * 欄位名稱皆為 WooCommerce 原生 REST 結構（與 Powerhouse 內部欄位名不同）。
 */
export type TWcBatchUpdateItem = {
	id: number
} & Record<string, unknown>

/**
 * batch 拆分結果
 *
 * - `simpleUpdates`：所有簡單商品（非變體）的逐筆 payload，組成一個 `products/batch`。
 * - `variationGroups`：依母商品 `parent_id` 分組的變體逐筆 payload，
 *   每組各自打一個 `products/{parent_id}/variations/batch`。
 */
export type TBatchPayload = {
	simpleUpdates: TWcBatchUpdateItem[]
	variationGroups: Record<string, TWcBatchUpdateItem[]>
}

/** 以 'yes' / 'no' 表示的布林字串轉為原生布林（WooCommerce REST 需要布林值） */
const yesNoToBool = (value: unknown): boolean | undefined => {
	if (value === 'yes') {
		return true
	}
	if (value === 'no') {
		return false
	}
	return undefined
}

/** id 字串陣列轉為 WooCommerce REST 的 `[{ id: number }]` 結構 */
const toIdObjects = (ids: unknown): { id: number }[] | undefined => {
	if (!Array.isArray(ids)) {
		return undefined
	}
	return ids
		.map((id) => Number(id))
		.filter((id) => !Number.isNaN(id))
		.map((id) => ({ id }))
}

/** 僅在值有意義（非 undefined / 非空字串）時才寫入 payload，避免覆寫成空值 */
const assignIfPresent = (
	target: Record<string, unknown>,
	key: string,
	value: unknown
) => {
	if (value === undefined || value === null || value === '') {
		return
	}
	target[key] = value
}

/**
 * 將單筆 Powerhouse 形狀的表單值轉換為 WooCommerce 原生 batch update payload
 *
 * 處理 Powerhouse 與 WooCommerce 原生 REST 的欄位差異：
 * - `category_ids` / `tag_ids` → `categories` / `tags`（`[{ id }]`）
 * - 扁平的 `length` / `width` / `height` → `dimensions` 物件
 * - `'yes'` / `'no'` 字串 → 原生布林
 * - `sale_date_range` 已於上游拆為 `date_on_sale_from` / `date_on_sale_to`（Unix 時間戳）
 *
 * @param {TFormValues} fields - 單筆商品的表單值（已通過 ZFormValues 驗證）
 * @return {TWcBatchUpdateItem} WooCommerce 原生 batch update 單筆 payload
 */
export const fieldsToWcUpdateItem = (
	fields: TFormValues
): TWcBatchUpdateItem => {
	const item: TWcBatchUpdateItem = { id: Number(fields.id) }

	// 狀態與可見度
	assignIfPresent(item, 'status', fields.status)
	assignIfPresent(item, 'catalog_visibility', fields.catalog_visibility)

	// 價格
	assignIfPresent(item, 'regular_price', fields.regular_price)
	assignIfPresent(item, 'sale_price', fields.sale_price)

	// date_on_sale_from / date_on_sale_to 為 Unix 時間戳，WC_Product 的 setter 可接受
	assignIfPresent(item, 'date_on_sale_from', fields.date_on_sale_from)
	assignIfPresent(item, 'date_on_sale_to', fields.date_on_sale_to)

	// 庫存
	assignIfPresent(item, 'sku', fields.sku)
	assignIfPresent(item, 'stock_status', fields.stock_status)
	assignIfPresent(item, 'backorders', fields.backorders)
	assignIfPresent(item, 'low_stock_amount', fields.low_stock_amount)
	const manageStock = yesNoToBool(fields.manage_stock)
	if (manageStock !== undefined) {
		item.manage_stock = manageStock
	}

	// stock_quantity 可為 0，需明確判斷 undefined / null
	if (fields.stock_quantity !== undefined && fields.stock_quantity !== null) {
		item.stock_quantity = fields.stock_quantity
	}

	// 尺寸與重量
	assignIfPresent(item, 'weight', fields.weight)
	const dimensions: Record<string, string> = {}
	assignIfPresent(dimensions, 'length', fields.length)
	assignIfPresent(dimensions, 'width', fields.width)
	assignIfPresent(dimensions, 'height', fields.height)
	if (Object.keys(dimensions).length > 0) {
		item.dimensions = dimensions
	}

	// 購買備註
	assignIfPresent(item, 'purchase_note', fields.purchase_note)

	// 其他布林欄位
	const booleanFields = [
		'virtual',
		'downloadable',
		'featured',
		'sold_individually',
		'reviews_allowed',
	] as const
	booleanFields.forEach((key) => {
		const value = yesNoToBool(fields[key])
		if (value !== undefined) {
			// key 為固定字串字面量，透過 Record<string, unknown> index signature 寫入
			item[key as string] = value
		}
	})

	// 分類 / 標籤（僅非變體商品有意義，變體無分類）
	const categories = toIdObjects(fields.category_ids)
	if (categories) {
		item.categories = categories
	}
	const tags = toIdObjects(fields.tag_ids)
	if (tags) {
		item.tags = tags
	}

	return item
}

/**
 * 將批量編輯表格的所有逐筆表單值，依商品類型拆分為 WooCommerce batch payload
 *
 * 拆分規則（第一性原理：效能優先，最少 request 數）：
 * - 簡單商品（非變體）：全部收集成一個 `products/batch`。
 * - 變體商品：依母商品 `parent_id` 分組，每組各自一個 `variations/batch`。
 *
 * 同步修改模式與逐筆模式皆共用此函式——因為表格狀態（virtualFields）即真實來源，
 * 同步模式下上游已將同一份值套用至每一列，逐筆模式下每列各自帶值，
 * 兩者最終都收斂為「每一列的當前值」。
 *
 * @param {TFormValues[]} allFields - 所有商品（含展開的變體）的逐筆表單值
 * @return {TBatchPayload} 拆分後的 batch payload
 */
export const buildBatchPayload = (allFields: TFormValues[]): TBatchPayload => {
	const simpleUpdates: TWcBatchUpdateItem[] = []
	const variationGroups: Record<string, TWcBatchUpdateItem[]> = {}

	allFields.forEach((fields) => {
		const type = Array.isArray(fields.type) ? fields.type[0] : fields.type
		const updateItem = fieldsToWcUpdateItem(fields)

		if (isVariation(type)) {
			// 變體須打 products/{parent_id}/variations/batch，依 parent_id 分組
			const parentId = fields.parent_id
			if (!parentId || parentId === '0') {
				// 沒有有效母商品 id 的變體無法歸組，略過以免打錯 endpoint
				return
			}
			if (!variationGroups[parentId]) {
				variationGroups[parentId] = []
			}
			variationGroups[parentId].push(updateItem)
			return
		}

		simpleUpdates.push(updateItem)
	})

	return { simpleUpdates, variationGroups }
}
