/**
 * 商品 Modal
 *
 * 1:1 照搬舊版一頁商店 `pages/PowerShopProducts/ProductModal/index.tsx`：
 * 左欄 sticky 圖片畫廊、右欄商品名 / 描述 / 變體 / 庫存 / 購買人數 / 價格 / 數量 /
 * 加入購物車，以及描述過長時右側浮出的垂直「展開 ── 收合全部內容」把手。
 *
 * 版型細節全部保留（**不可調整**，任何一項都會改變舊版的視覺）：
 * - `Modal`：`centered` / `footer={null}` / `lg:w-1/2 lg:max-w-[960px]` / `zIndex 1000000`
 *   （zIndex 壓過佈景主題的 sticky header 與購物車側欄）
 * - `Row`：`gutter={24}` + `max-h-[75vh] overflow-y-auto overflow-x-hidden`
 *   （捲動容器在 Row 上，左欄 `sticky top-0` 才有作用）
 * - 左欄 `lg={{ span: 10 }}`、右欄 `lg={{ span: 14 }}`；手機 `span={24}` 上下堆疊
 * - 把手 `writingMode: 'vertical-rl'` 文字直排，md 以上以 `-right-8` 外移到 Modal 之外，
 *   md 以下貼齊右緣並改為半透明 + 陰影 + 左側圓角
 *
 * 與舊版的兩處差異（皆為必要修正，無任何視覺變化）：
 * 1. `selectedVariationId` 直接取自 `useProductModalProps` 的回傳值，
 *    不再額外 `useAtomValue(selectedVariationIdAtom)`——同一顆 atom，舊版是重複讀取
 * 2. 把手補上 `role="button"` / `tabIndex` / `onKeyDown`（`jsx-a11y` 要求可點擊元素
 *    能以鍵盤操作）；`className` 與 `style` 一字未改
 */

import { ArrowsAltOutlined, ShrinkOutlined } from '@ant-design/icons'
import { Col, Modal, Row } from 'antd'
import { useAtom, useAtomValue } from 'jotai'
import { memo } from 'react'

import { isExpandAtom, showReadMoreAtom } from '../atoms'
import BuyerCount from '../components/BuyerCount'
import Gallery from '../components/Gallery'
import PlusMinusInput from '../components/PlusMinusInput'
import ProductVariationsSelect from '../components/ProductVariationsSelect'
import StockInfo from '../components/StockInfo'
import ToggleContent from '../components/ToggleContent'
import { useProductModalProps } from '../hooks/useProductModalProps'
import type { TFrontProduct } from '../types'

import AddToCartButton from './AddToCartButton'

/** ProductModal props（契約固定，與 Phase 1 placeholder 相同） */
type TProductModalProps = {
	product: TFrontProduct
}

/** 商品 Modal 元件 */
const ProductModalComponent: React.FC<TProductModalProps> = ({ product }) => {
	const {
		modalProps,
		images,
		name,
		price,
		description,
		plusMinusInputProps,
		qty,
		selectedVariationId,
	} = useProductModalProps(product)

	const [isExpand, setIsExpand] = useAtom(isExpandAtom)
	const showReadMore = useAtomValue(showReadMoreAtom)

	const handleExpand = () => {
		setIsExpand(!isExpand)
	}

	const handleExpandKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
		if (e.key !== 'Enter' && e.key !== ' ') return
		e.preventDefault()
		handleExpand()
	}

	return (
		<Modal {...modalProps}>
			<Row
				gutter={24}
				className="max-h-[75vh] overflow-y-auto overflow-x-hidden"
			>
				<Col span={24} lg={{ span: 10 }} className="mb-4 relative">
					<div className="sticky top-0">
						<Gallery images={images} />
					</div>
				</Col>
				<Col span={24} lg={{ span: 14 }}>
					<div className="flex flex-col">
						<div>
							<div className="text-xl mb-4">{name}</div>
						</div>
						<div className="my-4">
							<ToggleContent content={description} />
						</div>

						<div>
							{product?.type === 'variable' && !!product && (
								<ProductVariationsSelect product={product} />
							)}
							<StockInfo
								product={product}
								selectedVariationId={selectedVariationId}
							/>
							<BuyerCount
								product={product}
								selectedVariationId={selectedVariationId}
							/>
						</div>

						<div className="mt-4">{price}</div>

						<div>
							<p className="mb-0 mt-4">數量</p>
							<PlusMinusInput {...plusMinusInputProps} />
							<AddToCartButton product={product} quantity={qty} />
						</div>
					</div>
				</Col>
			</Row>
			{showReadMore && (
				<div
					role="button"
					tabIndex={0}
					className="absolute bottom-24 right-0 md:-right-8 bg-white w-8 flex items-center py-3 cursor-pointer shadow md:shadow-none opacity-50 md:opacity-100 rounded-l-lg md:rounded-l-none md:rounded-r-lg"
					style={{
						writingMode: 'vertical-rl',
					}}
					onClick={handleExpand}
					onKeyDown={handleExpandKeyDown}
				>
					{isExpand ? (
						<>
							<ShrinkOutlined className="mb-2" />
							收合全部內容
						</>
					) : (
						<>
							<ArrowsAltOutlined className="mb-2" />
							展開全部內容
						</>
					)}
				</div>
			)}
		</Modal>
	)
}

const ProductModal = memo(ProductModalComponent)

export default ProductModal
