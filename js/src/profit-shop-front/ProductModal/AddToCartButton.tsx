/**
 * 加入購物車按鈕（WooCommerce 原生表單送出）
 *
 * 動態建立一張隱藏 `<form method="post">` 送到**當前網址**，欄位與 WooCommerce
 * 單品頁 `add-to-cart` 表單完全相同，交給 `WC_Form_Handler::add_to_cart_action()`
 * （掛在 `wp_loaded`）處理，之後由 WooCommerce 自己決定要 redirect 回本頁還是
 * 購物車頁（`woocommerce_cart_redirect_after_add` 設定）。
 *
 * 送出的欄位：
 *
 * | 欄位 | 值 | 說明 |
 * |------|----|------|
 * | `add-to-cart` | **父商品** ID | 變體商品也是送父 ID（對齊 WC `variable.php` 樣板） |
 * | `quantity` | 數量選擇器的值 | |
 * | `variation_id` | 已選變體 ID | 只有 variable 商品才送 |
 * | `attribute_*` | 變體屬性值 | 只有 variable 商品才送，鍵取自 WC 自己回的 `attributes` |
 * | `profit_shop_id` | 賣場 post ID | **分潤定價的唯一依據**，見下方說明 |
 *
 * ⚠ `profit_shop_id` 必須送：`AddToCartHook`（priority 5）只從 `$_GET` / `$_POST`
 * 讀這個值，讀不到就**靜默走商品原價、分潤失效、沒有任何錯誤訊息**。表單是
 * `method="post"`，PHP 會正常填 `$_POST`，因此三道閘門（賣場存在 / `status=publish` /
 * 商品屬於該賣場）會照常驗證——這條路徑與 template 的 `<noscript>` 降級加購、
 * Phase 4-C1 的 query string 加購完全同源。
 *
 * ⚠ `attribute_*` 的**鍵直接取自選中變體的 `attributes` 物件**（WC
 * `get_available_variations()` 回的原始鍵，形如 `attribute_pa_color`），不是從
 * `selectedAttributesAtom` 的 `name` 自行加前綴組出來的。`selectedAttributes` 的
 * `name` 沒有 `attribute_` 前綴，且自訂（非 taxonomy）屬性的名稱經 WC
 * `sanitize_title()` 後可能與原始名稱不同（中文屬性會變成 percent-encoded），
 * 自行組鍵會在那些商品上安靜地對不上而被 WC 判為「缺少屬性」。變體本身帶的鍵
 * 才是 WC 認得的權威值。變體某個屬性值為空字串時（"任意" 變體）才退回使用者
 * 實際選的值。
 *
 * 與 Phase 10 / 11 版本的差異：不再有樂觀更新、彩帶、通知與購物車 atom——
 * 送出後整頁會重新載入，那些效果都來不及被看到。加購結果改由 WooCommerce 的
 * notice 呈現（template 已印出 `wc_print_notices()`）。
 */

import { Button } from 'antd'
import { useAtomValue } from 'jotai'
import { memo, useState } from 'react'

import {
	selectedAttributesAtom,
	selectedVariationIdAtom,
	shopStatusAtom,
} from '../atoms'
import { shopId } from '../env'
import type {
	TFrontProduct,
	TProductVariationAttribute,
	TShopStatus,
} from '../types'
import { getStockQty } from '../utils'

/** AddToCartButton props */
type TAddToCartButtonProps = {
	product: TFrontProduct

	/** 使用者在數量選擇器選的件數 */
	quantity: number
}

/**
 * 在表單中加入一個隱藏欄位
 *
 * @param form  目標表單
 * @param name  欄位名
 * @param value 欄位值
 */
const appendHiddenInput = (
	form: HTMLFormElement,
	name: string,
	value: string
): void => {
	const input = document.createElement('input')
	input.type = 'hidden'
	input.name = name
	input.value = value
	form.appendChild(input)
}

/**
 * 組出變體商品要送的 `attribute_*` 欄位
 *
 * 鍵一律取自選中變體自己的 `attributes`（WC 的權威格式）；值以變體的值優先，
 * 只有在變體該欄位是空字串（代表「任意」）時才退回使用者實際選的值。
 *
 * 變體查不到時（理論上不會發生，`selectedVariationId` 就是從 `variations` 比對來的）
 * 退回以 `selectedAttributes` 自行組鍵，至少讓加購有機會成功而不是直接送出空表單。
 *
 * @param  product             商品
 * @param  selectedVariationId 已選變體 ID
 * @param  selectedAttributes  使用者已選的屬性組合
 * @return {Record<string, string>} 欄位名 → 值
 */
const buildVariationFields = (
	product: TFrontProduct,
	selectedVariationId: number,
	selectedAttributes: TProductVariationAttribute[]
): Record<string, string> => {
	const variation = product?.variations?.find(
		(v) => v.variation_id === selectedVariationId
	)
	const attributes = variation?.attributes ?? {}
	const fields: Record<string, string> = {}

	Object.entries(attributes).forEach(([key, value]) => {
		const picked =
			selectedAttributes.find(
				(a) => `attribute_${a.name}`.toLowerCase() === key.toLowerCase()
			)?.value ?? ''

		fields[key] = value || picked
	})

	if (Object.keys(fields).length > 0) return fields

	selectedAttributes.forEach((a) => {
		fields[`attribute_${a.name.toLowerCase()}`] = a.value
	})

	return fields
}

/** 加入購物車按鈕元件 */
const AddToCartButtonComponent: React.FC<TAddToCartButtonProps> = ({
	product,
	quantity,
}) => {
	const selectedVariationId = useAtomValue(selectedVariationIdAtom)
	const selectedAttributes = useAtomValue(selectedAttributesAtom)
	const shopStatus = useAtomValue(shopStatusAtom)

	// 表單送出後整頁會重新載入，這段期間按鈕維持 loading 以避免連點送出多張表單
	const [isSubmitting, setIsSubmitting] = useState(false)

	const productId = product?.id

	const handleClick = () => {
		if (isSubmitting) return
		setIsSubmitting(true)

		const form = document.createElement('form')
		form.method = 'post'
		form.action = window.location.href
		form.style.display = 'none'

		appendHiddenInput(form, 'add-to-cart', String(productId))
		appendHiddenInput(form, 'quantity', String(quantity))

		// shopId 為 0 代表 PHP localize 缺失（理論上不會發生）。此時寧可不送，
		// 讓 WooCommerce 以商品原價加購，也不要送一個必然通不過閘門的假值。
		if (shopId > 0) {
			appendHiddenInput(form, 'profit_shop_id', String(shopId))
		}

		if (selectedVariationId) {
			appendHiddenInput(form, 'variation_id', String(selectedVariationId))

			const variationFields = buildVariationFields(
				product,
				selectedVariationId,
				selectedAttributes ?? []
			)

			Object.entries(variationFields).forEach(([name, value]) => {
				appendHiddenInput(form, name, value)
			})
		}

		document.body.appendChild(form)
		form.submit()
	}

	const stockQty = getStockQty(product, selectedVariationId)

	return (
		<Button
			className="w-full mt-4"
			type="primary"
			loading={isSubmitting}
			onClick={handleClick}
			disabled={
				!stockQty || !canAddToCart(product, selectedVariationId, shopStatus)
			}
		>
			加入購物車
		</Button>
	)
}

/**
 * 是否允許加入購物車
 *
 * - 賣場非 `published`（尚未開賣 / 已結束）一律不可加購
 * - variable：必須先選齊屬性（拿到 variation_id）且該變體不是 outofstock
 * - simple：商品本身不是 outofstock 即可
 * - 其他類型（grouped 等）：不在此把關，交給 WooCommerce 自己判斷
 *
 * @param  product             商品
 * @param  selectedVariationId 已選變體 ID（null = 尚未選齊）
 * @param  shopStatus          賣場販售狀態
 * @return {boolean} 是否允許加入購物車
 */
function canAddToCart(
	product: TFrontProduct,
	selectedVariationId: number | null,
	shopStatus: TShopStatus
) {
	if (shopStatus !== 'published') return false

	if (product.type === 'variable') {
		const variation = product?.variations?.find(
			(v) => v.variation_id === selectedVariationId
		)

		return (
			!!selectedVariationId && variation?.stock?.stockStatus !== 'outofstock'
		)
	}

	if (product.type === 'simple') {
		return product?.stock?.stockStatus !== 'outofstock'
	}

	return true
}

const AddToCartButton = memo(AddToCartButtonComponent)

export default AddToCartButton
