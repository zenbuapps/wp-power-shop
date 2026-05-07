import { Form, Input, type FormInstance, type FormRule } from 'antd'
import { memo } from 'react'

import type { TProfitPartnerInput } from '@/pages/admin/ProfitPartner/types'

const { Item } = Form

/** Partner slug 格式（與後端 InvalidPartnerSlug 對齊） */
const SLUG_FORMAT = /^[a-z0-9_-]+$/

type TPartnerFormProps = {
	form: FormInstance<TProfitPartnerInput>
	mode: 'create' | 'edit'
	submitting?: boolean
}

/**
 * Partner 共用表單欄位區塊
 *
 * Edit / Create 兩頁共用；不負責 submit，由父層處理。
 *
 * 安全紀律：
 *   - mode === 'edit' 時**不渲染** password 欄位（要改密碼請走 RegeneratePasswordButton）
 *   - mode === 'create' 時 password 為必填（admin_only endpoint 仍保留 validation）
 */
const PartnerFormComponent = ({
	form,
	mode,
	submitting,
}: TPartnerFormProps) => {
	const slugRules: FormRule[] = [
		{ required: true, message: '請輸入 slug' },
		{
			pattern: SLUG_FORMAT,
			message: '僅允許小寫英文、數字、連字號（-）與底線（_）',
		},
		{ max: 60, message: 'slug 長度不可超過 60 字元' },
	]

	const passwordRules: FormRule[] = [
		{ required: true, message: '請輸入初始密碼' },
		{ min: 8, message: '密碼長度至少 8 字元' },
		{ max: 100, message: '密碼長度不可超過 100 字元' },
	]

	return (
		<Form<TProfitPartnerInput>
			form={form}
			layout="vertical"
			disabled={submitting}
		>
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

			{mode === 'create' && (
				<Item
					name="password"
					label="初始密碼"
					rules={passwordRules}
					extra="建立後可在編輯頁透過「重新產生密碼」更換"
				>
					<Input.Password
						placeholder="至少 8 字元"
						autoComplete="new-password"
					/>
				</Item>
			)}
		</Form>
	)
}

export const PartnerForm = memo(PartnerFormComponent)
