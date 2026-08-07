import { isVariation } from 'antd-toolkit/wp'

import type { TProductRecord } from '@/components/product/types'

/**
 * 商品選擇 Modal 的選取規則（純函式）
 *
 * 從 `index.tsx` 抽出來，讓「哪些列不能勾」與「跨頁勾選怎麼累積」
 * 能被直接測到——那是 select-shop-products.feature 幾條 Rule 的全部內容，
 * 元件只是把結果接到 antd 的 `rowSelection` 上。
 */

/**
 * 單次批次加入上限
 *
 * 依據：WC REST `per_page` 上限為 100，商品顯示資訊補齊查詢一次打得完；
 * 且 ItemsEditor 每次按鍵重建整個 items 陣列，列數過多會使價格欄輸入明顯延遲。
 */
export const MAX_BATCH_SIZE = 100

/** 勾選框狀態的判定輸入 */
export type TCheckboxContext = {
	/** 已在賣場中的 product_id */
	existingIds: Set<number>

	/** 目前已選取的 row key */
	selectedKeys: Set<string | number>
}

/** 勾選框狀態 */
export type TCheckboxState = {
	disabled: boolean

	/** 停用原因；未停用時為 null。供測試與日後補 tooltip 用 */
	reason: 'variation' | 'already-in-shop' | 'batch-limit' | null
}

/**
 * 決定某一列的勾選框能不能勾
 *
 * 三個停用條件（順序即優先序，越前面越根本）：
 *   1. 變體列——賣場商品以父商品為單位，變體覆寫走 ItemsEditor 的展開列
 *   2. 已在賣場中——重複加入會讓同一個商品在賣場裡出現兩次
 *   3. 達單次批次上限——已選的仍可取消勾選，只是不能再加
 */
export const resolveCheckboxState = (
	record: Pick<TProductRecord, 'id' | 'type'>,
	{ existingIds, selectedKeys }: TCheckboxContext
): TCheckboxState => {
	if (isVariation(record?.type as string)) {
		return { disabled: true, reason: 'variation' }
	}

	/*
	 * ⚠️ `TProductRecord.id` 是 **string**、`product_id` 是 **number**。
	 * 不轉型直接 `Set.has` 永遠回 false → 已加入的商品不會被禁選 → 可重複加入。
	 * 這是 R-2，型別系統攔不到（Set<number>.has(string) 在 TS 只會抱怨型別，
	 * 而這裡的 record 來自 API 回應、通常已被 as 成寬鬆型別）。
	 */
	const productId = Number(record?.id)
	if (Number.isFinite(productId) && existingIds.has(productId)) {
		return { disabled: true, reason: 'already-in-shop' }
	}

	const isAtLimit = selectedKeys.size >= MAX_BATCH_SIZE
	if (isAtLimit && !selectedKeys.has(record?.id)) {
		return { disabled: true, reason: 'batch-limit' }
	}

	return { disabled: false, reason: null }
}

/**
 * 把新勾選的列併進既有選取集合（跨頁累積）
 *
 * ⚠️ 以 **record 物件**累積，不依賴當前頁 `dataSource`——換頁後 dataSource
 * 就沒有前一頁的 record 了，靠 antd `onChange` 的 `selectedRows` 回推會直接掉資料。
 *
 * 超過 `MAX_BATCH_SIZE` 的部分**丟棄**而非報錯：勾選框在達上限時已經 disabled，
 * 這裡是第二道防線（例如「全選」一次帶進超過剩餘額度的列）。
 */
export const mergeSelection = <T extends { id: string | number }>(
	prev: T[],
	additions: T[]
): T[] => {
	const known = new Set(prev.map((record) => record.id))
	const fresh = additions.filter((record) => !known.has(record.id))
	const room = Math.max(MAX_BATCH_SIZE - prev.length, 0)
	return [...prev, ...fresh.slice(0, room)]
}

/** 從既有選取集合移除取消勾選的列 */
export const dropSelection = <T extends { id: string | number }>(
	prev: T[],
	removing: T[]
): T[] => {
	const ids = new Set(removing.map((record) => record.id))
	return prev.filter((record) => !ids.has(record.id))
}
