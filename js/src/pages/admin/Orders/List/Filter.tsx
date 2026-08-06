import { SearchOutlined, UndoOutlined } from '@ant-design/icons'
import { Button, Form, type FormInstance } from 'antd'
import { memo } from 'react'

import { ShopSelector } from '@/pages/admin/ProfitSettlement/components/ShopSelector'

const { Item } = Form

/** 訂單列表的篩選條件（目前只有分潤賣場） */
export type TOrderFilterValues = {
	profit_shop_id?: number
}

/**
 * 訂單列表篩選表單
 *
 * ⚠️ 目前只有「分潤賣場」一個條件。這張表單是配合分潤結算功能新增的，
 * **不是**把原本被註解掉的通用訂單篩選器（狀態 / 日期 / 關鍵字）補回來——
 * 那組欄位需要另外確認 `orders` 端點支援哪些查詢參數，不在本次範圍內。
 * 之後要補的話，直接往下面那個 grid 裡加 `<Item>` 即可，版型已對齊
 * `admin-list-ui.rule.md` §4 的標準（斷點 2 / md:3 / 2xl:4、篩選 + 重置兩顆按鈕）。
 */
const FilterComponent = ({
	form,
	onFinish,
}: {
	form: FormInstance<TOrderFilterValues>
	onFinish: (values: TOrderFilterValues) => void
}) => {
	return (
		<div className="mb-2">
			<Form<TOrderFilterValues>
				form={form}
				layout="vertical"
				onFinish={onFinish}
			>
				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4">
					<Item
						name={['profit_shop_id']}
						label="分潤賣場"
						tooltip="只顯示曾透過該分潤賣場成交的訂單"
					>
						<ShopSelector placeholder="全部訂單（不限賣場）" />
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
