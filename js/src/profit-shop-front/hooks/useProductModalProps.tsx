/**
 * 商品 Modal 的 props 組裝
 *
 * 照搬舊版 `pages/PowerShopProducts/ProductModal/useProductModalProps.tsx`：
 * - `modalProps`：置中、無 footer、寬度 lg:1/2 且最大 960px、zIndex 1000000（壓過 theme header）
 * - `name` / `description`：渲染 HTML（description = 短描述 + 完整描述，並把 YouTube 連結轉 iframe）
 * - `plusMinusInputProps`：數量上限 = 庫存
 * - Modal 開啟時把數量重設為 1、內容收合
 * - `price`：simple 看商品層價格、variable 看選定變體價格，皆已套用賣場覆寫
 *
 * ⚠ 數量上限**不再扣掉購物車既有數量**：加購改走 WooCommerce 原生表單後，前台不再
 * 持有購物車狀態（沒有 `cartDataAtom`，也不打任何購物車 API）。超買由 WooCommerce
 * 自己在 `add_to_cart` 時擋下並顯示 notice——那本來就是庫存判斷的唯一真相，前端這層
 * 只是體驗優化。硬要在前端重建購物車狀態，等於為了一個 WooCommerce 已經做對的檢查，
 * 把整條購物車讀取通道重新接回來。
 */

import { type InputNumberProps, type ModalProps } from 'antd'
import { useAtom, useAtomValue, useSetAtom } from 'jotai'
import { useEffect } from 'react'

import {
	isExpandAtom,
	isProductModalOpenAtom,
	selectedVariationIdAtom,
} from '../atoms'
import Price from '../components/Price'
import type { TFrontProduct } from '../types'
import { formatYoutubeLinkToIframe, getStockQty, renderHTML } from '../utils'

import { usePlusMinusInput } from './usePlusMinusInput'

/**
 * 組裝商品 Modal 所需的全部 props
 *
 * @param  product 當前商品
 * @return {Object} Modal / 圖片 / 名稱 / 價格 / 描述 / 數量選擇器 props
 */
export const useProductModalProps = (product: TFrontProduct) => {
	const [isProductModalOpen, setIsProductModalOpen] = useAtom(
		isProductModalOpenAtom
	)
	const selectedVariationId = useAtomValue(selectedVariationIdAtom)

	const modalProps: ModalProps = {
		centered: true,
		footer: null,
		className: 'lg:w-1/2 lg:max-w-[960px]',
		open: isProductModalOpen,
		onCancel: () => setIsProductModalOpen(false),
		zIndex: 1000000,
	}

	const name = renderHTML(product?.name ?? '未知商品')
	const description = formatYoutubeLinkToIframe(
		`${product?.shortDescription || ''}${product?.description || ''}`
	)
	const images = product?.images ?? []
	const max = getStockQty(product, selectedVariationId)
	const { plusMinusInputProps, setPlusMinusInputProps } = usePlusMinusInput(max)

	const setIsExpand = useSetAtom(isExpandAtom)

	useEffect(() => {
		// 開關重設數量 & 將內容收合
		if (isProductModalOpen) {
			setPlusMinusInputProps({
				...plusMinusInputProps,
				value: 1,
			} as InputNumberProps<number>)
			setIsExpand(false)
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [isProductModalOpen])

	const productType = product?.type ?? 'simple'

	const getPrice = () => {
		switch (productType) {
			case 'simple':
				return (
					<Price
						salePrice={product?.salesPrice}
						regularPrice={product?.regularPrice}
					/>
				)
			case 'variable': {
				const productVariations = product?.variations ?? []
				const theVariation = productVariations.find(
					(v) => v.variation_id === selectedVariationId
				)

				return (
					<Price
						salePrice={theVariation?.salesPrice}
						regularPrice={theVariation?.regularPrice}
					/>
				)
			}
			default:
				return '找不到價格'
		}
	}

	const price = getPrice()

	return {
		modalProps,
		images,
		name,
		price,
		description,
		plusMinusInputProps,
		qty: Number(plusMinusInputProps?.value || 1),
		selectedVariationId,
	}
}
