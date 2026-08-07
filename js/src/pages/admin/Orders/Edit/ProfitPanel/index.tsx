import { ExclamationCircleOutlined } from '@ant-design/icons'
import { Link } from '@refinedev/core'
import { Table, Tag, Tooltip, Typography, type TableProps } from 'antd'
import { Card, Heading } from 'antd-toolkit'
import { useWoocommerce } from 'antd-toolkit/wp'
import { memo, useMemo } from 'react'

import { useRecord } from '@/pages/admin/Orders/Edit/hooks'
import { useProfitPartnerList } from '@/pages/admin/ProfitPartner/hooks'
import type { TProfitPartner } from '@/pages/admin/ProfitPartner/types'
import {
	DELETED_PARTNER_LABEL,
	DELETED_SHOP_LABEL,
	SETTLEMENT_STATUS_META,
	type TSettlementStatus,
} from '@/pages/admin/ProfitSettlement/types'
import { formatUnixSeconds } from '@/pages/admin/ProfitSettlement/utils'
import { useProfitShopList } from '@/pages/admin/ProfitShop/hooks'
import {
	PROFIT_SHOP_STATUS,
	type TProfitShop,
} from '@/pages/admin/ProfitShop/types'

import {
	sumProfitAmount,
	toPanelRows,
	type TProfitPanelRow,
} from './utils'

const { Text } = Typography

/**
 * 訂單頁的分潤資訊面板
 *
 * 對應 `specs/features/profit-shop/view-order-profit-panel.feature`。
 *
 * ## 資料來源：訂單品項自己的 meta，不是結算 API
 *
 * 分潤資訊在結帳當下就**凍結**在 order item meta 上（`OrderLineItemSettlementHook`），
 * 面板只是把它讀出來呈現。因此這裡直接讀 `record.items[].meta_data`，
 * 而不是另外打結算端點——這同時滿足 feature 的
 * 「Rule: 後置（凍結）- 面板顯示凍結值而非當下重新計算的值」：
 * 賣場之後改比例、改名、甚至被刪除，歷史訂單的數字都不會跟著變。
 *
 * ⚠️ 另一個現實理由：`GET /profit-settlements` **沒有 order_id 過濾條件**
 * （`SettlementFilterInput` 只有 partner / shop / status / date）。要用它取單一訂單的
 * 明細，只能整批撈回來再前端過濾，而列表有 per_page 100 的上限——訂單一多就會漏。
 *
 * ## 名稱解析是前端做的，佔位文字必須與後端逐字相同
 *
 * meta 裡凍結的是 `shop_id` / `partner_term_id` 兩個數字，名稱得自己查。
 * 這裡沿用結算頁共用的 `DELETED_SHOP_LABEL` / `DELETED_PARTNER_LABEL` 常數
 * （它們對齊 PHP `WpAdminSettlementQuery` 的同名常數），
 * 否則同一筆紀錄會在結算頁與訂單頁顯示不同的賣場名稱。
 *
 * 「已刪除」的判定也與後端一致：**查不到 或 狀態為 trash** 都算已刪除
 * （後端 `resolve_shop_names()` 對 `post_status === 'trash'` 一樣不回名稱）。
 *
 * ## 沒有任何分潤歸屬的訂單，整個面板不渲染
 *
 * 一般訂單印一個空面板只會佔版面、讓站長每次都得確認一次「這張真的沒有嗎」。
 */

/**
 * 面板本體（只在確定有分潤品項時才被 render）
 *
 * 拆成兩層元件是為了讓「一般訂單不查賣場 / 夥伴名稱」這件事**在結構上成立**：
 * hooks 不能寫在條件式裡，所以若把查詢放在外層再 early return，
 * 那兩個查詢仍然會照發——只是結果沒人用。把它們放進這個子元件之後，
 * 沒有分潤品項的訂單根本不會 render 它，查詢自然不會發生。
 */
const ProfitPanelBody = ({ rows }: { rows: TProfitPanelRow[] }) => {
	const { currency } = useWoocommerce()
	const { data: shopData } = useProfitShopList()
	const { data: partnerData } = useProfitPartnerList()

	/**
	 * shop_id → 名稱
	 *
	 * 與後端 `resolve_shop_names()` 一致：**已丟垃圾桶的賣場也視為已刪除**，
	 * 不回傳名稱（由呼叫端填佔位文字）。
	 */
	const shopNameMap = useMemo(() => {
		const list = shopData?.data?.data
		const shops: TProfitShop[] = Array.isArray(list) ? list : []
		const map = new Map<number, string>()
		shops.forEach((shop) => {
			if (PROFIT_SHOP_STATUS.TRASH === shop.status) return
			map.set(shop.id, shop.title)
		})
		return map
	}, [shopData])

	const partnerNameMap = useMemo(() => {
		const list = partnerData?.data?.data
		const partners: TProfitPartner[] = Array.isArray(list) ? list : []
		return new Map(partners.map((p) => [p.id, p.name]))
	}, [partnerData])

	/**
	 * 分潤合計
	 *
	 * ⚠️ 這是本專案唯一在前端加總分潤金額的地方，因此刻意用**字串定點加法**
	 * 而非 `Number()` 累加：金額是後端以 bcmath 產出的十進位字串，
	 * 用 float 相加在筆數一多時會出現 `48.00 + 180.00 = 227.99999999999997` 這種結果。
	 * 這裡把兩位小數當整數（分）相加，最後再插回小數點。
	 *
	 * 只支援兩位小數——WooCommerce 的 `wc_get_price_decimals()` 預設就是 2，
	 * 而後端 `format_money()` 也一律輸出兩位。若站台改成 3 位以上，
	 * 這個合計會少算，屆時應改為由後端提供合計欄位。
	 */
	const totalProfit = useMemo(() => sumProfitAmount(rows), [rows])

	const columns: TableProps<TProfitPanelRow>['columns'] = [
		{
			title: '商品',
			dataIndex: 'product_name',
			ellipsis: true,
			render: (name: string, row: TProfitPanelRow) => (
				<Tooltip title={`訂單品項 #${row.order_item_id}`}>
					<Text>{name || '（商品名稱未紀錄）'}</Text>
				</Tooltip>
			),
		},
		{
			title: '分潤賣場',
			dataIndex: 'shop_id',
			width: 180,
			ellipsis: true,
			render: (shopId: number) => {
				const name = shopNameMap.get(shopId)

				// 已刪除的賣場不提供連結——點過去只會是 404 / 空白編輯頁
				if (!name) {
					return <Text type="secondary">{DELETED_SHOP_LABEL}</Text>
				}
				return <Link to={`/profit-shop/edit/${shopId}`}>{name}</Link>
			},
		},
		{
			title: '分潤夥伴',
			dataIndex: 'partner_term_id',
			width: 160,
			ellipsis: true,
			render: (partnerId: number) => {
				const name = partnerNameMap.get(partnerId)
				if (!name) {
					return <Text type="secondary">{DELETED_PARTNER_LABEL}</Text>
				}
				return <Link to={`/profit-partner/edit/${partnerId}`}>{name}</Link>
			},
		},
		{
			title: '比例',
			dataIndex: 'rate',
			width: 110,
			align: 'right',
			render: (rate: number, row: TProfitPanelRow) =>
				row.needs_manual_rate ? (
					<Tooltip title="結帳當下賣場已被刪除，系統算不出分潤比例。這筆錢仍需支付，請人工確認金額。">
						<Tag color="red" icon={<ExclamationCircleOutlined />} className="m-0">
							需人工設定
						</Tag>
					</Tooltip>
				) : (
					<Text>{rate}%</Text>
				),
		},
		{
			title: '實際單價',
			dataIndex: 'actual_price',
			width: 120,
			align: 'right',
			render: (price: string) => (
				<Text>
					{currency?.symbol}
					{price}
				</Text>
			),
		},
		{
			title: '應分潤',
			dataIndex: 'profit_amount',
			width: 120,
			align: 'right',
			render: (amount: string) => (
				<Text strong>
					{currency?.symbol}
					{amount}
				</Text>
			),
		},
		{
			title: '結算狀態',
			dataIndex: 'status',
			width: 140,
			render: (status: TSettlementStatus, row: TProfitPanelRow) => {
				const meta = SETTLEMENT_STATUS_META[status]
				const settledAt = formatUnixSeconds(row.settled_at)

				return (
					<div className="flex flex-col items-start">
						<Tag color={meta?.color} className="m-0">
							{meta?.label ?? status}
						</Tag>
						{settledAt && (
							<Text type="secondary" className="text-xs mt-1">
								{settledAt}
							</Text>
						)}
					</div>
				)
			},
		},
	]

	return (
		<Card className="mt-8">
			<Heading className="mb-6">分潤資訊</Heading>

			<Table<TProfitPanelRow>
				size="small"
				rowKey="order_item_id"
				dataSource={rows}
				columns={columns}
				pagination={false}
				scroll={{ x: 1000 }}
				summary={() => (
					<Table.Summary.Row>
						<Table.Summary.Cell index={0} colSpan={5}>
							<Text strong>分潤合計</Text>
						</Table.Summary.Cell>
						<Table.Summary.Cell index={5} align="right">
							<Text strong>
								{currency?.symbol}
								{totalProfit}
							</Text>
						</Table.Summary.Cell>
						<Table.Summary.Cell index={6} />
					</Table.Summary.Row>
				)}
			/>

			<Text type="secondary" className="block mt-3 text-xs">
				以上數字在訂單成立當下即已凍結，之後賣場調整分潤比例或名稱都不會改變這裡的內容。
				撥款操作請至「行銷 → 分潤結算」頁面執行。
			</Text>
		</Card>
	)
}

/**
 * 分潤資訊面板的外層守門
 *
 * 只負責「這張訂單有沒有分潤品項」這一個判斷，沒有就完全不渲染
 * （feature：「頁面不應出現空的分潤資訊區塊」）。
 */
const ProfitPanelComponent = () => {
	const record = useRecord()

	const rows = useMemo(() => toPanelRows(record?.items), [record?.items])

	if (rows.length === 0) return null

	return <ProfitPanelBody rows={rows} />
}

export const ProfitPanel = memo(ProfitPanelComponent)
