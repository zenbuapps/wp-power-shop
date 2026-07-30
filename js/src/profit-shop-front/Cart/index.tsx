/**
 * 購物車表格
 *
 * 照搬舊版一頁商店 `pages/PowerShopProducts/Cart/index.tsx`，僅把資料層換成新契約：
 *
 * | 面向 | 舊版 | 新版 |
 * |------|------|------|
 * | 讀取購物車 | `useOne({ resource: 'cart', dataProvider: 'wc-store' })` | `useCart()`（`GET /profit-shop-cart`） |
 * | 移除項目 | `useAjax({ action: 'handle_remove_cart' })` | `removeCartItem()`（`POST /profit-shop-cart/remove-item`） |
 * | 單價來源 | `window.appData.products_info.meta` | `env.products`（PHP 已套用賣場價格覆寫）+ 購物車 API fallback |
 * | notification | `notification.useNotification()` + `contextHolder` | `App.useApp()`（provider 在 `main.tsx`，見 react.rule.md §2） |
 *
 * ⚠ 表格必須保留 `id="ps-cart-table"` / `className="ps-cart-table"` 與 thead 各欄的
 *   `data-key`——`styles.css` 的欄寬設定與 <=810px 卡片式 RWD 版型完全靠這些選擇器，
 *   少一個 attribute 手機版就會塌掉。
 */

import { DeleteOutlined } from '@ant-design/icons'
import { useMutation } from '@tanstack/react-query'
import { App as AntdApp, Button, Empty, Popconfirm } from 'antd'
import type { AxiosError } from 'axios'
import { useAtom } from 'jotai'
import { memo } from 'react'
import { BiMoneyWithdraw } from 'react-icons/bi'

import { removeCartItem } from '../api'
import { cartDataAtom } from '../atoms'
import LoadingText from '../components/LoadingText'
import { defaultImage } from '../defaultImage'
import { checkoutUrl, products } from '../env'
import { useCart, useInvalidateCart } from '../hooks/useCart'
import type {
	TCartData,
	TCartItem,
	TFrontApiErrorBody,
	TFrontCart,
	TFrontCartMutationResult,
} from '../types'
import { getPrice, renderHTML } from '../utils'

import ShippingField from './ShippingField'
import UpdateCartInputNumber from './UpdateCartInputNumber'

/**
 * 移除失敗時的回復資料
 *
 * 除了項目本身，還記下原本的索引位置——樂觀移除是把項目從陣列中拿掉，
 * 回復時必須插回原位，否則失敗一次順序就永久亂掉。
 */
type TRemoveRollback = {
	item: TCartItem
	index: number
}

/**
 * 把可能是字串的價格轉為數字
 *
 * @param  value 原始價格（`PriceOverride` 是十進位字串，可能為 null）
 * @return {number | null} 數字價格；空值或無法解析時為 null
 */
const toNumericPrice = (
	value: string | number | null | undefined
): number | null => {
	if (value === null || value === undefined || value === '') return null

	const price = Number(value)
	return Number.isFinite(price) ? price : null
}

/**
 * 特價優先、否則原價
 *
 * 與舊版 `!!salesPrice ? salesPrice : regularPrice` 語意一致：特價為 0 或空值時
 * 視為「未設定特價」並退回原價。刻意不把 0 當成合法特價——PHP 端若對「無特價」
 * 送 0 而非 null，全站商品都會顯示 0 元，比漏顯示一個 0 元贈品嚴重得多。
 *
 * @param  salesPrice   特價
 * @param  regularPrice 原價
 * @return {number | null} 應顯示的單價；兩者皆無時為 null
 */
const pickShopPrice = (
	salesPrice: string | number | null | undefined,
	regularPrice: string | number | null | undefined
): number | null => {
	const sales = toNumericPrice(salesPrice)
	if (sales !== null && sales > 0) return sales

	return toNumericPrice(regularPrice)
}

/**
 * 從賣場商品資料反查覆寫後的單價
 *
 * 對應舊版 `getMetaPrice`：非變體比對商品層 `id`、變體比對 `variations[].variation_id`
 * （Store API 的變體 cart item，`id` 就是 variation_id）。
 *
 * @param  id         購物車項目的 id（simple = product_id、變體 = variation_id）
 * @param  isVariable 該項目是否為變體（`item.variation` 非空）
 * @return {number | null} 覆寫後單價；賣場資料查不到時為 null
 */
const findShopUnitPrice = (id: number, isVariable: boolean): number | null => {
	if (!isVariable) {
		const product = products.find((p) => p.id === id)
		return pickShopPrice(product?.salesPrice, product?.regularPrice)
	}

	const variation = products
		.flatMap((p) => p.variations ?? [])
		.find((v) => v.variation_id === id)

	return pickShopPrice(variation?.salesPrice, variation?.regularPrice)
}

/**
 * 從物件安全讀出數字
 *
 * 購物車 API 的金額一律是「最小單位整數**字串**」，但仍逐欄位做 runtime 檢查
 * 而非直接 `Number(prices.price)`：`prices` 是 optional，且後端若哪天漏欄位，
 * 這裡只會退回 null（顯示 0 元）而不是整張表格 NaN。
 *
 * @param  source 來源物件
 * @param  key    欄位名
 * @return {number | null} 數字值；缺欄位或無法解析時為 null
 */
const readNumber = (
	source: Record<string, unknown> | undefined,
	key: string
): number | null => {
	const value = source?.[key]

	if (typeof value === 'number') return Number.isFinite(value) ? value : null

	if (typeof value === 'string' && value !== '') {
		const parsed = Number(value)
		return Number.isFinite(parsed) ? parsed : null
	}

	return null
}

/**
 * 從購物車 API 回應反查單價（賣場資料查不到時的 fallback）
 *
 * 賣場商品被移除、或購物車殘留其他來源加購的商品時會走到這裡。
 * 金額是「最小單位整數」，需除以 10^minor_unit 才是顯示金額。
 *
 * @param  rawCart           後端原始購物車
 * @param  cartItemKey       購物車項目 key
 * @param  fallbackMinorUnit 項目自身沒帶 minor unit 時退回的整車設定
 * @return {number} 顯示用單價；查不到時為 0
 */
const findCartApiUnitPrice = (
	rawCart: TFrontCart | undefined,
	cartItemKey: string,
	fallbackMinorUnit: number
): number => {
	const prices = rawCart?.items?.find((i) => i.key === cartItemKey)?.prices
	const price = readNumber(prices, 'price')
	if (price === null) return 0

	return getPrice(
		price,
		readNumber(prices, 'currency_minor_unit') ?? fallbackMinorUnit
	)
}

/**
 * 還原被樂觀移除的購物車項目
 *
 * ⚠ 這是與舊版唯一的行為差異（bug 修正）：舊版 `onError` 用
 * `prev.items.map()` 找回 rollback，但 `onMutate` 已經把該項目 `filter` 掉了，
 * `map` 永遠比對不到 → 移除失敗時商品直接從畫面消失（伺服器購物車其實還在，
 * 且 `onError` 也沒有 invalidate，畫面會一直錯到下次 refetch）。
 * 此處改為「不在清單中就插回原索引」，讓 rollback 真的會 rollback。
 *
 * @param  prev     當前購物車狀態
 * @param  rollBack 移除前的快照
 * @return {TCartData} 已還原的購物車狀態
 */
const restoreRemovedItem = (
	prev: TCartData,
	rollBack?: TRemoveRollback
): TCartData => {
	if (!rollBack) return { ...prev, isMutating: false }
	if (prev.items.some((item) => item.key === rollBack.item.key)) {
		return { ...prev, isMutating: false }
	}

	const items = [...prev.items]
	items.splice(Math.min(rollBack.index, items.length), 0, rollBack.item)

	return { ...prev, isMutating: false, items }
}

/** 購物車表格（空車時不渲染任何東西） */
const CartComponent: React.FC = () => {
	const [cartData, setCartData] = useAtom(cartDataAtom)
	const { rawCart, isFetching } = useCart()
	const invalidateCart = useInvalidateCart()
	const { notification } = AntdApp.useApp()

	const currencyMinorUnit = rawCart?.totals?.currency_minor_unit || 0
	const totalItems = getPrice(
		Number(rawCart?.totals?.total_items || '0'),
		currencyMinorUnit
	)
	const totalPrice = getPrice(
		Number(rawCart?.totals?.total_price || '0'),
		currencyMinorUnit
	)
	const cartItems = cartData?.items ?? []

	const { mutate, isLoading } = useMutation<
		TFrontCartMutationResult,
		AxiosError<TFrontApiErrorBody>,
		string,
		TRemoveRollback | undefined
	>({
		mutationFn: (cartItemKey) => removeCartItem(cartItemKey),
		onMutate: (cartItemKey) => {
			const index = cartData.items.findIndex((item) => item.key === cartItemKey)
			const rollBack =
				index < 0 ? undefined : { item: cartData.items[index], index }

			setCartData((prev) => ({
				...prev,
				items: prev.items.filter((item) => item.key !== cartItemKey),
				isMutating: true,
			}))

			return rollBack
		},
		onSuccess: () => {
			notification.success({ message: '移除購物車成功' })
			invalidateCart()
		},
		onError: (error, _cartItemKey, rollBack) => {
			notification.error({
				message: error?.response?.data?.message || '移除購物車失敗',
			})
			setCartData((prev) => restoreRemovedItem(prev, rollBack))
		},
	})

	/** Popconfirm 的 onConfirm 需要無參數 handler，用 currying 帶入 key */
	const confirm = (cartItemKey: string) => () => {
		mutate(cartItemKey)
	}

	const main = (
		<table id="ps-cart-table" className="ps-cart-table">
			<thead>
				<tr>
					<th data-key="product">商品</th>
					<th data-key="qty">數量</th>
					<th data-key="unit_price">單價</th>
					<th data-key="total">小計</th>
					<th data-key="action"></th>
				</tr>
			</thead>
			<tbody>
				{cartItems.length === 0 && (
					<tr>
						<td colSpan={5}>
							<Empty
								image={Empty.PRESENTED_IMAGE_SIMPLE}
								description="沒有資料"
							/>
						</td>
					</tr>
				)}
				{cartItems.length !== 0 &&
					cartItems.map((item) => {
						const variation = item?.variation ?? []
						const variationText = variation
							.map((v) => `${v?.attribute}: ${v?.value}`)
							.join(' | ')
						const isVariable = variation.length > 0
						const image = item?.images?.[0]?.src || defaultImage
						const id = item?.id ?? 0
						const price =
							findShopUnitPrice(id, isVariable) ??
							findCartApiUnitPrice(rawCart, item.key, currencyMinorUnit)
						const quantity = item?.quantity ?? 1

						return (
							<tr
								key={item.key}
								className={item.isMutating ? 'animate-pulse' : ''}
							>
								<td>
									<div className="flex">
										<img
											alt=""
											className="w-16 h-16 rounded-md aspect-square object-cover mr-2"
											src={image}
										/>
										<div className="text-left">
											<h6 className="font-normal text-lg mb-2 mt-0">
												{renderHTML(item?.name)}
											</h6>
											<div className="my-0 text-xs">{variationText}</div>
										</div>
									</div>
								</td>
								<td>
									<UpdateCartInputNumber item={item} />
								</td>
								<td>{`$ ${price.toLocaleString()}`}</td>
								<td>{`$ ${(price * quantity).toLocaleString()}`}</td>
								<td>
									<Popconfirm
										title="確定要從購物車移除嗎?"
										onConfirm={confirm(item.key)}
										okText="確認"
										cancelText="再想想"
									>
										<DeleteOutlined className="text-red-500 cursor-pointer" />
									</Popconfirm>
								</td>
							</tr>
						)
					})}
			</tbody>
			<tfoot>
				<tr>
					<th className="text-left pl-4">小計</th>
					<th></th>
					<th></th>
					<th>
						<LoadingText
							width="w-[4rem]"
							content={`$ ${totalItems.toLocaleString()}`}
							isLoading={isLoading || isFetching}
						/>
					</th>
					<th></th>
				</tr>
				<ShippingField cartData={cartData} isLoading={isFetching} />
				<tr>
					<th className="text-left pl-4">合計</th>
					<th></th>
					<th></th>
					<th>
						<LoadingText
							width="w-[4rem]"
							content={`$ ${totalPrice.toLocaleString()}`}
							isLoading={isLoading || isFetching}
						/>
					</th>
					<th></th>
				</tr>
			</tfoot>
		</table>
	)

	return cartItems.length === 0 ? null : (
		<div className="w-full mt-20">
			{main}
			<div className="text-right mb-8">
				<a href={checkoutUrl}>
					<Button
						icon={<BiMoneyWithdraw className="relative top-[2px]" />}
						size="large"
						className="px-12"
						type="primary"
						disabled={cartItems.length === 0 || cartData.isMutating}
					>
						前往結帳
					</Button>
				</a>
			</div>
		</div>
	)
}

const Cart = memo(CartComponent)

export default Cart
