/**
 * 購物車讀取 hook
 *
 * 對應舊版 `pages/PowerShopProducts/Cart/index.tsx` 裡的
 * `useOne({ resource: 'cart', dataProvider: 'wc-store' })`，
 * 但**不引入 Refine**：直接用 TanStack Query v4 的 `useQuery` 打本外掛自訂的
 * `GET /wp-json/power-shop/profit-shop-cart`。
 *
 * 唯一的副作用：`isFetching` 落回 false → 把 items 正規化（補 `quantity_raw` /
 * `isMutating`）寫入 `cartDataAtom`。
 *
 * 「等 `isFetching` 為 false 才寫入」是刻意的：`cartDataAtom` 是樂觀更新的單一真相來源，
 * 若在 refetch 進行中就覆蓋，會把使用者剛按下的加減數量蓋回伺服器舊值。
 */

import { useQuery, useQueryClient } from '@tanstack/react-query'
import type { AxiosError } from 'axios'
import { useSetAtom } from 'jotai'
import { useEffect } from 'react'

import { fetchCart } from '../api'
import { cartDataAtom } from '../atoms'
import type { TCartItem, TFrontCart } from '../types'

/** 購物車 queryKey（mutation 成功後用它 invalidate） */
export const CART_QUERY_KEY = ['profit-front-cart'] as const

/** 把後端的原始 items 正規化為前端使用的 `TCartItem[]` */
const normalizeItems = (raw: TFrontCart | undefined): TCartItem[] => {
	const items = raw?.items ?? []
	return items.map((item) => ({
		key: item.key,
		id: item.id,
		quantity: item.quantity,
		quantity_raw: item.quantity,
		name: item.name,
		short_description: item.short_description,
		description: item.description,
		images: item.images ?? [],
		variation: item.variation ?? [],
		isMutating: false,
	}))
}

/**
 * 讀取購物車並同步到 atom
 *
 * @return {Object} 購物車原始資料與查詢狀態
 */
export const useCart = () => {
	const setCartData = useSetAtom(cartDataAtom)

	const query = useQuery<TFrontCart, AxiosError>({
		queryKey: CART_QUERY_KEY,
		queryFn: fetchCart,
		retry: 0,
		refetchOnWindowFocus: false,
	})

	const { isFetching, data: rawCart } = query

	// 取回資料（且不在 fetching 中）→ 正規化寫入 cartDataAtom
	useEffect(() => {
		if (isFetching) return
		if (!rawCart) return

		setCartData({
			...rawCart,
			items: normalizeItems(rawCart),
			isMutating: false,
		})
	}, [isFetching, rawCart, setCartData])

	return {
		/** 後端原始購物車（含 totals / shipping_rates） */
		rawCart,
		isFetching,
		isLoading: query.isLoading,
		isError: query.isError,
		error: query.error,
		refetch: query.refetch,
	}
}

/**
 * 取得「讓購物車查詢失效」的函式
 *
 * 供加購 / 移除 / 更新 mutation 在 onSuccess 呼叫，
 * 避免各處各自組 queryKey 字串（DRY）。
 *
 * @return {Function} invalidate 函式
 */
export const useInvalidateCart = () => {
	const queryClient = useQueryClient()
	return () => queryClient.invalidateQueries({ queryKey: CART_QUERY_KEY })
}
