import {
	Form,
	Input,
	InputNumber,
	Radio,
	Slider,
	type FormInstance,
	type FormRule,
} from 'antd'
import { memo } from 'react'

import { PartnerSelector } from '@/pages/admin/ProfitPartner/components/PartnerSelector'
import { ItemsEditor } from '@/pages/admin/ProfitShop/components/ItemsEditor'
import { SlugInput } from '@/pages/admin/ProfitShop/components/SlugInput'
import {
	PROFIT_SHOP_MODE,
	PROFIT_SHOP_STATUS,
	type TProfitShop,
} from '@/pages/admin/ProfitShop/types'

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

type TProfitShopFormProps = {
	form: FormInstance // 受 antd Form 控制；由父層 useForm 傳入
	record?: TProfitShop // 編輯模式：傳入目前 record，用於 currentSlug + items 預設值
	submitting?: boolean // 建立 / 更新中（用於 disabled）
	mode: 'create' | 'edit' // 4-A2：Create 模式不開放 ItemsEditor（建立時 items: []）
}

/**
 * Profit Shop 共用表單欄位區塊
 *
 * Edit / Create 兩頁共用；不負責 submit，由父層處理 onFinish。
 *
 * 4-A2 變更：
 *   - partner_term_id：InputNumber → PartnerSelector（下拉選擇 + 搜尋）
 *   - items：唯讀 Tag list → ItemsEditor（僅 edit 模式啟用，create 仍寫死 [] 避免複雜度）
 */
const ProfitShopFormComponent = ({
	form,
	record,
	submitting,
	mode,
}: TProfitShopFormProps) => {
	return (
		<Form
			form={form}
			layout="vertical"
			disabled={submitting}
			initialValues={{
				status: PROFIT_SHOP_STATUS.DRAFT,
				mode: PROFIT_SHOP_MODE.PAGE,
				rate: 10,
			}}
		>
			<Item
				name="title"
				label="賣場名稱"
				rules={[
					{ required: true, message: '請輸入賣場名稱' },
					{ max: 200, message: '長度不可超過 200 字元' },
				]}
			>
				<Input placeholder="例：夏季限定夥伴賣場" />
			</Item>

			<Item
				name="slug"
				label="Slug（網址識別）"
				rules={[
					{ required: true, message: '請輸入 slug' },
					{
						pattern: /^[a-z0-9_-]+$/,
						message: '僅允許小寫英文、數字、連字號（-）與底線（_）',
					},
				]}
				extra="會經 5 類衝突檢查（保留字 / 站台 page / 既有賣場 / 夥伴 / 商品分類）"
			>
				<SlugInput currentSlug={record?.slug} />
			</Item>

			<Item
				name="status"
				label="狀態"
				rules={[{ required: true, message: '請選擇狀態' }]}
			>
				<Radio.Group>
					<Radio value={PROFIT_SHOP_STATUS.DRAFT}>草稿</Radio>
					<Radio value={PROFIT_SHOP_STATUS.PUBLISH}>已上架</Radio>
				</Radio.Group>
			</Item>

			<Item
				name="mode"
				label="呈現模式"
				rules={[{ required: true, message: '請選擇呈現模式' }]}
			>
				<Radio.Group>
					<Radio value={PROFIT_SHOP_MODE.PAGE}>獨立頁面</Radio>
					<Radio value={PROFIT_SHOP_MODE.SHORTCODE}>Shortcode</Radio>
				</Radio.Group>
			</Item>

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
				extra="若清單為空，請先到「分潤夥伴」頁面建立夥伴"
			>
				<PartnerSelector />
			</Item>

			<Item label="分潤比例">
				<div className="tw-flex tw-items-center tw-gap-4">
					<Item name="rate" noStyle rules={RATE_RULES}>
						<Slider className="tw-flex-1" min={0} max={100} step={1} />
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

			{mode === 'edit' && (
				<Item
					name="items"
					label="賣場商品"
					extra="可新增、移除商品；price_override 留空代表沿用原價"
				>
					<ItemsEditor disabled={submitting} />
				</Item>
			)}
		</Form>
	)
}

export const ProfitShopForm = memo(ProfitShopFormComponent)
