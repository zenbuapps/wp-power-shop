import { SearchOutlined, UndoOutlined } from '@ant-design/icons'
import { Button, Form, Input, Select, type FormInstance } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import { memo } from 'react'

/**
 * 分潤夥伴列表篩選條件
 *
 * - `search`：關鍵字，比對 id / name / slug / contact_email
 * - `has_image`：是否已設定夥伴圖片
 */
export type TProfitPartnerFilterValues = {
	search?: string
	has_image?: 'yes' | 'no'
}

/** 夥伴圖片狀態下拉選項 */
const HAS_IMAGE_OPTIONS: { label: string; value: 'yes' | 'no' }[] = [
	{ label: '已設定', value: 'yes' },
	{ label: '未設定', value: 'no' },
]

const { Item } = Form

/**
 * 分潤夥伴篩選表單
 *
 * 後端 `GET /profit-partners` 目前無 server-side 分頁 / 關鍵字查詢，
 * 因此本表單只負責收集條件，實際過濾由 List 端 client-side `useMemo` 執行
 * （見 `.claude/rules/admin-list-ui.rule.md` §3.2 路線 B）。
 */
const FilterComponent = ({
	form,
	onFinish,
}: {
	form: FormInstance<TProfitPartnerFilterValues>
	onFinish: (values: TProfitPartnerFilterValues) => void
}) => {
	return (
		<div className="mb-2">
			<Form<TProfitPartnerFilterValues>
				form={form}
				layout="vertical"
				onFinish={onFinish}
			>
				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4">
					<Item name={['search']} label="關鍵字搜尋">
						<Input placeholder="可輸入 ID / 名稱 / Slug / Email" allowClear />
					</Item>
					<Item name={['has_image']} label="夥伴圖片">
						<Select
							{...defaultSelectProps}
							mode={undefined}
							placeholder="不限"
							options={HAS_IMAGE_OPTIONS}
						/>
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
