import { Create } from '@refinedev/antd'
import { useGo } from '@refinedev/core'
import { App, Form } from 'antd'
import { memo } from 'react'

import { PartnerForm } from '@/pages/admin/ProfitPartner/components/PartnerForm'
import { useProfitPartnerCreate } from '@/pages/admin/ProfitPartner/hooks'
import type { TProfitPartnerInput } from '@/pages/admin/ProfitPartner/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const CreateComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitPartnerInput>()
	const creator = useProfitPartnerCreate()

	const handleSubmit = async () => {
		try {
			const values = await form.validateFields()

			const payload: TProfitPartnerInput = {
				name: values.name,
				slug: values.slug,
				contact_email: values.contact_email ?? null,
				password: values.password,
			}

			const result = await creator.mutateAsync(payload)
			const newId = result?.data?.data?.id

			notification.success({
				message: '已建立夥伴',
				description: `#${newId ?? ''} ${values.name}`,
			})

			// 注意：表單暫存的 password 在這裡會隨頁面 unmount 自動回收；
			// 不做額外 setFieldsValue({ password: '' }) 是為了讓 antd 自然走 unmount cleanup，
			// 避免反覆 setState 造成多餘 re-render
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
						name: name as keyof TProfitPartnerInput,
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
