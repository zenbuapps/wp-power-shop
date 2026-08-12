import { SearchOutlined, UndoOutlined } from '@ant-design/icons'
import { Button, DatePicker, Form, Select, type FormInstance } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import { memo } from 'react'

import { PartnerSelector } from '@/pages/admin/ProfitPartner/components/PartnerSelector'
import { ShopSelector } from '@/pages/admin/ProfitSettlement/components/ShopSelector'
import {
	SETTLEMENT_STATUS_OPTIONS,
	type TSettlementFilterValues,
} from '@/pages/admin/ProfitSettlement/types'

const { Item } = Form
const { RangePicker } = DatePicker

/**
 * 分潤結算列表篩選表單
 *
 * 四個條件（夥伴 / 賣場 / 狀態 / 日期區間）對應後端
 * `SettlementFilterInput` 的四種過濾，全部走 **server-side**——
 * 結算紀錄是「每張訂單每個分潤品項一筆」，不像賣場 / 夥伴那樣可以整批撈回來
 * 前端過濾（見 `admin-list-ui.rule.md` §3.2 路線 B 的但書）。
 *
 * ⚠️ 日期送到後端是 **unix 秒**、且結束日要取當天最後一秒，
 * 轉換集中在 `utils.ts` 的 `toSettlementQuery()`，這裡只負責收集 Dayjs 值。
 */
const FilterComponent = ({
	form,
	onFinish,
}: {
	form: FormInstance<TSettlementFilterValues>
	onFinish: (values: TSettlementFilterValues) => void
}) => {
	return (
		<div className="mb-2">
			<Form<TSettlementFilterValues>
				form={form}
				layout="vertical"
				onFinish={onFinish}
			>
				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4">
					<Item name={['partner_term_id']} label="分潤夥伴">
						<PartnerSelector placeholder="全部夥伴" />
					</Item>
					<Item name={['shop_id']} label="分潤賣場">
						<ShopSelector placeholder="全部賣場" />
					</Item>
					<Item name={['status']} label="結算狀態">
						<Select
							{...defaultSelectProps}
							mode="multiple"
							placeholder="全部狀態"
							options={SETTLEMENT_STATUS_OPTIONS}
						/>
					</Item>
					<Item name={['date_range']} label="訂單日期區間">
						<RangePicker className="w-full" allowEmpty={[true, true]} />
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

							// resetFields 之後必須 submit，否則畫面停在上一組篩選結果
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
