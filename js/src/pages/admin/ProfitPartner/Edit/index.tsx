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
import {
	shouldFillForm,
	shouldSendPassword,
} from '@/pages/admin/ProfitShop/formGuards'

const EditComponent = () => {
	const { id } = useParsed()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TPartnerFormValues>()

	const { data, isLoading, isFetching } = useProfitPartnerOne(id)
	const updater = useProfitPartnerUpdate()

	const record: TProfitPartner | undefined = data?.data?.data

	// 同 ProfitShop Edit：filledIdRef 防 refetch 蓋掉編輯中內容
	const filledIdRef = useRef<number | null>(null)

	/**
	 * 載入當下的密碼原值，用來判斷站長有沒有真的改動密碼
	 *
	 * 後端 `save()` 只要收到 password 就會 bump `password_changed_at`，
	 * 而那會讓該夥伴**當下所有登入 session 立即失效**。密碼現在是預填的一般欄位，
	 * 若不比對就原封不動送回去，站長每次改個名稱按儲存，夥伴都會莫名被踢出報表頁。
	 */
	const initialPasswordRef = useRef<string | null>(null)

	useEffect(() => {
		if (!record) return
		if (!shouldFillForm(record.id, filledIdRef.current)) return
		filledIdRef.current = record.id
		initialPasswordRef.current = record.password

		form.setFieldsValue({
			name: record.name,
			slug: record.slug,
			contact_email: record.contact_email,

			// null（舊資料無明文）填成空字串而非 undefined——undefined 會讓 antd
			// 沿用欄位既有值，切換不同夥伴時可能殘留上一位的密碼。
			password: record.password ?? '',

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
			// 明確列舉要驗的欄位。這份清單與下面的 payload 欄位是一組的，
			// 新增欄位時兩處要一起改。
			const values = await form.validateFields([
				'name',
				'slug',
				'contact_email',
				'images',
				'password',
			])

			const nextPassword = values.password ?? ''

			/**
			 * 密碼只在「真的被改動」時才送
			 *
			 * 空字串一律不送（= 留空表示不變更）；與載入時的原值相同也不送——
			 * 送了就會 bump password_changed_at，把該夥伴踢出登入。
			 */
			const passwordChanged = shouldSendPassword(
				nextPassword,
				initialPasswordRef.current
			)

			const payload: TProfitPartnerInput = {
				name: values.name,
				slug: values.slug,
				contact_email: values.contact_email ?? null,

				// 表單持有 TImage[]，只把第一張的 id 送後端；null 明確代表「移除圖片」
				image_id: toImageId(values.images),

				...(passwordChanged ? { password: nextPassword } : {}),
			}

			await updater.mutateAsync(record.id, payload)

			// 送出成功後把基準線推到新值，否則連按兩次儲存會重複判定為「已改動」，
			// 白白讓夥伴被登出第二次。
			if (passwordChanged) {
				initialPasswordRef.current = nextPassword
			}

			notification.success({
				message: '已更新夥伴',
				description: passwordChanged
					? `#${record.id} ${values.name ?? ''}｜密碼已變更，該夥伴需用新密碼重新登入`
					: `#${record.id} ${values.name ?? ''}`,
				duration: passwordChanged ? 8 : undefined,
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
				// 密碼是表單內的一般欄位（「聯絡 Email」下方），不需要右上角的獨立入口。
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
					passwordUnavailable={null === record.password}
				/>
			)}
		</Edit>
	)
}

export const ProfitPartnerEdit = memo(EditComponent)
