import { Create } from '@refinedev/antd'
import { useGo } from '@refinedev/core'
import { App, Form } from 'antd'
import { memo } from 'react'

import { PartnerForm } from '@/pages/admin/ProfitPartner/components/PartnerForm'
import { useProfitPartnerCreate } from '@/pages/admin/ProfitPartner/hooks'
import type {
	TPartnerFormValues,
	TProfitPartnerInput,
} from '@/pages/admin/ProfitPartner/types'
import {
	toImageId,
	toPartnerFormFieldName,
} from '@/pages/admin/ProfitPartner/utils'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const CreateComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TPartnerFormValues>()
	const creator = useProfitPartnerCreate()

	const handleSubmit = async () => {
		try {
			const values = await form.validateFields()

			const payload: TProfitPartnerInput = {
				name: values.name,
				slug: values.slug,
				contact_email: values.contact_email ?? null,
				password: values.password,
				image_id: toImageId(values.images),
			}

			const result = await creator.mutateAsync(payload)
			const newId = result?.data?.data?.id

			notification.success({
				message: '已建立夥伴',
				description: `#${newId ?? ''} ${values.name}`,
			})

			// password 隨 navigate 後 unmount 自然 GC，不額外清欄位避免多餘 re-render。
			if (newId) {
				go({
					to: { resource: 'profit-partner', action: 'edit', id: newId },
				})
			}
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
				message: '建立失敗',
				description: mapped.toastMessage,
			})
		}
	}

	return (
		<Create
			title="建立分潤夥伴"
			saveButtonProps={{
				children: '建立',
				loading: creator.isLoading,
				onClick: handleSubmit,
			}}
			headerButtons={() => null}
			goBack={undefined}
			resource="profit-partner"
		>
			<PartnerForm form={form} mode="create" submitting={creator.isLoading} />
		</Create>
	)
}

export const ProfitPartnerCreate = memo(CreateComponent)
