/**
 * 運費列（購物車 `tfoot` 的其中一列）
 *
 * 照搬舊版一頁商店 `pages/PowerShopProducts/Cart/ShippingField.tsx`。
 *
 * 資料層差異：選擇運送方式改用 `selectShippingRate()`（`POST
 * /profit-shop-cart/select-shipping-rate`），取代舊版的
 * `useUpdate({ resource: 'cart/select-shipping-rate', dataProvider: 'wc-store' })`。
 *
 * 後端會先驗證 `rate_id` 屬於該 package，再寫進 WooCommerce session 並重算整車，
 * 因此 mutation 回傳的就是重算後的完整購物車。nonce 與其他購物車請求共用同一顆
 * `X-WP-Nonce`（由 `api.ts` 統一帶上），本元件**不需要**任何額外的 nonce 狀態。
 *
 * 本元件回傳 `<tr>`，必須放在 `<tfoot>` 內；`options` 為空時回 `null`（該列整列不出現）。
 */

import { InfoCircleFilled } from '@ant-design/icons'
import { useMutation } from '@tanstack/react-query'
import { App as AntdApp, Select, Tooltip } from 'antd'
import type { AxiosError } from 'axios'
import { memo } from 'react'

import { selectShippingRate } from '../api'
import LoadingText from '../components/LoadingText'
import { useInvalidateCart } from '../hooks/useCart'
import type {
	TCartData,
	TFrontApiErrorBody,
	TFrontCart,
	TShippingRates,
} from '../types'
import { getPrice } from '../utils'

/**
 * 單一運送方式的選項內容（左邊名稱、右邊費用）
 *
 * @param  shippingRate      運送方式
 * @param  currencyMinorUnit 幣別小數位數
 * @return {React.ReactElement} 選項節點
 */
const renderItem = (
	shippingRate: TShippingRates,
	currencyMinorUnit: number
) => {
	const price = getPrice(
		parseInt(shippingRate?.price ?? '0', 10),
		currencyMinorUnit
	)

	return (
		<div className="flex items-center justify-between">
			<div className="flex items-center">
				{shippingRate?.name ?? '未知運送方式'}
			</div>

			<div>{`$ ${price}`}</div>
		</div>
	)
}

/** ShippingField props */
type TShippingFieldProps = {
	cartData: TCartData

	/** 整車是否正在讀取（Select 顯示 loading、金額顯示骨架） */
	isLoading: boolean
}

/** 運費列 */
const ShippingFieldComponent: React.FC<TShippingFieldProps> = ({
	cartData,
	isLoading,
}) => {
	const shipments = cartData?.shipping_rates ?? []
	const currencyMinorUnit = cartData?.totals?.currency_minor_unit || 0
	const packageId = shipments?.[0]?.package_id ?? 0
	const shippingRates = shipments?.[0]?.shipping_rates ?? []
	const rawTotalShipping = parseInt(cartData?.totals?.total_shipping || '0', 10)
	const totalShipping = getPrice(rawTotalShipping, currencyMinorUnit)

	const invalidateCart = useInvalidateCart()
	const { notification } = AntdApp.useApp()

	const options = shippingRates.map((shippingRate) => ({
		value: shippingRate?.rate_id ?? '',
		label: renderItem(shippingRate, currencyMinorUnit),
	}))

	const nonOptions = [
		{
			value: 'none',
			label: <>請選擇運送方式</>,
		},
	]

	const defaultOption =
		shippingRates.find((shippingRate) => shippingRate.selected)?.rate_id ?? ''

	const { mutate, isLoading: updateIsLoading } = useMutation<
		TFrontCart,
		AxiosError<TFrontApiErrorBody>,
		string
	>({
		mutationFn: (rateId) => selectShippingRate({ packageId, rateId }),
		onSuccess: () => {
			invalidateCart()
		},

		// 後端會對「不屬於此包裹的 rate_id」回 400（例如使用者在另一個分頁改了購物車，
		// 導致運送方式重算）。少了這段，Select 只會在下次 refetch 時默默彈回舊值，
		// 使用者看到的是「點了沒反應」——與同 bundle 的移除 / 改量兩個 mutation 不一致。
		onError: (error) => {
			notification.error({
				message: error?.response?.data?.message || '更新運送方式失敗',
			})
			invalidateCart()
		},
	})

	const handleChange = (rateId: string) => {
		mutate(rateId)
	}

	if (options.length === 0) {
		return null
	}

	/*
	 * 以下 `options.length === 0` 的兩處分支在上方 early return 之後已不可能成立
	 * （`nonOptions`、`disabled`、Tooltip 皆為死碼）。刻意保留與舊版一致的結構，
	 * 未來若要改成「無運送方式時仍顯示該列並提示」，把 early return 拿掉即可生效。
	 */
	return (
		<tr>
			<th className="text-left pl-4" colSpan={3}>
				<span className="mr-2">運費</span>
				<Select
					className="min-w-[12rem]"
					defaultValue={nonOptions[0].value}
					value={options.length > 0 ? defaultOption : nonOptions[0].value}
					options={options.length > 0 ? options : nonOptions}
					onChange={handleChange}
					loading={isLoading}
					disabled={options.length === 0}
				/>
				{options.length === 0 && (
					<Tooltip title='請確認您的地址"國家"欄位有可用的運送方式或者確認購物車有商品'>
						<InfoCircleFilled className="ml-2 text-orange-500" />
					</Tooltip>
				)}
			</th>
			<th className="text-right">
				<LoadingText
					width="w-[4rem]"
					content={`$ ${totalShipping.toLocaleString()}`}
					isLoading={isLoading || updateIsLoading}
				/>
			</th>
			<th></th>
		</tr>
	)
}

const ShippingField = memo(ShippingFieldComponent)

export default ShippingField
