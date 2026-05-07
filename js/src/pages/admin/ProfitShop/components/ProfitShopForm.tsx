import {
	Form,
	Input,
	InputNumber,
	Radio,
	Slider,
	Tag,
	type FormInstance,
	type FormRule,
} from 'antd'
import { memo } from 'react'

import { SlugInput } from '@/pages/admin/ProfitShop/components/SlugInput'
import {
	PROFIT_SHOP_MODE,
	PROFIT_SHOP_STATUS,
	type TOverrideItem,
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
	record?: TProfitShop // 編輯模式：傳入目前 record，用於 currentSlug + 顯示 items 唯讀預覽
	submitting?: boolean // 建立 / 更新中（用於 disabled）
}

/**
 * Profit Shop 共用表單欄位區塊
 *
 * Edit / Create 兩頁共用；不負責 submit，由父層處理 onFinish。
 *
 * 注意：本 Phase 4-A1 範圍內 partner_term_id 暫用 InputNumber，
 * 4-A2 會替換為 PartnerSelector；items 暫顯示唯讀 Tag 列表，
 * 4-A2 會替換為 ItemsEditor。
 */
const ProfitShopFormComponent = ({
	form,
	record,
	submitting,
}: TProfitShopFormProps) => {
	const items: TOverrideItem[] = record?.items ?? []

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
				label="分潤夥伴 ID"
				rules={[
					{ required: true, message: '請填入夥伴 ID' },
					{
						type: 'number',
						min: 1,
						message: '請填入有效的夥伴 ID',
					},
				]}
				extra="（4-A2 將替換為夥伴下拉選單）"
			>
				<InputNumber min={1} className="tw-w-full" />
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

			{record && (
				<Item
					label="商品（唯讀預覽）"
					extra="（4-A2 將開放批次新增 / 移除 / 浮報）"
				>
					{items.length === 0 ? (
						<Tag color="default">尚未加入任何商品</Tag>
					) : (
						<div className="tw-flex tw-flex-wrap tw-gap-2">
							{items.map((it) => (
								<Tag key={`${it.product_id}-${it.variation_id}`} color="blue">
									#{it.product_id}
									{it.variation_id ? `／${it.variation_id}` : ''}
									{it.name ? `（${it.name}）` : ''}
								</Tag>
							))}
						</div>
					)}
				</Item>
			)}
		</Form>
	)
}

export const ProfitShopForm = memo(ProfitShopFormComponent)
