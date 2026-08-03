import { Edit } from '@refinedev/antd'
import { useParsed } from '@refinedev/core'
import { App, Form, Skeleton, Tag } from 'antd'
import { memo, useEffect, useRef } from 'react'

import { PartnerForm } from '@/pages/admin/ProfitPartner/components/PartnerForm'
import {
	useProfitPartnerOne,
	useProfitPartnerUpdate,
} from '@/pages/admin/ProfitPartner/hooks'
import type {
	TPartnerFormValues,
	TProfitPartner,
	TProfitPartnerInput,
} from '@/pages/admin/ProfitPartner/types'
import {
	toImageId,
	toPartnerFormFieldName,
} from '@/pages/admin/ProfitPartner/utils'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const EditComponent = () => {
	const { id } = useParsed()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TPartnerFormValues>()

	const { data, isLoading, isFetching } = useProfitPartnerOne(id)
	const updater = useProfitPartnerUpdate()

	const record: TProfitPartner | undefined = data?.data?.data

	// 同 ProfitShop Edit：filledIdRef 防 refetch 蓋掉編輯中內容
	const filledIdRef = useRef<number | null>(null)

	useEffect(() => {
		if (!record) return
		if (filledIdRef.current === record.id) return
		filledIdRef.current = record.id

		form.setFieldsValue({
			name: record.name,
			slug: record.slug,
			contact_email: record.contact_email,

			// image_url 為 null 代表 attachment 已被刪除（image_id 仍在）——
			// 這種情況視同未設定，讓站長重新挑一張，而不是顯示破圖。
			// Gallery 吃 TImage[]（id 為字串），故需轉型並包成陣列。
			images:
				record.image_id && record.image_url
					? [{ id: String(record.image_id), url: record.image_url }]
					: [],
		})
	}, [record, form])

	const handleSubmit = async () => {
		if (!record) return
		try {
			// ⚠️ 明確列舉要驗的欄位，**不可**用無參數的 form.validateFields()——
			// 那會連帶驗 PasswordSection 的 new_password / new_password_confirm，
			// 造成「密碼打到一半（只填了新密碼、還沒填確認）就存不了名稱」這種
			// 不相干的阻擋。密碼有自己的「更新密碼」按鈕負責驗證與送出。
			//
			// 這份清單與下面的 payload 欄位是一組的，新增欄位時兩處要一起改。
			const values = await form.validateFields([
				'name',
				'slug',
				'contact_email',
				'images',
			])

			// Edit 模式絕不送 password 欄位（密碼一律走 PasswordSection 的獨立端點）
			const payload: TProfitPartnerInput = {
				name: values.name,
				slug: values.slug,
				contact_email: values.contact_email ?? null,

				// 表單持有 TImage[]，只把第一張的 id 送後端；null 明確代表「移除圖片」
				image_id: toImageId(values.images),
			}

			await updater.mutateAsync(record.id, payload)
			notification.success({
				message: '已更新夥伴',
				description: `#${record.id} ${values.name ?? ''}`,
			})
		} catch (err) {
			if (err && typeof err === 'object' && 'errorFields' in err) return
			const mapped = mapProfitShopException(err)
			if (mapped.fieldErrors) {
				const fields = Object.entries(mapped.fieldErrors).map(
					([name, msg]) => ({
						name: toPartnerFormFieldName(name),
						errors: [msg],
					})
				)
				form.setFields(fields)
			}
			notification.error({
				message: '更新失敗',
				description: mapped.toastMessage,
			})
		}
	}

	return (
		<Edit
			title={
				record ? (
					<>
						{record.name}{' '}
						<Tag bordered={false} color="blue">
							#{record.id}
						</Tag>
					</>
				) : (
					'載入中…'
				)
			}
			isLoading={isLoading}
			saveButtonProps={{
				children: '儲存',
				loading: updater.isLoading,
				onClick: handleSubmit,
				disabled: !record,
			}}
			headerButtons={() => {
				// 密碼相關操作**不放右上角**，一律在表單內「聯絡 Email」下方的
				// PasswordSection（設定新密碼 + 重新產生）——站長是在編輯這位夥伴的
				// 資料，密碼就是其中一個欄位，不該散落到頁面另一端。
				// 這裡回 null 是為了蓋掉 Refine 的預設 header buttons，不是漏寫。
				return null
			}}
			goBack={undefined}
			footerButtonProps={{}}
			recordItemId={record?.id}
			resource="profit-partner"
			canDelete={false}
		>
			{isLoading || isFetching || !record ? (
				<Skeleton active paragraph={{ rows: 6 }} />
			) : (
				<PartnerForm
					form={form}
					mode="edit"
					submitting={updater.isLoading}
					partnerId={record.id}
					partnerName={record.name}
				/>
			)}
		</Edit>
	)
}

export const ProfitPartnerEdit = memo(EditComponent)
