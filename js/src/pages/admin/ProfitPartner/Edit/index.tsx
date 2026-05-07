import { Edit } from '@refinedev/antd'
import { useParsed } from '@refinedev/core'
import { App, Form, Skeleton, Tag } from 'antd'
import { memo, useEffect, useRef } from 'react'

import { PartnerForm } from '@/pages/admin/ProfitPartner/components/PartnerForm'
import { RegeneratePasswordButton } from '@/pages/admin/ProfitPartner/Edit/RegeneratePasswordButton'
import {
	useProfitPartnerOne,
	useProfitPartnerUpdate,
} from '@/pages/admin/ProfitPartner/hooks'
import type {
	TProfitPartner,
	TProfitPartnerInput,
} from '@/pages/admin/ProfitPartner/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const EditComponent = () => {
	const { id } = useParsed()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitPartnerInput>()

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
		})
	}, [record, form])

	const handleSubmit = async () => {
		if (!record) return
		try {
			const values = await form.validateFields()

			// Edit 模式絕不送 password 欄位（要改密碼請走 RegeneratePasswordButton）
			const payload: TProfitPartnerInput = {
				name: values.name,
				slug: values.slug,
				contact_email: values.contact_email ?? null,
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
						name: name as keyof TProfitPartnerInput,
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
			headerButtons={() =>
				record ? (
					<RegeneratePasswordButton
						partnerId={record.id}
						partnerName={record.name}
					/>
				) : null
			}
			goBack={undefined}
			footerButtonProps={{}}
			recordItemId={record?.id}
			resource="profit-partner"
			canDelete={false}
		>
			{isLoading || isFetching || !record ? (
				<Skeleton active paragraph={{ rows: 6 }} />
			) : (
				<PartnerForm form={form} mode="edit" submitting={updater.isLoading} />
			)}
		</Edit>
	)
}

export const ProfitPartnerEdit = memo(EditComponent)
