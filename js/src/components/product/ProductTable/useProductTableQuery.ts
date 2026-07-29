import { useTable } from '@refinedev/antd'
import { HttpError } from '@refinedev/core'
import {
	TProductFilterProps,
	onProductSearch as onSearch,
	objToCrudFilters,
	initialFilteredValues,
} from 'antd-toolkit/refine'

import { TProductRecord } from '@/components/product/types'

/**
 * useProductTableQuery 參數
 *
 * `syncWithLocation`：是否把 filters / pagination 同步進網址。
 *
 * **不傳（`undefined`）代表繼承 Refine 全域設定**（`App1.tsx` 設為 `true`），
 * 這正是 `#/products` 要的行為 —— 別為了「明確」而在 caller 傳 `true`，
 * 也別在本 hook 內給預設值，否則就跟繼承語意打架了。
 *
 * 內嵌在 Modal 裡使用時必須明確傳 `false`，
 * 否則 Modal 的篩選 / 分頁會污染宿主頁面的網址（風險 R-1）。
 */
type TUseProductTableQueryOpts = {
	syncWithLocation?: boolean
}

/**
 * 商品列表的共用 useTable 設定
 *
 * 由 `#/products` 外殼與 ProductPickerModal 共用，確保兩邊的 resource /
 * 搜尋行為 / 預設篩選 / 分頁大小完全一致，差異只留 `syncWithLocation` 一項。
 */
export const useProductTableQuery = (opts?: TUseProductTableQueryOpts) =>
	useTable<TProductRecord, HttpError, TProductFilterProps>({
		resource: 'products',
		onSearch,
		filters: {
			initial: objToCrudFilters(initialFilteredValues),
			permanent: objToCrudFilters({
				meta_keys: ['_variation_description'],
			}),
			defaultBehavior: 'replace',
		},
		pagination: {
			pageSize: 20,
		},

		// 用條件展開而不是 `syncWithLocation: opts?.syncWithLocation`：
		// 後者會把 key 帶成 undefined，是否等同「沒傳」得看 Refine 內部怎麼解析。
		// 這樣寫讓 key 在未指定時「根本不存在」，按構造與抽取前的原始呼叫完全一致。
		...(undefined === opts?.syncWithLocation
			? {}
			: { syncWithLocation: opts.syncWithLocation }),
	})
