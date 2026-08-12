import { ReloadOutlined } from '@ant-design/icons'
import {
	Button,
	Form,
	Input,
	Space,
	Tooltip,
	type FormInstance,
	type FormRule,
} from 'antd'
import { memo } from 'react'

import { Gallery } from '@/components/product/fields'
import type { TPartnerFormValues } from '@/pages/admin/ProfitPartner/types'
import { generatePassword } from '@/pages/admin/ProfitPartner/utils'

const { Item } = Form

/** Partner slug 格式（與後端 InvalidPartnerSlug 對齊） */
const SLUG_FORMAT = /^[a-z0-9_-]+$/

type TPartnerFormProps = {
	form: FormInstance<TPartnerFormValues>
	mode: 'create' | 'edit'
	submitting?: boolean

	/**
	 * edit 模式：該夥伴在 DB 裡沒有可還原的明文密碼
	 *
	 * 只有「保存明文」功能上線前建立的夥伴會是 true。此時欄位是空的，
	 * 但那**不代表密碼是空的**——文案必須講清楚，否則站長會以為夥伴沒有密碼。
	 */
	passwordUnavailable?: boolean
}

/**
 * Partner 共用表單欄位區塊
 *
 * Edit / Create 兩頁共用；不負責 submit，由父層處理。
 *
 * 密碼是**一般表單欄位**：兩種模式都渲染、都隨主表單的送出按鈕一起送。
 * Edit 模式預填目前密碼供站長直接查看 / 修改 / 複製（後端額外保存一份明文，
 * 見 `PartnerTermRepository` class docblock）。
 *
 * ⚠️ Edit 模式的密碼欄位**不可加 `required`**：主表單 submit 會驗這個欄位，
 * 而「留空 = 不變更密碼」是刻意保留的行為（給舊資料無明文、站長又不想動密碼的情況）。
 * Create 模式則必填——新夥伴沒有既有密碼可沿用。
 */
const PartnerFormComponent = ({
	form,
	mode,
	submitting,
	passwordUnavailable,
}: TPartnerFormProps) => {
	const isEdit = 'edit' === mode
	const slugRules: FormRule[] = [
		{ required: true, message: '請輸入 slug' },
		{
			pattern: SLUG_FORMAT,
			message: '僅允許小寫英文、數字、連字號（-）與底線（_）',
		},
		{ max: 60, message: 'slug 長度不可超過 60 字元' },
	]

	const passwordRules: FormRule[] = [
		// Edit 模式不加 required——留空代表「不變更密碼」（後端把空字串正規化為「不變更」）。
		...(isEdit ? [] : [{ required: true, message: '請輸入初始密碼' }]),
		{ min: 8, message: '密碼長度至少 8 字元' },
		{ max: 100, message: '密碼長度不可超過 100 字元' },
	]

	/** 產生一組隨機密碼直接填入欄位（純前端，按「儲存」後才真正生效） */
	const handleGenerate = () => {
		form.setFieldValue('password', generatePassword())

		// 產生後主動觸發驗證，清掉先前可能殘留的「長度不足」紅字
		void form.validateFields(['password']).catch(() => {})
	}

	return (
		<Form<TPartnerFormValues>
			form={form}
			layout="vertical"
			disabled={submitting}
		>
			{/*
			 * 直接複用商品編輯頁「商品圖片」的 Gallery 元件（同一套媒體庫 + 操作邏輯），
			 * 只調整為單張、圓形、換標題。Gallery 自帶 hidden Form.Item 管理 `images` 值，
			 * 因此**不可**再包一層 <Item>，否則會變成兩層巢狀欄位而讀不到值。
			 */}
			{/*
			 * Gallery 內部是原生 div / Image，吃不到 antd Form 的 disabled context，
			 * 送出期間得自己擋掉互動，避免使用者在 request 飛行中又開媒體庫改圖。
			 */}
			<div
				className={`mb-6 ${submitting ? 'pointer-events-none opacity-50' : ''}`}
			>
				<Gallery name={['images']} limit={1} shape="circle" title="夥伴圖片" />
				<div className="text-xs text-gray-400 mt-2">
					顯示於夥伴列表與賣場的分潤設定選單；建議使用正方形圖片，會裁切為圓形呈現
				</div>
			</div>

			<Item
				name="name"
				label="夥伴名稱"
				rules={[
					{ required: true, message: '請輸入夥伴名稱' },
					{ max: 200, message: '長度不可超過 200 字元' },
				]}
			>
				<Input placeholder="例：王小明（部落客）" autoComplete="off" />
			</Item>

			<Item
				name="slug"
				label="Slug（網址識別）"
				rules={slugRules}
				extra="用於 partner 登入與 partner 報表 URL，建立後不建議再變更"
			>
				<Input placeholder="wang-xiaoming" autoComplete="off" />
			</Item>

			<Item
				name="contact_email"
				label="聯絡 Email（可選）"
				rules={[{ type: 'email', message: '請輸入有效的 Email' }]}
			>
				<Input
					type="email"
					placeholder="contact@example.com"
					autoComplete="off"
				/>
			</Item>

			<Item
				label={isEdit ? '報表登入密碼' : '初始密碼'}
				extra={
					isEdit
						? passwordUnavailable
							? '此夥伴建立於舊版，目前密碼無法顯示（資料庫僅存不可逆的雜湊）。輸入新密碼並儲存即可重設，之後就看得到了。'
							: '夥伴登入分潤報表頁使用。修改後按「儲存」生效，該夥伴目前的登入狀態會失效、需用新密碼重新登入。'
						: '建立後可在編輯頁隨時查看與變更'
				}
				required={!isEdit}
			>
				<Space.Compact className="w-full">
					{/*
					 * 巢狀 Item：外層只負責 label / extra（含「產生」按鈕的排版），
					 * 內層 noStyle 才是真正綁定 `password` 欄位的那一個。
					 * 不這樣拆的話，「產生」按鈕會被塞進 Form.Item 的值綁定範圍內。
					 */}
					<Item name="password" rules={passwordRules} noStyle>
						<Input.Password
							placeholder={isEdit ? '留空表示不變更密碼' : '至少 8 字元'}
							autoComplete="new-password"
						/>
					</Item>
					<Tooltip title="產生一組隨機密碼（按「儲存」後才生效）">
						<Button
							icon={<ReloadOutlined />}
							onClick={handleGenerate}
							disabled={submitting}
						>
							產生
						</Button>
					</Tooltip>
				</Space.Compact>
			</Item>
		</Form>
	)
}

export const PartnerForm = memo(PartnerFormComponent)
