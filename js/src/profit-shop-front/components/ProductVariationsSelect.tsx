/**
 * 變體屬性選擇器
 *
 * 照搬舊版 `components/ProductVariationsSelect/index.tsx`：
 * 每個屬性一排按鈕，點選後按 `variation_attributes` 的鍵順序排序已選屬性，
 * 再逐一比對 `variations[].attributes` 找出對應的 `variation_id`。
 *
 * 比對時兩邊都做 `decodeURIComponent` + `toLowerCase`——WooCommerce 的
 * `attributes` 鍵是 `attribute_pa_color` 形式且值可能 URL-encoded。
 *
 * Modal 開啟時重設已選屬性（`isProductModalOpenAtom` 變 true）。
 */

import { CheckCircleFilled, CloseCircleFilled } from '@ant-design/icons'
import { Button } from 'antd'
import { useAtom, useAtomValue } from 'jotai'
import { sortBy, toArray } from 'lodash-es'
import { memo, useEffect } from 'react'

import {
	isProductModalOpenAtom,
	selectedAttributesAtom,
	selectedVariationIdAtom,
} from '../atoms'
import type { TFrontProduct } from '../types'

/** 安全 decode：非法百分號序列時原樣回傳，不 throw */
const safeDecode = (str: string): string => {
	try {
		return decodeURIComponent(str)
	} catch {
		return str
	}
}

/** ProductVariationsSelect props */
type TProductVariationsSelectProps = {
	product: TFrontProduct
}

/** 變體屬性選擇器元件 */
const ProductVariationsSelectComponent: React.FC<
	TProductVariationsSelectProps
> = ({ product }) => {
	const [selectedVariationId, setSelectedVariationId] = useAtom(
		selectedVariationIdAtom
	)
	const [selectedAttributes, setSelectedAttributes] = useAtom(
		selectedAttributesAtom
	)
	const isProductModalOpen = useAtomValue(isProductModalOpenAtom)

	const variations = product?.variations ?? []
	const formattedAttributes = product?.variation_attributes ?? {}

	const handleClick = (attributeName: string, option: string) => () => {
		const otherSelectedAttribute = selectedAttributes.filter(
			(item) => item.name !== attributeName
		)
		const itemToBeAdded = {
			name: attributeName,
			value: option ?? '',
		}
		const newSelected = [...otherSelectedAttribute, itemToBeAdded]
		const order = Object.keys(formattedAttributes).map((a) => a)
		const sortedNewSelected = sortBy(newSelected, (item) => {
			const index = order.indexOf(item.name)
			return index !== -1 ? index : Infinity
		})

		setSelectedAttributes(sortedNewSelected)

		const theVariation = variations.find((v) => {
			const theAttributes = v?.attributes ?? {}

			return Object.keys(theAttributes).every((a) => {
				const theSelectedAttribute = sortedNewSelected.find(
					(s) =>
						`attribute_${safeDecode(s.name.toLowerCase())}` ===
						safeDecode(a.toLowerCase())
				)

				return theSelectedAttribute?.value === theAttributes[a]
			})
		})

		if (theVariation) {
			setSelectedVariationId(theVariation.variation_id)
		} else {
			setSelectedVariationId(null)
		}
	}

	useEffect(() => {
		if (isProductModalOpen) {
			setSelectedAttributes([])
			setSelectedVariationId(null)
		}
	}, [isProductModalOpen, setSelectedAttributes, setSelectedVariationId])

	return (
		<>
			{Object.keys(formattedAttributes).map((attributeName) => {
				const options = toArray(formattedAttributes[attributeName])
				const selectedOption = selectedAttributes.find(
					(item) => item.name === attributeName
				) ?? { name: '', value: '' }

				return (
					<div key={attributeName} className="mb-4">
						<p className="mb-0">
							{attributeName === 'pa_color' ? '顏色' : attributeName}
						</p>
						<div className="flex flex-wrap">
							{options.map((option) => (
								<Button
									key={option}
									type={selectedOption.value === option ? 'primary' : 'default'}
									onClick={handleClick(attributeName, option)}
									size="small"
									className="mr-1 mb-1"
								>
									<span className="text-xs">{safeDecode(option)}</span>
								</Button>
							))}
						</div>
					</div>
				)
			})}

			{!selectedVariationId && (
				<p className="m-0 text-gray-500 text-xs">
					<CloseCircleFilled className="mr-2 text-red-500" />
					未選擇商品屬性
				</p>
			)}
			{!!selectedVariationId && (
				<p className="m-0 text-gray-500 text-xs">
					<CheckCircleFilled className="mr-2 text-green-500" />
					已選擇商品屬性
				</p>
			)}
		</>
	)
}

const ProductVariationsSelect = memo(ProductVariationsSelectComponent)

export default ProductVariationsSelect
