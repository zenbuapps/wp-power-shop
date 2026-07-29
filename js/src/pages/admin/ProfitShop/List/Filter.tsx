import { SearchOutlined, UndoOutlined } from '@ant-design/icons'
import { Button, Form, Input, Select, type FormInstance } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import { memo } from 'react'

import { PartnerSelector } from '@/pages/admin/ProfitPartner/components/PartnerSelector'
import {
	PROFIT_SHOP_STATUS,
	type TProfitShopStatus,
} from '@/pages/admin/ProfitShop/types'

/**
 * 分潤賣場列表篩選條件
 *
 * - `search`：關鍵字，比對 id / title / slug
 * - `status`：狀態多選
 * - `partner_term_id`：分潤夥伴 term id
 */
export type TProfitShopFilterValues = {
	search?: string
	status?: TProfitShopStatus[]
	partner_term_id?: number
}

/** 狀態下拉選項 */
const STATUS_OPTIONS: { label: string; value: TProfitShopStatus }[] = [
	{ label: '已上架', value: PROFIT_SHOP_STATUS.PUBLISH },
	{ label: '草稿', value: PROFIT_SHOP_STATUS.DRAFT },
	{ label: '已刪除', value: PROFIT_SHOP_STATUS.TRASH },
]

const { Item } = Form

/**
 * 分潤賣場篩選表單
 *
 * 後端 `GET /profit-shops` 目前無 server-side 分頁 / 關鍵字查詢，
 * 因此本表單只負責收集條件，實際過濾由 List 端 client-side `useMemo` 執行
 * （見 `.claude/rules/admin-list-ui.rule.md` §3.2 路線 B）。
 */
const FilterComponent = ({
	form,
	onFinish,
}: {
	form: FormInstance<TProfitShopFilterValues>
	onFinish: (values: TProfitShopFilterValues) => void
}) => {
	return (
		<div className="mb-2">
			<Form<TProfitShopFilterValues>
				form={form}
				layout="vertical"
				onFinish={onFinish}
			>
				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4">
					<Item name={['search']} label="關鍵字搜尋">
						<Input placeholder="可輸入賣場 ID、名稱或 Slug" allowClear />
					</Item>
					<Item name={['status']} label="狀態">
						<Select
							{...defaultSelectProps}
							mode="multiple"
							placeholder="全部狀態"
							options={STATUS_OPTIONS}
						/>
					</Item>
					<Item name={['partner_term_id']} label="分潤夥伴">
						<PartnerSelector placeholder="全部夥伴" />
					</Item>
				</div>
				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4">
					<Button
						type="primary"
						className="w-full"
						icon={<SearchOutlined />}
						onClick={form.submit}
					>
						篩選
					</Button>
					<Button
						type="default"
						className="w-full"
						icon={<UndoOutlined />}
						onClick={() => {
							form.resetFields()
							form.submit()
						}}
					>
						重置
					</Button>
				</div>
			</Form>
		</div>
	)
}

export const Filter = memo(FilterComponent)
