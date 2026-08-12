import { Select, Spin, Tag } from 'antd'
import { memo, useMemo } from 'react'

import { useProfitShopList } from '@/pages/admin/ProfitShop/hooks'
import {
	PROFIT_SHOP_STATUS,
	type TProfitShop,
} from '@/pages/admin/ProfitShop/types'

/**
 * 分潤賣場下拉選單
 *
 * 供「結算頁篩選」與「訂單列表的分潤賣場過濾」兩處共用，因此放在
 * `ProfitSettlement/components/` 而不是 `ProfitShop/components/`——它的兩個消費端
 * 都是結算相關流程，賣場 CRUD 自己並不需要一個賣場選擇器。
 *
 * ⚠️ **已刪除（trash）的賣場仍然要出現在選項裡**，只是標上「已刪除」Tag。
 * 結算紀錄凍結的是 shop_id，賣場被刪掉之後那些錢還是要付；把已刪除賣場
 * 從篩選器移除，等於讓站長再也篩不出那批待撥款的紀錄。
 *
 * ⚠️ `label` 是 ReactNode（帶狀態 Tag），antd 內建的 `optionFilterProp="label"`
 * 對 ReactNode 無法比對字串，因此**必須**保留自訂 `filterOption` 走 option 上
 * 額外掛的純字串欄位——移除它會讓搜尋整個靜默失效（與 `PartnerSelector` 同一個坑）。
 */

/** 後端 list endpoint 無 server-side pagination，逼近此門檻時提示改用搜尋 */
const SHOP_LIST_WARN_THRESHOLD = 100

type TShopSelectorProps = {
	/** 受 antd Form.Item 控制的目前值（shop id） */
	value?: number

	/** 由 antd Form.Item 注入（null = 清除） */
	onChange?: (value: number | null) => void
	disabled?: boolean
	placeholder?: string
}

const ShopSelectorComponent = ({
	value,
	onChange,
	disabled,
	placeholder = '全部賣場',
}: TShopSelectorProps) => {
	const { data, isLoading, isFetching } = useProfitShopList()

	const shops: TProfitShop[] = useMemo(() => {
		const list = data?.data?.data
		return Array.isArray(list) ? list : []
	}, [data])

	const options = useMemo(
		() =>
			shops.map((shop) => ({
				label: (
					<span className="flex items-center gap-2">
						<span className="truncate">{shop.title}</span>
						{PROFIT_SHOP_STATUS.TRASH === shop.status && (
							<Tag color="red" className="m-0 shrink-0">
								已刪除
							</Tag>
						)}
					</span>
				),
				value: shop.id,
				name: shop.title ?? '',
				slug: shop.slug ?? '',
			})),
		[shops]
	)

	const isOverThreshold = shops.length >= SHOP_LIST_WARN_THRESHOLD

	return (
		<Select<number>
			showSearch
			allowClear
			placeholder={placeholder}
			loading={isLoading || isFetching}
			notFoundContent={
				isLoading ? (
					<div className="text-center py-2">
						<Spin size="small" />
					</div>
				) : (
					'尚未建立任何賣場'
				)
			}
			options={options}
			value={value}
			onChange={(v) => onChange?.(typeof v === 'number' ? v : null)}
			disabled={disabled}
			filterOption={(input, option) => {
				if (!option) return false
				const keyword = input.toLowerCase()
				return (
					(option.name ?? '').toLowerCase().includes(keyword) ||
					(option.slug ?? '').toLowerCase().includes(keyword)
				)
			}}
			dropdownRender={(menu) => (
				<>
					{menu}
					{isOverThreshold && (
						<div
							className="text-xs text-orange-500 px-3 py-2 border-t border-gray-200"
							role="note"
						>
							結果可能超過 {SHOP_LIST_WARN_THRESHOLD} 筆，請使用上方搜尋過濾。
						</div>
					)}
				</>
			)}
			className="w-full"
		/>
	)
}

export const ShopSelector = memo(ShopSelectorComponent)
