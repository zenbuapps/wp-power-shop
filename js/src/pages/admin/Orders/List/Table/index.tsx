import { useTable } from '@refinedev/antd'
import { CrudFilters, HttpError } from '@refinedev/core'
import { Alert, Form, Spin, Table, TableProps } from 'antd'
import {
	Card,
	useRowSelection,
	getDefaultPaginationProps,
	defaultTableProps,
} from 'antd-toolkit'
import { objToCrudFilters } from 'antd-toolkit/refine'
import { memo, useMemo, useState } from 'react'

import useColumns from '@/pages/admin/Orders/List/hooks/useColumns'
import {
	Filter,
	type TOrderFilterValues,
} from '@/pages/admin/Orders/List/Filter'
import { TOrderBaseRecord, TOrderRecord } from '@/pages/admin/Orders/List/types'
import { useSettlementOrderIds } from '@/pages/admin/ProfitSettlement/hooks'

import DeleteButton from './DeleteButton'

/**
 * 不可能命中任何訂單的 `include` 值
 *
 * WooCommerce REST 的 `include` 最終落到 WP_Query 的 `post__in`，給 `[0]` 會回傳空結果。
 *
 * 🔴 **不能改成送空陣列或不送 `include`**：那等於「不過濾」，站長選了一個沒有任何
 * 訂單的賣場，卻會看到**全部訂單**——而畫面上的篩選 Tag 還顯示著那個賣場名稱。
 * 他會直接得出「這個賣場賣了 500 張訂單」的錯誤結論。
 */
const IMPOSSIBLE_ORDER_IDS = [0]

const Main = () => {
	const [form] = Form.useForm<TOrderFilterValues>()

	/** 已按下「篩選」的賣場（undefined = 不限賣場） */
	const [shopId, setShopId] = useState<number | undefined>(undefined)

	/*
	 * 決策 D-6：WooCommerce REST 的 `orders` 端點無法依 order item meta 篩選
	 * （分潤歸屬寫在 line item 上、不在訂單本體），因此分兩段：
	 * 先向本外掛索取「屬於該賣場的訂單編號清單」，再把那份清單當成 `include` 條件。
	 */
	const { data: orderIdsData, isFetching: isOrderIdsFetching } =
		useSettlementOrderIds(shopId)

	const orderIdsResult = orderIdsData?.data?.data

	const permanentFilters: CrudFilters = useMemo(() => {
		if (!shopId) return objToCrudFilters({})

		/*
		 * 清單還在路上時先送不可能命中的值。
		 * 若這裡改成「先不加 include、等資料回來再加」，使用者會先看到一整頁
		 * 未過濾的訂單再突然縮成幾筆——那一瞬間的畫面是錯的，而且他很可能
		 * 剛好在那時候截圖或做判斷。
		 */
		const ids = isOrderIdsFetching
			? IMPOSSIBLE_ORDER_IDS
			: orderIdsResult?.order_ids?.length
				? orderIdsResult.order_ids
				: IMPOSSIBLE_ORDER_IDS

		/*
		 * ⚠️ `include` 走 **filter** 而不是其他管道：6 個 dataProvider 共用同一個
		 * factory，只有 filters 會被序列化成 WooCommerce 認得的 query string
		 * （`pagination` 會被轉成 Powerhouse 風格的 `paged` / `posts_per_page`，
		 * 而 WC REST 完全不認那兩個參數）。同款陷阱見 react.rule.md §3。
		 */
		return [
			{
				field: 'include',
				operator: 'eq',
				value: ids,
			},
		] as CrudFilters
	}, [shopId, isOrderIdsFetching, orderIdsResult])

	const { tableProps } = useTable<TOrderBaseRecord, HttpError>({
		resource: 'orders',
		filters: {
			permanent: permanentFilters,
		},
		pagination: {
			pageSize: 20,
		},
	})

	const { rowSelection, selectedRowKeys, setSelectedRowKeys } =
		useRowSelection<TOrderBaseRecord>()

	const columns = useColumns()

	return (
		<Spin spinning={tableProps?.loading as boolean}>
			<Card title="篩選" className="mb-4">
				<Filter
					form={form}
					onFinish={(values) => {
						setShopId(values.profit_shop_id ?? undefined)

						// 換了賣場等於換了一份資料集，先前的勾選已無意義
						setSelectedRowKeys([])
					}}
				/>
			</Card>

			{/*
			 * 🔴 據實告知截斷：`order-ids` 有筆數上限（v1 為 200），
			 * 靜默呈現截斷後的結果會讓站長以為那就是全部。
			 * 這條提示不可省略——feature「Rule: 已知落差」明文要求。
			 */}
			{shopId && orderIdsResult?.truncated && (
				<Alert
					type="warning"
					showIcon
					className="mb-4"
					message={`此賣場共有 ${orderIdsResult.total} 張分潤訂單，目前僅顯示最近的 ${orderIdsResult.limit} 張`}
					description="訂單查詢的「指定編號」條件有長度上限，因此清單有筆數上限。若要查看更早的訂單，請改用「行銷 → 分潤結算」頁面並搭配日期區間篩選。"
				/>
			)}

			{shopId &&
				!isOrderIdsFetching &&
				orderIdsResult?.order_ids?.length === 0 && (
					<Alert
						type="info"
						showIcon
						className="mb-4"
						message="這個賣場目前沒有任何分潤訂單"
						description="顧客透過該賣場完成結帳後，訂單才會出現在這裡。"
					/>
				)}

			<Card>
				<div className="mb-4 flex justify-end">
					<DeleteButton
						selectedRowKeys={selectedRowKeys}
						setSelectedRowKeys={setSelectedRowKeys}
					/>
				</div>
				<Table
					{...(defaultTableProps as unknown as TableProps<TOrderRecord>)}
					{...tableProps}
					pagination={{
						...tableProps.pagination,
						...getDefaultPaginationProps({ label: '訂單' }),
					}}
					rowSelection={rowSelection}
					columns={columns}
					rowKey={(record) => record.id.toString()}
				/>
			</Card>
		</Spin>
	)
}

export default memo(Main)
