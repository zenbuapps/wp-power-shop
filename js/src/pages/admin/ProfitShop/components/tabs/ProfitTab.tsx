import { ExportOutlined } from '@ant-design/icons'
import { Form, InputNumber, Slider, type FormRule } from 'antd'
import { memo } from 'react'

import { useEnv } from '@/hooks'
import { PartnerSelector } from '@/pages/admin/ProfitPartner/components/PartnerSelector'

const { Item } = Form

/** 分潤比例驗證規則（Slider + InputNumber 兩個 Form.Item 共用） */
const RATE_RULES: FormRule[] = [
	{ required: true, message: '請輸入分潤比例' },
	{
		type: 'number',
		min: 0,
		max: 100,
		message: '分潤比例需介於 0-100',
	},
]

/** 「分潤設定」Tab：分潤夥伴 / 分潤比例 */
const ProfitTabComponent = () => {
	const { SITE_URL, KEBAB } = useEnv()

	/**
	 * 分潤夥伴管理頁的完整 admin URL
	 *
	 * 用完整 URL（而非 Refine `<Link>`）才能 target="_blank" 開新分頁，
	 * 讓使用者建完夥伴後回到本頁不遺失填到一半的表單內容。
	 */
	const partnerAdminUrl = `${SITE_URL}/wp-admin/admin.php?page=${KEBAB}#/profit-partner`

	return (
		<>
			<Item
				name="partner_term_id"
				label="分潤夥伴"
				rules={[
					{ required: true, message: '請選擇分潤夥伴' },
					{
						type: 'number',
						min: 1,
						message: '請選擇有效的分潤夥伴',
					},
				]}
				extra={
					<>
						若清單為空，請先到
						<a href={partnerAdminUrl} target="_blank" rel="noopener noreferrer">
							「分潤夥伴」
							<ExportOutlined className="ml-1" />
						</a>
						頁面建立夥伴
					</>
				}
			>
				<PartnerSelector />
			</Item>

			<Item label="分潤比例">
				<div className="flex items-center gap-4">
					<Item name="rate" noStyle rules={RATE_RULES}>
						<Slider className="flex-1" min={0} max={100} step={1} />
					</Item>
					<Item name="rate" noStyle rules={RATE_RULES}>
						<InputNumber
							min={0}
							max={100}
							step={1}
							addonAfter="%"
							style={{ width: 110 }}
						/>
					</Item>
				</div>
			</Item>
		</>
	)
}

export const ProfitTab = memo(ProfitTabComponent)
